<?php
$titulo = 'Subir Boleta de Honorarios';
require_once __DIR__ . '/header.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$user_id = $_SESSION['usuario_id'];
$user = usuarioActual();

$historia = $db->prepare("
    SELECT h.*, u.nombre AS admin_nombre
    FROM historias h
    LEFT JOIN usuarios u ON h.creada_por = u.id
    WHERE h.id = ? AND h.periodista_asignado = ?
");
$historia->execute([$id, $user_id]);
$h = $historia->fetch();

if (!$h) {
    flash('error', 'Historia no encontrada.');
    header('Location: ' . BASE_URL . '/periodista/index.php');
    exit;
}

if ($h['estado'] !== 'revisada') {
    flash('info', 'Solo puedes subir la boleta cuando la historia esté aprobada.');
    header('Location: ' . BASE_URL . '/periodista/historia.php?id=' . $id);
    exit;
}

$monto = (int)($h['monto_total_a_pagar'] ?? $h['presupuesto']);
$retencion = (int)round($monto * 0.1525);
$liquido = $monto - $retencion;

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if ($h['boleta_path']) {
        flash('error', 'Ya existe una boleta subida para esta historia.');
        header('Location: ' . BASE_URL . '/periodista/historia.php?id=' . $id);
        exit;
    }

    if (empty($_FILES['boleta']['name'])) {
        $error = 'Debes seleccionar un archivo.';
    } else {
        $ruta = subirComprobante($_FILES['boleta'], 'boletas');
        if ($ruta) {
            $db->prepare("UPDATE historias SET boleta_path=?, boleta_subida_en=NOW() WHERE id=?")
               ->execute([$ruta, $id]);

            $admin = $db->query("SELECT email FROM usuarios WHERE rol='admin' AND activo=1 LIMIT 1")->fetch();
            if ($admin) {
                $titSafe   = e($h['titulo']);
                $nomSafe   = e($user['nombre']);
                $montoFmt  = '$' . number_format($monto, 0, ',', '.');
                $boletaUrl = e(urlImagen($ruta));
                $enlaceAdmin = BASE_URL . '/admin/historia-editar.php?id=' . $id;
                $subject = "Boleta subida: " . preg_replace('/[\r\n]+/', ' ', mb_substr($h['titulo'], 0, 80));
                $msg = "
<p><strong>{$nomSafe}</strong> ha subido su boleta de honorarios para la historia <strong>{$titSafe}</strong>.</p>
<table style='border-collapse:collapse;font-family:sans-serif;font-size:14px;margin:12px 0'>
  <tr><td style='padding:4px 12px 4px 0;color:#666'>Monto bruto:</td><td style='padding:4px 0;font-weight:bold'>{$montoFmt}</td></tr>
</table>
<p style='margin-top:16px'>
  <a href='{$enlaceAdmin}' style='background:#5e6ad2;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:bold'>Ver en el panel de administración</a>
</p>
<p style='margin-top:8px;font-size:12px;color:#888'>
  <a href='{$boletaUrl}'>Ver boleta directamente</a>
</p>";
                enviarCorreo($admin['email'], $subject, $msg);
            }

            $boleta_ok = true;
        } else {
            $error = 'No se pudo subir el archivo. Verifica que sea PDF, JPG, PNG o WEBP y que pese menos de 10 MB.';
        }
    }
}
?>

