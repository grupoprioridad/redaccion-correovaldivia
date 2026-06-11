<?php
$titulo = 'Detalle Historia';
require_once __DIR__ . '/header.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$user_id = $_SESSION['usuario_id'];

$historia = $db->prepare("SELECT h.*, u.nombre AS creador_nombre, c.nombre AS categoria_nombre FROM historias h JOIN usuarios u ON h.creada_por = u.id LEFT JOIN categorias_redaccion c ON h.categoria_id = c.id WHERE h.id = ? AND h.periodista_asignado = ?");
$historia->execute([$id, $user_id]);
$h = $historia->fetch();

if (!$h) {
    flash('error', 'Historia no encontrada.');
    header('Location: ' . BASE_URL . '/periodista/index.php');
    exit;
}

// Calcular progreso del plazo
$dias_total = 0;
$dias_restantes = 0;
$porcentaje = 0;
if ($h['asignada_en']) {
    $inicio = new DateTime($h['asignada_en']);
    $entrega = new DateTime($h['fecha_entrega']);
    $ahora = new DateTime();
    $dias_total = max(1, $inicio->diff($entrega)->days);
    $dias_transcurridos = max(0, $inicio->diff($ahora)->days);
    $dias_restantes = max(0, $dias_total - $dias_transcurridos);
    $porcentaje = min(100, round(($dias_transcurridos / $dias_total) * 100));
}

// Obtener entrega
$entrega = $db->prepare("SELECT * FROM entregas WHERE historia_id = ? AND periodista_id = ? ORDER BY created_at DESC LIMIT 1");
$entrega->execute([$id, $user_id]);
$ent = $entrega->fetch();

// Documento cesión
$doc = null;
if ($ent) {
    $docStmt = $db->prepare("SELECT * FROM documentos_cesion WHERE entrega_id = ?");
    $docStmt->execute([$ent['id']]);
    $doc = $docStmt->fetch();
}

// Pago
$pago = $db->prepare("SELECT * FROM pagos WHERE historia_id = ? AND periodista_id = ? LIMIT 1");
$pago->execute([$id, $user_id]);
$pag = $pago->fetch();
?>

