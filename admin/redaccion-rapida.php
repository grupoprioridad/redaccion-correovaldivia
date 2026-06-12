<?php
$titulo = 'Redacción Rápida';
require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . '/includes/wordpress-export.php';

$db = getDB();
$categorias = $db->query("SELECT id, nombre FROM categorias_redaccion WHERE activo=1 ORDER BY nombre")->fetchAll();
$wp_activo = wp_config_get('export_activo') === '1';
$wp_status = wp_config_get('exportar_como') ?: 'draft';

function groq_rapida(string $prompt, float $temp = 0.7): string {
    $api_key = defined('GROQ_API_KEY') ? GROQ_API_KEY : '';
    if (!$api_key) return '';
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'llama-3.3-70b-versatile',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => $temp,
            'max_tokens' => 4096,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http_code !== 200 || !$response) return '';
    $data = json_decode($response, true);
    return trim($data['choices'][0]['message']['content'] ?? '');
}

function wp_publicar_directo(string $titulo, string $html, string $extracto, ?int $categoria_id, array $imagenes, PDO $db): array {
    $wp_base = rtrim(wp_config_get('wp_url'), '/');
    $wp_user = wp_config_get('wp_user');
    $wp_pass = wp_config_get('wp_app_password');
    $status  = wp_config_get('exportar_como') ?: 'draft';
    if (!$wp_base || !$wp_user || !$wp_pass) {
        return ['ok' => false, 'mensaje' => 'WordPress no configurado. Ve a WordPress en el menú.'];
    }
    $auth = base64_encode($wp_user . ':' . $wp_pass);

    foreach ($imagenes as $img) {
        if (!file_exists($img['tmp_path'])) continue;
        $nueva_url = wp_subir_imagen($img['tmp_path'], $img['name'], $wp_base, $auth);
        if ($nueva_url) {
            $html = str_replace($img['local_url'], $nueva_url, $html);
        }
    }

    $post = [
        'title'   => $titulo,
        'content' => $html,
        'status'  => $status,
        'excerpt' => $extracto ?: '',
    ];

    if ($categoria_id) {
        $st = $db->prepare("SELECT nombre, slug FROM categorias_redaccion WHERE id=?");
        $st->execute([$categoria_id]);
        $c = $st->fetch();
        if ($c) {
            $cat_id = wp_obtener_o_crear_categoria($c['nombre'], $c['slug'], $wp_base, $auth);
            if ($cat_id) $post['categories'] = [$cat_id];
        }
    }

    $ch = curl_init($wp_base . '/wp-json/wp/v2/posts');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($post),
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . $auth,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 201) {
        $json = json_decode($resp, true);
        return ['ok' => true, 'mensaje' => 'Publicado', 'wp_post_id' => $json['id'] ?? null, 'link' => $json['link'] ?? ''];
    }
    $json = json_decode($resp, true);
    return ['ok' => false, 'mensaje' => 'WP error (HTTP ' . $code . '): ' . ($json['message'] ?? mb_substr($resp, 0, 200))];
}

// Acciones IA via AJAX - deben ejecutarse ANTES de cualquier salida HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['accion_ia'])) {
    require_once __DIR__ . '/../includes/auth.php';
    requerirAdmin();
    csrf_verify();
    $action = $_POST['accion_ia'];
    $tit = trim($_POST['titulo'] ?? '');
    $texto = trim($_POST['contenido'] ?? '');
    $categoria_id = (int)($_POST['categoria_id'] ?? 0);
    $resultado = '';
    $error = '';

    switch ($action) {
        case 'sugerir_titulo':
            $src = ($texto ?: $tit) ?: 'Sin contenido';
            $p = "Eres un editor de un diario local chileno. Basado en este texto, genera EXACTAMENTE 3 títulos atractivos (max 100 chars c/u). Responde SOLO JSON: {\"titulos\":[\"t1\",\"t2\",\"t3\"]}\n\nTexto:\n" . mb_substr($src, 0, 3000);
            $raw = groq_rapida($p, 0.8);
            $j = json_decode($raw, true);
            $resultado = ($j && isset($j['titulos'])) ? json_encode($j) : '';
            if (!$resultado) $error = 'No se generaron títulos.';
            break;
        case 'mejorar':
            $p = "Eres editor de El Correo de Valdivia. Mejora este texto noticioso: corrige ortografía, mejora redacción, estructura en párrafos. Mantén tono periodístico. Responde SOLO con el texto mejorado.\n\nTexto:\n" . mb_substr($texto, 0, 6000);
            $resultado = groq_rapida($p, 0.5);
            if (!$resultado) $error = 'Error al mejorar texto.';
            break;
        case 'extracto':
            $src = ($texto ?: $tit) ?: 'Sin contenido';
            $p = "Genera un extracto de max 2 oraciones (160 chars) para este artículo. Responde SOLO el extracto.\n\nTexto:\n" . mb_substr($src, 0, 3000);
            $resultado = groq_rapida($p, 0.5);
            if (!$resultado) $error = 'Error al generar extracto.';
            break;
        case 'formatear':
            $p = "Convierte este texto plano en HTML semántico limpio para artículo de noticias. Usa <h2>,<h3>,<p>,<blockquote>,<ul>,<ol>. NUNCA <h1>. Sin CSS, sin clases, sin ```html. Responde SOLO el HTML.\n\nTexto:\n" . mb_substr($texto, 0, 8000);
            $resultado = groq_rapida($p, 0.4);
            if (!$resultado) $error = 'Error al formatear.';
            break;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($error ? ['error' => $error] : ['resultado' => $resultado]);
    exit;
}

