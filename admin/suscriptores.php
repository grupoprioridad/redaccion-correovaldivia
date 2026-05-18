<?php
$titulo = 'Suscriptores';
require_once __DIR__ . '/header.php';

function getSiteDB(): PDO {
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO(
            'mysql:host=170.187.205.201;dbname=elcorreodevaldivia;charset=utf8mb4',
            'elcorreo_user', ',e~vm!RAXdX3JKNf',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}
$site = getSiteDB();

// ── Exportar CSV ─────────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $todos = $site->query("SELECT id, nombre, correo, telefono, fecha_registro FROM suscriptores ORDER BY fecha_registro DESC")->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="suscriptores_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Nombre', 'Correo', 'Teléfono', 'Fecha de registro']);
    foreach ($todos as $s) fputcsv($out, [$s['id'], $s['nombre'], $s['correo'], $s['telefono'], $s['fecha_registro']]);
    fclose($out);
    exit;
}

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'eliminar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) { $site->prepare("DELETE FROM suscriptores WHERE id = ?")->execute([$id]); flash('success', 'Suscriptor eliminado.'); }
    }

    if ($action === 'eliminar_multiple') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        $ids = array_filter($ids);
        if ($ids) {
            $in = implode(',', $ids);
            $site->exec("DELETE FROM suscriptores WHERE id IN ($in)");
            flash('success', count($ids) . ' suscriptor(es) eliminado(s).');
        } else {
            flash('error', 'No seleccionaste ningún suscriptor.');
        }
    }

    header('Location: ' . BASE_URL . '/admin/suscriptores.php');
    exit;
}

// ── Búsqueda + paginación ─────────────────────────────────────────────────────
$q    = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 50;
$off  = ($page - 1) * $per;

if ($q !== '') {
    $like  = '%' . $q . '%';
    $cnt   = $site->prepare("SELECT COUNT(*) FROM suscriptores WHERE nombre LIKE ? OR correo LIKE ? OR telefono LIKE ?");
    $cnt->execute([$like, $like, $like]);
    $total = (int)$cnt->fetchColumn();
    $stmt  = $site->prepare("SELECT * FROM suscriptores WHERE nombre LIKE ? OR correo LIKE ? OR telefono LIKE ? ORDER BY fecha_registro DESC LIMIT $per OFFSET $off");
    $stmt->execute([$like, $like, $like]);
} else {
    $total = (int)$site->query("SELECT COUNT(*) FROM suscriptores")->fetchColumn();
    $stmt  = $site->prepare("SELECT * FROM suscriptores ORDER BY fecha_registro DESC LIMIT $per OFFSET $off");
    $stmt->execute();
}

$suscriptores = $stmt->fetchAll();
$pages        = (int)ceil($total / $per);
$stats        = $site->query("SELECT COUNT(*) AS total, SUM(fecha_registro >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS semana, SUM(fecha_registro >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS mes FROM suscriptores")->fetch();
?>

<div class="page-header">
    <div>
        <h1>📧 Suscriptores</h1>
        <div class="subtitle">Lectores registrados en El Correo de Valdivia</div>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a href="<?= BASE_URL ?>/admin/enviar-articulo.php" class="btn btn-primary">✉ Enviar artículo</a>
        <a href="?export=csv" class="btn btn-secondary">↓ CSV</a>
    </div>
</div>

<div class="stats-grid" style="margin-bottom:1.5rem">
    <div class="stat-card"><div class="stat-value"><?= number_format((int)$stats['total']) ?></div><div class="stat-label">Total</div></div>
    <div class="stat-card"><div class="stat-value" style="color:var(--success)"><?= (int)$stats['semana'] ?></div><div class="stat-label">Últimos 7 días</div></div>
    <div class="stat-card"><div class="stat-value" style="color:var(--accent)"><?= (int)$stats['mes'] ?></div><div class="stat-label">Últimos 30 días</div></div>
</div>

<form method="get" style="margin-bottom:1.25rem;display:flex;gap:.6rem;flex-wrap:wrap">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar por nombre, correo o teléfono…" class="form-control" style="max-width:360px">
    <button type="submit" class="btn btn-secondary">Buscar</button>
    <?php if ($q): ?><a href="?" class="btn btn-secondary">✕</a><?php endif; ?>
</form>

<!-- Acciones masivas (sobre la tabla) -->
<div id="bulk-bar" style="display:none;align-items:center;gap:.75rem;margin-bottom:.75rem;padding:.7rem 1rem;background:var(--surface2);border:1px solid rgba(94,106,210,.3);border-radius:10px">
    <span id="bulk-count" style="font-size:.85rem;color:var(--accent-h);font-weight:600">0 seleccionados</span>
    <button type="button" class="btn btn-danger btn-sm" onclick="bulkDelete()">🗑 Eliminar seleccionados</button>
    <a id="bulk-send-link" href="<?= BASE_URL ?>/admin/enviar-articulo.php" class="btn btn-primary btn-sm">✉ Enviar artículo a seleccionados</a>
    <button type="button" class="btn btn-secondary btn-sm" onclick="clearSelection()">Deseleccionar todo</button>
