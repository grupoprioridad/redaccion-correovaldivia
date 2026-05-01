<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
securityHeaders();

$error = '';
$success = '';
$modo = 'formulario';
$email_verificando = '';
$db = getDB();
$categorias_disponibles = $db->query("SELECT id, nombre, descripcion FROM categorias_redaccion WHERE activo=1 ORDER BY nombre")->fetchAll();

// ── Paso 2: Verificar código ──
if (isset($_GET['codigo']) || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verificar')) {
    $modo = 'codigo_enviado';
    $email_verificando = $_SESSION['verificar_email'] ?? ($_GET['email'] ?? '');
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verificar') {
        csrf_verify();
        $codigo = trim($_POST['codigo'] ?? '');
        $email = $_SESSION['verificar_email'] ?? '';
        
        if (empty($email)) {
            $error = 'Sesión expirada. Regístrate de nuevo.';
            $modo = 'formulario';
        } elseif (empty($codigo)) {
            $error = 'Ingresa el código de verificación.';
        } else {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM codigos_verificacion WHERE email = ? AND codigo = ? AND usado = 0 AND expira_en > NOW() ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$email, $codigo]);
            $row = $stmt->fetch();
            
            if ($row) {
                // Marcar código como usado
                $db->prepare("UPDATE codigos_verificacion SET usado = 1 WHERE id = ?")->execute([$row['id']]);
                // Marcar email como verificado
                $db->prepare("UPDATE usuarios SET email_verificado = 1 WHERE email = ? AND email_verificado = 0")->execute([$email]);
                
                $_SESSION['verificar_email'] = '';
                $modo = 'verificado';
                $success = '✅ Email verificado correctamente. Ahora el administrador revisará tu solicitud.';
            } else {
                $error = 'Código inválido o expirado. Solicita uno nuevo.';
            }
        }
    }
}

// ── Paso 3: Reenviar código ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reenviar') {
    csrf_verify();
    $email = $_SESSION['verificar_email'] ?? $_POST['email'] ?? '';
    if (!empty($email)) {
        $codigo = generarCodigo();
        $db = getDB();
        $db->prepare("INSERT INTO codigos_verificacion (email, codigo, expira_en) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))")->execute([$email, $codigo]);
        enviarCodigoVerificacion($email, $codigo);
        flash('info', 'Nuevo código enviado a tu email.');
    }
    header('Location: ' . BASE_URL . '/inscribirse.php?codigo=1&email=' . urlencode($email));
    exit;
}

// ── Paso 1: Formulario de inscripción ──
if ($modo === 'formulario' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'inscribir') {
    csrf_verify();

    $ip = clientIp();
    if (!rateLimitOk('inscribir:' . $ip, 5, 3600)) {
        $errores[] = 'Demasiadas inscripciones desde tu conexión. Intenta más tarde.';
        $error_msg = implode('<br>', array_map('e', $errores));
        goto fin_form;
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
    if (empty($intereses)) $errores[] = 'Debes seleccionar al menos un tema de interés periodístico.';
    if (!$acepto_terminos) $errores[] = 'Debes aceptar las condiciones de funcionamiento, cesión de derechos y penalizaciones.';

    if (empty($errores)) {
        rateLimitRecord('inscribir:' . $ip, 5, 3600);
        $db = getDB();
        $hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $db->prepare("INSERT INTO usuarios (nombre, email, password, rol, rut, telefono, banco, tipo_cuenta, numero_cuenta, activo, aprobado, email_verificado) VALUES (?, ?, ?, 'periodista', ?, ?, ?, ?, ?, 1, 0, 0)");
            $stmt->execute([$nombre, $email, $hash, $rut, $telefono, $banco, $tipo_cuenta, $numero_cuenta]);
            $user_id = $db->lastInsertId();

            // Guardar postulación
            $intereses_json = json_encode(array_map('intval', $intereses));
            $stmt2 = $db->prepare("INSERT INTO postulaciones (usuario_id, intereses_categorias, motivacion) VALUES (?, ?, ?)");
            $stmt2->execute([$user_id, $intereses_json, $motivacion]);

            // Generar y enviar código de verificación
            $codigo = generarCodigo();
            $db->prepare("INSERT INTO codigos_verificacion (email, codigo, expira_en) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))")->execute([$email, $codigo]);
            enviarCodigoVerificacion($email, $codigo);

            // Guardar en sesión para el paso 2
            $_SESSION['verificar_email'] = $email;
            $_SESSION['verificar_nombre'] = $nombre;
            
            // Redirigir al paso de código
            header('Location: ' . BASE_URL . '/inscribirse.php?codigo=1&email=' . urlencode($email));
            exit;
            exit;

        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $errores[] = 'Este email ya está registrado. ¿Ya te inscribiste antes?';
            } else {
                error_log('Inscripción error: ' . $e->getMessage());
                $errores[] = 'Error al registrar. Intenta de nuevo.';
            }
        }
    }

    $error_msg = !empty($errores) ? implode('<br>', array_map('e', $errores)) : '';
    fin_form:
}

