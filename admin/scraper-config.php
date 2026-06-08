<?php
$titulo = 'Fuentes del Scraper';
require_once __DIR__ . '/header.php';

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'crear') {
        $nombre = trim($_POST['nombre'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $tipo = $_POST['tipo'] ?? 'portada';
        if (!empty($nombre) && !empty($url)) {
            $db->prepare("INSERT INTO scraper_fuentes (nombre, url, tipo) VALUES (?, ?, ?)")->execute([$nombre, $url, $tipo]);
            flash('success', 'Fuente agregada.');
        }
        header('Location: ' . BASE_URL . '/admin/scraper-config.php');
        exit;
    }
    
    if ($action === 'editar') {
        $id = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $tipo = $_POST['tipo'] ?? 'portada';
        $activo = isset($_POST['activo']) ? 1 : 0;
        $db->prepare("UPDATE scraper_fuentes SET nombre=?, url=?, tipo=?, activo=? WHERE id=?")->execute([$nombre, $url, $tipo, $activo, $id]);
        flash('success', 'Fuente actualizada.');
        header('Location: ' . BASE_URL . '/admin/scraper-config.php');
        exit;
    }
    
    if ($action === 'eliminar') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM scraper_fuentes WHERE id=?")->execute([$id]);
        flash('info', 'Fuente eliminada.');
        header('Location: ' . BASE_URL . '/admin/scraper-config.php');
        exit;
    }
    
    if ($action === 'ejecutar_scraper') {
        $output = shell_exec('python3 ' . ROOT_PATH . '/scripts/scraper_medios.py 2>&1');
        flash('success', 'Scraper ejecutado. Ver resultados abajo.');
        $_SESSION['scraper_output'] = $output;
        header('Location: ' . BASE_URL . '/admin/scraper-config.php');
        exit;
    }
}

$fuentes = $db->query("SELECT * FROM scraper_fuentes ORDER BY activo DESC, nombre ASC")->fetchAll();
$editId = (int)($_GET['edit'] ?? 0);
$editFuente = null;
if ($editId) {
    $s = $db->prepare("SELECT * FROM scraper_fuentes WHERE id = ?");
    $s->execute([$editId]);
    $editFuente = $s->fetch();
}

// Stats
$total_noticias = 0;
$archivo_noticias = ROOT_PATH . '/datos/noticias_scrapeadas.json';
if (file_exists($archivo_noticias)) {
    $data = json_decode(file_get_contents($archivo_noticias), true) ?: [];
    $total_noticias = is_array($data) ? count($data) : 0;
}
?>

<div class="page-header">
    <div>
        <h1>📡 Fuentes del Scraper</h1>
        <div class="subtitle">Gestiona los sitios web que el scraper revisa para proponer historias</div>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('nuevo-form').style.display='block'">➕ Agregar Fuente</button>
</div>

<div style="display:flex;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap">
    <div class="stat-card" style="flex:1;min-width:120px">
        <div class="stat-value"><?= count($fuentes) ?></div>
        <div class="stat-label">Fuentes configuradas</div>
    </div>
    <div class="stat-card" style="flex:1;min-width:120px">
        <div class="stat-value"><?= $total_noticias ?></div>
        <div class="stat-label">Noticias scrapeadas</div>
    </div>
    <div class="stat-card" style="flex:1;min-width:120px">
        <div class="stat-value"><?= count(array_filter($fuentes, fn($f) => $f['activo'])) ?></div>
        <div class="stat-label">Fuentes activas</div>
    </div>
    <div style="display:flex;align-items:center">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="ejecutar_scraper">
            <button type="submit" class="btn btn-secondary">▶️ Ejecutar Scraper Ahora</button>
        </form>
    </div>
</div>