</div>

<!-- Formulario de eliminación masiva (oculto) -->
<form id="bulk-form" method="post" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="eliminar_multiple">
    <div id="bulk-ids"></div>
</form>

<div class="card">
    <div class="card-header">
        <h2><?= $q ? 'Resultados para "' . e($q) . '"' : 'Todos los suscriptores' ?></h2>
        <span class="badge"><?= $total ?> <?= $total === 1 ? 'registro' : 'registros' ?></span>
    </div>

    <?php if (empty($suscriptores)): ?>
    <div class="empty-state"><div style="font-size:2rem;margin-bottom:.5rem">📭</div><p>Sin resultados.</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:36px">
                        <input type="checkbox" id="chk-all" title="Seleccionar todos"
                               style="width:16px;height:16px;cursor:pointer;accent-color:var(--accent)">
                    </th>
                    <th>#</th>
                    <th>Nombre</th>
                    <th>Correo</th>
                    <th>Teléfono</th>
                    <th>Registro</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($suscriptores as $s): ?>
                <tr>
                    <td>
                        <input type="checkbox" class="row-chk"
                               data-id="<?= $s['id'] ?>"
                               data-correo="<?= e($s['correo']) ?>"
                               style="width:16px;height:16px;cursor:pointer;accent-color:var(--accent)">
                    </td>
                    <td style="color:var(--muted);font-size:.8rem"><?= $s['id'] ?></td>
                    <td><strong><?= e($s['nombre']) ?></strong></td>
                    <td><a href="mailto:<?= e($s['correo']) ?>" style="color:var(--accent);font-size:.88rem"><?= e($s['correo']) ?></a></td>
                    <td style="color:var(--text2);font-size:.88rem"><?= e($s['telefono']) ?: '—' ?></td>
                    <td style="color:var(--muted);font-size:.8rem;white-space:nowrap"><?= date('d/m/Y H:i', strtotime($s['fecha_registro'])) ?></td>
                    <td>
                        <form method="post" style="display:inline"
                              onsubmit="return confirm('¿Eliminar a <?= e(addslashes($s['nombre'])) ?>?\n<?= e(addslashes($s['correo'])) ?>')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="eliminar">
                            <input type="hidden" name="id"     value="<?= $s['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-xs">🗑</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
    <div style="display:flex;justify-content:center;gap:.4rem;padding:1.2rem 0 .4rem;flex-wrap:wrap">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a href="?<?= $q ? 'q=' . urlencode($q) . '&' : '' ?>page=<?= $i ?>"
           class="btn btn-xs <?= $i === $page ? 'btn-primary' : 'btn-secondary' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
const chkAll  = document.getElementById('chk-all');
const bulkBar = document.getElementById('bulk-bar');
const bulkCnt = document.getElementById('bulk-count');
const bulkIds = document.getElementById('bulk-ids');
const sendLink= document.getElementById('bulk-send-link');
const rows    = () => [...document.querySelectorAll('.row-chk')];

function updateBulkBar() {
    const checked = rows().filter(c => c.checked);
    const n       = checked.length;
    bulkBar.style.display = n > 0 ? 'flex' : 'none';
    bulkCnt.textContent   = n + ' seleccionado' + (n !== 1 ? 's' : '');
    chkAll.indeterminate  = n > 0 && n < rows().length;
    chkAll.checked        = n > 0 && n === rows().length;

    // Pasar IDs al link de envío
    const correos = checked.map(c => c.dataset.correo).join(',');
    const ids     = checked.map(c => c.dataset.id).join(',');
    sendLink.href = '<?= BASE_URL ?>/admin/enviar-articulo.php?ids=' + ids;
}

chkAll.addEventListener('change', () => {
    rows().forEach(c => c.checked = chkAll.checked);
    updateBulkBar();
});

document.querySelectorAll('.row-chk').forEach(c =>
    c.addEventListener('change', updateBulkBar)
);

function bulkDelete() {
    const checked = rows().filter(c => c.checked);
    if (!checked.length) return;
    if (!confirm('¿Eliminar ' + checked.length + ' suscriptor(es)?\n\nEsta acción no se puede deshacer.')) return;
    bulkIds.innerHTML = '';
    checked.forEach(c => {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = c.dataset.id;
        bulkIds.appendChild(inp);
    });
    document.getElementById('bulk-form').submit();
}

function clearSelection() {
    rows().forEach(c => c.checked = false);
    chkAll.checked = false;
    updateBulkBar();
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
