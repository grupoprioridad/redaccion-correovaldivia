<?php
$titulo = 'Entregar Historia';
require_once __DIR__ . '/header.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$user_id = $_SESSION['usuario_id'];
$user = usuarioActual();

$historia = $db->prepare("SELECT * FROM historias WHERE id = ? AND periodista_asignado = ?");
$historia->execute([$id, $user_id]);
$h = $historia->fetch();

if (!$h) {
    flash('error', 'Historia no encontrada.');
    header('Location: ' . BASE_URL . '/periodista/index.php');
    exit;
}

// ── Parsear extensión esperada ────────────────────────────────────────────────
function parsearExtension(?string $ext): array {
    if (!$ext || trim($ext) === '') return ['min' => 0, 'max' => 0, 'tipo' => 'caracteres'];
    $lower = strtolower(trim($ext));
    $tipo  = str_contains($lower, 'palabra') ? 'palabras' : 'caracteres';
    preg_match_all('/\d+/', $lower, $m);
    $nums = array_map('intval', $m[0]);
    return ['min' => $nums[0] ?? 0, 'max' => $nums[1] ?? 0, 'tipo' => $tipo];
}
$ext_info = parsearExtension($h['extension_esperada']);

// Bloquear si ya fue revisada o pagada
if (in_array($h['estado'], ['revisada', 'pagada'])) {
    flash('info', 'Esta historia ya fue revisada y no puede modificarse.');
    header('Location: ' . BASE_URL . '/periodista/historia.php?id=' . $id);
    exit;
}

$modo_edicion = ($h['estado'] === 'entregada');

// Verificar si el admin tiene la historia abierta en este momento
$admin_lock = null;
if ($modo_edicion) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS historia_locks (historia_id INT NOT NULL PRIMARY KEY, admin_id INT NOT NULL, locked_at DATETIME NOT NULL)");
        $lockStmt = $db->prepare("SELECT admin_id FROM historia_locks WHERE historia_id = ? AND locked_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $lockStmt->execute([$id]);
        $admin_lock = $lockStmt->fetchColumn();
    } catch (Throwable $e) {}
}

if ($admin_lock) {
    flash('warning', 'El administrador tiene esta historia abierta en este momento. Intenta nuevamente en unos minutos.');
    header('Location: ' . BASE_URL . '/periodista/historia.php?id=' . $id);
    exit;
}

