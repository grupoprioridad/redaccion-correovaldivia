<?php
$titulo = 'Ver Historia';
require_once __DIR__ . '/header.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

$historia = $db->prepare("
    SELECT h.*, u.nombre AS creador_nombre, p.nombre AS periodista_nombre, c.nombre AS categoria_nombre
    FROM historias h
    LEFT JOIN usuarios u ON h.creada_por = u.id
    LEFT JOIN usuarios p ON h.periodista_asignado = p.id
    LEFT JOIN categorias_redaccion c ON h.categoria_id = c.id
    WHERE h.id = ?
");
$historia->execute([$id]);
$h = $historia->fetch();

$categorias = $db->query("SELECT id, nombre FROM categorias_redaccion WHERE activo=1 ORDER BY nombre")->fetchAll();
$periodistas = $db->query("SELECT id, nombre, email FROM usuarios WHERE rol='periodista' AND activo=1 AND aprobado=1 ORDER BY nombre")->fetchAll();
$visStmt = $db->prepare("SELECT usuario_id FROM historia_visibilidad WHERE historia_id = ?");
$visStmt->execute([$id]);
$visibilidad_actual = array_map('intval', $visStmt->fetchAll(PDO::FETCH_COLUMN));

if (!$h) {
    flash('error', 'Historia no encontrada.');
    header('Location: ' . BASE_URL . '/admin/index.php');
    exit;
}

// Adquirir lock de admin para que periodistas no editen mientras tenemos la página abierta
$db->exec("CREATE TABLE IF NOT EXISTS historia_locks (historia_id INT NOT NULL PRIMARY KEY, admin_id INT NOT NULL, locked_at DATETIME NOT NULL)");
$db->prepare("INSERT INTO historia_locks (historia_id, admin_id, locked_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE admin_id=VALUES(admin_id), locked_at=NOW()")->execute([$id, $_SESSION['usuario_id']]);

// Acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'revisar') {
        $estado = $_POST['estado'] ?? '';
        $notas = trim($_POST['notas'] ?? '');
        if (in_array($estado, ['aprobado', 'rechazado'], true)) {
            $nuevo_estado = $estado === 'aprobado' ? 'revisada' : 'entregada';
            $db->prepare("UPDATE entregas SET estado=?, notas_revision=?, revisado_por=? WHERE historia_id=? AND estado='pendiente_revision'")->execute([$estado, $notas, $_SESSION['usuario_id'], $id]);
            $db->prepare("UPDATE historias SET estado=? WHERE id=?")->execute([$nuevo_estado, $id]);
            flash('success', 'Historia ' . ($estado === 'aprobado' ? 'aprobada' : 'rechazada') . '.');

            if ($estado === 'aprobado') {
                require_once ROOT_PATH . '/includes/wordpress-export.php';
                $res_wp = wp_exportar_entrega($id, $db);
                if ($res_wp['ok']) {
                    flash('info', 'WordPress: ' . $res_wp['mensaje']);
                } elseif ($res_wp['mensaje'] !== 'Exportación a WordPress desactivada.') {
                    flash('warning', 'WordPress: ' . $res_wp['mensaje']);
                }

                // Email al periodista con datos de facturación
                $per = $db->prepare("SELECT nombre, email, rut, banco, tipo_cuenta, numero_cuenta FROM usuarios WHERE id = ?");
                $per->execute([$h['periodista_asignado']]);
                $periodista = $per->fetch();
                if ($periodista && $periodista['email']) {
                    $monto_b  = (int)($h['monto_total_a_pagar'] ?? $h['presupuesto']);
                    $ret_b    = (int)round($monto_b * 0.1525);
                    $liq_b    = $monto_b - $ret_b;
                    $titSafe  = e($h['titulo']);
                    $nomSafe  = e($periodista['nombre']);
                    $enlace   = BASE_URL . '/periodista/subir-boleta.php?id=' . $id;
                    $codigo_h = e($h['codigo'] ?? '');
                    $subject_per = "Historia aprobada [{$codigo_h}] — Genera tu boleta de honorarios";
                    $msg_per = "
<p>Hola <strong>{$nomSafe}</strong>,</p>
<p>¡Tu historia <strong>«{$titSafe}»</strong> fue <span style='color:#27a644;font-weight:bold'>aprobada</span>! Para procesar tu pago, necesitas generar una <strong>Boleta de Honorarios Electrónica</strong> en <a href='https://homer.sii.cl/'>SII.cl</a> y subirla a nuestra plataforma.</p>

<h3 style='margin-top:1.2rem;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666'>Emite la boleta a nombre de</h3>
<table style='border-collapse:collapse;font-size:14px;margin:8px 0;width:100%'>
  <tr><td style='padding:4px 12px 4px 0;color:#888;white-space:nowrap'>Empresa</td><td style='font-weight:bold'>" . e(EMPRESA_NOMBRE) . "</td></tr>
  " . (EMPRESA_RUT ? "<tr><td style='padding:4px 12px 4px 0;color:#888'>RUT</td><td style='font-weight:bold'>" . e(EMPRESA_RUT) . "</td></tr>" : "") . "
  <tr><td style='padding:4px 12px 4px 0;color:#888'>Giro</td><td>" . e(EMPRESA_GIRO) . "</td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#888'>Dirección</td><td>" . e(EMPRESA_DIRECCION) . "</td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#888'>Concepto</td><td>Honorarios periodísticos · {$titSafe}</td></tr>
  " . ($codigo_h ? "<tr><td style='padding:4px 12px 4px 0;color:#888'>Referencia de pago</td><td style='font-weight:bold;font-family:monospace;font-size:15px;color:#5e6ad2'>{$codigo_h}</td></tr>" : "") . "
</table>

<h3 style='margin-top:1.2rem;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666'>Montos</h3>
<table style='border-collapse:collapse;font-size:14px;margin:8px 0'>
  <tr><td style='padding:4px 12px 4px 0;color:#888'>Monto bruto (valor de la boleta)</td><td style='font-weight:bold;font-size:16px'>$" . number_format($monto_b, 0, ',', '.') . "</td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#888'>Retención segunda categoría (15,25%)</td><td style='color:#f59e0b'>− $" . number_format($ret_b, 0, ',', '.') . "</td></tr>
  <tr style='border-top:1px solid #eee'><td style='padding:8px 12px 4px 0;color:#888;font-weight:bold'>Líquido a recibir</td><td style='font-weight:bold;font-size:16px;color:#27a644'>$" . number_format($liq_b, 0, ',', '.') . "</td></tr>
</table>
<p style='font-size:12px;color:#888'>Emite la boleta por el <strong>monto bruto</strong>. La retención es calculada y declarada por nosotros ante el SII.</p>

<p style='margin-top:1.4rem'>
  <a href='{$enlace}' style='background:#5e6ad2;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block'>📤 Subir mi boleta de honorarios</a>
</p>
<p style='font-size:12px;color:#888;margin-top:.5rem'>O ingresa a: {$enlace}</p>
<p style='margin-top:1.2rem'>Si tienes dudas sobre facturación escribe a <a href='mailto:" . e(EMPRESA_EMAIL_FINANZAS) . "'>" . e(EMPRESA_EMAIL_FINANZAS) . "</a>.</p>";
                    enviarCorreo($periodista['email'], $subject_per, $msg_per);
                }
            }

            header('Location: ' . BASE_URL . '/admin/historia-editar?id=' . $id);
            exit;
        }
    }

    if ($action === 'editar') {
        @file_put_contents('/tmp/historia_editar_debug.log',
            '['.date('Y-m-d H:i:s')."] POST id=$id\n".print_r($_POST, true)."\n",
            FILE_APPEND);
        $tit = trim($_POST['titulo'] ?? '');
        $desc = trim($_POST['descripcion'] ?? '');
        $foco = trim($_POST['foco'] ?? '');
        $ext = trim($_POST['extension'] ?? '');
        $fecha = $_POST['fecha_entrega'] ?? '';
        $presupuesto = (int)($_POST['presupuesto'] ?? 0);
        $categoria_id = !empty($_POST['categoria_id']) ? (int)$_POST['categoria_id'] : null;
        $visible_todos = (($_POST['visible_todos'] ?? '1') === '1') ? 1 : 0;
        $periodistas_sel = $_POST['periodistas'] ?? [];
        $periodista_nuevo = !empty($_POST['periodista_asignado']) ? (int)$_POST['periodista_asignado'] : null;
        $estadosValidos = ['disponible','asignada','en_curso','entregada','revisada','pagada'];
        $estado = $_POST['estado'] ?? $h['estado'];
        if (!in_array($estado, $estadosValidos, true)) $estado = $h['estado'];

        if (empty($tit) || empty($fecha)) {
            flash('error', 'Título y fecha son obligatorios.');
        } else {
            $monto_total_a_pagar = isset($_POST['monto_total_a_pagar']) && $_POST['monto_total_a_pagar'] !== '' ? (int)$_POST['monto_total_a_pagar'] : $presupuesto;

            $periodista_actual = $h['periodista_asignado'] ? (int)$h['periodista_asignado'] : null;
            $asignada_en_sql = 'asignada_en';
            $asignada_en_param = null;
            $usar_param_asignada = false;
            if ($periodista_nuevo !== $periodista_actual) {
                if ($periodista_nuevo === null) {
                    $asignada_en_sql = 'NULL';
                    if ($estado === 'asignada') $estado = 'disponible';
                } else {
                    $asignada_en_sql = '?';
                    $asignada_en_param = date('Y-m-d H:i:s');
                    $usar_param_asignada = true;
                    if ($estado === 'disponible') $estado = 'asignada';
                }
            }

            $sql = "UPDATE historias SET titulo=?, descripcion=?, foco_periodistico=?, extension_esperada=?, fecha_entrega=?, presupuesto=?, monto_total_a_pagar=?, estado=?, categoria_id=?, visible_para_todos=?, periodista_asignado=?, asignada_en={$asignada_en_sql} WHERE id=?";
            $params = [$tit, $desc, $foco, $ext, $fecha, $presupuesto, $monto_total_a_pagar, $estado, $categoria_id, $visible_todos, $periodista_nuevo];
            if ($usar_param_asignada) $params[] = $asignada_en_param;
            $params[] = $id;
            $db->prepare($sql)->execute($params);

            $db->prepare("DELETE FROM historia_visibilidad WHERE historia_id = ?")->execute([$id]);
            if (!$visible_todos && !empty($periodistas_sel)) {
                $stmt_vis = $db->prepare("INSERT INTO historia_visibilidad (historia_id, usuario_id) VALUES (?, ?)");
                foreach ($periodistas_sel as $pid) {
                    $stmt_vis->execute([$id, (int)$pid]);
                }
            }

            flash('success', 'Historia actualizada.');
        }
        header('Location: ' . BASE_URL . '/admin/historia-editar?id=' . $id);
        exit;
    }

    if ($action === 'subir_comprobante') {
        $pago_row = $db->prepare("SELECT id, comprobante FROM pagos WHERE historia_id = ? ORDER BY created_at DESC LIMIT 1");
        $pago_row->execute([$id]);
        $pago_row = $pago_row->fetch();
        if (!$pago_row) {
            flash('error', 'No hay pago registrado para esta historia.');
        } elseif ($pago_row['comprobante']) {
            flash('error', 'Ya hay un comprobante adjunto a este pago.');
        } elseif (empty($_FILES['comprobante']['name'])) {
            flash('error', 'Selecciona un archivo.');
        } else {
            $ruta = subirComprobante($_FILES['comprobante']);
            if ($ruta) {
                $db->prepare("UPDATE pagos SET comprobante=? WHERE id=?")->execute([$ruta, $pago_row['id']]);
                flash('success', 'Comprobante adjuntado al pago.');
            } else {
                flash('error', 'No se pudo subir el archivo. Verifica formato (PDF, JPG, PNG, WEBP) y tamaño (máx. 10 MB).');
            }
        }
        header('Location: ' . BASE_URL . '/admin/historia-editar?id=' . $id);
        exit;
    }

    if ($action === 'marcar_pagado') {
        if ($h['estado'] !== 'revisada' || !$h['periodista_asignado']) {
            flash('error', 'La historia no está en estado para pagar.');
            header('Location: ' . BASE_URL . '/admin/historia-editar?id=' . $id);
            exit;
        }
        $monto_total = (int)($h['monto_total_a_pagar'] ?? $h['presupuesto']);
        $retencion   = (int)round($monto_total * 0.1525); // 15,25% fijo
        $honorarios  = $monto_total;
        $liquido     = $monto_total - $retencion;

        $comprobante = null;
        if (!empty($_FILES['comprobante']['name'])) {
            $ruta = subirComprobante($_FILES['comprobante']);
            if ($ruta) {
                $comprobante = $ruta;
            } else {
                flash('warning', 'El comprobante no pudo subirse (formato o tamaño inválido). El pago fue registrado igualmente.');
            }
        }

        $db->prepare("INSERT INTO pagos (historia_id, periodista_id, monto_total, honorarios, retencion, liquido, comprobante, estado, fecha_pago) VALUES (?,?,?,?,?,?,?,'pagado',NOW())")
            ->execute([$id, $h['periodista_asignado'], $monto_total, $honorarios, $retencion, $liquido, $comprobante]);
        $db->prepare("UPDATE historias SET estado='pagada' WHERE id=?")->execute([$id]);

        // Email al periodista: agradecimiento + informe de pago
        $perPago = $db->prepare("SELECT nombre, email FROM usuarios WHERE id = ?");
        $perPago->execute([$h['periodista_asignado']]);
        $perData = $perPago->fetch();
        if ($perData && $perData['email']) {
            $titPago   = e($h['titulo']);
            $nomPago   = e($perData['nombre']);
            $fechaPago = date('d/m/Y');
            $compUrl   = $comprobante ? e(urlImagen($comprobante)) : null;
            $codigoPago = e($h['codigo'] ?? '');
            $subject_pago = "Pago procesado [{$codigoPago}] — " . preg_replace('/[\r\n]+/', ' ', mb_substr($h['titulo'], 0, 80));
            $msg_pago = "
<p>Hola <strong>{$nomPago}</strong>,</p>
<p>¡Muchas gracias por tu trabajo! Tu pago por la historia <strong>«{$titPago}»</strong> ha sido procesado exitosamente.</p>

<h3 style='margin-top:1.2rem;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666'>Informe de pago</h3>
<table style='border-collapse:collapse;font-size:14px;margin:8px 0;width:100%;max-width:400px'>
  <tr><td style='padding:5px 16px 5px 0;color:#888'>Historia</td><td style='font-weight:bold'>{$titPago}</td></tr>
  " . ($codigoPago ? "<tr><td style='padding:5px 16px 5px 0;color:#888'>Referencia</td><td style='font-weight:bold;font-family:monospace;color:#5e6ad2'>{$codigoPago}</td></tr>" : "") . "
  <tr><td style='padding:5px 16px 5px 0;color:#888'>Fecha de pago</td><td>{$fechaPago}</td></tr>
  <tr style='border-top:1px solid #eee'><td style='padding:8px 16px 5px 0;color:#888'>Honorarios brutos</td><td style='font-weight:bold'>$" . number_format($monto_total, 0, ',', '.') . "</td></tr>
  <tr><td style='padding:5px 16px 5px 0;color:#888'>Retención (15,25%)</td><td style='color:#f59e0b'>− $" . number_format($retencion, 0, ',', '.') . "</td></tr>
  <tr style='border-top:2px solid #eee'><td style='padding:8px 16px 5px 0;font-weight:bold'>Monto líquido transferido</td><td style='font-size:18px;font-weight:bold;color:#27a644'>$" . number_format($liquido, 0, ',', '.') . "</td></tr>
</table>
" . ($compUrl ? "<p style='margin-top:1.2rem'><a href='{$compUrl}' style='background:#5e6ad2;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block'>📎 Ver comprobante de transferencia</a></p>" : "") . "
<p style='margin-top:1.4rem;font-size:.85rem;color:#666'>Fue un placer trabajar contigo. Esperamos seguir contando con tus reportajes.</p>
<p style='font-size:.85rem;color:#666'>— El equipo de <strong>" . e(SITE_NAME) . "</strong></p>";
            enviarCorreo($perData['email'], $subject_pago, $msg_pago);
        }

        flash('success', 'Pago registrado. Se notificó al periodista por correo.');
        header('Location: ' . BASE_URL . '/admin/historia-editar?id=' . $id);
        exit;
    }
}