<?php if (isset($_SESSION['scraper_output'])): ?>
<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header">
        <h2>📋 Salida del Scraper</h2>
        <span style="cursor:pointer;color:var(--muted)" onclick="this.closest('.card').style.display='none'">✕</span>
    </div>
    <pre style="font-size:.75rem;color:var(--text2);line-height:1.6;max-height:300px;overflow-y:auto;background:var(--surface2);padding:1rem;border-radius:8px"><?= e($_SESSION['scraper_output']) ?></pre>
</div>
<?php unset($_SESSION['scraper_output']); endif; ?>

<div class="card" id="nuevo-form" style="display:none;max-width:500px;margin-bottom:1.5rem">
    <div class="card-header">
        <h2>Nueva Fuente</h2>
        <span style="cursor:pointer;color:var(--muted);font-size:1.2rem" onclick="this.closest('.card').style.display='none'">✕</span>
    </div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="crear">
        <div class="form-group">
            <label>Nombre del medio</label>
            <input type="text" name="nombre" required placeholder="Ej: Diario de Río Bueno">
        </div>
        <div class="form-group">
            <label>URL</label>
            <input type="url" name="url" required placeholder="https://www.ejemplo.cl">
        </div>
        <div class="form-group">
            <label>Tipo</label>
            <select name="tipo">
                <option value="portada">Portada</option>
                <option value="seccion">Sección</option>
                <option value="rss">RSS</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Agregar</button>
    </form>
</div>

<?php if ($editFuente): ?>
<div class="card" style="max-width:500px;margin-bottom:1.5rem">
    <div class="card-header">
        <h2>Editar: <?= e($editFuente['nombre']) ?></h2>
        <a href="<?= BASE_URL ?>/admin/scraper-config.php" style="font-size:.8rem">✕ Cerrar</a>
    </div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="editar">
        <input type="hidden" name="id" value="<?= $editFuente['id'] ?>">
        <div class="form-group">
            <label>Nombre</label>
            <input type="text" name="nombre" value="<?= e($editFuente['nombre']) ?>" required>
        </div>
        <div class="form-group">
            <label>URL</label>
            <input type="url" name="url" value="<?= e($editFuente['url']) ?>" required>
        </div>
        <div class="form-group">
            <label>Tipo</label>
            <select name="tipo">
                <option value="portada" <?= $editFuente['tipo']==='portada'?'selected':'' ?>>Portada</option>
                <option value="seccion" <?= $editFuente['tipo']==='seccion'?'selected':'' ?>>Sección</option>
                <option value="rss" <?= $editFuente['tipo']==='rss'?'selected':'' ?>>RSS</option>
            </select>
        </div>
        <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" name="activo" value="1" <?= $editFuente['activo']?'checked':'' ?>>
                <span>Fuente activa</span>
            </label>
        </div>
        <button type="submit" class="btn btn-primary">Guardar</button>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Medio</th>
                    <th>URL</th>
                    <th>Tipo</th>
                    <th>Estado</th>
                    <th>Último scrape</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($fuentes as $f): ?>
                <tr>
                    <td><strong><?= e($f['nombre']) ?></strong></td>
                    <td style="font-size:.75rem;font-family:'Geist Mono',monospace;max-width:250px;overflow:hidden;text-overflow:ellipsis"><?= e($f['url']) ?></td>
                    <td><span class="badge badge-disponible"><?= $f['tipo'] ?></span></td>
                    <td>
                        <?php if ($f['activo']): ?>
                            <span style="color:var(--success)">● Activo</span>
                        <?php else: ?>
                            <span style="color:var(--error)">● Inactivo</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.8rem"><?= $f['ultimo_scrape'] ? date('d/m/Y H:i', strtotime($f['ultimo_scrape'])) : '—' ?></td>
                    <td>
                        <a href="<?= BASE_URL ?>/admin/scraper-config.php?edit=<?= $f['id'] ?>" class="btn btn-secondary btn-xs">Editar</a>
                        <form method="post" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="eliminar">
                            <input type="hidden" name="id" value="<?= $f['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-xs" onclick="return confirm('¿Eliminar esta fuente?')">Eliminar</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