// Cargar entrega existente para modo edición
$entrega_existente = null;
if ($modo_edicion) {
    $entStmt = $db->prepare("SELECT * FROM entregas WHERE historia_id = ? AND periodista_id = ? ORDER BY created_at DESC LIMIT 1");
    $entStmt->execute([$id, $user_id]);
    $entrega_existente = $entStmt->fetch();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $contenido_raw = (string)($_POST['contenido'] ?? '');
    $contenido = sanitizarHTMLEntrega($contenido_raw);
    $imagenes_json = $_POST['imagenes_data'] ?? '[]';

    $texto_plano = trim(strip_tags($contenido));
    $n_chars = mb_strlen(preg_replace('/\s+/', '', $texto_plano));
    $n_words = $texto_plano === '' ? 0 : count(preg_split('/\s+/', $texto_plano, -1, PREG_SPLIT_NO_EMPTY));
    $n_actual = $ext_info['tipo'] === 'palabras' ? $n_words : $n_chars;

    if ($texto_plano === '') {
        $error = 'Debes escribir el contenido de la historia.';
    } elseif ($ext_info['min'] > 0 && $n_actual < $ext_info['min']) {
        $unidad = $ext_info['tipo'] === 'palabras' ? 'palabras' : 'caracteres';
        $error  = "Tu historia tiene {$n_actual} {$unidad} y el mínimo requerido es {$ext_info['min']} {$unidad}. Por favor, desarrolla más el contenido.";
    } elseif (!$modo_edicion && (empty(trim($_POST['firma_nombre'] ?? '')) || empty(trim($_POST['firma_rut'] ?? '')))) {
        $error = 'Completa tu nombre y RUT para la cesión de derechos.';
    } elseif (!$modo_edicion && !isset($_POST['firma_aceptacion'])) {
        $error = 'Debes aceptar la cesión de derechos para entregar.';
    } else {
        $imagenes = json_decode($imagenes_json, true);
        if (!is_array($imagenes)) $imagenes = [];
        $imagenes = array_values(array_filter($imagenes, function($u) {
            return is_string($u) && (str_starts_with($u, UPLOADS_URL . '/') || str_starts_with($u, BASE_URL . '/uploads/'));
        }));

        if ($modo_edicion && $entrega_existente) {
            // Actualizar entrega existente. Reseteamos el estado a 'pendiente_revision'
            // para que una re-entrega tras un rechazo vuelva a la cola de revisión;
            // de lo contrario la entrega queda 'rechazado' y la aprobación posterior
            // (que solo toca filas 'pendiente_revision') no la marca como aprobada,
            // impidiendo la exportación a WordPress.
            $db->prepare("UPDATE entregas SET contenido=?, imagenes=?, estado='pendiente_revision', notas_revision=NULL WHERE id=?")->execute([$contenido, json_encode($imagenes), $entrega_existente['id']]);

            try {
                $db->prepare("DELETE FROM borradores WHERE historia_id=? AND periodista_id=?")->execute([$id, $user_id]);
            } catch (Throwable $e) {}

            flash('success', '✅ Entrega actualizada correctamente.');
            header('Location: ' . BASE_URL . '/periodista/historia.php?id=' . $id);
            exit;
        }

        // Entrega nueva
        $firma_nombre = trim($_POST['firma_nombre'] ?? '');
        $firma_rut    = trim($_POST['firma_rut'] ?? '');

        $stmt = $db->prepare("INSERT INTO entregas (historia_id, periodista_id, contenido, imagenes) VALUES (?, ?, ?, ?)");
        $stmt->execute([$id, $user_id, $contenido, json_encode($imagenes)]);
        $entrega_id = $db->lastInsertId();

        $token = bin2hex(random_bytes(16));
        $pdf_filename = 'cesion-' . $entrega_id . '-' . $token . '.txt';

        $stmt_doc = $db->prepare("INSERT INTO documentos_cesion (entrega_id, historia_id, periodista_id, pdf_generado, pdf_path, firma_nombre, firma_rut, firma_aceptacion, fecha_firma) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())");
        $stmt_doc->execute([$entrega_id, $id, $user_id, 1, $pdf_filename, $firma_nombre, $firma_rut]);

        if (!is_dir(CESIONES_PATH)) @mkdir(CESIONES_PATH, 0750, true);
        $pdf_content  = "=== CESION DE DERECHOS DE CONTENIDO ===\n\n";
        $pdf_content .= "Por la presente, yo, {$firma_nombre}, RUT {$firma_rut},\n";
        $pdf_content .= "cedo los derechos del contenido titulado \"" . str_replace('"', "'", $h['titulo']) . "\"\n";
        $pdf_content .= "a El Correo de Valdivia, para su publicacion y distribucion.\n\n";
        $pdf_content .= "Fecha de cesion: " . date('d/m/Y H:i') . "\n";
        $pdf_content .= "ID de entrega: {$entrega_id}\n\n";
        $pdf_content .= "Firmante: {$firma_nombre}\n";
        $pdf_content .= "RUT: {$firma_rut}\n";
        $pdf_content .= "Aceptacion digital: Si\n";
        file_put_contents(CESIONES_PATH . '/' . $pdf_filename, $pdf_content);
        @chmod(CESIONES_PATH . '/' . $pdf_filename, 0640);

        $db->prepare("UPDATE historias SET estado = 'entregada' WHERE id = ?")->execute([$id]);

        try {
            $db->prepare("DELETE FROM borradores WHERE historia_id=? AND periodista_id=?")->execute([$id, $user_id]);
        } catch (Throwable $e) {}

        $admin = $db->query("SELECT email FROM usuarios WHERE rol='admin' AND activo=1 LIMIT 1")->fetch();
        if ($admin) {
            $titSafe    = e($h['titulo']);
            $nombreSafe = e($user['nombre']);
            $idSafe     = (int)$id;
            $subject = "Entrega recibida: " . preg_replace('/[\r\n]+/', ' ', mb_substr($h['titulo'], 0, 100));
            $msg = "<p><strong>{$nombreSafe}</strong> ha entregado la historia <strong>{$titSafe}</strong>.</p>";
            $msg .= "<p><a href='" . BASE_URL . "/admin/historia-editar.php?id={$idSafe}'>Revisar entrega</a></p>";
            enviarCorreo($admin['email'], $subject, $msg);
        }

        flash('success', '✅ Historia entregada exitosamente. Cesión de derechos registrada.');
        header('Location: ' . BASE_URL . '/periodista/historia.php?id=' . $id);
        exit;
    }
}

