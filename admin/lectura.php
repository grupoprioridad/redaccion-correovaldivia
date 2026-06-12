<?php
$titulo = 'Lectura';
require_once __DIR__ . '/../includes/auth.php';
require_once ROOT_PATH . '/includes/wordpress-export.php';
requerirAdmin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: ' . BASE_URL . '/admin/index.php');
    exit;
}

$db = getDB();
$entrega = $db->prepare("
    SELECT e.*, h.titulo, h.codigo, h.estado as historia_estado,
           u.nombre as periodista_nombre, u.email as periodista_email,
           c.nombre as categoria_nombre
    FROM entregas e
    JOIN historias h ON h.id = e.historia_id
    JOIN usuarios u ON u.id = e.periodista_id
    LEFT JOIN categorias_redaccion c ON c.id = h.categoria_id
    WHERE e.id = ?
");
$entrega->execute([$id]);
$ent = $entrega->fetch();

if (!$ent) {
    header('Location: ' . BASE_URL . '/admin/index.php');
    exit;
}

$dbh = $db; // alias for wp functions

// Save edited content
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar') {
    csrf_verify();
    $contenido = $_POST['contenido'] ?? '';
    $db->prepare("UPDATE entregas SET contenido = ? WHERE id = ?")->execute([$contenido, $id]);
    flash('success', 'Contenido guardado.');
    header('Location: ' . BASE_URL . '/admin/lectura.php?id=' . $id);
    exit;
}