<div class="page-header">
    <div>
        <h1><?= e($h['titulo']) ?></h1>
        <div class="subtitle">
            <span class="badge badge-<?= $h['estado'] ?>"><?= $h['estado'] ?></span>
            · Creada por <?= e($h['creador_nombre']) ?>
        </div>
    </div>
    <div style="display:flex;gap:.5rem">
        <a href="<?= BASE_URL ?>/periodista/index.php" class="btn btn-secondary btn-sm">← Volver</a>
        <?php if (in_array($h['estado'], ['asignada','en_curso'])): ?>
        <a href="<?= BASE_URL ?>/periodista/entregar.php?id=<?= $id ?>" class="btn btn-primary btn-sm">📝 Entregar</a>
        <?php endif; ?>
        <?php if ($h['estado'] === 'entregada'): ?>
        <?php
            $lockCheck = null;
            try {
                $db->exec("CREATE TABLE IF NOT EXISTS historia_locks (historia_id INT NOT NULL PRIMARY KEY, admin_id INT NOT NULL, locked_at DATETIME NOT NULL)");
                $lkStmt = $db->prepare("SELECT 1 FROM historia_locks WHERE historia_id = ? AND locked_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
                $lkStmt->execute([$id]);
                $lockCheck = $lkStmt->fetchColumn();
            } catch (Throwable $e) {}
        ?>
        <?php if (!$lockCheck): ?>
        <a href="<?= BASE_URL ?>/periodista/entregar.php?id=<?= $id ?>" class="btn btn-primary btn-sm">✏️ Editar entrega</a>
        <?php else: ?>
        <span class="btn btn-secondary btn-sm" style="opacity:.6;cursor:not-allowed" title="El administrador tiene esta historia abierta">🔒 En revisión</span>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($h['estado'] === 'revisada' && empty($ent['boleta_path'] ?? null)): ?>
        <?php
            // Verificar boleta en historia (puede no estar en $ent)
            $boletaCheck = $db->prepare("SELECT boleta_path FROM historias WHERE id=?");
            $boletaCheck->execute([$id]);
            $bchk = $boletaCheck->fetch();
        ?>
        <?php if (empty($bchk['boleta_path'])): ?>
        <a href="<?= BASE_URL ?>/periodista/subir-boleta.php?id=<?= $id ?>" class="btn btn-primary btn-sm">🧾 Subir boleta</a>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
// Recargar datos de boleta desde historias
$hBoleta = $db->prepare("SELECT boleta_path, boleta_subida_en FROM historias WHERE id=?");
$hBoleta->execute([$id]);
$hB = $hBoleta->fetch();
$h['boleta_path']      = $hB['boleta_path'] ?? null;
$h['boleta_subida_en'] = $hB['boleta_subida_en'] ?? null;
?>

<?php if (in_array($h['estado'], ['entregada','revisada','pagada'])): ?>
<?php
$paso_p = 1;
if (in_array($h['estado'], ['entregada','revisada','pagada'])) $paso_p = 2;
if ($h['estado'] === 'revisada' && !$h['boleta_path']) $paso_p = 3;
if ($h['estado'] === 'revisada' && $h['boleta_path']) $paso_p = 4;
if ($h['estado'] === 'pagada') $paso_p = 5;

$pasos_p = [
    1 => ['label' => 'Historia entregada', 'icon' => '📝'],
    2 => ['label' => 'Aprobada', 'icon' => '✅'],
    3 => ['label' => 'Sube tu boleta', 'icon' => '🧾'],
    4 => ['label' => 'Boleta enviada', 'icon' => '🧾'],
];
?>
<div class="card" style="margin-bottom:1.2rem;padding:1rem 1.5rem">
    <div style="display:flex;align-items:center;gap:0;overflow-x:auto">
        <?php foreach ($pasos_p as $n => $info):
            $done    = $paso_p > $n;
            $current = $paso_p === $n;
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
            <div style="flex:1;height:2px;background:<?= $paso_p > $n ? '#27a644' : 'var(--border)' ?>;min-width:12px"></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($paso_p === 3): ?>
    <p style="text-align:center;margin-top:.75rem;font-size:.82rem;color:var(--accent)">
        Tu historia fue aprobada. <a href="<?= BASE_URL ?>/periodista/subir-boleta.php?id=<?= $id ?>" style="font-weight:700">Genera tu boleta de honorarios y súbela aquí →</a>
    </p>
    <?php elseif ($paso_p === 4): ?>
    <p style="text-align:center;margin-top:.75rem;font-size:.82rem">
        <span style="color:var(--success);font-weight:600">✅ Boleta recibida por el equipo administrativo.</span><br>
        <span style="color:var(--muted)">En espera de confirmación de pago. Te avisaremos por correo cuando sea procesado.</span>
    </p>
    <?php elseif ($paso_p === 5 || $h['estado'] === 'pagada'): ?>
    <p style="text-align:center;margin-top:.75rem;font-size:.82rem;color:var(--success);font-weight:600">¡Proceso completado! Recibirás o ya recibiste el pago.</p>
    <?php endif; ?>
</div>
<?php endif; ?>


<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.2rem">
    <div class="card">
        <div class="card-header"><h2>📋 Detalles</h2></div>
        <div class="detail-row">
            <span class="detail-label">Código</span>
            <span class="detail-value" style="font-family:monospace;font-weight:700;font-size:.95rem;color:var(--accent)"><?= e($h['codigo'] ?? '—') ?></span>
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
    </div>
    
    <div class="card">
        <div class="card-header"><h2>💰 Información</h2></div>
        <div class="detail-row">
            <span class="detail-label">Presupuesto</span>
            <span class="detail-value" style="font-weight:600">$<?= number_format($h['monto_total_a_pagar'] ?? $h['presupuesto'], 0, ',', '.') ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Fecha entrega</span>
            <span class="detail-value"><?= date('d/m/Y', strtotime($h['fecha_entrega'])) ?></span>
        </div>
        <?php if ($h['asignada_en']):
            $terminada = in_array($h['estado'], ['revisada','pagada']);
            $col = $terminada ? '#5e6ad2' : ($porcentaje >= 100 ? '#ef4444' : ($porcentaje >= 80 ? '#ef4444' : ($porcentaje >= 50 ? '#f59e0b' : '#27a644')));
            $lbl = $terminada ? ucfirst($h['estado'])
                 : ($porcentaje >= 100 ? 'Vencida'
                 : $dias_restantes . ' día' . ($dias_restantes !== 1 ? 's' : '') . ' restantes');
        ?>
        <div class="detail-row" style="flex-direction:column;align-items:flex-start;gap:.5rem">
            <span class="detail-label">Avance del plazo</span>
            <div style="width:100%">
                <div style="background:rgba(0,0,0,.3);border-radius:6px;height:8px;overflow:hidden;margin-bottom:.35rem">
                    <div style="width:<?= $porcentaje ?>%;height:100%;background:<?= $col ?>;border-radius:6px;transition:width .4s<?= (!$terminada && $porcentaje >= 100) ? ';animation:pulseBar 1.4s ease-in-out infinite' : '' ?>"></div>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <span style="font-size:.72rem;font-weight:600;color:<?= $col ?>"><?= e($lbl) ?></span>
                    <span style="font-size:.68rem;color:var(--muted)"><?= $porcentaje ?>% del plazo</span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:.65rem;color:var(--muted);margin-top:.25rem">
                    <span>Inicio: <?= date('d/m/Y', strtotime($h['asignada_en'])) ?></span>
                    <span>Entrega: <?= date('d/m/Y', strtotime($h['fecha_entrega'])) ?></span>
                </div>
            </div>
        </div>
        <style>@keyframes pulseBar{0%,100%{opacity:1}50%{opacity:.4}}</style>
        <?php endif; ?>
        <div class="detail-row">
            <span class="detail-label">Estado</span>
            <span class="detail-value"><span class="badge badge-<?= $h['estado'] ?>"><?= $h['estado'] ?></span></span>
        </div>
        <?php if ($pag): ?>
        <div class="detail-row">
            <span class="detail-label">💵 Pago</span>
            <span class="detail-value" style="color:var(--success)">
                $<?= number_format($pag['liquido'], 0, ',', '.') ?> líquido ·
                <?= date('d/m/Y', strtotime($pag['fecha_pago'])) ?>
            </span>
        </div>
        <?php if ($pag['comprobante']): ?>
        <div class="detail-row">
            <span class="detail-label">Comprobante</span>
            <span class="detail-value">
                <?php $cext = strtolower(pathinfo($pag['comprobante'], PATHINFO_EXTENSION)); ?>
                <a href="<?= e(urlImagen($pag['comprobante'])) ?>" target="_blank" class="btn btn-secondary btn-xs">
                    <?= $cext === 'pdf' ? '📄 Ver PDF' : '🖼 Ver comprobante' ?>
                </a>
            </span>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($h['boleta_path']): ?>
        <div class="detail-row">
            <span class="detail-label">Tu boleta</span>
            <span class="detail-value">
                <?php $bext = strtolower(pathinfo($h['boleta_path'], PATHINFO_EXTENSION)); ?>
                <a href="<?= e(urlImagen($h['boleta_path'])) ?>" target="_blank" class="btn btn-secondary btn-xs">
                    <?= $bext === 'pdf' ? '📄 Ver PDF' : '🖼 Ver boleta' ?>
                </a>
                <span style="font-size:.72rem;color:var(--muted);margin-left:.5rem">subida <?= date('d/m/Y', strtotime($h['boleta_subida_en'])) ?></span>
            </span>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($ent && $ent['contenido']): ?>
<div class="card" style="margin-top:1.2rem">
    <div class="card-header">
        <h2>📖 Mi Entrega</h2>
        <span class="badge badge-<?= $ent['estado'] ?>"><?= $ent['estado'] ?></span>
    </div>
    <div style="line-height:1.8;font-size:.95rem;color:var(--text2)">
        <?= sanitizarHTMLEntrega($ent['contenido'] ?? '') ?>
    </div>
    <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);font-size:.8rem;color:var(--muted)">
        Entregado el <?= date('d/m/Y H:i', strtotime($ent['fecha_entrega'])) ?>
        <?php if ($doc && $doc['firma_aceptacion']): ?>
        · ✅ Cesión de derechos firmada
        <?php endif; ?>
    </div>
    <?php if ($ent['notas_revision']): ?>
    <div style="margin-top:1rem;padding:1rem;background:var(--surface2);border-radius:10px;font-size:.85rem">
        <strong style="color:var(--accent)">Notas de revisión:</strong><br>
        <?= nl2br(e($ent['notas_revision'])) ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