<?php if (!empty($boleta_ok)): ?>
<!-- ══ PANTALLA DE CONFIRMACIÓN ══════════════════════════════════════ -->
<div style="max-width:600px;margin:3rem auto;text-align:center">
    <div class="card" style="border-color:rgba(39,166,68,.5);padding:2.5rem 2rem">
        <div style="font-size:3.5rem;margin-bottom:1rem">✅</div>
        <h1 style="font-size:1.5rem;margin-bottom:.5rem">¡Boleta recibida!</h1>
        <p style="color:var(--muted);font-size:.9rem;margin-bottom:1.5rem">
            Tu boleta de honorarios fue enviada exitosamente al equipo administrativo.<br>
            Te avisaremos cuando el pago sea procesado.
        </p>

        <!-- Mini stepper de estado -->
        <div style="display:flex;align-items:center;justify-content:center;gap:0;margin:1.5rem 0">
            <?php
            $pasos_conf = [
                ['icon'=>'📝','label'=>'Entregada','done'=>true],
                ['icon'=>'✅','label'=>'Aprobada','done'=>true],
                ['icon'=>'🧾','label'=>'Boleta enviada','done'=>true,'current'=>false],
                ['icon'=>'💰','label'=>'En espera de pago','done'=>false,'current'=>true],
            ];
            foreach ($pasos_conf as $i => $p):
                $col  = $p['done'] ? '#27a644' : ($p['current'] ? '#5e6ad2' : 'var(--muted)');
                $bg   = $p['done'] ? 'rgba(39,166,68,.12)' : ($p['current'] ? 'rgba(94,106,210,.15)' : 'transparent');
                $bord = $p['done'] ? '2px solid #27a644' : ($p['current'] ? '2px solid #5e6ad2' : '2px solid var(--border)');
            ?>
            <div style="display:flex;align-items:center;gap:0">
                <div style="display:flex;flex-direction:column;align-items:center;gap:.25rem;min-width:80px;padding:.4rem .2rem;background:<?= $bg ?>;border:<?= $bord ?>;border-radius:10px">
                    <span style="font-size:1rem"><?= $p['icon'] ?><?= $p['done'] ? ' ✓' : '' ?></span>
                    <span style="font-size:.62rem;font-weight:<?= ($p['current']??false)?'700':'500' ?>;color:<?= $col ?>;text-align:center;line-height:1.3"><?= $p['label'] ?></span>
                </div>
                <?php if ($i < count($pasos_conf)-1): ?>
                <div style="width:14px;height:2px;background:<?= $p['done'] ? '#27a644' : 'var(--border)' ?>"></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="background:rgba(94,106,210,.08);border-radius:10px;padding:1rem;font-size:.82rem;color:var(--muted);margin-bottom:1.5rem">
            El administrador revisará tu boleta y procesará la transferencia.<br>
            Recibirás un correo de confirmación cuando el pago sea emitido.
        </div>

        <a href="<?= BASE_URL ?>/periodista/historia.php?id=<?= $id ?>" class="btn btn-primary">
            Ver estado de mi historia →
        </a>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; exit; ?>
<?php endif; ?>

<!-- ══ PANTALLA NORMAL ═══════════════════════════════════════════════ -->
<div class="page-header">
    <div>
        <h1>Boleta de Honorarios</h1>
        <div class="subtitle"><?= e($h['titulo']) ?></div>
    </div>
    <a href="<?= BASE_URL ?>/periodista/historia.php?id=<?= $id ?>" class="btn btn-secondary btn-sm">← Volver</a>
</div>

