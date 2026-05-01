<?php
require_once __DIR__ . '/includes/config.php';
securityHeaders();

$error = '';
$success = '';
$enviado = false;

function interesesParaEmail($ids) {
    if (empty($ids)) return 'No seleccionó intereses';
    $db = getDB();
    $ids_int = array_map('intval', $ids);
    $placeholders = implode(',', array_fill(0, count($ids_int), '?'));
    $cats = $db->prepare("SELECT nombre FROM categorias_redaccion WHERE id IN ($placeholders)");
    $cats->execute($ids_int);
    $nombres = $cats->fetchAll(PDO::FETCH_COLUMN);
    return implode(', ', $nombres);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $ip = clientIp();
    if (!rateLimitOk('inscribir:' . $ip, 5, 3600)) {
        $errores[] = 'Demasiadas inscripciones desde tu conexión. Intenta más tarde.';
        $error = implode('<br>', array_map('e', $errores));
        goto fin_post;
    }

    $nombre = trim($_POST['nombre'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';
    $rut = trim($_POST['rut'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $banco = trim($_POST['banco'] ?? '');
    $tipo_cuenta = trim($_POST['tipo_cuenta'] ?? '');
    $numero_cuenta = trim($_POST['numero_cuenta'] ?? '');
    $intereses = $_POST['intereses'] ?? [];
    $motivacion = trim($_POST['motivacion'] ?? '');
    $acepto_terminos = isset($_POST['acepto_terminos']) ? 1 : 0;

    $errores = [];

    if (empty($nombre)) $errores[] = 'El nombre es obligatorio.';
    if (mb_strlen($nombre) > 120) $errores[] = 'El nombre es demasiado largo.';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = 'Email válido es obligatorio.';
    if (empty($password)) $errores[] = 'La contraseña es obligatoria.';
    if (strlen($password) < 8) $errores[] = 'La contraseña debe tener al menos 8 caracteres.';
    if ($password !== $password2) $errores[] = 'Las contraseñas no coinciden.';
    if (!$acepto_terminos) $errores[] = 'Debes aceptar las condiciones de funcionamiento, cesión de derechos y penalizaciones.';

    if (empty($errores)) {
        rateLimitRecord('inscribir:' . $ip, 5, 3600);
        $db = getDB();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        
        try {
            $stmt = $db->prepare("INSERT INTO usuarios (nombre, email, password, rol, rut, telefono, banco, tipo_cuenta, numero_cuenta, activo, aprobado) VALUES (?, ?, ?, 'periodista', ?, ?, ?, ?, ?, 1, 0)");
            $stmt->execute([$nombre, $email, $hash, $rut, $telefono, $banco, $tipo_cuenta, $numero_cuenta]);
            $user_id = $db->lastInsertId();
            
            // Guardar datos adicionales de postulación
            $intereses_json = json_encode(array_map('intval', $intereses));
            $stmt2 = $db->prepare("INSERT INTO postulaciones (usuario_id, experiencia, intereses_categorias, motivacion) VALUES (?, ?, ?, ?)");
            $stmt2->execute([$user_id, '', $intereses_json, $motivacion]);
            
            // Notificar al admin
            $admin = $db->query("SELECT email, nombre FROM usuarios WHERE rol='admin' AND activo=1 LIMIT 1")->fetch();
            if ($admin) {
                $subject = "Nuevo periodista inscrito: " . preg_replace('/[\r\n]+/', ' ', $nombre);
                $msg = "
                <div style='font-family:sans-serif;max-width:600px;margin:0 auto;background:#111214;padding:2rem;border-radius:12px;border:1px solid rgba(255,255,255,0.08)'>
                    <h2 style='color:#5e6ad2;margin-bottom:1rem'>✍️ Nueva inscripción de periodista</h2>
                    <p style='color:#f7f8f8;margin-bottom:.5rem'><strong>" . e($nombre) . "</strong> se ha inscrito en la plataforma.</p>
                    <table style='color:#a0a4ab;font-size:.9rem;line-height:1.8'>
                        <tr><td style='padding-right:1rem;color:#62666d'>Email:</td><td>" . e($email) . "</td></tr>
                        <tr><td style='padding-right:1rem;color:#62666d'>RUT:</td><td>" . e($rut ?: '—') . "</td></tr>
                        <tr><td style='padding-right:1rem;color:#62666d'>Teléfono:</td><td>" . e($telefono ?: '—') . "</td></tr>
                        <tr><td style='padding-right:1rem;color:#62666d'>Banco:</td><td>" . e($banco ?: '—') . "</td></tr>
                    </table>
                    <p style='color:#a0a4ab;margin-top:1rem;line-height:1.6'><strong>Intereses periodísticos:</strong><br>" . interesesParaEmail($intereses) . "</p>
                    <p style='color:#a0a4ab;line-height:1.6'><strong>Motivación:</strong><br>" . nl2br(e($motivacion ?: 'No indicada')) . "</p>
                    <p style='margin-top:1.5rem'><a href='" . BASE_URL . "/admin/usuarios.php' style='display:inline-block;padding:10px 20px;background:#5e6ad2;color:#fff;text-decoration:none;border-radius:8px'>Revisar y aprobar</a></p>
                    <hr style='border-color:rgba(255,255,255,0.08);margin:1.5rem 0'>
                    <p style='font-size:.8rem;color:#62666d'>El Correo de Valdivia · Sistema de Redacción</p>
                </div>";
                enviarCorreo($admin['email'], $subject, $msg);
            }
            
            $success = '¡Inscripción recibida! Ahora el administrador revisará tus datos y te activará. Te llegará un correo cuando estés aprobado.';
            $enviado = true;
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $errores[] = 'Este email ya está registrado. ¿Ya te inscribiste antes?';
            } else {
                error_log('Inscripción error: ' . $e->getMessage());
                $errores[] = 'Error al registrar. Intenta de nuevo.';
            }
        }
    }

    $error = !empty($errores) ? implode('<br>', array_map('e', $errores)) : '';
    fin_post:
}
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Inscribirse como Periodista · Redacción · El Correo de Valdivia</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700&family=Geist+Mono:wght@400;500&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css">
<style>
.inscribirse-page {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    padding: 2rem;
    background: var(--bg);
}
.inscribirse-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 2.5rem;
    width: 100%;
    max-width: 700px;
    box-shadow: 0 0 0 1px rgba(94,106,210,0.05), 0 8px 40px rgba(0,0,0,0.4);
}
.inscribirse-card .logo { text-align: center; margin-bottom: 1.5rem; }
.inscribirse-card .logo svg { color: var(--text); width: 200px; height: auto; }
.inscribirse-card h1 {
    font-family: 'Geist', system-ui, sans-serif;
    font-size: 1.3rem; font-weight: 600;
    text-align: center; margin-bottom: .3rem;
    color: var(--white);
}
.inscribirse-card .subtitle {
    text-align: center; font-size: .85rem;
    color: var(--text2); margin-bottom: 2rem; line-height: 1.6;
}
.form-section {
    margin-bottom: 1.8rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px solid var(--border);
}
.form-section h3 {
    font-size: .85rem;
    font-weight: 600;
    color: var(--accent);
    margin-bottom: 1rem;
    font-family: 'Geist Mono', monospace;
    text-transform: uppercase;
    letter-spacing: 1px;
}
.terminos-box {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 1rem;
    margin: 1rem 0;
    font-size: .8rem;
    color: var(--text2);
    line-height: 1.6;
    max-height: 200px;
    overflow-y: auto;
}
.success-box {
    text-align: center;
    padding: 3rem 1rem;
}
.success-box .icon {
    font-size: 4rem;
    margin-bottom: 1rem;
}
.success-box h2 {
    color: var(--success);
    margin-bottom: 1rem;
}
.success-box p {
    color: var(--text2);
    line-height: 1.6;
    margin-bottom: 2rem;
}
</style>
</head>
<body class="inscribirse-page">
<div class="inscribirse-card">
    <div class="logo">
        <?php include ROOT_PATH . '/includes/logo.svg'; ?>
    </div>
    
    <?php if ($enviado): ?>
    <div class="success-box">
        <div class="icon">✍️</div>
        <h2>¡Inscripción recibida!</h2>
        <p>Gracias por postular a <strong>El Correo de Valdivia</strong>.<br>
        El administrador revisará tus datos y te activará a la brevedad.<br>
        Te llegará un correo de confirmación cuando estés aprobado.</p>
        <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary">Volver al inicio</a>
    </div>
    <?php else: ?>
    
    <h1>Inscribirse como Periodista</h1>
    <p class="subtitle">Completa tus datos para postular a la redacción de <strong>El Correo de Valdivia</strong>. El administrador revisará tu solicitud y te activará.</p>
    
    <?php if ($error): ?>
    <div class="alert alert-error"><?= $error ?></div>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>
        <div class="form-section">
            <h3>📋 Datos personales</h3>
            <div class="form-row">
                <div class="form-group">
                    <label for="nombre">Nombre completo *</label>
                    <input type="text" id="nombre" name="nombre" required value="<?= e($_POST['nombre'] ?? '') ?>" placeholder="Ej: María González">
                </div>
                <div class="form-group">
                    <label for="email">Email *</label>
                    <input type="email" id="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>" placeholder="tucorreo@ejemplo.cl">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="password">Contraseña *</label>
                    <input type="password" id="password" name="password" required minlength="6" placeholder="Mínimo 6 caracteres">
                </div>
                <div class="form-group">
                    <label for="password2">Repetir contraseña *</label>
                    <input type="password" id="password2" name="password2" required minlength="6" placeholder="Repite la contraseña">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="rut">RUT / Carnet</label>
                    <input type="text" id="rut" name="rut" value="<?= e($_POST['rut'] ?? '') ?>" placeholder="Ej: 12.345.678-9">
                </div>
                <div class="form-group">
                    <label for="telefono">Teléfono</label>
                    <input type="tel" id="telefono" name="telefono" value="<?= e($_POST['telefono'] ?? '') ?>" placeholder="+56 9 XXXX XXXX">
                </div>
            </div>
        </div>
        
        <div class="form-section">
            <h3>🏦 Datos bancarios (para pagos)</h3>
            <div class="form-group">
                <label for="banco">Banco</label>
                <input type="text" id="banco" name="banco" value="<?= e($_POST['banco'] ?? '') ?>" placeholder="Ej: Banco de Chile">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="tipo_cuenta">Tipo de cuenta</label>
                    <select id="tipo_cuenta" name="tipo_cuenta">
                        <option value="">Seleccionar...</option>
                        <option value="Corriente" <?= ($_POST['tipo_cuenta']??'')==='Corriente'?'selected':'' ?>>Cuenta Corriente</option>
                        <option value="Vista" <?= ($_POST['tipo_cuenta']??'')==='Vista'?'selected':'' ?>>Cuenta Vista</option>
                        <option value="RUT" <?= ($_POST['tipo_cuenta']??'')==='RUT'?'selected':'' ?>>Cuenta RUT</option>
                        <option value="Ahorro" <?= ($_POST['tipo_cuenta']??'')==='Ahorro'?'selected':'' ?>>Cuenta de Ahorro</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="numero_cuenta">Número de cuenta</label>
                    <input type="text" id="numero_cuenta" name="numero_cuenta" value="<?= e($_POST['numero_cuenta'] ?? '') ?>" placeholder="Ej: 123456789">
                </div>
            </div>
        </div>
        
        <div class="form-section" style="border-bottom:none">
            <h3>🎯 Temas de interés periodístico</h3>
            <p style="font-size:.8rem;color:var(--text2);margin-bottom:1rem;line-height:1.5">
                Selecciona los temas que más te interesa cubrir. Esto ayudará al administrador a asignarte las historias que mejor se ajusten a tu perfil.
            </p>
            <div class="checkbox-group" style="display:grid;grid-template-columns:1fr 1fr;gap:.3rem">
                <?php
                $cats = $db->query("SELECT id, nombre, descripcion FROM categorias_redaccion WHERE activo=1 ORDER BY nombre")->fetchAll();
                foreach ($cats as $cat):
                ?>
                <label class="checkbox-item">
                    <input type="checkbox" name="intereses[]" value="<?= $cat['id'] ?>" <?= in_array((string)$cat['id'], $_POST['intereses'] ?? []) ? 'checked' : '' ?>>
                    <span class="label">
                        <strong><?= e($cat['nombre']) ?></strong>
                        <?php if ($cat['descripcion']): ?>
                        <br><span style="font-size:.7rem;color:var(--muted)"><?= e($cat['descripcion']) ?></span>
                        <?php endif; ?>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="terminos-box">
            <strong>Términos y condiciones</strong><br><br>
            Al inscribirme como periodista en El Correo de Valdivia, me comprometo a:<br><br>
            • Entregar contenido original y veraz.<br>
            • Respetar las fechas de entrega acordadas.<br>
            • Ceder los derechos de publicación al medio para su distribución.<br>
            • Mantener una comunicación profesional con la administración.<br><br>
            El Correo de Valdivia se reserva el derecho de aceptar o rechazar solicitudes de inscripción.
        </div>
        
        <label class="checkbox-item" style="margin-bottom:1.5rem">
            <input type="checkbox" name="acepto_terminos" value="1" required>
            <span class="label" style="font-size:.85rem">Acepto las <a href="<?= BASE_URL ?>/condiciones.php" target="_blank" style="color:var(--accent);text-decoration:underline">condiciones de funcionamiento</a>, la cesión de derechos y las penalizaciones por atraso. *</span>
        </label>
        
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:.8rem;font-size:.95rem">
            ✍️ Enviar inscripción
        </button>
        
        <p style="text-align:center;margin-top:1.2rem;font-size:.8rem;color:var(--muted)">
            ¿Ya tienes cuenta? <a href="<?= BASE_URL ?>/index.php">Inicia sesión</a>
        </p>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
