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

if (in_array($h['estado'], ['entregada', 'revisada', 'pagada'])) {
    flash('info', 'Esta historia ya fue entregada.');
    header('Location: ' . BASE_URL . '/periodista/historia.php?id=' . $id);
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $contenido_raw = (string)($_POST['contenido'] ?? '');
    $contenido = sanitizarHTMLEntrega($contenido_raw);
    $imagenes_json = $_POST['imagenes_data'] ?? '[]';
    $firma_nombre = trim($_POST['firma_nombre'] ?? '');
    $firma_rut = trim($_POST['firma_rut'] ?? '');
    $firma_aceptacion = isset($_POST['firma_aceptacion']) ? 1 : 0;

    if (trim(strip_tags($contenido)) === '') {
        $error = 'Debes escribir el contenido de la historia.';
    } elseif (empty($firma_nombre) || empty($firma_rut)) {
        $error = 'Completa tu nombre y RUT para la cesión de derechos.';
    } elseif (!$firma_aceptacion) {
        $error = 'Debes aceptar la cesión de derechos para entregar.';
    } else {
        $imagenes = json_decode($imagenes_json, true);
        if (!is_array($imagenes)) $imagenes = [];
        // Solo URLs propias (mismo origen) en imagenes
        $imagenes = array_values(array_filter($imagenes, function($u) {
            return is_string($u) && (str_starts_with($u, UPLOADS_URL . '/') || str_starts_with($u, BASE_URL . '/uploads/'));
        }));

        $stmt = $db->prepare("INSERT INTO entregas (historia_id, periodista_id, contenido, imagenes) VALUES (?, ?, ?, ?)");
        $stmt->execute([$id, $user_id, $contenido, json_encode($imagenes)]);
        $entrega_id = $db->lastInsertId();

        // Nombre aleatorio para la cesión (no enumerable).
        $token = bin2hex(random_bytes(16));
        $pdf_filename = 'cesion-' . $entrega_id . '-' . $token . '.txt';

        $stmt_doc = $db->prepare("INSERT INTO documentos_cesion (entrega_id, historia_id, periodista_id, pdf_generado, pdf_path, firma_nombre, firma_rut, firma_aceptacion, fecha_firma) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())");
        $stmt_doc->execute([$entrega_id, $id, $user_id, 1, $pdf_filename, $firma_nombre, $firma_rut]);

        // Guardar archivo de cesión FUERA del docroot (private/cesiones).
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
?>

<div class="page-header">
    <div>
        <h1>📝 Entregar: <?= e($h['titulo']) ?></h1>
        <div class="subtitle">
            Foco: <?= nl2br(e(mb_substr($h['foco_periodistico'] ?? $h['descripcion'] ?? '', 0, 100))) ?>
        </div>
    </div>
    <a href="<?= BASE_URL ?>/periodista/historia.php?id=<?= $id ?>" class="btn btn-secondary btn-sm">← Volver</a>
</div>

<?php if ($error): ?>
<div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<form method="post" id="form-entrega">
    <?= csrf_field() ?>
    <div class="card">
        <div class="card-header">
            <h2>📖 Contenido</h2>
            <?php if ($h['extension_esperada']): ?>
            <span class="badge badge-disponible" style="font-size:.7rem"><?= e($h['extension_esperada']) ?></span>
            <?php endif; ?>
        </div>
        
        <!-- Quill Editor -->
        <div class="editor-wrapper">
            <div id="toolbar">
                <span class="ql-formats">
                    <select class="ql-header">
                        <option value="1">Título</option>
                        <option value="2">Subtítulo</option>
                        <option value="3">Encabezado</option>
                        <option value="">Normal</option>
                    </select>
                </span>
                <span class="ql-formats">
                    <button class="ql-bold"></button>
                    <button class="ql-italic"></button>
                    <button class="ql-underline"></button>
                    <button class="ql-strike"></button>
                </span>
                <span class="ql-formats">
                    <button class="ql-list" value="ordered"></button>
                    <button class="ql-list" value="bullet"></button>
                </span>
                <span class="ql-formats">
                    <button class="ql-link"></button>
                    <button class="ql-image"></button>
                    <button class="ql-blockquote"></button>
                </span>
                <span class="ql-formats">
                    <button class="ql-clean"></button>
                </span>
            </div>
            <div id="editor" style="min-height:400px"></div>
        </div>
        <textarea name="contenido" id="contenido-html" style="display:none"></textarea>
        <input type="hidden" name="imagenes_data" id="imagenes-data" value="[]">
        
        <div id="image-preview" style="margin-top:1rem;display:none">
            <p style="font-size:.8rem;color:var(--muted);margin-bottom:.5rem">🖼️ Imágenes subidas:</p>
            <div id="image-list" style="display:flex;gap:.5rem;flex-wrap:wrap"></div>
        </div>
    </div>
    
    <!-- Firma digital -->
    <div class="card">
        <div class="card-header"><h2>✍️ Cesión de Derechos</h2></div>
        
        <div class="firma-box">
            <h3>Documento de Cesión de Derechos</h3>
            <div class="firma-info">
                <p>Al entregar este contenido, aceptas ceder los derechos de publicación a <strong>El Correo de Valdivia</strong>.</p>
                <p style="margin-top:.5rem;font-size:.75rem;color:var(--muted)">
                    Esto incluye el derecho de editar, publicar y distribuir el contenido en todas las plataformas del medio.
                    La cesión no impide que puedas republicar el contenido en tu propio portafolio personal 
                    dando crédito a El Correo de Valdivia como medio original.
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
                    y de mi autoría, y cedo los derechos de publicación a El Correo de Valdivia.
                </span>
            </label>
        </div>
    </div>
    
    <div style="display:flex;gap:1rem;justify-content:flex-end">
        <a href="<?= BASE_URL ?>/periodista/historia.php?id=<?= $id ?>" class="btn btn-secondary">Cancelar</a>
        <button type="submit" class="btn btn-primary" id="btn-entregar" style="padding:.8rem 2rem">
            📤 Entregar Historia
        </button>
    </div>
</form>

<!-- Quill.js -->
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>

<script>
const quill = new Quill('#editor', {
    theme: 'snow',
    modules: {
        toolbar: '#toolbar'
    },
    placeholder: 'Escribe tu historia aquí... Puedes agregar imágenes, enlaces y formato.'
});

// Subida de imágenes personalizada
quill.getModule('toolbar').addHandler('image', function() {
    const input = document.createElement('input');
    input.setAttribute('type', 'file');
    input.setAttribute('accept', 'image/*');
    input.click();
    
    input.onchange = async function() {
        const file = input.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('imagen', file);
        formData.append('_csrf', '<?= csrf_token() ?>');

        try {
            const res = await fetch('<?= BASE_URL ?>/periodista/subir-imagen.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': '<?= csrf_token() ?>' }
            });
            const data = await res.json();
            
            if (data.url) {
                const range = quill.getSelection(true);
                quill.insertEmbed(range.index, 'image', data.url);
                
                // Track images
                const input = document.getElementById('imagenes-data');
                const images = JSON.parse(input.value || '[]');
                images.push(data.url);
                input.value = JSON.stringify(images);
                
                // Show preview
                showImagePreview();
            } else {
                alert('Error al subir la imagen: ' + (data.error || 'desconocido'));
            }
        } catch(e) {
            alert('Error de conexión al subir la imagen');
        }
    };
});