// Recargar historia para obtener boleta_path actualizado
$historia->execute([$id]);
$h = $historia->fetch();

// Obtener entrega
$entrega = $db->prepare("SELECT e.*, u.nombre AS periodista_nombre FROM entregas e JOIN usuarios u ON e.periodista_id = u.id WHERE e.historia_id = ? ORDER BY e.created_at DESC LIMIT 1");
$entrega->execute([$id]);
$ent = $entrega->fetch();

// Documento cesión
$doc = null;
if ($ent) {
    $docStmt = $db->prepare("SELECT * FROM documentos_cesion WHERE entrega_id = ?");
    $docStmt->execute([$ent['id']]);
    $doc = $docStmt->fetch();
}

// Pago existente
$pago = $db->prepare("SELECT * FROM pagos WHERE historia_id = ? ORDER BY created_at DESC LIMIT 1");
$pago->execute([$id]);
$pag = $pago->fetch();

// Progreso del plazo
$dias_total = 0;
$dias_restantes = 0;
$dias_transcurridos = 0;
$porcentaje = 0;
$vencida = false;
if ($h['asignada_en'] && $h['fecha_entrega']) {
    $inicio = new DateTime($h['asignada_en']);
    $entrega_dt = new DateTime($h['fecha_entrega']);
    $ahora = new DateTime();
    $dias_total = max(1, $inicio->diff($entrega_dt)->days);
    $dias_transcurridos = max(0, $inicio->diff($ahora)->days);
    if ($ahora > $entrega_dt) {
        $vencida = true;
        $dias_restantes = 0;
        $porcentaje = 100;
    } else {
        $dias_restantes = max(0, $dias_total - $dias_transcurridos);
        $porcentaje = min(100, round(($dias_transcurridos / $dias_total) * 100));
    }
}
// Datos bancarios del periodista
$datosPerio = null;
if ($h['periodista_asignado']) {
    $dp = $db->prepare("SELECT nombre, email, rut, banco, tipo_cuenta, numero_cuenta FROM usuarios WHERE id = ?");
    $dp->execute([$h['periodista_asignado']]);
    $datosPerio = $dp->fetch();
}
?>

