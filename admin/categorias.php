<?php
$titulo = 'Categorías';
require_once __DIR__ . '/header.php';

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'crear') {
        $nombre = trim($_POST['nombre'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $desc = trim($_POST['descripcion'] ?? '');
        if (empty($slug)) {
            $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($nombre));
            $slug = trim(preg_replace('/-+/', '-', $slug), '-');
        }
        if (empty($nombre)) {
            flash('error', 'El nombre es obligatorio.');
        } else {
            try {
                $db->prepare("INSERT INTO categorias_redaccion (nombre, slug, descripcion) VALUES (?, ?, ?)")->execute([$nombre, $slug, $desc]);
                flash('success', 'Categoría creada.');
            } catch (PDOException $e) {
                flash('error', 'El slug ya existe.');
            }
        }
        header('Location: ' . BASE_URL . '/admin/categorias.php');
        exit;
    }
    
    if ($action === 'editar') {
        $id = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $desc = trim($_POST['descripcion'] ?? '');
        $activo = isset($_POST['activo']) ? 1 : 0;
        if (!empty($nombre)) {
            $db->prepare("UPDATE categorias_redaccion SET nombre=?, slug=?, descripcion=?, activo=? WHERE id=?")->execute([$nombre, $slug, $desc, $activo, $id]);
            flash('success', 'Categoría actualizada.');
        }
        header('Location: ' . BASE_URL . '/admin/categorias.php');
        exit;
    }
    
    if ($action === 'eliminar') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE categorias_redaccion SET activo=0 WHERE id=?")->execute([$id]);
        flash('info', 'Categoría desactivada.');
        header('Location: ' . BASE_URL . '/admin/categorias.php');
        exit;
    }
}

$categorias = $db->query("
    SELECT c.*, 
           (SELECT COUNT(*) FROM historias WHERE categoria_id = c.id) AS total_historias,
           (SELECT COUNT(*) FROM postulaciones WHERE JSON_CONTAINS(intereses_categorias, CAST(c.id AS JSON), '$')) AS total_interesados
    FROM categorias_redaccion c
    ORDER BY c.activo DESC, c.nombre ASC
")->fetchAll();

$editId = (int)($_GET['edit'] ?? 0);
$editCat = null;
if ($editId) {
    $s = $db->prepare("SELECT * FROM categorias_redaccion WHERE id = ?");
    $s->execute([$editId]);
    $editCat = $s->fetch();
}
?>

<div class="page-header">
    <div>
        <h1>🏷️ Categorías de Redacción</h1>
        <div class="subtitle">Temas de interés periodístico — configura los temas que cubre el medio</div>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('nuevo-form').style.display='block'">➕ Nueva Categoría</button>
</div>

<div class="card" id="nuevo-form" style="display:none;max-width:500px;margin-bottom:1.5rem">
    <div class="card-header">
        <h2>Nueva Categoría</h2>
        <span style="cursor:pointer;color:var(--muted);font-size:1.2rem" onclick="this.closest('.card').style.display='none'">✕</span>
    </div>
    <form method="post">
        <input type="hidden" name="action" value="crear">
        <div class="form-group">
            <label for="nombre">Nombre *</label>
            <input type="text" id="nombre" name="nombre" required placeholder="Ej: Educación">
        </div>
        <div class="form-group">
            <label for="slug">Slug (URL)</label>
            <input type="text" id="slug" name="slug" placeholder="ej: educacion (se genera automático si se deja vacío)">
        </div>
        <div class="form-group">
            <label for="descripcion">Descripción</label>
            <input type="text" id="descripcion" name="descripcion" placeholder="Ej: Educación, colegios, universidades">
        </div>
        <button type="submit" class="btn btn-primary">Crear</button>
    </form>
</div>

<?php if ($editCat): ?>
<div class="card" style="max-width:500px;margin-bottom:1.5rem">
    <div class="card-header">
        <h2>Editar: <?= e($editCat['nombre']) ?></h2>
        <a href="<?= BASE_URL ?>/admin/categorias.php" style="font-size:.8rem">✕ Cerrar</a>
    </div>
    <form method="post">
        <input type="hidden" name="action" value="editar">
        <input type="hidden" name="id" value="<?= $editCat['id'] ?>">
        <div class="form-group">
            <label>Nombre</label>
            <input type="text" name="nombre" value="<?= e($editCat['nombre']) ?>" required>
        </div>
        <div class="form-group">
            <label>Slug</label>
            <input type="text" name="slug" value="<?= e($editCat['slug']) ?>" required>
        </div>
        <div class="form-group">
            <label>Descripción</label>
            <input type="text" name="descripcion" value="<?= e($editCat['descripcion'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" name="activo" value="1" <?= $editCat['activo']?'checked':'' ?>>
                <span>Activo</span>
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
                    <th>Nombre</th>
                    <th>Slug</th>
                    <th>Descripción</th>
                    <th>Historias</th>
                    <th>🖊️ Interesados</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categorias as $c): ?>
                <tr>
                    <td><strong><?= e($c['nombre']) ?></strong></td>
                    <td style="font-family:'Geist Mono',monospace;font-size:.75rem;color:var(--muted)"><?= e($c['slug']) ?></td>
                    <td style="font-size:.8rem;color:var(--text2)"><?= e($c['descripcion'] ?? '—') ?></td>
                    <td><?= $c['total_historias'] ?></td>
                    <td><?= $c['total_interesados'] ?> periodistas</td>
                    <td>
                        <?php if ($c['activo']): ?>
                            <span style="color:var(--success)">● Activo</span>
                        <?php else: ?>
                            <span style="color:var(--error)">● Inactivo</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= BASE_URL ?>/admin/categorias.php?edit=<?= $c['id'] ?>" class="btn btn-secondary btn-xs">Editar</a>
                        <?php if ($c['activo']): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="action" value="eliminar">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-xs" onclick="return confirm('¿Desactivar esta categoría?')">Desactivar</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