function showImagePreview() {
    const images = JSON.parse(document.getElementById('imagenes-data').value || '[]');
    const container = document.getElementById('image-preview');
    const list = document.getElementById('image-list');
    
    if (images.length === 0) {
        container.style.display = 'none';
        return;
    }
    
    container.style.display = 'block';
    list.innerHTML = images.map((url, i) => 
        `<div style="position:relative;width:80px;height:80px;border-radius:8px;overflow:hidden;border:1px solid var(--border)">
            <img src="${url}" style="width:100%;height:100%;object-fit:cover">
            <span style="position:absolute;bottom:2px;right:2px;font-size:.6rem;background:rgba(0,0,0,.7);padding:1px 5px;border-radius:4px;color:#fff">${i+1}</span>
        </div>`
    ).join('');
}

// Submit handler
document.getElementById('form-entrega').onsubmit = function() {
    const html = quill.root.innerHTML;
    document.getElementById('contenido-html').value = html;
    
    const btn = document.getElementById('btn-entregar');
    btn.disabled = true;
    btn.textContent = '⏳ Enviando...';
    return true;
};
</script>

<style>
/* Override Quill image styles for dark theme */
.ql-editor img { max-width: 100%; border-radius: 8px; margin: 1rem 0; }
</style>

<?php require_once __DIR__ . '/footer.php'; ?>