// Cargar borrador guardado (si existe)
$borrador = null;
try {
    $borrStmt = $db->prepare("SELECT contenido, imagenes, updated_at FROM borradores WHERE historia_id=? AND periodista_id=?");
    $borrStmt->execute([$id, $user_id]);
    $borrador = $borrStmt->fetch();
} catch (Throwable $e) {
    // Tabla no existe aún; el primer auto-guardado la creará
}
?>

<div class="page-header">
    <div>
        <h1><?= $modo_edicion ? '✏️ Editar entrega' : '📝 Entregar' ?>: <?= e($h['titulo']) ?></h1>
        <div class="subtitle">
            Foco: <?= nl2br(e(mb_substr($h['foco_periodistico'] ?? $h['descripcion'] ?? '', 0, 100))) ?>
        </div>
    </div>
    <a href="<?= BASE_URL ?>/periodista/historia.php?id=<?= $id ?>" class="btn btn-secondary btn-sm">← Volver</a>
</div>

<?php if ($modo_edicion): ?>
<div class="alert alert-info">Estás editando una historia ya entregada. Tus cambios reemplazarán el contenido anterior. La cesión de derechos original sigue vigente.</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($borrador && trim(strip_tags($borrador['contenido'])) !== ''): ?>
<div id="borrador-banner" class="alert alert-info" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
    <span>Borrador recuperado — guardado el <?= date('d/m/Y H:i', strtotime($borrador['updated_at'])) ?>. Tu trabajo fue restaurado automáticamente.</span>
    <button type="button" onclick="descartarBorrador()" style="background:none;border:1px solid currentColor;padding:.25rem .75rem;border-radius:6px;cursor:pointer;color:inherit;font-size:.8rem;white-space:nowrap">Descartar y empezar de cero</button>
</div>
<?php endif; ?>

