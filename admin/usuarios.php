<?php
$titulo = 'Usuarios';
require_once __DIR__ . '/header.php';

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    
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
                $stmt = $db->prepare("INSERT INTO usuarios (nombre, email, password, rol, activo, aprobado) VALUES (?, ?, ?, ?, 1, 1)");
                $stmt->execute([$nombre, $email, $hash, $rol]);
                flash('success', 'Usuario creado exitosamente.');
            } catch (PDOException $e) {
                flash('error', 'El email ya está registrado.');
            }
        }
        header('Location: ' . BASE_URL . '/admin/usuarios.php');
        exit;
    }
    
    if ($action === 'aprobar') {
        $db->prepare("UPDATE usuarios SET aprobado=1, activo=1, created_at_aprobacion=NOW() WHERE id=? AND rol='periodista'")->execute([$id]);
        
        // Enviar email de bienvenida al periodista aprobado
        $user = $db->prepare("SELECT * FROM usuarios WHERE id=?");
        $user->execute([$id]);
        $u = $user->fetch();
        if ($u) {
            $subject = "✅ Has sido aprobado en El Correo de Valdivia";
            $msg = "
            <div style='font-family:sans-serif;max-width:600px;margin:0 auto;background:#111214;padding:2rem;border-radius:12px;border:1px solid rgba(255,255,255,0.08)'>
                <h2 style='color:#5e6ad2;margin-bottom:1rem'>✅ ¡Bienvenido a la redacción!</h2>
                <p style='color:#f7f8f8;margin-bottom:1rem'>Hola <strong>{$u['nombre']}</strong>,</p>
                <p style='color:#a0a4ab;line-height:1.6'>Tu solicitud de inscripción ha sido <strong style='color:#4ade80'>aprobada</strong>.<br>
                Ya puedes ingresar a la plataforma de redacción de El Correo de Valdivia para ver las historias disponibles y comenzar a trabajar.</p>
                <p style='margin:1.5rem 0'><a href='" . BASE_URL . "/index.php' style='display:inline-block;padding:12px 24px;background:#5e6ad2;color:#fff;text-decoration:none;border-radius:8px;font-size:.95rem'>Ingresar a la plataforma →</a></p>
                <p style='color:#a0a4ab;font-size:.85rem;line-height:1.6'>Tus datos de acceso:<br>
                Email: {$u['email']}<br>
                Contraseña: la que registraste al inscribirte.</p>
                <hr style='border-color:rgba(255,255,255,0.08);margin:1.5rem 0'>
                <p style='font-size:.8rem;color:#62666d'>El Correo de Valdivia · Sistema de Redacción</p>
            </div>";
            enviarCorreo($u['email'], $subject, $msg);
        }
        
        flash('success', 'Periodista aprobado y notificado por email.');
        header('Location: ' . BASE_URL . '/admin/usuarios.php');
        exit;
    }
    
    if ($action === 'rechazar') {
        $db->prepare("UPDATE usuarios SET aprobado=0, activo=0 WHERE id=? AND rol='periodista'")->execute([$id]);
        
        $user = $db->prepare("SELECT * FROM usuarios WHERE id=?");
        $user->execute([$id]);
        $u = $user->fetch();
        if ($u) {
            $subject = "Actualización sobre tu inscripción en El Correo de Valdivia";
            $msg = "
            <div style='font-family:sans-serif;max-width:600px;margin:0 auto;background:#111214;padding:2rem;border-radius:12px;border:1px solid rgba(255,255,255,0.08)'>
                <p style='color:#a0a4ab;line-height:1.6'>Hola <strong>{$u['nombre']}</strong>,</p>
                <p style='color:#a0a4ab;line-height:1.6'>Lamentamos informarte que tu solicitud de inscripción en El Correo de Valdivia no ha sido aprobada en esta ocasión.</p>
                <p style='color:#a0a4ab;line-height:1.6'>Si tienes dudas, puedes contactarnos directamente.</p>
                <hr style='border-color:rgba(255,255,255,0.08);margin:1.5rem 0'>
                <p style='font-size:.8rem;color:#62666d'>El Correo de Valdivia · Sistema de Redacción</p>
            </div>";
            enviarCorreo($u['email'], $subject, $msg);
        }
        
        flash('success', 'Solicitud rechazada.');
        header('Location: ' . BASE_URL . '/admin/usuarios.php');
        exit;
    }
    
    if ($action === 'editar') {
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

// Postulaciones pendientes (periodistas no aprobados)
$pendientes = $db->query("
    SELECT u.*, p.experiencia, p.motivacion, p.created_at AS postulado_en
    FROM usuarios u
    LEFT JOIN postulaciones p ON u.id = p.usuario_id
    WHERE u.rol='periodista' AND u.aprobado = 0 AND u.activo = 1
    ORDER BY u.created_at DESC
")->fetchAll();

$usuarios = $db->query("SELECT * FROM usuarios ORDER BY FIELD(aprobado,0) DESC, FIELD(rol,'admin') DESC, nombre ASC")->fetchAll();
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

<?php if (!empty($pendientes)): ?>
<div class="card" style="border-color:rgba(245,158,11,.3);margin-bottom:1.5rem">
    <div class="card-header">
        <h2 style="color:var(--warning)">⏳ Postulaciones pendientes de aprobación</h2>
        <span class="badge badge-pendiente"><?= count($pendientes) ?> pendiente(s)</span>
    </div>
    <?php foreach ($pendientes as $p): ?>
    <div style="background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:1.2rem;margin-bottom:.8rem">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
            <div style="flex:1">
                <h3 style="font-size:1rem;margin-bottom:.3rem"><?= e($p['nombre']) ?></h3>
                <p style="font-size:.8rem;color:var(--text2)"><?= e($p['email']) ?> · Postulado el <?= date('d/m/Y H:i', strtotime($p['postulado_en'] ?? $p['created_at'])) ?></p>
                <?php if ($p['rut']): ?><p style="font-size:.75rem;color:var(--muted)">RUT: <?= e($p['rut']) ?></p><?php endif; ?>
                <?php if ($p['telefono']): ?><p style="font-size:.75rem;color:var(--muted)">📞 <?= e($p['telefono']) ?></p><?php endif; ?>
                <?php if ($p['banco']): ?><p style="font-size:.75rem;color:var(--muted)">🏦 <?= e($p['banco']) ?> · <?= e($p['tipo_cuenta']) ?> · <?= e($p['numero_cuenta']) ?></p><?php endif; ?>
                <?php if ($p['experiencia']): ?>
                <details style="margin-top:.5rem">
                    <summary style="font-size:.8rem;color:var(--accent);cursor:pointer">📋 Ver experiencia</summary>
                    <p style="font-size:.8rem;color:var(--text2);margin-top:.3rem;line-height:1.5"><?= nl2br(e($p['experiencia'])) ?></p>
                </details>
                <?php endif; ?>
                <?php if ($p['motivacion']): ?>
                <details>
                    <summary style="font-size:.8rem;color:var(--accent);cursor:pointer">💭 Ver motivación</summary>
                    <p style="font-size:.8rem;color:var(--text2);margin-top:.3rem;line-height:1.5"><?= nl2br(e($p['motivacion'])) ?></p>
                </details>
                <?php endif; ?>
            </div>
            <div style="display:flex;gap:.5rem;flex-shrink:0">
                <form method="post">
                    <input type="hidden" name="action" value="aprobar">
                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn btn-success btn-sm">✅ Aprobar</button>
                </form>
                <form method="post" onsubmit="return confirm('¿Rechazar esta postulación?')">
                    <input type="hidden" name="action" value="rechazar">
                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm">✕ Rechazar</button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card" id="nuevo-form" style="display:none;max-width:500px;margin-bottom:1.5rem">
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
                <div style="display:flex;flex-direction:column;gap:8px;margin-top:1.5rem">
                    <label style="display:flex;align-items:center;gap:8px">
                        <input type="checkbox" name="activo" value="1" <?= $editUser['activo']?'checked':'' ?>>
                        <span>Usuario activo</span>
                    </label>
                    <?php if ($editUser['rol']==='periodista'): ?>
                    <label style="display:flex;align-items:center;gap:8px">
                        <input type="checkbox" name="aprobado" value="1" <?= $editUser['aprobado']?'checked':'' ?> disabled>
                        <span style="font-size:.8rem;color:var(--muted)">Aprobado: <?= $editUser['aprobado'] ? '✅ Sí' : '⏳ Pendiente' ?></span>
                    </label>
                    <?php endif; ?>
                </div>
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
                    <th>Estado</th>
                    <th>Aprobado</th>
                    <th>RUT</th>
                    <th>Banco</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td><strong><?= e($u['nombre']) ?></strong></td>
                    <td><?= e($u['email']) ?></td>
                    <td><span class="badge badge-<?= $u['rol']==='admin' ? 'revisada' : 'disponible' ?>"><?= $u['rol'] ?></span></td>
                    <td>
                        <?php if ($u['activo']): ?>
                            <span style="color:var(--success)">● Activo</span>
                        <?php else: ?>
                            <span style="color:var(--error)">● Inactivo</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($u['rol'] === 'admin'): ?>
                            <span style="color:var(--muted)">—</span>
                        <?php elseif ($u['aprobado']): ?>
                            <span style="color:var(--success)">✅</span>
                        <?php else: ?>
                            <span style="color:var(--warning)">⏳</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($u['rut'] ?? '—') ?></td>
                    <td><?= e($u['banco'] ?? '—') ?></td>
                    <td>
                        <a href="<?= BASE_URL ?>/admin/usuarios.php?edit=<?= $u['id'] ?>" class="btn btn-secondary btn-xs">Editar</a>
                        <?php if ($u['rol']==='periodista' && !$u['aprobado']): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="action" value="aprobar">
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-success btn-xs">Aprobar</button>
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
