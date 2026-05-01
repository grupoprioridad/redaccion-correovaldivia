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

if (!$h) {
    flash('error', 'Historia no encontrada.');
    header('Location: ' . BASE_URL . '/admin/index.php');
    exit;
}

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
            header('Location: ' . BASE_URL . '/admin/historia-editar.php?id=' . $id);
            exit;
        }
    }

    if ($action === 'editar') {
        $tit = trim($_POST['titulo'] ?? '');
        $desc = trim($_POST['descripcion'] ?? '');
        $foco = trim($_POST['foco'] ?? '');
        $ext = trim($_POST['extension'] ?? '');
        $fecha = $_POST['fecha_entrega'] ?? '';
        $presupuesto = (int)($_POST['presupuesto'] ?? 0);
        $estadosValidos = ['disponible','asignada','en_curso','entregada','revisada','pagada'];
        $estado = $_POST['estado'] ?? $h['estado'];
        if (!in_array($estado, $estadosValidos, true)) $estado = $h['estado'];

        if (empty($tit) || empty($fecha)) {
            flash('error', 'Título y fecha son obligatorios.');
        } else {
            $db->prepare("UPDATE historias SET titulo=?, descripcion=?, foco_periodistico=?, extension_esperada=?, fecha_entrega=?, presupuesto=?, estado=? WHERE id=?")
                ->execute([$tit, $desc, $foco, $ext, $fecha, $presupuesto, $estado, $id]);
            flash('success', 'Historia actualizada.');
        }
        header('Location: ' . BASE_URL . '/admin/historia-editar.php?id=' . $id);
        exit;
    }

    if ($action === 'marcar_pagado') {
        if ($h['estado'] !== 'revisada' || !$h['periodista_asignado']) {
            flash('error', 'La historia no está en estado para pagar.');
            header('Location: ' . BASE_URL . '/admin/historia-editar.php?id=' . $id);
            exit;
        }
        $monto_total = (int)$h['presupuesto'];
        $retencion = max(0, min($monto_total, (int)($_POST['retencion'] ?? 0)));
        $honorarios = $monto_total - $retencion;
        $liquido = $honorarios;

        $db->prepare("INSERT INTO pagos (historia_id, periodista_id, monto_total, honorarios, retencion, liquido, estado, fecha_pago) VALUES (?,?,?,?,?,?,'pagado',NOW())")
            ->execute([$id, $h['periodista_asignado'], $monto_total, $honorarios, $retencion, $liquido]);
        $db->prepare("UPDATE historias SET estado='pagada' WHERE id=?")->execute([$id]);
        flash('success', 'Pago registrado.');
        header('Location: ' . BASE_URL . '/admin/historia-editar.php?id=' . $id);
        exit;
    }
}

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
?>

<div class="page-header">
    <div>
        <h1><?= e($h['titulo']) ?></h1>
        <div class="subtitle">
            Creada por <?= e($h['creador_nombre']) ?> · 
            <span class="badge badge-<?= $h['estado'] ?>"><?= $h['estado'] ?></span>
        </div>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-secondary btn-sm">← Volver</a>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.2rem">
    <div class="card">
        <div class="card-header"><h2>Detalles</h2></div>
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
        <div class="detail-row">
            <span class="detail-label">Presupuesto</span>
            <span class="detail-value">$<?= number_format($h['presupuesto'], 0, ',', '.') ?></span>
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
        <div class="card">
            <div class="card-header"><h2>💰 Pago</h2></div>
            <div class="detail-row">
                <span class="detail-label">Monto total</span>
                <span class="detail-value">$<?= number_format($pag['monto_total'], 0, ',', '.') ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Honorarios</span>
                <span class="detail-value">$<?= number_format($pag['honorarios'], 0, ',', '.') ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Retención</span>
                <span class="detail-value">$<?= number_format($pag['retencion'], 0, ',', '.') ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Líquido a pagar</span>
                <span class="detail-value" style="color:var(--success);font-weight:600">$<?= number_format($pag['liquido'], 0, ',', '.') ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Pagado el</span>
                <span class="detail-value"><?= date('d/m/Y', strtotime($pag['fecha_pago'])) ?></span>
            </div>
        </div>
        <?php elseif ($h['estado'] === 'revisada' && $h['periodista_asignado']): ?>
        <div class="card">
            <div class="card-header"><h2>💰 Registrar Pago</h2></div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="marcar_pagado">
                <div class="form-group">
                    <label>Monto total</label>
                    <input type="text" value="$<?= number_format($h['presupuesto'], 0, ',', '.') ?>" disabled>
                </div>
                <div class="form-group">
                    <label for="retencion">Retención ($)</label>
                    <input type="number" id="retencion" name="retencion" value="0" min="0">
                    <div class="hint">Monto a retener (ej: retención de renta).</div>
                </div>
                <button type="submit" class="btn btn-success">✓ Marcar como Pagado</button>
            </form>
        </div>
        <?php endif; ?>
        
        <?php if ($h['estado'] === 'entregada' && $ent): ?>
        <div class="card">
            <div class="card-header">
                <h2>📄 Revisar Entrega</h2>
                <span class="badge badge-pendiente_revision">Pendiente</span>
            </div>
            <form method="post">
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
