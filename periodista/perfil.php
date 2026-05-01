<?php
$titulo = 'Mi Perfil';
require_once __DIR__ . '/header.php';

$db = getDB();
$user_id = $_SESSION['usuario_id'];
$user = usuarioActual();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $nombre = trim($_POST['nombre'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $rut = trim($_POST['rut'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $banco = trim($_POST['banco'] ?? '');
    $tipo_cuenta = trim($_POST['tipo_cuenta'] ?? '');
    $numero_cuenta = trim($_POST['numero_cuenta'] ?? '');

    if (empty($nombre) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Nombre y email válidos son obligatorios.');
        header('Location: ' . BASE_URL . '/periodista/perfil.php');
        exit;
    }

    try {
        $stmt = $db->prepare("UPDATE usuarios SET nombre=?, email=?, rut=?, telefono=?, banco=?, tipo_cuenta=?, numero_cuenta=? WHERE id=?");
        $stmt->execute([$nombre, $email, $rut, $telefono, $banco, $tipo_cuenta, $numero_cuenta, $user_id]);

        $_SESSION['usuario_nombre'] = $nombre;

        flash('success', 'Perfil actualizado correctamente.');
    } catch (PDOException $e) {
        flash('error', 'El email ya está en uso.');
    }

    header('Location: ' . BASE_URL . '/periodista/perfil.php');
    exit;
}

// Obtener datos actualizados
$stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$user_id]);
$u = $stmt->fetch();

// Resumen de pagos
$pagos = $db->prepare("
    SELECT COUNT(*) AS total_pagos, COALESCE(SUM(liquido),0) AS total_liquido,
           COALESCE(SUM(retencion),0) AS total_retencion, COALESCE(SUM(monto_total),0) AS total_bruto
    FROM pagos WHERE periodista_id = ? AND estado = 'pagado'
");
$pagos->execute([$user_id]);
$resumen_pagos = $pagos->fetch();
?>

<div class="page-header">
    <h1>👤 Mi Perfil</h1>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.2rem">
    <div class="card">
        <div class="card-header"><h2>Datos Personales</h2></div>
        <div class="perfil-avatar"><?= mb_substr($u['nombre'], 0, 1) ?></div>
        
        <form method="post">
            <?= csrf_field() ?>
            <div class="form-row">
                <div class="form-group">
                    <label>Nombre completo</label>
                    <input type="text" name="nombre" value="<?= e($u['nombre']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" value="<?= e($u['email']) ?>" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>RUT / Carnet</label>
                    <input type="text" name="rut" value="<?= e($u['rut'] ?? '') ?>" placeholder="Ej: 12.345.678-9">
                </div>
                <div class="form-group">
                    <label>Teléfono</label>
                    <input type="tel" name="telefono" value="<?= e($u['telefono'] ?? '') ?>" placeholder="+56 9 XXXX XXXX">
                </div>
            </div>
            
            <hr style="border-color:var(--border);margin:1.5rem 0">
            
            <h3 style="font-size:.9rem;margin-bottom:1rem;color:var(--white)">🏦 Datos Bancarios</h3>
            
            <div class="form-group">
                <label>Banco</label>
                <input type="text" name="banco" value="<?= e($u['banco'] ?? '') ?>" placeholder="Ej: Banco de Chile">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Tipo de cuenta</label>
                    <select name="tipo_cuenta">
                        <option value="">Seleccionar...</option>
                        <option value="Corriente" <?= ($u['tipo_cuenta']??'')==='Corriente'?'selected':'' ?>>Cuenta Corriente</option>
                        <option value="Vista" <?= ($u['tipo_cuenta']??'')==='Vista'?'selected':'' ?>>Cuenta Vista</option>
                        <option value="RUT" <?= ($u['tipo_cuenta']??'')==='RUT'?'selected':'' ?>>Cuenta RUT</option>
                        <option value="Ahorro" <?= ($u['tipo_cuenta']??'')==='Ahorro'?'selected':'' ?>>Cuenta de Ahorro</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Número de cuenta</label>
                    <input type="text" name="numero_cuenta" value="<?= e($u['numero_cuenta'] ?? '') ?>" placeholder="Ej: 123456789">
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary" style="margin-top:1rem">💾 Guardar Cambios</button>
        </form>
    </div>
    
    <div>
        <div class="card">
            <div class="card-header"><h2>💰 Mis Pagos</h2></div>
            <div class="stats-grid" style="grid-template-columns:1fr 1fr">
                <div class="stat-card" style="padding:.8rem">
                    <div class="stat-value" style="font-size:1.3rem">$<?= number_format($resumen_pagos['total_bruto'], 0, ',', '.') ?></div>
                    <div class="stat-label">Bruto total</div>
                </div>
                <div class="stat-card" style="padding:.8rem">
                    <div class="stat-value" style="font-size:1.3rem;color:var(--warning)">$<?= number_format($resumen_pagos['total_retencion'], 0, ',', '.') ?></div>
                    <div class="stat-label">Retenciones</div>
                </div>
                <div class="stat-card" style="padding:.8rem">
                    <div class="stat-value" style="font-size:1.3rem;color:var(--success)">$<?= number_format($resumen_pagos['total_liquido'], 0, ',', '.') ?></div>
                    <div class="stat-label">Líquido recibido</div>
                </div>
                <div class="stat-card" style="padding:.8rem">
                    <div class="stat-value" style="font-size:1.3rem"><?= $resumen_pagos['total_pagos'] ?></div>
                    <div class="stat-label">Pagos recibidos</div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header"><h2>📋 Últimas Historias</h2></div>
            <?php
            $ultimas = $db->prepare("SELECT titulo, estado, fecha_entrega, presupuesto FROM historias WHERE periodista_asignado = ? ORDER BY created_at DESC LIMIT 5");
            $ultimas->execute([$user_id]);
            $rows = $ultimas->fetchAll();
            if (empty($rows)): ?>
                <div class="empty-state"><p>Aún no tienes historias.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Título</th><th>Estado</th><th>Monto</th></tr></thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?= e($r['titulo']) ?></td>
                                <td><span class="badge badge-<?= $r['estado'] ?>"><?= $r['estado'] ?></span></td>
                                <td>$<?= number_format($r['presupuesto'], 0, ',', '.') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