<form method="post" id="form-entrega">
    <?= csrf_field() ?>
    <div class="card">
        <div class="card-header" style="flex-wrap:wrap;gap:.6rem">
            <h2>📖 Contenido</h2>
            <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-left:auto">
                <?php if ($h['extension_esperada']): ?>
                <span class="badge badge-disponible" style="font-size:.7rem"><?= e($h['extension_esperada']) ?></span>
                <?php endif; ?>
                <!-- Contador compacto en el header (siempre visible) -->
                <div id="cnt-header" style="display:flex;align-items:center;gap:.5rem;font-family:'Geist Mono',monospace;font-size:.72rem">
                    <span id="cnt-h-num" style="font-weight:700;color:var(--text)">0</span>
                    <span id="cnt-h-tipo" style="color:var(--muted)"><?= $ext_info['tipo'] === 'palabras' ? 'palabras' : 'caracteres' ?></span>
                    <?php if ($ext_info['min'] > 0): ?>
                    <span style="color:var(--border)">·</span>
                    <span id="cnt-h-min" style="font-size:.65rem;color:var(--muted)">mín <?= number_format($ext_info['min'], 0, ',', '.') ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Barra de progreso sticky debajo del header -->
        <?php if ($ext_info['min'] > 0): ?>
        <div style="position:sticky;top:60px;z-index:10;background:var(--surface);padding:.5rem 1.5rem;border-bottom:1px solid var(--border);margin:0 -1.5rem">
            <div style="display:flex;align-items:center;gap:.75rem">
                <div style="flex:1;background:rgba(0,0,0,.3);border-radius:4px;height:5px;overflow:hidden">
                    <div id="cnt-bar" style="height:100%;border-radius:4px;width:0%;transition:width .3s,background .3s;background:#ef4444"></div>
                </div>
                <span id="cnt-estado" style="font-size:.7rem;font-weight:600;white-space:nowrap;min-width:160px;text-align:right;color:#ef4444"></span>
            </div>
        </div>
        <?php endif; ?>

        <!-- TinyMCE Editor -->
        <div style="margin:0 -1.5rem">
            <textarea id="editor" style="visibility:hidden"></textarea>
        </div>
        <div style="display:flex;align-items:center;justify-content:flex-end;margin-top:.5rem;min-height:1.4rem">
            <span id="autosave-indicator" style="font-size:.75rem;color:var(--muted);opacity:0;transition:opacity .6s"></span>
        </div>
        <textarea name="contenido" id="contenido-html" style="display:none"></textarea>
        <?php
            // En modo edición, las imágenes base son las de la entrega existente; el borrador las sobreescribe si existe
            $imgs_init = '[]';
            if ($modo_edicion && $entrega_existente) {
                $imgs_init = $entrega_existente['imagenes'] ?? '[]';
            }
            if (is_array($borrador) && !empty($borrador['imagenes'])) {
                $imgs_init = $borrador['imagenes'];
            }
        ?>
        <input type="hidden" name="imagenes_data" id="imagenes-data" value="<?= e($imgs_init) ?>">
    </div>

    <?php if (!$modo_edicion): ?>
    <!-- Firma digital -->
    <div class="card">
        <div class="card-header"><h2>✍️ Cesión de Derechos</h2></div>

        <div class="firma-box">
            <h3>Documento de Cesión de Derechos</h3>
            <div class="firma-info">
                <p>Al entregar este contenido, aceptas ceder los derechos de publicación a <strong>El Correo de Valdivia</strong>.</p>
                <p style="margin-top:.5rem;font-size:.75rem;color:var(--muted)">
                    Esto incluye el derecho de editar, publicar y distribuir el contenido en todas las plataformas del medio.
                    El contenido es de exclusiva propiedad de El Correo de Valdivia. El periodista no puede republicarlo sin autorización expresa del medio.
                    El Correo de Valdivia se compromete a mantener tu autoría en todas las publicaciones.
                </p>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="firma_nombre">Nombre completo *</label>
                    <input type="text" id="firma_nombre" name="firma_nombre"
                           value="<?= e($user['nombre']) ?>" required
                           placeholder="Tu nombre completo">
                </div>
                <div class="form-group">
                    <label for="firma_rut">RUT *</label>
                    <input type="text" id="firma_rut" name="firma_rut"
                           value="<?= e($user['rut'] ?? '') ?>" required
                           placeholder="Ej: 12.345.678-9">
                </div>
            </div>

            <label class="firma-check">
                <input type="checkbox" name="firma_aceptacion" value="1" required>
                <span class="firma-text">
                    Acepto la cesión de derechos del contenido presentado. Confirmo que el trabajo es original
                    y es de mi autoría. Cedo los derechos de publicación en forma exclusiva a El Correo de Valdivia, quien se compromete a mantener mi autoría en todas las difusiones del contenido.
                </span>
            </label>
        </div>
    </div>
    <?php endif; ?>

    <div style="display:flex;gap:1rem;justify-content:flex-end">
        <a href="<?= BASE_URL ?>/periodista/historia.php?id=<?= $id ?>" class="btn btn-secondary">Cancelar</a>
        <button type="submit" class="btn btn-primary" id="btn-entregar" style="padding:.8rem 2rem">
            <?= $modo_edicion ? '💾 Guardar cambios' : '📤 Entregar Historia' ?>
        </button>
    </div>
</form>

<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>