<?php if ($error): ?>
<div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<!-- Datos de facturación -->
<div class="card" style="border-color:rgba(94,106,210,.35)">
    <div class="card-header">
        <h2>Datos para tu boleta</h2>
        <span class="badge badge-revisada">Historia aprobada</span>
    </div>

    <p style="font-size:.85rem;color:var(--muted);margin-bottom:1.2rem">
        Tu historia fue aprobada. Para procesar el pago necesitas generar una <strong>Boleta de Honorarios Electrónica</strong>
        en <a href="https://homer.sii.cl/" target="_blank" rel="noopener" style="color:var(--accent)">SII.cl</a> con los siguientes datos y luego subirla aquí.
    </p>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem">
        <div>
            <h3 style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:.8rem">Emite la boleta a nombre de</h3>
            <div class="detail-row">
                <span class="detail-label">Empresa</span>
                <span class="detail-value"><strong><?= e(EMPRESA_NOMBRE) ?></strong></span>
            </div>
            <?php if (EMPRESA_RUT): ?>
            <div class="detail-row">
                <span class="detail-label">RUT</span>
                <span class="detail-value"><strong><?= e(EMPRESA_RUT) ?></strong></span>
            </div>
            <?php endif; ?>
            <div class="detail-row">
                <span class="detail-label">Giro</span>
                <span class="detail-value"><?= e(EMPRESA_GIRO) ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Dirección</span>
                <span class="detail-value"><?= e(EMPRESA_DIRECCION) ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Concepto</span>
                <span class="detail-value">Honorarios periodísticos · <?= e(mb_substr($h['titulo'], 0, 60)) ?></span>
            </div>
            <?php if ($h['codigo']): ?>
            <div class="detail-row" style="background:rgba(94,106,210,.1);border-radius:8px;padding:.5rem .8rem;margin-top:.4rem">
                <span class="detail-label" style="font-weight:600">Referencia de pago</span>
                <span class="detail-value" style="font-family:monospace;font-size:1.15rem;font-weight:700;color:var(--accent);letter-spacing:.05em"><?= e($h['codigo']) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <div>
            <h3 style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:.8rem">Montos</h3>
            <div class="detail-row">
                <span class="detail-label">Monto bruto</span>
                <span class="detail-value" style="font-size:1.15rem;font-weight:700;color:var(--text)">$<?= number_format($monto, 0, ',', '.') ?></span>
            </div>
            <div style="padding:.6rem 0;border-bottom:1px solid var(--border)">
                <div style="display:flex;justify-content:space-between;font-size:.8rem;color:var(--muted);margin-bottom:.25rem">
                    <span>Retención segunda categoría (15,25%)</span>
                    <span style="color:var(--warning)">− $<?= number_format($retencion, 0, ',', '.') ?></span>
                </div>
                <div style="font-size:.72rem;color:var(--muted)">Retención aplicada por el empleador sobre honorarios</div>
            </div>
            <div class="detail-row" style="padding-top:.8rem">
                <span class="detail-label">Líquido a recibir</span>
                <span class="detail-value" style="font-size:1.1rem;font-weight:700;color:var(--success)">$<?= number_format($liquido, 0, ',', '.') ?></span>
            </div>
            <div style="margin-top:.5rem;padding:.6rem .8rem;background:rgba(39,166,68,.08);border-radius:8px;font-size:.75rem;color:var(--muted)">
                Emite la boleta por el monto bruto ($<?= number_format($monto, 0, ',', '.') ?>). La retención es calculada y declarada por nosotros.<br>
                ¿Dudas de facturación? Escribe a <a href="mailto:<?= e(EMPRESA_EMAIL_FINANZAS) ?>" style="color:var(--accent)"><?= e(EMPRESA_EMAIL_FINANZAS) ?></a>
            </div>
        </div>
    </div>

    <div style="padding:1rem;background:var(--surface2);border-radius:10px;font-size:.82rem;line-height:1.7">
        <strong>Instrucciones:</strong>
        <ol style="margin:.4rem 0 0 1.2rem;padding:0">
            <li>Entra a <a href="https://homer.sii.cl/" target="_blank" rel="noopener" style="color:var(--accent)">SII.cl</a> con tu RUT y clave tributaria.</li>
            <li>Ve a <strong>Servicios online → Boleta de honorarios → Emitir boleta</strong>.</li>
            <li>Ingresa los datos de la empresa de arriba y el monto bruto <strong>$<?= number_format($monto, 0, ',', '.') ?></strong>.</li>
            <li>Descarga el PDF de la boleta emitida y súbela a continuación.</li>
        </ol>
    </div>
</div>

<!-- Formulario subida -->
<?php if ($h['boleta_path']): ?>
<div class="card" style="border-color:rgba(39,166,68,.35)">
    <div class="card-header"><h2>✅ Boleta ya subida</h2></div>
    <p style="font-size:.85rem;color:var(--muted)">Ya subiste tu boleta. El administrador la está revisando para procesar el pago.</p>
    <?php
        $ext = strtolower(pathinfo($h['boleta_path'], PATHINFO_EXTENSION));
        $boletaUrl = urlImagen($h['boleta_path']);
    ?>
    <a href="<?= e($boletaUrl) ?>" target="_blank" class="btn btn-secondary btn-sm">
        <?= $ext === 'pdf' ? '📄 Ver boleta PDF' : '🖼 Ver boleta' ?>
    </a>
    <p style="font-size:.72rem;color:var(--muted);margin-top:.75rem">Subida el <?= date('d/m/Y H:i', strtotime($h['boleta_subida_en'])) ?></p>
</div>
<?php else: ?>
<div class="card">
    <div class="card-header"><h2>Subir boleta</h2></div>
    <p style="font-size:.85rem;color:var(--muted);margin-bottom:1rem">Formatos aceptados: PDF, JPG, PNG, WEBP · Máximo 10 MB</p>
    <form method="post" enctype="multipart/form-data" action="<?= BASE_URL ?>/periodista/subir-boleta?id=<?= $id ?>">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="boleta_file">Archivo de boleta *</label>
            <input type="file" id="boleta_file" name="boleta" accept=".pdf,.jpg,.jpeg,.png,.webp" required style="padding:.4rem 0">
        </div>
        <button type="submit" class="btn btn-primary">📤 Subir boleta</button>
    </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