// Envío a WordPress
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['accion_ia'])) {
    require_once __DIR__ . '/../includes/auth.php';
    requerirAdmin();
    csrf_verify();
    $tit = trim($_POST['titulo'] ?? '');
    $html = trim($_POST['html_generado'] ?? '');
    $extracto = trim($_POST['extracto'] ?? '');
    $categoria_id = (int)($_POST['categoria_id'] ?? 0);
    $imagenes = [];

    if (!empty($_FILES['imagenes']['tmp_name'][0])) {
        foreach ($_FILES['imagenes']['tmp_name'] as $idx => $tmp) {
            if (empty($tmp) || !is_uploaded_file($tmp)) continue;
            $nombre = $_FILES['imagenes']['name'][$idx];
            $mime = mime_content_type($tmp);
            if (!$mime || !str_starts_with($mime, 'image/')) continue;
            $dest = ROOT_PATH . '/uploads/rapida/' . bin2hex(random_bytes(8)) . '_' . basename($nombre);
            if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
            if (move_uploaded_file($tmp, $dest)) {
                $local_url = UPLOADS_URL . '/rapida/' . basename($dest);
                $imagenes[] = ['tmp_path' => $dest, 'name' => $nombre, 'local_url' => $local_url];
                $html = str_replace("__IMG_{$idx}__", '<img src="' . $local_url . '" alt="' . e($nombre) . '" style="max-width:100%;height:auto;border-radius:8px;margin:1em 0">', $html);
            }
        }
    }

    if (empty($tit) || empty($html)) {
        require_once __DIR__ . '/header.php';
        flash('error', 'Título y contenido obligatorios.');
    } else {
        $r = wp_publicar_directo($tit, $html, $extracto, $categoria_id, $imagenes, $db);
        require_once __DIR__ . '/header.php';
        $mensaje = $r['ok']
            ? 'Articulo enviado a WordPress como ' . $wp_status . ($r['link'] ? '. <a href="' . e($r['link']) . '" target="_blank" style="color:var(--accent)">Ver en WP</a>' : '')
            : $r['mensaje'];
        flash($r['ok'] ? 'success' : 'error', $mensaje);
    }
} else {
    require_once __DIR__ . '/header.php';
}
?>
<div class="page-header">
    <div>
        <h1>Redacción Rápida</h1>
        <div class="subtitle">Escribe y publica directamente a WordPress con ayuda de IA</div>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center">
        <?php if ($wp_activo): ?>
            <span class="badge" style="background:rgba(34,197,94,.15);color:#22c55e">WP conectado</span>
        <?php else: ?>
            <a href="<?= BASE_URL ?>/admin/wordpress.php" class="btn btn-secondary btn-xs" style="font-size:.75rem">Configurar WP</a>
        <?php endif; ?>
    </div>
</div>

