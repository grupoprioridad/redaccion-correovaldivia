<?php
$titulo = 'Mis Historias';
require_once __DIR__ . '/header.php';

$db = getDB();
$user_id = $_SESSION['usuario_id'];

// Mis historias (asignadas a mí)
$mis_historias = $db->prepare("
    SELECT * FROM historias 
    WHERE periodista_asignado = ? 
    ORDER BY FIELD(estado,'asignada','en_curso','entregada','revisada','pagada'), fecha_entrega ASC
");
$mis_historias->execute([$user_id]);
$mis_h = $mis_historias->fetchAll();

// Historias disponibles (que puedo tomar)
$disponibles = $db->prepare("
    SELECT h.*, u.nombre AS creador_nombre
    FROM historias h
    JOIN usuarios u ON h.creada_por = u.id
    WHERE h.estado = 'disponible'
    AND (
        h.visible_para_todos = 1
        OR h.id IN (SELECT historia_id FROM historia_visibilidad WHERE usuario_id = ?)
    )
    ORDER BY h.fecha_entrega ASC
");
$disponibles->execute([$user_id]);
$disps = $disponibles->fetchAll();
?>

<div class="page-header">
    <h1>Mis Historias</h1>
</div>

<?php if (!empty($mis_h)): ?>
<div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr))">
    <?php 
    $counts = ['asignada'=>0,'en_curso'=>0,'entregada'=>0,'revisada'=>0,'pagada'=>0];
    foreach ($mis_h as $h) $counts[$h['estado']]++;
    ?>
    <div class="stat-card"><div class="stat-value"><?= $counts['asignada']+$counts['en_curso'] ?></div><div class="stat-label">En curso</div></div>
    <div class="stat-card"><div class="stat-value"><?= $counts['entregada'] ?></div><div class="stat-label">Entregadas</div></div>
    <div class="stat-card"><div class="stat-value"><?= $counts['revisada'] ?></div><div class="stat-label">Aprobadas</div></div>
    <div class="stat-card"><div class="stat-value"><?= $counts['pagada'] ?></div><div class="stat-label">Pagadas</div></div>
</div>

<div class="card">
    <div class="card-header"><h2>📋 Mis Historias</h2></div>
    <div class="card-grid">
        <?php foreach ($mis_h as $h): ?>
        <div class="card card-hover" style="cursor:default">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:.8rem">
                <h3 style="font-size:.95rem;font-weight:600"><?= e($h['titulo']) ?></h3>
                <span class="badge badge-<?= $h['estado'] ?>"><?= $h['estado'] ?></span>
            </div>
            
            <?php if ($h['foco_periodistico']): ?>
            <p style="font-size:.8rem;color:var(--text2);margin-bottom:.5rem;line-height:1.5">
                <?= nl2br(e(mb_substr($h['foco_periodistico'], 0, 150))) ?><?= mb_strlen($h['foco_periodistico']) > 150 ? '...' : '' ?>
            </p>
            <?php endif; ?>
            
            <div style="display:flex;gap:1rem;font-size:.75rem;color:var(--muted);margin-bottom:.8rem">
                <span>⏱ <?= date('d/m/Y', strtotime($h['fecha_entrega'])) ?></span>
                <?php if ($h['presupuesto']): ?>
                <span>💰 $<?= number_format($h['presupuesto'], 0, ',', '.') ?></span>
                <?php endif; ?>
                <?php if ($h['extension_esperada']): ?>
                <span>📄 <?= e($h['extension_esperada']) ?></span>
                <?php endif; ?>
            </div>
            
            <?php if ($h['asignada_en']): ?>
            <p style="font-size:.7rem;color:var(--muted);margin-bottom:.5rem">
                Tomada el <?= date('d/m/Y H:i', strtotime($h['asignada_en'])) ?>
            </p>
            <?php endif; ?>
            
            <div style="margin-top:.5rem">
                <a href="<?= BASE_URL ?>/periodista/historia.php?id=<?= $h['id'] ?>" class="btn btn-primary btn-sm">Ver detalle</a>
                <?php if (in_array($h['estado'], ['asignada','en_curso','rechazado'])): ?>
                <a href="<?= BASE_URL ?>/periodista/entregar.php?id=<?= $h['id'] ?>" class="btn btn-secondary btn-sm">Entregar</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php elseif (empty($disps)): ?>
    <div class="empty-state">
        <div class="icon">📭</div>
        <p>No tienes historias asignadas ni disponibles. Cuando el administrador te asigne una, aparecerá aquí.</p>
    </div>
<?php endif; ?>

<?php if (!empty($disps)): ?>
<div class="card" style="margin-top:1.5rem">
    <div class="card-header">
        <h2>📢 Historias Disponibles</h2>
        <span class="badge badge-disponible"><?= count($disps) ?> disponibles</span>
    </div>
    <div class="card-grid">
        <?php foreach ($disps as $h): ?>
        <div class="card card-hover" style="cursor:default">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:.8rem">
                <h3 style="font-size:.95rem;font-weight:600"><?= e($h['titulo']) ?></h3>
                <span class="badge badge-disponible">Disponible</span>
            </div>
            
            <?php if ($h['descripcion']): ?>
            <p style="font-size:.8rem;color:var(--text2);margin-bottom:.5rem;line-height:1.5">
                <?= nl2br(e(mb_substr($h['descripcion'], 0, 200))) ?><?= mb_strlen($h['descripcion']) > 200 ? '...' : '' ?>
            </p>
            <?php endif; ?>
            
            <div style="display:flex;gap:1rem;font-size:.75rem;color:var(--muted);margin-bottom:.8rem">
                <span>⏱ Entrega: <?= date('d/m/Y', strtotime($h['fecha_entrega'])) ?></span>
                <?php if ($h['presupuesto']): ?>
                <span>💰 $<?= number_format($h['presupuesto'], 0, ',', '.') ?></span>
                <?php endif; ?>
            </div>
            
            <form method="post" action="<?= BASE_URL ?>/periodista/tomar.php">
                <input type="hidden" name="historia_id" value="<?= $h['id'] ?>">
                <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('¿Tomar esta historia? El timing de entrega comenzará.')">Tomar Historia</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