// Si viene con GET codigo, mostrar formulario de código
if (isset($_GET['codigo'])) {
    $modo = 'codigo_enviado';
    $email_verificando = $_GET['email'] ?? $_SESSION['verificar_email'] ?? '';
    if (!empty($email_verificando)) {
        $_SESSION['verificar_email'] = $email_verificando;
    }
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
.inscribirse-page{display:flex;align-items:center;justify-content:center;min-height:100vh;padding:2rem;background:var(--bg)}
.inscribirse-card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:2.5rem;width:100%;max-width:700px;box-shadow:0 0 0 1px rgba(94,106,210,0.05),0 8px 40px rgba(0,0,0,0.4)}
.inscribirse-card .logo{text-align:center;margin-bottom:1.5rem}
.inscribirse-card .logo svg{color:var(--text);width:200px;height:auto}
.inscribirse-card h1{font-family:'Geist',system-ui,sans-serif;font-size:1.3rem;font-weight:600;text-align:center;margin-bottom:.3rem;color:var(--white)}
.inscribirse-card .subtitle{text-align:center;font-size:.85rem;color:var(--text2);margin-bottom:2rem;line-height:1.6}
.form-section{margin-bottom:1.8rem;padding-bottom:1.5rem;border-bottom:1px solid var(--border)}
.form-section h3{font-size:.85rem;font-weight:600;color:var(--accent);margin-bottom:1rem;font-family:'Geist Mono',monospace;text-transform:uppercase;letter-spacing:1px}
.terminos-box{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:1rem;margin:1rem 0;font-size:.8rem;color:var(--text2);line-height:1.6;max-height:200px;overflow-y:auto}
.success-box,.codigo-box{text-align:center;padding:2rem 1rem}
.success-box .icon,.codigo-box .icon{font-size:4rem;margin-bottom:1rem}
.success-box h2{color:var(--success);margin-bottom:1rem}
.success-box p,.codigo-box p{color:var(--text2);line-height:1.6;margin-bottom:1rem}
.codigo-input{max-width:300px;margin:1.5rem auto}
.codigo-input input{text-align:center;font-size:1.8rem;font-family:'Geist Mono',monospace;letter-spacing:10px;padding:.8rem;background:var(--surface2);border:1px solid var(--border);border-radius:12px;color:var(--text);width:100%;outline:none;transition:all .25s}
.codigo-input input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-glow)}
</style>
</head>
<body class="inscribirse-page">
<div class="inscribirse-card">
<div class="logo"><?php include ROOT_PATH.'/includes/logo.svg'; ?></div>

<?php if ($modo === 'verificado'): ?>
    <div class="success-box">
        <div class="icon">✅</div>
        <h2>Email verificado</h2>
        <p>Gracias por registrarte en <strong>El Correo de Valdivia</strong>. Tu email ha sido verificado correctamente.<br>
        Ahora el administrador revisará tus datos y te activará a la brevedad.<br>
        Te llegará un correo cuando estés aprobado.</p>
        <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary">Volver al inicio</a>
    </div>

