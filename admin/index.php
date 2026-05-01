<?php
$titulo = 'Dashboard';
require_once __DIR__ . '/header.php';

$db = getDB();

// Stats
$stats = [];
$stats['total'] = $db->query("SELECT COUNT(*) FROM historias")->fetchColumn();
$stats['disponibles'] = $db->query("SELECT COUNT(*) FROM historias WHERE estado='disponible'")->fetchColumn();
$stats['en_curso'] = $db->query("SELECT COUNT(*) FROM historias WHERE estado IN ('asignada','en_curso')")->fetchColumn();
$stats['entregadas'] = $db->query("SELECT COUNT(*) FROM historias WHERE estado='entregada'")->fetchColumn();
$stats['periodistas'] = $db->query("SELECT COUNT(*) FROM usuarios WHERE rol='periodista' AND activo=1")->fetchColumn();

// Últimas historias
$historias = $db->query("
    SELECT h.*, u.nombre AS periodista_nombre
    FROM historias h
    LEFT JOIN usuarios u ON h.periodista_asignado = u.id
    ORDER BY h.created_at DESC
    LIMIT 15
")->fetchAll();
?>

<div class="page-header">
    <div>
        <h1>Dashboard</h1>
        <div class="subtitle">Panel de control de historias</div>
    </div>
    <a href="<?= BASE_URL ?>/admin/historia-nueva.php" class="btn btn-primary">➕ Nueva Historia</a>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?= $stats['disponibles'] ?></div>
        <div class="stat-label">Historias disponibles</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $stats['en_curso'] ?></div>
        <div class="stat-label">En curso</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $stats['entregadas'] ?></div>
        <div class="stat-label">Entregadas</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $stats['periodistas'] ?></div>
        <div class="stat-label">Periodistas activos</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Últimas Historias</h2>
        <span class="badge badge-disponible"><?= $stats['total'] ?> total</span>
    </div>
    <?php if (empty($historias)): ?>
        <div class="empty-state">
            <div class="icon">📝</div>
            <p>No hay historias aún. ¡Crea la primera!</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Título</th>
                        <th>Estado</th>
                        <th>Periodista</th>
                        <th>Presupuesto</th>
                        <th>Entrega</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historias as $h): ?>
                    <tr>
                        <td><strong><?= e($h['titulo']) ?></strong></td>
                        <td><span class="badge badge-<?= $h['estado'] ?>"><?= $h['estado'] ?></span></td>
                        <td><?= e($h['periodista_nombre'] ?? '—') ?></td>
                        <td>$<?= number_format($h['presupuesto'], 0, ',', '.') ?></td>
                        <td><?= date('d/m/Y', strtotime($h['fecha_entrega'])) ?></td>
                        <td>
                            <a href="<?= BASE_URL ?>/admin/historia-editar.php?id=<?= $h['id'] ?>" class="btn btn-secondary btn-xs">Ver</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