require_once __DIR__ . '/../includes/security.php';
$contenido_html = sanitizarHTMLEntrega($ent['contenido'] ?? '');
$estado_class = $ent['estado'];
$wp_url = wp_config_get('wp_url');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=5.0">
<title><?= e($ent['titulo']) ?> · Lectura · El Correo de Valdivia</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700&family=Geist+Mono:wght@400;500&family=IBM+Plex+Serif:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&display=swap" rel="stylesheet">
<style>
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0 }
html { font-size:18px }
body {
    font-family:'IBM Plex Serif',Georgia,'Times New Roman',serif;
    background:#08090a;
    color:#e8e8ea;
    line-height:1.8;
    -webkit-font-smoothing:antialiased;
    padding:env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);
}
.reader-container {
    max-width:740px;
    margin:0 auto;
    padding:2rem 1.5rem 6rem;
}
.reader-header {
    margin-bottom:2.5rem;
    padding-bottom:1.5rem;
    border-bottom:1px solid rgba(255,255,255,.06);
}
.reader-title {
    font-family:'Geist',system-ui,sans-serif;
    font-size:1.8rem;
    font-weight:700;
    line-height:1.25;
    letter-spacing:-.5px;
    color:#f7f8f8;
    margin-bottom:.8rem;
}
.reader-meta {
    display:flex;
    flex-wrap:wrap;
    gap:.6rem 1.2rem;
    font-family:'Geist',system-ui,sans-serif;
    font-size:.7rem;
    color:#62666d;
    margin-bottom:.5rem;
}
.reader-meta span { display:inline-flex; align-items:center; gap:.35rem }
.reader-meta .badge {
    display:inline-block;
    padding:.15rem .55rem;
    border-radius:9999px;
    font-size:.58rem;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:.05em;
}
.badge-pendiente_revision { background:rgba(234,179,8,.15); color:#eab308 }
.badge-aprobado { background:rgba(34,197,94,.15); color:#22c55e }
.badge-rechazado { background:rgba(239,68,68,.15); color:#ef4444 }
.reader-actions {
    display:flex;
    gap:.5rem;
    flex-wrap:wrap;
    margin-top:1rem;
    font-family:'Geist',system-ui,sans-serif;
}
.btn {
    display:inline-flex;
    align-items:center;
    gap:.4rem;
    padding:.45rem 1rem;
    border-radius:8px;
    font-size:.65rem;
    font-weight:500;
    font-family:'Geist',system-ui,sans-serif;
    text-decoration:none;
    cursor:pointer;
    border:none;
    transition:all .12s;
}
.btn-primary { background:#5e6ad2; color:#fff }
.btn-primary:hover { background:#4f5ac7 }
.btn-secondary { background:rgba(255,255,255,.08); color:#a0a4ab }
.btn-secondary:hover { background:rgba(255,255,255,.12); color:#f7f8f8 }
.btn-success { background:#22c55e; color:#fff }
.btn-danger { background:#ef4444; color:#fff }
.btn-sm { padding:.35rem .75rem; font-size:.6rem }
.reader-content {
    font-size:1.05rem;
    color:#d4d4d8;
    line-height:2;
}
.reader-content p { margin:0 0 1.2em }
.reader-content h1,
.reader-content h2,
.reader-content h3,
.reader-content h4 {
    font-family:'Geist',system-ui,sans-serif;
    color:#f7f8f8;
    font-weight:600;
    line-height:1.3;
    margin:1.8em 0 .6em;
}
.reader-content h1 { font-size:1.5rem }
.reader-content h2 { font-size:1.3rem }
.reader-content h3 { font-size:1.15rem }
.reader-content blockquote {
    border-left:3px solid #5e6ad2;
    padding:.5rem 0 .5rem 1.2rem;
    margin:1.2em 0;
    color:#a0a4ab;
    font-style:italic;
    background:rgba(94,106,210,.04);
    border-radius:0 6px 6px 0;
}
.reader-content ul, .reader-content ol { margin:0 0 1.2em; padding-left:1.5em }
.reader-content li { margin-bottom:.3em }
.reader-content a { color:#828fff; text-decoration:underline; text-underline-offset:2px }
.reader-content a:hover { color:#a5b0ff }
.reader-content img {
    max-width:100%;
    height:auto;
    border-radius:8px;
    margin:1.5em 0;
    display:block;
}
.reader-content hr {
    border:none;
    height:1px;
    background:rgba(255,255,255,.06);
    margin:2em 0;
}
.reader-content pre, .reader-content code {
    font-family:'Geist Mono',monospace;
    font-size:.82rem;
    background:rgba(255,255,255,.04);
    border-radius:4px;
}
.reader-content pre { padding:.8rem; overflow-x:auto; margin-bottom:1.2em }
.reader-content code { padding:.15rem .3rem }
.reader-navbar {
    position:fixed;
    top:0;
    left:0;
    right:0;
    z-index:100;
    background:rgba(8,9,10,.9);
    backdrop-filter:blur(12px);
    -webkit-backdrop-filter:blur(12px);
    border-bottom:1px solid rgba(255,255,255,.06);
    padding:.5rem 1rem;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:.5rem;
    font-family:'Geist',system-ui,sans-serif;
}
.reader-navbar .nav-left { display:flex; align-items:center; gap:.5rem; min-width:0 }
.reader-navbar .nav-title {
    font-size:.7rem;
    color:#62666d;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    max-width:200px;
}
.reader-navbar .nav-right { display:flex; align-items:center; gap:.4rem; flex-shrink:0 }
.reader-navbar .nav-back {
    color:#a0a4ab;
    text-decoration:none;
    font-size:.65rem;
    padding:.3rem .6rem;
    border-radius:6px;
    display:flex;
    align-items:center;
    gap:.3rem;
}
.reader-navbar .nav-back:hover { background:rgba(255,255,255,.08); color:#f7f8f8 }

/* Edit mode */
.edit-textarea {
    width:100%;
    min-height:60vh;
    font-family:'Geist Mono',monospace;
    font-size:.82rem;
    line-height:1.7;
    color:#d4d4d8;
    background:#111214;
    border:1px solid rgba(255,255,255,.1);
    border-radius:8px;
    padding:1rem;
    resize:vertical;
}
.edit-textarea:focus { outline:none; border-color:#5e6ad2 }
.edit-bar {
    display:flex;
    gap:.5rem;
    align-items:center;
    margin-top:.8rem;
    flex-wrap:wrap;
}
.edit-hint { font-size:.6rem; color:#62666d; font-family:'Geist',system-ui,sans-serif }

/* iPad / responsive */
@media (max-width:768px) {
    html { font-size:16px }
    .reader-container { padding:1.2rem 1rem 5rem }
    .reader-title { font-size:1.5rem }
    .reader-content { font-size:1rem }
    .reader-navbar .nav-title { max-width:120px }
}
@media (min-width:1024px) {
    .reader-container { padding-top:3.5rem }
}
</style>
</head>
<body>

<!-- Top navbar -->
<div class="reader-navbar">
    <div class="nav-left">
        <a href="<?= BASE_URL ?>/admin/historia-editar.php?id=<?= (int)$ent['historia_id'] ?>" class="nav-back">← Volver</a>
        <span class="nav-title"><?= e($ent['titulo']) ?></span>
    </div>
    <div class="nav-right">
        <button class="btn btn-secondary btn-sm" id="toggle-edit" onclick="toggleEdit()">✏️ Editar</button>
        <a href="<?= BASE_URL ?>/admin/historia-editar.php?id=<?= (int)$ent['historia_id'] ?>" class="btn btn-secondary btn-sm">📋 Administrar</a>
    </div>
</div>

<div class="reader-container">

    <!-- Header -->
    <div class="reader-header">
        <div class="reader-meta">
            <span>📝 <?= e($ent['periodista_nombre']) ?></span>
            <span>📅 <?= date('d/m/Y H:i', strtotime($ent['fecha_entrega'])) ?></span>
            <?php if ($ent['categoria_nombre']): ?>
            <span>🏷️ <?= e($ent['categoria_nombre']) ?></span>
            <?php endif; ?>
            <span class="badge badge-<?= $estado_class ?>"><?= str_replace('_', ' ', $estado_class) ?></span>
            <?php if ($ent['codigo']): ?>
            <span>#<?= e($ent['codigo']) ?></span>
            <?php endif; ?>
        </div>
        <h1 class="reader-title"><?= e($ent['titulo']) ?></h1>
    </div>

    <!-- Reading mode -->
    <div id="reading-mode">
        <div class="reader-content">
            <?= $contenido_html ?>
        </div>
    </div>

    <!-- Edit mode (hidden by default) -->
    <div id="edit-mode" style="display:none">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="guardar">
            <textarea name="contenido" class="edit-textarea"><?= e($ent['contenido'] ?? '') ?></textarea>
            <div class="edit-bar">
                <button type="submit" class="btn btn-primary">💾 Guardar cambios</button>
                <button type="button" class="btn btn-secondary" onclick="toggleEdit()">Cancelar</button>
                <span class="edit-hint">Puedes editar el HTML directamente. Usa etiquetas semánticas (p, h2, blockquote, etc.).</span>
            </div>
        </form>
    </div>

</div>

<script>
function toggleEdit() {
    const reading = document.getElementById('reading-mode');
    const editing = document.getElementById('edit-mode');
    const btn = document.getElementById('toggle-edit');
    const isEditing = editing.style.display !== 'none';
    reading.style.display = isEditing ? '' : 'none';
    editing.style.display = isEditing ? 'none' : '';
    btn.textContent = isEditing ? '✏️ Editar' : '👁️ Vista lectura';
}
</script>

</body>
</html>