<?php elseif ($modo === 'codigo_enviado'): ?>
    <div class="codigo-box">
        <div class="icon">📧</div>
        <h1>Verifica tu email</h1>
        <p>Hemos enviado un código de verificación a<br>
        <strong style="color:var(--accent)"><?= e($email_verificando) ?></strong></p>
        <p style="font-size:.8rem;color:var(--muted)">Revisa tu bandeja de entrada (y la carpeta de spam si no lo ves).</p>
        
        <?php if ($error): ?>
        <div class="alert alert-error"><?= $error ?></div>
        <?php endif; ?>
        
        <?php notificarFlash(); ?>
        
        <form method="post" action="<?= BASE_URL ?>/inscribirse.php?codigo=1">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="verificar">
            <div class="codigo-input">
                <input type="text" name="codigo" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" placeholder="000000" autofocus required>
            </div>
            <button type="submit" class="btn btn-primary" style="padding:.8rem 2.5rem;font-size:.95rem">
                🔐 Verificar código
            </button>
        </form>
        
        <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border);text-align:center">
            <p style="font-size:.85rem;color:var(--text2);margin-bottom:.8rem">
                ¿No recibiste el código?
            </p>
            <form method="post" action="<?= BASE_URL ?>/inscribirse.php?codigo=1" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reenviar">
                <input type="hidden" name="email" value="<?= e($email_verificando) ?>">
                <button type="submit" class="btn btn-secondary">
                    🔄 Reenviar código
                </button>
            </form>
            <p style="margin-top:.8rem;font-size:.75rem;color:var(--muted)">
                El código anterior expirará. El código expira en 30 minutos.
            </p>
        </div>
    </div>

<?php else: ?>
    <h1>Inscribirse como Periodista</h1>
    <p class="subtitle">Completa tus datos para postular a la redacción de <strong>El Correo de Valdivia</strong>. Recibirás un código de verificación por email y luego el administrador revisará tu solicitud.</p>
    
    <?php if (!empty($error_msg)): ?>
    <div class="alert alert-error"><?= $error_msg ?></div>
    <?php endif; ?>
    
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="inscribir">
        
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
                    <input type="password" id="password" name="password" required minlength="8" placeholder="Mínimo 8 caracteres">
                </div>
                <div class="form-group">
                    <label for="password2">Repetir contraseña *</label>
                    <input type="password" id="password2" name="password2" required minlength="8" placeholder="Repite la contraseña">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="rut">RUT / Carnet</label>
                    <input type="text" id="rut" name="rut" required value="<?= e($_POST['rut'] ?? '') ?>" placeholder="Ej: 12.345.678-9">
                </div>
                <div class="form-group">
                    <label for="telefono">Teléfono</label>
                    <input type="tel" id="telefono" name="telefono" required value="<?= e($_POST['telefono'] ?? '') ?>" placeholder="+56 9 XXXX XXXX">
                </div>
            </div>
        </div>
        
        <div class="form-section">
            <h3>🏦 Datos bancarios (para pagos)</h3>
            <div class="form-group">
                <label for="banco">Banco</label>
                <input type="text" id="banco" name="banco" required value="<?= e($_POST['banco'] ?? '') ?>" placeholder="Ej: Banco de Chile">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="tipo_cuenta">Tipo de cuenta</label>
                    <select id="tipo_cuenta" name="tipo_cuenta" required>
                        <option value="">Seleccionar...</option>
                        <option value="Corriente" <?= ($_POST['tipo_cuenta']??'')==='Corriente'?'selected':'' ?>>Cuenta Corriente</option>
                        <option value="Vista" <?= ($_POST['tipo_cuenta']??'')==='Vista'?'selected':'' ?>>Cuenta Vista</option>
                        <option value="RUT" <?= ($_POST['tipo_cuenta']??'')==='RUT'?'selected':'' ?>>Cuenta RUT</option>
                        <option value="Ahorro" <?= ($_POST['tipo_cuenta']??'')==='Ahorro'?'selected':'' ?>>Cuenta de Ahorro</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="numero_cuenta">Número de cuenta</label>
                    <input type="text" id="numero_cuenta" name="numero_cuenta" required value="<?= e($_POST['numero_cuenta'] ?? '') ?>" placeholder="Ej: 123456789">
                </div>
            </div>
        </div>
        
        <div class="form-section" style="border-bottom:none">
            <h3>🎯 Temas de interés periodístico</h3>
            <p style="font-size:.8rem;color:var(--text2);margin-bottom:1rem;line-height:1.5">
                Selecciona los temas que más te interesa cubrir (al menos uno).
            </p>
            <div class="checkbox-group" style="display:grid;grid-template-columns:1fr 1fr;gap:.3rem">
                <?php
                foreach ($categorias_disponibles as $cat):
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
            <span class="label" style="font-size:.85rem">
                Acepto las <a href="<?= BASE_URL ?>/condiciones.php" target="_blank" style="color:var(--accent);text-decoration:underline">condiciones de funcionamiento</a>, la cesión de derechos y las penalizaciones por atraso. *
            </span>
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