<div class="page-header">
    <div>
        <h1><?= e($h['titulo']) ?></h1>
        <div class="subtitle">
            <span style="font-family:'Geist Mono',monospace;font-size:.85rem;background:var(--surface2);padding:.15rem .5rem;border-radius:5px;letter-spacing:.03em"><?= e($h['codigo'] ?? '—') ?></span>
            · Creada por <?= e($h['creador_nombre']) ?> ·
            <span class="badge badge-<?= $h['estado'] ?>"><?= $h['estado'] ?></span>
        </div>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-secondary btn-sm">← Volver</a>
        <button onclick="toggleEdit()" class="btn btn-primary btn-sm">✏️ Editar Historia</button>
    </div>
</div>

<?php if ($h['asignada_en'] && $h['fecha_entrega'] && !in_array($h['estado'], ['pagada','revisada'], true)): ?>
<?php
    $color_barra = $vencida ? 'var(--error)' : ($porcentaje > 80 ? 'var(--error)' : ($porcentaje > 50 ? 'var(--warning)' : 'var(--accent)'));
    if ($vencida) {
        $etiqueta_tiempo = 'Plazo vencido hace ' . (new DateTime($h['fecha_entrega']))->diff(new DateTime())->days . ' días';
    } elseif ($dias_restantes === 0) {
        $etiqueta_tiempo = 'Vence hoy';
    } else {
        $etiqueta_tiempo = $dias_restantes . ' día' . ($dias_restantes === 1 ? '' : 's') . ' restante' . ($dias_restantes === 1 ? '' : 's');
    }