<form method="post" enctype="multipart/form-data" id="form-rapida">
    <?= csrf_field() ?>

    <div style="display:grid;grid-template-columns:1fr 320px;gap:1.5rem;align-items:start">

    <!-- Columna principal: editor -->
    <div>
        <div class="card" style="margin-bottom:1rem">
            <div class="card-header">
                <h2>Título</h2>
                <button type="button" class="btn btn-secondary btn-xs ia-btn" data-accion="sugerir_titulo" title="Sugerir títulos con IA">
                    ✨ Sugerir
                </button>
            </div>
            <input type="text" name="titulo" id="campo-titulo" class="form-control"
                   placeholder="Título de la noticia…" value="<?= e($_POST['titulo'] ?? '') ?>"
                   style="font-size:1.2rem;font-weight:600">
            <div id="sugerencias-titulo" style="margin-top:.5rem;display:none"></div>
        </div>

        <div class="card" style="margin-bottom:1rem">
            <div class="card-header">
                <h2>Contenido</h2>
                <div style="display:flex;gap:.4rem">
                    <button type="button" class="btn btn-secondary btn-xs ia-btn" data-accion="mejorar" title="Mejorar texto con IA">✨ Mejorar</button>
                    <button type="button" class="btn btn-secondary btn-xs ia-btn" data-accion="formatear" title="Auto-formatear a HTML">🔧 Formatear</button>
                    <button type="button" class="btn btn-secondary btn-xs ia-btn" data-accion="extracto" title="Generar extracto">📝 Extracto</button>
                </div>
            </div>
            <textarea name="contenido" id="campo-contenido" class="form-control" rows="18"
                      placeholder="Escribe o pega aquí el texto de la noticia...&#10;&#10;IA disponible:&#10;✨ Sugerir título&#10;✨ Mejorar texto&#10;🔧 Auto-formatear a HTML&#10;📝 Generar extracto"><?= e($_POST['contenido'] ?? '') ?></textarea>
            <div class="hint" style="margin-top:.4rem">Escribe en texto plano, luego usa IA para mejorar o formatear.</div>
        </div>

        <div class="card" style="margin-bottom:1rem">
            <div class="card-header">
                <h2>HTML final</h2>
                <div style="display:flex;gap:.4rem;align-items:center">
                    <button type="button" class="btn btn-secondary btn-xs" onclick="togglePreview()" id="btn-preview">👁️ Vista previa</button>
                    <button type="button" class="btn btn-secondary btn-xs ia-btn" data-accion="formatear">🔧 Auto-formatear</button>
                </div>
            </div>
            <div id="html-editor-wrap">
                <textarea name="html_generado" id="campo-html" class="form-control code" rows="10"
                          placeholder="HTML del artículo. Usa el botón 🔧 Auto-formatear para generarlo desde el texto plano."><?= e($_POST['html_generado'] ?? '') ?></textarea>
            </div>
            <div id="html-preview-wrap" style="display:none">
                <div id="html-preview" style="padding:1.5rem;font-family:Georgia,'Times New Roman',serif;font-size:1.1rem;line-height:1.9;color:#d4d4d8;background:#0d0e10;border-radius:8px;border:1px solid rgba(255,255,255,.06);min-height:200px"></div>
                <div style="margin-top:.6rem;font-size:.6rem;color:var(--muted);font-family:'Geist',sans-serif;display:flex;gap:1rem;flex-wrap:wrap">
                    <span>📱 Vista previa aproximada del artículo en WordPress</span>
                    <span class="preview-close" style="cursor:pointer;color:var(--accent)" onclick="togglePreview()">← Volver al HTML</span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Imágenes</h2>
                <span style="font-size:.75rem;color:var(--muted)">Se subirán automáticamente a WP</span>
            </div>
            <input type="file" name="imagenes[]" multiple accept="image/*" class="form-control" style="padding:.5rem">
            <div class="hint" style="margin-top:.3rem">Usa <code>__IMG_0__</code>, <code>__IMG_1__</code> etc. en el HTML para insertar.</div>
        </div>
    </div>

    <!-- Columna derecha: metadatos + publicar -->
    <div>
        <div class="card" style="margin-bottom:1rem">
            <div class="card-header"><h2>Extracto</h2></div>
            <textarea name="extracto" id="campo-extracto" class="form-control" rows="3"
                      placeholder="Extracto corto para WP..."><?= e($_POST['extracto'] ?? '') ?></textarea>
        </div>

        <div class="card" style="margin-bottom:1rem">
            <div class="card-header"><h2>Categoría</h2></div>
            <select name="categoria_id" class="form-control">
                <option value="">Sin categoría</option>
                <?php foreach ($categorias as $c): ?>
                <option value="<?= $c['id'] ?>" <?= ($_POST['categoria_id']??'')==(string)$c['id']?'selected':'' ?>><?= e($c['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="card" style="margin-bottom:1rem">
            <div class="card-header"><h2>Atajos IA</h2></div>
            <div style="display:flex;flex-direction:column;gap:.5rem">
                <button type="button" class="btn btn-secondary btn-sm ia-btn w-100" data-accion="sugerir_titulo">✨ Sugerir título</button>
                <button type="button" class="btn btn-secondary btn-sm ia-btn w-100" data-accion="mejorar">✨ Mejorar texto</button>
                <button type="button" class="btn btn-secondary btn-sm ia-btn w-100" data-accion="formatear">🔧 Formatear HTML</button>
                <button type="button" class="btn btn-secondary btn-sm ia-btn w-100" data-accion="extracto">📝 Generar extracto</button>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>Publicar</h2></div>
            <p style="font-size:.8rem;color:var(--muted);margin-bottom:1rem">
                Se enviará a WordPress como <strong><?= $wp_status ?></strong>.
            </p>
            <button type="submit" class="btn btn-primary w-100" style="padding:.7rem;font-size:1rem">
                📤 Enviar a WordPress
            </button>
            <?php if ($wp_activo): ?>
            <div style="margin-top:.5rem;font-size:.75rem;color:var(--success);text-align:center">✓ WP configurado</div>
            <?php endif; ?>
        </div>
    </div>

    </div>
</form>

<div id="ia-overlay" style="display:none;position:fixed;inset:0;background:rgba(8,9,10,.85);z-index:9999;align-items:center;justify-content:center;flex-direction:column;gap:1rem;backdrop-filter:blur(8px)">
    <div style="font-size:2rem;animation:pulse 1.5s ease-in-out infinite">🤖</div>
    <p style="color:#f7f8f8;font-size:1.1rem;font-weight:500">Procesando con IA...</p>
</div>

<style>
@keyframes pulse { 0%,100%{transform:scale(1);opacity:1} 50%{transform:scale(1.15);opacity:.6} }
#campo-html.code { font-family:'Geist Mono',monospace;font-size:.82rem;color:#a0a4ab }
.w-100 { width:100% }
.hint { font-size:.75rem;color:var(--muted);line-height:1.4 }
.sug-titulo { padding:.6rem .8rem;cursor:pointer;border-radius:8px;transition:background .12s;font-size:.95rem;color:var(--text) }
.sug-titulo:hover { background:var(--accent);color:#fff }
</style>

<script>
document.querySelectorAll('.ia-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const accion = this.dataset.accion;
        const titulo = document.getElementById('campo-titulo').value.trim();
        const contenido = document.getElementById('campo-contenido').value.trim();

        if (accion !== 'sugerir_titulo' && !contenido) {
            alert('Escribe contenido primero.');
            return;
        }

        const overlay = document.getElementById('ia-overlay');
        overlay.style.display = 'flex';

        const form = document.getElementById('form-rapida');
        const fd = new FormData(form);
        fd.set('accion_ia', accion);

        try {
            const res = await fetch(window.location.href, { method: 'POST', body: fd });
            const data = await res.json();

            if (data.error) {
                alert('Error: ' + data.error);
                return;
            }

            const resultado = data.resultado;

            switch (accion) {
                case 'sugerir_titulo': {
                    const parsed = JSON.parse(resultado);
                    const div = document.getElementById('sugerencias-titulo');
                    div.innerHTML = parsed.titulos.map((t, i) =>
                        '<div class="sug-titulo" onclick="document.getElementById(\'campo-titulo\').value=this.textContent;document.getElementById(\'sugerencias-titulo\').style.display=\'none\'">' +
                        t + '</div>'
                    ).join('');
                    div.style.display = 'block';
                    break;
                }
                case 'mejorar':
                    document.getElementById('campo-contenido').value = resultado;
                    break;
                case 'formatear':
                    document.getElementById('campo-html').value = resultado;
                    break;
                case 'extracto':
                    document.getElementById('campo-extracto').value = resultado;
                    break;
            }
        } catch (e) {
            alert('Error de conexión: ' + e.message);
        } finally {
            overlay.style.display = 'none';
        }
    });
});

function togglePreview() {
    const editor = document.getElementById('html-editor-wrap');
    const preview = document.getElementById('html-preview-wrap');
    const btn = document.getElementById('btn-preview');
    const html = document.getElementById('campo-html').value;
    if (preview.style.display === 'none') {
        updatePreview();
        editor.style.display = 'none';
        preview.style.display = 'block';
        btn.textContent = '✏️ Editar HTML';
    } else {
        preview.style.display = 'none';
        editor.style.display = 'block';
        btn.textContent = '👁️ Vista previa';
    }
}
function updatePreview() {
    const html = document.getElementById('campo-html').value;
    document.getElementById('html-preview').innerHTML = html;
}
document.getElementById('campo-html').addEventListener('input', updatePreview);
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