<script>
const EXT_MIN  = <?= (int)$ext_info['min'] ?>;
const EXT_MAX  = <?= (int)$ext_info['max'] ?>;
const EXT_TIPO = <?= json_encode($ext_info['tipo']) ?>;

let csrfToken  = '<?= csrf_token() ?>';
const historiaId = <?= $id ?>;

function updateCsrf(token) {
    csrfToken = token;
    const f = document.querySelector('input[name="_csrf"]');
    if (f) f.value = token;
}

// ── Contador de extensión ─────────────────────────────────────────
function actualizarContador() {
    const ed = tinymce.get('editor');
    const texto = ed ? ed.getContent({ format: 'text' }) : '';
    const palabras = texto.trim() === '' ? 0 : texto.trim().split(/\s+/).filter(w => w).length;
    const chars    = texto.replace(/\s/g, '').length;
    const actual   = EXT_TIPO === 'palabras' ? palabras : chars;

    const hNum = document.getElementById('cnt-h-num');
    if (hNum) hNum.textContent = actual.toLocaleString('es-CL');

    if (EXT_MIN > 0) {
        const pct = Math.min(100, Math.round((actual / EXT_MIN) * 100));
        const bar = document.getElementById('cnt-bar');
        const est = document.getElementById('cnt-estado');
        let col, msg;
        if (actual >= EXT_MIN) {
            col = '#27a644';
            msg = EXT_MAX > 0 && actual > EXT_MAX
                ? '⚠ Superado el máximo (' + EXT_MAX.toLocaleString('es-CL') + ')'
                : '✓ Mínimo alcanzado';
        } else if (pct >= 70) {
            col = '#f59e0b';
            msg = 'Faltan ' + (EXT_MIN - actual).toLocaleString('es-CL') + ' ' + EXT_TIPO;
        } else {
            col = '#ef4444';
            msg = 'Faltan ' + (EXT_MIN - actual).toLocaleString('es-CL') + ' ' + EXT_TIPO;
        }
        if (bar) { bar.style.width = pct + '%'; bar.style.background = col; }
        if (est) { est.textContent = msg; est.style.color = col; }
        if (hNum) hNum.style.color = col;
    }
}

// ── Subida de imágenes ────────────────────────────────────────────
function trackImage(url) {
    const inp  = document.getElementById('imagenes-data');
    const imgs = JSON.parse(inp.value || '[]');
    if (!imgs.includes(url)) { imgs.push(url); inp.value = JSON.stringify(imgs); }
}

// TinyMCE envía blobs con nombre sin extensión (ej: "blobid0").
// Derivamos la extensión del MIME type para que pasen la validación del servidor.
const mimeToExt = { 'image/jpeg': '.jpg', 'image/png': '.png', 'image/gif': '.gif', 'image/webp': '.webp' };