?>
<div class="card" style="margin-bottom:1.2rem">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;flex-wrap:wrap;gap:.5rem">
        <span style="font-size:.8rem;color:var(--muted)">Progreso del plazo · <?= $dias_transcurridos ?> de <?= $dias_total ?> días (<?= $porcentaje ?>%)</span>
        <span style="font-size:.8rem;color:<?= $vencida ? 'var(--error)' : 'var(--text2)' ?>;font-weight:<?= $vencida ? '600' : '400' ?>"><?= e($etiqueta_tiempo) ?></span>
    </div>
    <div style="height:6px;background:var(--surface2);border-radius:99px;overflow:hidden">
        <div style="height:100%;width:<?= $porcentaje ?>%;background:<?= $color_barra ?>;border-radius:99px;transition:width .5s"></div>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:.65rem;color:var(--muted);margin-top:.3rem">
        <span>Inicio: <?= date('d/m/Y', strtotime($h['asignada_en'])) ?></span>
        <span>Entrega: <?= date('d/m/Y', strtotime($h['fecha_entrega'])) ?></span>
    </div>
</div>
<?php endif; ?>

<?php
// Determinar paso actual del proceso
$paso = 1;
if (in_array($h['estado'], ['entregada','revisada','pagada'])) $paso = 2;
if ($h['estado'] === 'revisada' && !$h['boleta_path']) $paso = 3;
if ($h['estado'] === 'revisada' && $h['boleta_path']) $paso = 4;
if ($h['estado'] === 'pagada') $paso = 5;

$pasos = [
    1 => ['label' => 'Historia entregada', 'icon' => '📝'],
    2 => ['label' => 'Aprobada por admin', 'icon' => '✅'],
    3 => ['label' => 'Periodista sube boleta', 'icon' => '🧾'],
    4 => ['label' => 'Admin registra pago', 'icon' => '💰'],
];
if (in_array($h['estado'], ['entregada','revisada','pagada'])):
?>
<div class="card" style="margin-bottom:1.2rem;padding:1rem 1.5rem">
    <div style="display:flex;align-items:center;gap:0;overflow-x:auto">
        <?php foreach ($pasos as $n => $info):
            $done    = $paso > $n;
            $current = $paso === $n;
            $pending = $paso < $n;
            $col  = $done ? '#27a644' : ($current ? '#5e6ad2' : 'var(--muted)');
            $bg   = $done ? 'rgba(39,166,68,.12)' : ($current ? 'rgba(94,106,210,.15)' : 'transparent');
            $bord = $done ? '2px solid #27a644' : ($current ? '2px solid #5e6ad2' : '2px solid var(--border)');
        ?>
        <div style="display:flex;align-items:center;gap:0;flex:1;min-width:0">
            <div style="display:flex;flex-direction:column;align-items:center;gap:.3rem;min-width:90px;padding:.5rem .25rem;background:<?= $bg ?>;border:<?= $bord ?>;border-radius:10px">
                <span style="font-size:1.1rem"><?= $info['icon'] ?><?= $done ? ' ✓' : '' ?></span>
                <span style="font-size:.68rem;font-weight:<?= $current?'700':'500' ?>;color:<?= $col ?>;text-align:center;line-height:1.3"><?= $info['label'] ?></span>
            </div>
            <?php if ($n < 4): ?>
            <div style="flex:1;height:2px;background:<?= $paso > $n ? '#27a644' : 'var(--border)' ?>;min-width:12px"></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Edit Form (hidden by default) -->
