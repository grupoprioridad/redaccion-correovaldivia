<?php
$titulo = 'Historial';
require_once __DIR__ . '/header.php';

$db = getDB();
$user_id = $_SESSION['usuario_id'];

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$total = $db->prepare("SELECT COUNT(*) FROM historias WHERE periodista_asignado = ?");
$total->execute([$user_id]);
$total_hist = $total->fetchColumn();
$total_pages = max(1, ceil($total_hist / $per_page));

$historias = $db->prepare("
    SELECT h.*, 
           (SELECT estado FROM entregas WHERE historia_id = h.id AND periodista_id = ? ORDER BY created_at DESC LIMIT 1) AS entrega_estado,
           (SELECT fecha_entrega FROM entregas WHERE historia_id = h.id AND periodista_id = ? ORDER BY created_at DESC LIMIT 1) AS entrega_fecha
    FROM historias h
    WHERE h.periodista_asignado = ?
    ORDER BY h.created_at DESC
    LIMIT ? OFFSET ?
");
$historias->execute([$user_id, $user_id, $user_id, $per_page, $offset]);
$rows = $historias->fetchAll();
?>

<div class="page-header">
    <h1>📚 Historial</h1>
</div>

<div class="card">
    <div class="card-header">
        <h2>Todas mis historias</h2>
        <span class="badge badge-disponible"><?= $total_hist ?> total</span>
    </div>
    
    <?php if (empty($rows)): ?>
        <div class="empty-state">
            <div class="icon">📭</div>
            <p>Aún no tienes historias en tu historial.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Título</th>
                        <th>Estado</th>
                        <th>Presupuesto</th>
                        <th>Entrega</th>
                        <th>Entregado</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $h): ?>
                    <tr>
                        <td><strong><?= e($h['titulo']) ?></strong></td>
                        <td><span class="badge badge-<?= $h['estado'] ?>"><?= $h['estado'] ?></span></td>
                        <td>$<?= number_format($h['presupuesto'], 0, ',', '.') ?></td>
                        <td><?= date('d/m/Y', strtotime($h['fecha_entrega'])) ?></td>
                        <td><?= $h['entrega_fecha'] ? date('d/m/Y', strtotime($h['entrega_fecha'])) : '—' ?></td>
                        <td>
                            <a href="<?= BASE_URL ?>/periodista/historia.php?id=<?= $h['id'] ?>" class="btn btn-secondary btn-xs">Ver</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($total_pages > 1): ?>
        <div style="display:flex;gap:.5rem;justify-content:center;margin-top:1.5rem">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <a href="?page=<?= $i ?>" class="btn btn-<?= $i === $page ? 'primary' : 'secondary' ?> btn-xs"><?= $i ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
