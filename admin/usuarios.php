<?php
$titulo = 'Usuarios';
require_once __DIR__ . '/header.php';

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'crear') {
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $rol = $_POST['rol'] ?? 'periodista';
        
        if (empty($nombre) || empty($email) || empty($password)) {
            flash('error', 'Todos los campos son obligatorios.');
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            try {
                $stmt = $db->prepare("INSERT INTO usuarios (nombre, email, password, rol) VALUES (?, ?, ?, ?)");
                $stmt->execute([$nombre, $email, $hash, $rol]);
                flash('success', 'Usuario creado exitosamente.');
            } catch (PDOException $e) {
                flash('error', 'El email ya está registrado.');
            }
        }
        header('Location: ' . BASE_URL . '/admin/usuarios.php');
        exit;
    }
    
    if ($action === 'editar') {
        $id = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $rol = $_POST['rol'] ?? 'periodista';
        $activo = isset($_POST['activo']) ? 1 : 0;
        
        $db->prepare("UPDATE usuarios SET nombre=?, email=?, rol=?, activo=? WHERE id=?")
            ->execute([$nombre, $email, $rol, $activo, $id]);
        flash('success', 'Usuario actualizado.');
        header('Location: ' . BASE_URL . '/admin/usuarios.php');
        exit;
    }
    
    if ($action === 'cambiar_password') {
        $id = (int)($_POST['id'] ?? 0);
        $password = $_POST['password'] ?? '';
        if (!empty($password)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db->prepare("UPDATE usuarios SET password=? WHERE id=?")->execute([$hash, $id]);
            flash('success', 'Contraseña actualizada.');
        }
        header('Location: ' . BASE_URL . '/admin/usuarios.php');
        exit;
    }
}

$usuarios = $db->query("SELECT * FROM usuarios ORDER BY rol, nombre")->fetchAll();
$editId = (int)($_GET['edit'] ?? 0);
$editUser = null;
if ($editId) {
    $stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch();
}
?>

<div class="page-header">
    <div>
        <h1>Usuarios</h1>
        <div class="subtitle">Gestión de periodistas y administradores</div>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('nuevo-form').style.display='block'">➕ Nuevo Usuario</button>
</div>

<div class="card" id="nuevo-form" style="display:<?= $editUser ? 'none' : 'none' ?>;max-width:500px;margin-bottom:1.5rem">
    <div class="card-header">
        <h2>Nuevo Usuario</h2>
        <span style="cursor:pointer;color:var(--muted);font-size:1.2rem" onclick="this.closest('.card').style.display='none'">✕</span>
    </div>
    <form method="post">
        <input type="hidden" name="action" value="crear">
        <div class="form-row">
            <div class="form-group">
                <label>Nombre</label>
                <input type="text" name="nombre" required>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" required>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Contraseña</label>
                <input type="password" name="password" required>
            </div>
            <div class="form-group">
                <label>Rol</label>
                <select name="rol">
                    <option value="periodista">Periodista</option>
                    <option value="admin">Administrador</option>
                </select>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Crear Usuario</button>
    </form>
</div>

<?php if ($editUser): ?>
<div class="card" style="max-width:500px;margin-bottom:1.5rem">
    <div class="card-header">
        <h2>Editar: <?= e($editUser['nombre']) ?></h2>
        <a href="<?= BASE_URL ?>/admin/usuarios.php" style="font-size:.8rem">✕ Cerrar</a>
    </div>
    <form method="post">
        <input type="hidden" name="action" value="editar">
        <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
        <div class="form-row">
            <div class="form-group">
                <label>Nombre</label>
                <input type="text" name="nombre" value="<?= e($editUser['nombre']) ?>" required>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" value="<?= e($editUser['email']) ?>" required>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Rol</label>
                <select name="rol">
                    <option value="periodista" <?= $editUser['rol']==='periodista'?'selected':'' ?>>Periodista</option>
                    <option value="admin" <?= $editUser['rol']==='admin'?'selected':'' ?>>Administrador</option>
                </select>
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;margin-top:1.5rem">
                    <input type="checkbox" name="activo" value="1" <?= $editUser['activo']?'checked':'' ?>>
                    <span>Usuario activo</span>
                </label>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Guardar Cambios</button>
    </form>
    
    <hr style="border-color:var(--border);margin:1.5rem 0">
    
    <h3 style="font-size:.9rem;margin-bottom:.8rem">Cambiar Contraseña</h3>
    <form method="post">
        <input type="hidden" name="action" value="cambiar_password">
        <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
        <div class="form-row">
            <div class="form-group">
                <label>Nueva contraseña</label>
                <input type="password" name="password" required minlength="6">
            </div>
        </div>
        <button type="submit" class="btn btn-warning btn-sm">Actualizar Contraseña</button>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Email</th>
                    <th>Rol</th>
                    <th>RUT</th>
                    <th>Banco</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td><strong><?= e($u['nombre']) ?></strong></td>
                    <td><?= e($u['email']) ?></td>
                    <td><span class="badge badge-<?= $u['rol']==='admin' ? 'revisada' : 'disponible' ?>"><?= $u['rol'] ?></span></td>
                    <td><?= e($u['rut'] ?? '—') ?></td>
                    <td><?= e($u['banco'] ?? '—') ?></td>
                    <td>
                        <?php if ($u['activo']): ?>
                            <span style="color:var(--success)">● Activo</span>
                        <?php else: ?>
                            <span style="color:var(--error)">● Inactivo</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= BASE_URL ?>/admin/usuarios.php?edit=<?= $u['id'] ?>" class="btn btn-secondary btn-xs">Editar</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