<div id="edit-form-card" class="card" style="margin-bottom:1.2rem;display:none;border-color:var(--accent)">
    <div class="card-header"><h2>✏️ Editar Historia</h2></div>
    <form method="post" action="<?= BASE_URL ?>/admin/historia-editar?id=<?= $id ?>" style="max-width:600px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="editar">
        
        <div class="form-group">
            <label for="edit_titulo">Título</label>
            <input type="text" id="edit_titulo" name="titulo" required value="<?= e($h['titulo']) ?>">
        </div>
        <div class="form-group">
            <label for="edit_descripcion">Descripción</label>
            <textarea id="edit_descripcion" name="descripcion" rows="3"><?= e($h['descripcion'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
            <label for="edit_foco">Foco periodístico</label>
            <textarea id="edit_foco" name="foco" rows="3"><?= e($h['foco_periodistico'] ?? '') ?></textarea>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="edit_extension">Extensión esperada</label>
                <input type="text" id="edit_extension" name="extension" value="<?= e($h['extension_esperada'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="edit_fecha">Fecha de entrega</label>
                <input type="date" id="edit_fecha" name="fecha_entrega" required value="<?= e($h['fecha_entrega']) ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="edit_presupuesto">Presupuesto estimado ($)</label>
                <input type="number" id="edit_presupuesto" name="presupuesto" min="0" value="<?= (int)$h['presupuesto'] ?>">
            </div>
            <div class="form-group">
                <label for="edit_monto_total_a_pagar">Monto total a pagar ($)</label>
                <input type="number" id="edit_monto_total_a_pagar" name="monto_total_a_pagar" min="0" value="<?= (int)($h['monto_total_a_pagar'] ?? $h['presupuesto']) ?>">
                <div class="hint">Monto que efectivamente se pagará al periodista.</div>
            </div>
        </div>
        <div class="form-group">
            <label for="edit_categoria">Categoría / Tema de interés</label>
            <select id="edit_categoria" name="categoria_id">
                <option value="">Sin categoría</option>
                <?php foreach ($categorias as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= (int)$h['categoria_id']===(int)$cat['id']?'selected':'' ?>><?= e($cat['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="hint">Los periodistas interesados en esta categoría recibirán prioridad en la notificación.</div>
        </div>

        <div class="form-group">
            <label for="edit_periodista_asignado">Periodista asignado</label>
            <select id="edit_periodista_asignado" name="periodista_asignado">
                <option value="">— Sin asignar (disponible para tomar) —</option>
                <?php foreach ($periodistas as $p): ?>
                <option value="<?= $p['id'] ?>" <?= (int)$h['periodista_asignado']===(int)$p['id']?'selected':'' ?>><?= e($p['nombre']) ?> · <?= e($p['email']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="hint">Si asignas un periodista, la historia pasa a "asignada" automáticamente. Si lo quitas y estaba "asignada", vuelve a "disponible".</div>
        </div>

        <div class="form-group">
            <label>¿A quién mostramos esta historia?</label>
            <div class="hint" style="margin-bottom:.5rem">Quién puede verla en su panel mientras esté disponible (no afecta al ya asignado).</div>
            <div class="checkbox-group" style="margin-bottom:.6rem">
                <label class="checkbox-item">
                    <input type="radio" name="visible_todos" value="1" <?= !empty($h['visible_para_todos']) ? 'checked' : '' ?> onchange="toggleEditPeriodistas()">
                    <span class="label">A todos los periodistas</span>
                </label>
                <label class="checkbox-item">
                    <input type="radio" name="visible_todos" value="0" <?= empty($h['visible_para_todos']) ? 'checked' : '' ?> onchange="toggleEditPeriodistas()">
                    <span class="label">Solo a los periodistas que yo elija</span>
                </label>
            </div>
            <div id="edit-periodistas-select" style="padding:.6rem .8rem;border:1px solid var(--border);border-radius:8px;<?= !empty($h['visible_para_todos']) ? 'opacity:.5;pointer-events:none' : '' ?>">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem">
                    <p style="font-size:.8rem;color:var(--muted);margin:0">Marca uno o varios periodistas:</p>
                    <div style="display:flex;gap:.4rem">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAllPeriodistas(true)">Marcar todos</button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAllPeriodistas(false)">Desmarcar</button>
                    </div>
                </div>
                <?php foreach ($periodistas as $p): ?>
                <label class="checkbox-item">
                    <input type="checkbox" name="periodistas[]" value="<?= $p['id'] ?>" <?= in_array((int)$p['id'], $visibilidad_actual, true) ? 'checked' : '' ?>>
                    <span class="label"><?= e($p['nombre']) ?> · <?= e($p['email']) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <script>
        function toggleEditPeriodistas() {
            var radio = document.querySelector('input[name="visible_todos"]:checked');
            var box = document.getElementById('edit-periodistas-select');
            var todos = !radio || radio.value === '1';
            box.style.opacity = todos ? '.5' : '1';
            box.style.pointerEvents = todos ? 'none' : 'auto';
        }
        function toggleAllPeriodistas(check) {
            document.querySelectorAll('#edit-periodistas-select input[type="checkbox"]').forEach(function(cb){ cb.checked = check; });
        }
        </script>

        <div class="form-group">
            <label for="edit_estado">Estado</label>
            <select id="edit_estado" name="estado">
                <option value="disponible" <?= $h['estado']==='disponible'?'selected':'' ?>>Disponible</option>
                <option value="asignada" <?= $h['estado']==='asignada'?'selected':'' ?>>Asignada</option>
                <option value="en_curso" <?= $h['estado']==='en_curso'?'selected':'' ?>>En curso</option>
                <option value="entregada" <?= $h['estado']==='entregada'?'selected':'' ?>>Entregada</option>
                <option value="revisada" <?= $h['estado']==='revisada'?'selected':'' ?>>Revisada</option>
                <option value="pagada" <?= $h['estado']==='pagada'?'selected':'' ?>>Pagada</option>
            </select>
        </div>
        <div style="display:flex;gap:.5rem">
            <button type="submit" class="btn btn-primary">💾 Guardar Cambios</button>
            <button type="button" class="btn btn-secondary" onclick="toggleEdit()">Cancelar</button>
        </div>
    </form>
</div>

<script>
function toggleEdit() {
    var el = document.getElementById('edit-form-card');
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
    if (el.style.display === 'block') {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}
</script>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.2rem">
    <div class="card">
        <div class="card-header"><h2>Detalles</h2></div>
        <div class="detail-row">
            <span class="detail-label">Código</span>
            <span class="detail-value" style="font-family:'Geist Mono',monospace;font-weight:700;font-size:1rem;color:var(--accent)"><?= e($h['codigo'] ?? '—') ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Descripción</span>
            <span class="detail-value"><?= nl2br(e($h['descripcion'] ?? '—')) ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Foco periodístico</span>
            <span class="detail-value"><?= nl2br(e($h['foco_periodistico'] ?? '—')) ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Extensión</span>
            <span class="detail-value"><?= e($h['extension_esperada'] ?? '—') ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Categoría</span>
            <span class="detail-value"><?= e($h['categoria_nombre'] ?? '—') ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Fecha entrega</span>
            <span class="detail-value"><?= date('d/m/Y', strtotime($h['fecha_entrega'])) ?></span>
        </div>
        <?php if ($h['asignada_en'] && !in_array($h['estado'], ['disponible'])):
            $ini  = strtotime($h['asignada_en']);
            $dead = strtotime($h['fecha_entrega'] . ' 23:59:59');
            $now  = time();
            $pct  = (int)min(100, max(0, round(($now - $ini) / max(1, $dead - $ini) * 100)));
            $dias = (int)ceil(($dead - $now) / 86400);
            $terminada = in_array($h['estado'], ['revisada','pagada']);
            if ($terminada)    { $col = '#5e6ad2'; $lbl = ucfirst($h['estado']); }
            elseif ($h['estado'] === 'entregada') { $col = '#27a644'; $lbl = 'Entregada a tiempo'; }
            elseif ($pct >= 100) { $col = '#ef4444'; $lbl = abs($dias) . ' día' . (abs($dias)!==1?'s':'') . ' vencida'; }
            elseif ($pct >= 85)  { $col = '#ef4444'; $lbl = $dias . ' día' . ($dias!==1?'s':'') . ' restantes'; }
            elseif ($pct >= 60)  { $col = '#f59e0b'; $lbl = $dias . ' día' . ($dias!==1?'s':'') . ' restantes'; }
            else                 { $col = '#27a644'; $lbl = $dias . ' día' . ($dias!==1?'s':'') . ' restantes'; }
        ?>
        <div class="detail-row" style="flex-direction:column;align-items:flex-start;gap:.5rem">
            <span class="detail-label">Avance del plazo</span>
            <div style="width:100%">
                <div style="background:rgba(0,0,0,.3);border-radius:6px;height:8px;overflow:hidden;margin-bottom:.35rem">
                    <div style="width:<?= $pct ?>%;height:100%;background:<?= $col ?>;border-radius:6px;transition:width .4s<?= (!$terminada && $pct>=100) ? ';animation:pulseBar 1.4s ease-in-out infinite' : '' ?>"></div>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <span style="font-size:.72rem;font-weight:600;color:<?= $col ?>"><?= e($lbl) ?></span>
                    <span style="font-size:.68rem;color:var(--muted)"><?= $pct ?>% del plazo</span>
                </div>
            </div>
        </div>
        <style>@keyframes pulseBar{0%,100%{opacity:1}50%{opacity:.4}}</style>
        <?php endif; ?>
        <div class="detail-row">
            <span class="detail-label">Presupuesto estimado</span>
            <span class="detail-value">$<?= number_format($h['presupuesto'], 0, ',', '.') ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Monto total a pagar</span>
            <span class="detail-value" style="font-weight:600;color:var(--success)">$<?= number_format($h['monto_total_a_pagar'] ?? $h['presupuesto'], 0, ',', '.') ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Periodista</span>
            <span class="detail-value"><?= e($h['periodista_nombre'] ?? '— (disponible)') ?></span>
        </div>
        <?php if ($h['asignada_en']): ?>
        <div class="detail-row">
            <span class="detail-label">Asignada</span>
            <span class="detail-value"><?= date('d/m/Y H:i', strtotime($h['asignada_en'])) ?></span>
        </div>
        <?php endif; ?>
    </div>
    
    <div>
        <?php if ($h['estado'] === 'pagada' && $pag): ?>
        <div class="card" style="border-color:rgba(39,166,68,.3)">
            <div class="card-header"><h2>💰 Pago registrado</h2><span class="badge badge-revisada">Completado</span></div>
            <div class="detail-row">
                <span class="detail-label">Monto total</span>
                <span class="detail-value">$<?= number_format($pag['monto_total'], 0, ',', '.') ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Retención</span>
                <span class="detail-value" style="color:var(--warning)">$<?= number_format($pag['retencion'], 0, ',', '.') ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Líquido pagado</span>
                <span class="detail-value" style="color:var(--success);font-weight:600;font-size:1.05rem">$<?= number_format($pag['liquido'], 0, ',', '.') ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Pagado el</span>
                <span class="detail-value"><?= date('d/m/Y', strtotime($pag['fecha_pago'])) ?></span>
            </div>
            <!-- Boleta del periodista -->
            <?php if ($h['boleta_path']): ?>
            <div class="detail-row">
                <span class="detail-label">Boleta periodista</span>
                <span class="detail-value">
                    <?php $ext_b = strtolower(pathinfo($h['boleta_path'], PATHINFO_EXTENSION)); ?>
                    <a href="<?= e(urlImagen($h['boleta_path'])) ?>" target="_blank" class="btn btn-secondary btn-xs">
                        <?= $ext_b === 'pdf' ? '📄 Ver PDF' : '🖼 Ver boleta' ?>
                    </a>
                    <span style="font-size:.72rem;color:var(--muted);margin-left:.5rem">subida <?= date('d/m/Y', strtotime($h['boleta_subida_en'])) ?></span>
                </span>
            </div>
            <?php endif; ?>
            <!-- Comprobante transferencia -->
            <?php if ($pag['comprobante']): ?>
            <div class="detail-row">
                <span class="detail-label">Comprobante transferencia</span>
                <span class="detail-value">
                    <?php $comp_ext = strtolower(pathinfo($pag['comprobante'], PATHINFO_EXTENSION)); ?>
                    <?php if ($comp_ext === 'pdf'): ?>
                        <a href="<?= e(urlImagen($pag['comprobante'])) ?>" target="_blank" class="btn btn-secondary btn-xs">📄 Ver PDF</a>
                    <?php else: ?>
                        <a href="<?= e(urlImagen($pag['comprobante'])) ?>" target="_blank">
                            <img src="<?= e(urlImagen($pag['comprobante'])) ?>" alt="Comprobante" style="max-width:100%;max-height:160px;border-radius:6px;border:1px solid var(--border);margin-top:.3rem;display:block">
                        </a>
                    <?php endif; ?>
                </span>
            </div>
            <?php else: ?>
            <div class="detail-row" style="justify-content:flex-end">
                <form method="post" action="<?= BASE_URL ?>/admin/historia-editar?id=<?= $id ?>" enctype="multipart/form-data" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="subir_comprobante">
                    <input type="file" name="comprobante" accept=".pdf,.jpg,.jpeg,.png,.webp" style="font-size:.8rem" required>
                    <button type="submit" class="btn btn-secondary btn-xs">📎 Subir comprobante</button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <?php elseif ($h['estado'] === 'revisada' && $h['periodista_asignado']): ?>
        <?php
            $mt  = (int)($h['monto_total_a_pagar'] ?? $h['presupuesto']);
            $ret = (int)round($mt * 0.1525);
            $liq = $mt - $ret;
            $tiene_cesion = $doc && $doc['pdf_generado'];
            $tiene_boleta = !empty($h['boleta_path']);
        ?>

        <!-- Estado de cesión de derechos -->
        <div class="card" style="margin-bottom:.8rem;padding:.75rem 1.2rem;border-color:<?= $tiene_cesion ? 'rgba(39,166,68,.3)' : 'rgba(239,68,68,.3)' ?>">
            <div style="display:flex;align-items:center;gap:.6rem">
                <span style="font-size:1.1rem"><?= $tiene_cesion ? '✅' : '⚠️' ?></span>
                <span style="font-size:.85rem;font-weight:600;color:<?= $tiene_cesion ? 'var(--success)' : 'var(--error)' ?>">
                    <?= $tiene_cesion ? 'Cesión de derechos firmada' : 'Sin cesión de derechos' ?>
                </span>
                <?php if ($tiene_cesion): ?>
                <a href="<?= BASE_URL ?>/admin/cesion.php?id=<?= (int)$doc['id'] ?>" target="_blank" class="btn btn-secondary btn-xs" style="margin-left:auto">📄 Descargar</a>
                <?php endif; ?>
            </div>
            <?php if (!$tiene_cesion): ?>
            <p style="font-size:.75rem;color:var(--muted);margin:.3rem 0 0 1.7rem">No se puede procesar el pago sin cesión de derechos firmada.</p>
            <?php endif; ?>
        </div>

        <!-- Estado de boleta -->
        <div class="card" style="margin-bottom:.8rem;padding:.75rem 1.2rem;border-color:<?= $tiene_boleta ? 'rgba(39,166,68,.3)' : 'rgba(245,158,11,.3)' ?>">
            <div style="display:flex;align-items:center;gap:.6rem">
                <span style="font-size:1.1rem"><?= $tiene_boleta ? '🧾' : '⏳' ?></span>
                <span style="font-size:.85rem;font-weight:600;color:<?= $tiene_boleta ? 'var(--success)' : 'var(--warning)' ?>">
                    <?= $tiene_boleta ? 'Boleta de honorarios recibida' : 'Esperando boleta del periodista' ?>
                </span>
                <?php if ($tiene_boleta): ?>
                <?php $ext_b = strtolower(pathinfo($h['boleta_path'], PATHINFO_EXTENSION)); ?>
                <a href="<?= e(urlImagen($h['boleta_path'])) ?>" target="_blank" class="btn btn-secondary btn-xs" style="margin-left:auto">
                    <?= $ext_b === 'pdf' ? '📄 Ver boleta' : '🖼 Ver boleta' ?>
                </a>
                <?php endif; ?>
            </div>
            <?php if ($tiene_boleta): ?>
            <p style="font-size:.72rem;color:var(--muted);margin:.3rem 0 0 1.7rem">Subida el <?= date('d/m/Y H:i', strtotime($h['boleta_subida_en'])) ?></p>
            <?php else: ?>
            <p style="font-size:.75rem;color:var(--muted);margin:.3rem 0 0 1.7rem">El periodista recibirá un recordatorio. Puedes esperar a que la suba antes de pagar.</p>
            <?php endif; ?>
        </div>

        <!-- Formulario pago -->
        <div class="card" style="<?= !$tiene_cesion ? 'opacity:.6;pointer-events:none' : '' ?>">
            <div class="card-header">
                <h2>💰 Registrar Pago</h2>
                <?php if (!$tiene_cesion): ?><span style="font-size:.75rem;color:var(--error)">Requiere cesión de derechos</span><?php endif; ?>
            </div>

            <!-- Datos bancarios del periodista -->
            <?php if ($datosPerio): ?>
            <div style="margin-bottom:1rem;padding:.8rem 1rem;background:var(--surface2);border-radius:10px">
                <p style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:.5rem">Transferir a</p>
                <div style="display:grid;grid-template-columns:auto 1fr;gap:.25rem .8rem;font-size:.85rem">
                    <span style="color:var(--muted)">Nombre</span><strong><?= e($datosPerio['nombre']) ?></strong>
                    <?php if ($datosPerio['rut']): ?><span style="color:var(--muted)">RUT</span><span><?= e($datosPerio['rut']) ?></span><?php endif; ?>
                    <?php if ($datosPerio['banco']): ?><span style="color:var(--muted)">Banco</span><span><?= e($datosPerio['banco']) ?></span><?php endif; ?>
                    <?php if ($datosPerio['tipo_cuenta']): ?><span style="color:var(--muted)">Cuenta</span><span><?= e($datosPerio['tipo_cuenta']) ?><?= $datosPerio['numero_cuenta'] ? ' · ' . e($datosPerio['numero_cuenta']) : '' ?></span><?php endif; ?>
                    <?php if ($datosPerio['email']): ?><span style="color:var(--muted)">Email</span><span><?= e($datosPerio['email']) ?></span><?php endif; ?>
                </div>
                <?php if (!$datosPerio['banco'] && !$datosPerio['numero_cuenta']): ?>
                <p style="font-size:.75rem;color:var(--warning);margin-top:.5rem">⚠ El periodista no ha completado sus datos bancarios en su perfil.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <form method="post" action="<?= BASE_URL ?>/admin/historia-editar?id=<?= $id ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="marcar_pagado">
                <div class="form-group">
                    <label>Monto total bruto</label>
                    <input type="text" value="$<?= number_format($mt, 0, ',', '.') ?>" disabled>
                </div>
                <div class="form-group">
                    <label>Retención 15,25%</label>
                    <input type="text" value="$<?= number_format($ret, 0, ',', '.') ?>" disabled style="color:var(--error)">
                </div>
                <div class="form-group">
                    <label>Líquido a transferir</label>
                    <input type="text" value="$<?= number_format($liq, 0, ',', '.') ?>" disabled style="color:var(--success);font-weight:600;font-size:1.1rem">
                </div>
                <div class="form-group">
                    <label for="comprobante_file">Comprobante de transferencia <span style="font-weight:400;color:var(--muted)">(recomendado)</span></label>
                    <input type="file" id="comprobante_file" name="comprobante" accept=".pdf,.jpg,.jpeg,.png,.webp" style="padding:.4rem 0">
                    <div class="hint">PDF o imagen del comprobante bancario, máx. 10 MB.</div>
                </div>
                <button type="submit" class="btn btn-success"
                        onclick="return confirm('¿Registrar pago de $<?= number_format($liq, 0, ',', '.') ?> líquido a <?= e(addslashes($h['periodista_nombre'] ?? '')) ?>?')"
                        <?= !$tiene_cesion ? 'disabled title="Requiere cesión de derechos"' : '' ?>>
                    ✓ Marcar como Pagado
                </button>
                <?php if (!$tiene_boleta): ?><p style="font-size:.75rem;color:var(--muted);margin-top:.5rem">La boleta del periodista aún no ha sido subida, pero puedes pagar de todas formas.</p><?php endif; ?>
            </form>
        </div>
        <?php endif; ?>
        
        <?php if ($h['estado'] === 'entregada' && $ent): ?>
        <div class="card">
            <div class="card-header">
                <h2>📄 Revisar Entrega</h2>
                <span class="badge badge-pendiente_revision">Pendiente</span>
            </div>
            <form method="post" action="<?= BASE_URL ?>/admin/historia-editar?id=<?= $id ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="revisar">
                <div style="margin-bottom:.8rem">
                    <a href="#" onclick="verEntrega(event, <?= $id ?>)" class="btn btn-secondary btn-sm">📖 Ver contenido</a>
                    <?php if ($doc && $doc['pdf_generado']): ?>
                    <a href="<?= BASE_URL ?>/admin/cesion.php?id=<?= (int)$doc['id'] ?>" target="_blank" class="btn btn-secondary btn-sm">📄 Ver cesión</a>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Decisión</label>
                    <div style="display:flex;gap:.5rem">
                        <button type="submit" name="estado" value="aprobado" class="btn btn-success btn-sm">✓ Aprobar</button>
                        <button type="submit" name="estado" value="rechazado" class="btn btn-danger btn-sm">✗ Rechazar</button>
                    </div>
                </div>
                <div class="form-group">
                    <label for="notas">Notas de revisión</label>
                    <textarea id="notas" name="notas" rows="3" placeholder="Comentarios para el periodista..."></textarea>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($ent && $ent['contenido']): ?>
<div class="card" style="margin-top:1.2rem" id="contenido-<?= $id ?>">
    <div class="card-header">
        <h2>📖 Contenido Entregado</h2>
        <span class="badge badge-<?= $ent['estado'] ?>"><?= $ent['estado'] ?></span>
    </div>
    <div style="line-height:1.8;font-size:.95rem;color:var(--text2)">
        <?= sanitizarHTMLEntrega($ent['contenido'] ?? '') ?>
    </div>
    <?php if ($ent['estado'] === 'pendiente_revision'): ?>
    <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border)">
        <p style="font-size:.8rem;color:var(--muted)">Entregado por <?= e($ent['periodista_nombre']) ?> el <?= date('d/m/Y H:i', strtotime($ent['fecha_entrega'])) ?></p>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
function verEntrega(e, id) {
    e.preventDefault();
    document.getElementById('contenido-' + id).scrollIntoView({ behavior: 'smooth' });
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