function tinyUploadHandler(blobInfo) {
    return new Promise((resolve, reject) => {
        const blob     = blobInfo.blob();
        const origName = blobInfo.filename() || 'imagen';
        const ext      = mimeToExt[blob.type] || '.jpg';
        const filename = origName.includes('.') ? origName : origName + ext;

        const fd = new FormData();
        fd.append('imagen', blob, filename);
        fd.append('_csrf', csrfToken);
        fetch('<?= BASE_URL ?>/periodista/subir-imagen', {
            method: 'POST', body: fd, credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(data => {
            if (data.url) { trackImage(data.url); resolve(data.url); }
            else reject({ message: data.error || 'Error al subir imagen', remove: true });
        })
        .catch(() => reject({ message: 'Error de conexión al servidor', remove: true }));
    });
}

// Selector de archivos nativo para el botón de imagen en la toolbar
function filePickerCallback(cb, value, meta) {
    if (meta.filetype !== 'image') return;
    const input = document.createElement('input');
    input.type  = 'file';
    input.accept = 'image/jpeg,image/png,image/gif,image/webp';
    input.onchange = function() {
        const file = input.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(e) {
            const id      = 'img_' + Date.now();
            const blobCache = tinymce.activeEditor.editorUpload.blobCache;
            const base64  = e.target.result.split(',')[1];
            const blobInfo = blobCache.create(id, file, base64);
            blobCache.add(blobInfo);
            cb(blobInfo.blobUri(), { title: file.name });
        };
        reader.readAsDataURL(file);
    };
    input.click();
}

// ── TinyMCE init ──────────────────────────────────────────────────
tinymce.init({
    selector: '#editor',
    base_url: 'https://cdn.jsdelivr.net/npm/tinymce@7',
    suffix: '.min',
    height: 580,
    skin: 'oxide-dark',
    content_css: 'dark',
    menubar: 'edit insert format table',
    statusbar: true,
    branding: false,
    resize: true,
    plugins: 'advlist lists link image table searchreplace fullscreen wordcount quickbars',
    toolbar: 'undo redo | blocks | bold italic underline strikethrough | ' +
             'alignleft aligncenter alignright alignjustify | ' +
             'bullist numlist | blockquote | link image table | searchreplace fullscreen',
    block_formats: 'Párrafo=p; Título 1=h1; Subtítulo=h2; Encabezado=h3',
    // Toolbar flotante al seleccionar texto
    quickbars_selection_toolbar: 'bold italic | blocks | link blockquote',
    // Toolbar flotante al hacer clic en una imagen
    quickbars_image_toolbar: 'alignleft aligncenter alignright | imageoptions',
    // Imagen
    images_upload_handler: tinyUploadHandler,
    automatic_uploads: true,
    file_picker_types: 'image',
    file_picker_callback: filePickerCallback,
    image_title: true,
    image_caption: true,
    image_advtab: true,
    image_dimensions: true,
    // Tabla
    table_resize_bars: true,
    table_style_by_css: true,
    // Contenido
    content_style: [
        'body { font-family: Georgia, "Times New Roman", serif; font-size: 17px;',
        '       line-height: 1.85; padding: 1.5rem 2rem; max-width: 780px; margin: 0 auto; }',
        'h1 { font-size: 1.65rem; margin: 1.5rem 0 .5rem; font-family: sans-serif; }',
        'h2 { font-size: 1.3rem; margin: 1.3rem 0 .4rem; font-family: sans-serif; }',
        'h3 { font-size: 1.1rem; margin: 1.1rem 0 .4rem; font-family: sans-serif; }',
        'blockquote { border-left: 3px solid #5e6ad2; margin: 1.2rem 2rem; padding: .6rem 1.2rem;',
        '             color: #a0a4ab; font-style: italic; }',
        // Imagen centrada (por defecto)
        'img { max-width: 100%; border-radius: 6px; margin: 1rem auto; display: block; }',
        // Imagen alineada a la izquierda con texto flotando
        'img.align-left, figure.align-left { float: left; margin: .5rem 1.4rem 1rem 0;',
        '    max-width: 50%; border-radius: 6px; }',
        // Imagen alineada a la derecha con texto flotando
        'img.align-right, figure.align-right { float: right; margin: .5rem 0 1rem 1.4rem;',
        '    max-width: 50%; border-radius: 6px; }',
        // Imagen centrada
        'figure.align-center { display: block; text-align: center; margin: 1rem auto; clear: both; }',
        'figure { display: table; }',
        'figure figcaption { display: table-caption; caption-side: bottom;',
        '    font-size: .8rem; color: #888; text-align: center; padding: .3rem 0; font-style: italic; }',
        'table { width: 100%; border-collapse: collapse; margin: 1rem 0; }',
        'td, th { border: 1px solid #444; padding: .5rem .75rem; }',
        'th { background: #2a2b2e; font-weight: 600; }',
        'a { color: #5e6ad2; }',
        'p { margin: 0 0 .9rem; }',
        '.mce-content-body[data-mce-placeholder]:not(.mce-visualblocks)::before { color: #555; }'
    ].join(' '),
    placeholder: 'Escribe tu historia aquí… Haz clic en el ícono de imagen para insertar fotos dentro del texto.',
    setup: function(editor) {
        editor.on('init', function() {
            <?php if ($borrador && trim(strip_tags($borrador['contenido'])) !== ''): ?>
            editor.setContent(<?= json_encode($borrador['contenido']) ?>);
            <?php elseif ($modo_edicion && $entrega_existente && trim(strip_tags($entrega_existente['contenido'])) !== ''): ?>
            editor.setContent(<?= json_encode($entrega_existente['contenido']) ?>);
            <?php endif; ?>
            actualizarContador();
        });
        editor.on('input keyup change SetContent Paste', actualizarContador);
    }
});

// ── Auto-guardado cada 30 s ───────────────────────────────────────
let lastSaved = '';

async function autoSave() {
    const ed = tinymce.get('editor');
    if (!ed) return;
    const html = ed.getContent();
    const imgs = document.getElementById('imagenes-data').value;
    const fd   = new FormData();
    fd.append('_csrf', csrfToken);
    fd.append('historia_id', historiaId);
    fd.append('contenido', html);
    fd.append('imagenes', imgs);
    try {
        const res  = await fetch('<?= BASE_URL ?>/periodista/borrador.php', {
            method: 'POST', body: fd, credentials: 'same-origin'
        });
        const data = await res.json();
        if (data.ok) {
            if (data.csrf) updateCsrf(data.csrf);
            if (html !== lastSaved) {
                lastSaved = html;
                const el = document.getElementById('autosave-indicator');
                if (el) {
                    el.textContent = 'Guardado ' + new Date().toLocaleTimeString('es-CL', {hour:'2-digit', minute:'2-digit'});
                    el.style.opacity = '1';
                    setTimeout(() => { el.style.opacity = '0'; }, 3000);
                }
            }
        }
    } catch(e) {}
}

setInterval(autoSave, 30000);

// ── Descartar borrador ────────────────────────────────────────────
async function descartarBorrador() {
    if (!confirm('¿Descartar el borrador y empezar desde cero?')) return;
    try {
        const fd = new FormData();
        fd.append('_csrf', csrfToken);
        fd.append('historia_id', historiaId);
        fd.append('action', 'delete');
        await fetch('<?= BASE_URL ?>/periodista/borrador.php', {method:'POST', body:fd, credentials:'same-origin'});
    } catch(e) {}
    const ed = tinymce.get('editor');
    if (ed) ed.setContent('');
    document.getElementById('imagenes-data').value = '[]';
    const banner = document.getElementById('borrador-banner');
    if (banner) banner.remove();
    actualizarContador();
}

// ── Submit ────────────────────────────────────────────────────────
document.getElementById('form-entrega').addEventListener('submit', async function(e) {
    e.preventDefault();

    const ed    = tinymce.get('editor');
    const texto = ed ? ed.getContent({ format: 'text' }) : '';
    if (EXT_MIN > 0) {
        const palabras = texto.trim() === '' ? 0 : texto.trim().split(/\s+/).filter(w => w).length;
        const chars    = texto.replace(/\s/g, '').length;
        const actual   = EXT_TIPO === 'palabras' ? palabras : chars;
        if (actual < EXT_MIN) {
            const faltan = EXT_MIN - actual;
            alert(`Tu historia tiene ${actual.toLocaleString('es-CL')} ${EXT_TIPO} y el mínimo requerido es ${EXT_MIN.toLocaleString('es-CL')}.\n\nFaltan ${faltan.toLocaleString('es-CL')} ${EXT_TIPO} para poder entregar.`);
            document.getElementById('cnt-bar')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
    }

    document.getElementById('contenido-html').value = ed ? ed.getContent() : '';
    const btn = document.getElementById('btn-entregar');
    btn.disabled = true;
    btn.textContent = '⏳ Enviando...';
    try {
        const fd = new FormData();
        fd.append('_csrf', csrfToken);
        fd.append('historia_id', historiaId);
        fd.append('action', 'delete');
        await fetch('<?= BASE_URL ?>/periodista/borrador.php', {method:'POST', body:fd, credentials:'same-origin'});
    } catch(e) {}
    this.submit();
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
