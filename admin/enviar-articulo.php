<?php
$titulo = 'Enviar artículo';
require_once __DIR__ . '/header.php';
require_once ROOT_PATH . '/includes/smtp.php';

// ── Conexiones ────────────────────────────────────────────────────────────────
// getSiteDB() se define en includes/config.php

// ── Fetch posts WP REST API ───────────────────────────────────────────────────
function wpPosts(): array {
    $url = 'https://www.elcorreodevaldivia.cl/leer/wp-json/wp/v2/posts?per_page=50&status=publish&_embed=wp:featuredmedia,wp:term';
    $ctx = stream_context_create(['http'=>['timeout'=>10,'ignore_errors'=>true]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw ? json_decode($raw, true) ?? [] : [];
}

// ── Construir email HTML del artículo ────────────────────────────────────────
function buildEmail(array $post, string $modo, array $sus): string {
    $destinatario  = (string)($sus['correo'] ?? '');
    $nombre_sus    = trim((string)($sus['nombre'] ?? ''));
    $primer_nombre = $nombre_sus !== '' ? explode(' ', $nombre_sus)[0] : '';
    $baja_url      = 'https://www.elcorreodevaldivia.cl/baja.php?t=' . rawurlencode((string)($sus['token'] ?? ''));

    $titulo  = html_entity_decode(strip_tags($post['title']['rendered'] ?? ''), ENT_QUOTES, 'UTF-8');
    $excerpt = strip_tags($post['excerpt']['rendered'] ?? '');
    $link    = $post['link'] ?? '';
    $img_url = $post['_embedded']['wp:featuredmedia'][0]['source_url'] ?? '';
    $cat     = $post['_embedded']['wp:term'][0][0]['name'] ?? '';

    // Saludo de apertura (personalizado con el nombre del suscriptor)
    $saludo_block = '<p style="color:#c8ccd4;line-height:1.7;font-size:1rem;margin:0 0 .6rem;font-weight:600">'
        . ($primer_nombre !== '' ? 'Hola ' . htmlspecialchars($primer_nombre, ENT_QUOTES, 'UTF-8') . ' 👋' : 'Hola 👋')
        . '</p>'
        . '<p style="color:#a0a4ab;line-height:1.7;font-size:.9rem;margin:0 0 1.6rem">Te compartimos una nueva historia de <strong style="color:#c8ccd4">El Correo de Valdivia</strong>:</p>';

    // Cierre / despedida
    $cierre_block = '<p style="color:#a0a4ab;line-height:1.7;font-size:.88rem;margin:1.6rem 0 0;padding-top:1.2rem;border-top:1px solid rgba(255,255,255,0.06)">'
        . 'Gracias por acompañarnos. Seguimos haciendo periodismo local, profundo y a tu ritmo.<br>'
        . '<span style="color:#c8ccd4;font-weight:600">— Equipo de El Correo de Valdivia</span></p>';

    if ($modo === 'completo') {
        $cuerpo = $post['content']['rendered'] ?? '';
        // Inline estilos básicos para email
        $cuerpo = preg_replace('/<h([1-6])([^>]*)>/', '<h$1$2 style="font-family:Inter,system-ui,sans-serif;color:#f7f8f8;margin:1.2em 0 .5em;line-height:1.3">', $cuerpo);
        $cuerpo = preg_replace('/<p([^>]*)>/', '<p$1 style="color:#a0a4ab;line-height:1.75;margin:0 0 1em;font-size:.95rem">', $cuerpo);
        $cuerpo = preg_replace('/<blockquote([^>]*)>/', '<blockquote$1 style="border-left:3px solid #5e6ad2;padding:.6rem 0 .6rem 1rem;margin:1em 0;color:#a0a4ab;font-style:italic">', $cuerpo);
        $cuerpo = preg_replace('/<img([^>]*)>/', '<img$1 style="max-width:100%;border-radius:8px;margin:1em 0">', $cuerpo);
    } else {
        $cuerpo = '<p style="color:#a0a4ab;line-height:1.75;font-size:1rem;margin:0">' . htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    $img_block = $img_url ? '
        <tr><td style="padding:0">
          <img src="' . htmlspecialchars($img_url) . '" alt="" width="520"
               style="width:100%;max-width:520px;height:220px;object-fit:cover;display:block;border-radius:10px 10px 0 0;opacity:.85">
        </td></tr>' : '';

    $cat_block = $cat ? '<div style="display:inline-block;background:rgba(94,106,210,.15);border:1px solid rgba(94,106,210,.3);border-radius:9999px;padding:.18rem .7rem;margin-bottom:1rem"><span style="font-family:monospace;font-size:.58rem;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:#828fff">◇ ' . htmlspecialchars($cat) . '</span></div>' : '';

    return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($titulo) . '</title></head>
<body style="margin:0;padding:0;background:#08090a;font-family:Inter,-apple-system,BlinkMacSystemFont,\'Segoe UI\',system-ui,sans-serif;-webkit-font-smoothing:antialiased">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:linear-gradient(135deg,#0d0d1a 0%,#08090a 55%,#0f0a1a 100%);min-height:100vh">
<tr><td align="center" style="padding:3rem 1rem">
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;margin-bottom:1rem">
    <tr>
      <td><span style="font-family:monospace;font-size:.58rem;text-transform:uppercase;letter-spacing:3px;color:#3a3d44">El Correo de Valdivia</span></td>
      <td align="right"><span style="font-family:monospace;font-size:.58rem;text-transform:uppercase;letter-spacing:2px;color:#3a3d44">Periodismo local</span></td>
    </tr>
  </table>
  <table width="100%" cellpadding="0" cellspacing="0" border="0"
         style="max-width:520px;background:#111214;border:1px solid rgba(255,255,255,0.08);border-radius:12px;overflow:hidden">
    ' . $img_block . '
    <tr><td style="padding:2rem 2rem 1.5rem">
      ' . $saludo_block . '
      ' . $cat_block . '
      <h1 style="font-family:Inter,system-ui,sans-serif;font-size:1.4rem;font-weight:700;letter-spacing:-.4px;color:#f7f8f8;margin:0 0 1.2rem;line-height:1.25">' . htmlspecialchars($titulo) . '</h1>
      <div style="margin-bottom:1.5rem">' . $cuerpo . '</div>
      <table cellpadding="0" cellspacing="0" border="0" style="margin-bottom:1.5rem">
        <tr><td style="background:#5e6ad2;border-radius:10px">
          <a href="' . htmlspecialchars($link) . '" style="display:inline-block;padding:.7rem 1.6rem;font-family:Inter,system-ui,sans-serif;font-size:.92rem;font-weight:500;color:#fff;text-decoration:none;white-space:nowrap">
            ' . ($modo === 'completo' ? 'Leer en el sitio →' : 'Leer artículo completo →') . '
          </a>
        </td></tr>
      </table>
      ' . $cierre_block . '
    </td></tr>
    <tr><td style="padding:.8rem 2rem 1.4rem;border-top:1px solid rgba(255,255,255,0.05)">
      <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td><span style="font-family:monospace;font-size:.56rem;text-transform:uppercase;letter-spacing:2px;color:#2a2d33">El Correo de Valdivia · Metanoia TV SpA</span></td>
        </tr>
        <tr><td style="padding-top:.5rem">
          <span style="font-family:monospace;font-size:.54rem;color:#2a2d33">
            Recibiste este correo porque te suscribiste a El Correo de Valdivia.<br>
            <a href="' . htmlspecialchars($baja_url) . '"
               style="color:#5e6ad2;text-decoration:underline">No quiero recibir las alertas de noticia</a>
          </span>
        </td></tr>
      </table>
    </td></tr>
  </table>
  <p style="font-family:monospace;font-size:.56rem;text-transform:uppercase;letter-spacing:2px;color:#2a2d33;margin:1rem 0 0;text-align:center">Sin spam · Sin costo · Solo periodismo</p>
</td></tr>
</table>
</body></html>';
}

// ── Cargar datos ──────────────────────────────────────────────────────────────
$site    = getSiteDB();
$posts   = wpPosts();
$todos_s = $site->query("SELECT id, nombre, correo, token FROM suscriptores WHERE activo = 1 ORDER BY nombre ASC")->fetchAll();

// Asegurar un token único de baja para cada suscriptor que aún no lo tenga
if (array_filter($todos_s, fn($s) => empty($s['token']))) {
    $updTok = $site->prepare("UPDATE suscriptores SET token = ? WHERE id = ?");
    foreach ($todos_s as &$s) {
        if (empty($s['token'])) {
            $s['token'] = bin2hex(random_bytes(16));
            $updTok->execute([$s['token'], $s['id']]);
        }
    }
    unset($s);
}

// IDs preseleccionados (desde suscriptores.php con multi-select)
$presel_ids = array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')));

// ── Procesamiento del envío ───────────────────────────────────────────────────
$resultados = [];
$enviando   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $post_id    = (int)($_POST['wp_post_id'] ?? 0);
    $modo       = $_POST['modo'] ?? 'fragmento';
    $modo       = in_array($modo, ['fragmento', 'completo']) ? $modo : 'fragmento';
    $dest_ids   = array_filter(array_map('intval', (array)($_POST['dest_ids'] ?? [])));
    $todos_dest = !empty($_POST['todos_dest']);

    // Seleccionar post
    $post_sel = null;
    foreach ($posts as $p) {
        if ((int)$p['id'] === $post_id) { $post_sel = $p; break; }
    }

    if (!$post_sel) {
        flash('error', 'Artículo no encontrado.');
        header('Location: ' . BASE_URL . '/admin/enviar-articulo.php');
        exit;
    }

    // Seleccionar destinatarios
    if ($todos_dest) {
        $destinatarios = $todos_s;
    } else {
        $destinatarios = array_filter($todos_s, fn($s) => in_array((int)$s['id'], $dest_ids));
    }

    if (empty($destinatarios)) {
        flash('error', 'No seleccionaste ningún destinatario.');
        header('Location: ' . BASE_URL . '/admin/enviar-articulo.php');
        exit;
    }

    $titulo_art = html_entity_decode(strip_tags($post_sel['title']['rendered'] ?? ''), ENT_QUOTES, 'UTF-8');

    // ── Envío anti-spam con delays y batches ─────────────────────────────────
    set_time_limit(0);          // permitir ejecución larga
    ini_set('max_execution_time', 0);

    $batch_size  = 10;          // correos por lote
    $delay_min   = 700_000;     // microsegundos mín entre correos (0.7s)
    $delay_max   = 1_300_000;   // microsegundos máx entre correos (1.3s)
    $batch_pause = 3_000_000;   // 3s entre lotes

    $destinatarios = array_values($destinatarios);
    $total_dest    = count($destinatarios);

    foreach ($destinatarios as $idx => $s) {
        $html = buildEmail($post_sel, $modo, $s);
        try {
            $ok = enviarEmailMarketing(
                $s['correo'],
                'El Correo de Valdivia: ' . $titulo_art,
                $html,
                '',
                'https://www.elcorreodevaldivia.cl/baja.php?t=' . rawurlencode((string)($s['token'] ?? ''))
            );
            $resultados[] = ['nombre' => $s['nombre'], 'correo' => $s['correo'], 'ok' => $ok];
        } catch (Throwable $e) {
            $resultados[] = ['nombre' => $s['nombre'], 'correo' => $s['correo'], 'ok' => false, 'err' => $e->getMessage()];
            $ok = false;
        }

        // Delay entre correos (no aplica al último)
        if ($idx < $total_dest - 1) {
            $es_fin_batch = (($idx + 1) % $batch_size === 0);
            usleep($es_fin_batch ? $batch_pause : random_int($delay_min, $delay_max));
        }
    }
    $enviando = true;
}
?>

<div class="page-header">
    <div>
        <h1>✉ Enviar artículo</h1>
        <div class="subtitle">Difunde una nota a tus suscriptores por correo</div>
    </div>
    <a href="<?= BASE_URL ?>/admin/suscriptores.php" class="btn btn-secondary">← Suscriptores</a>
</div>

<?php if ($enviando): ?>
<!-- ══════════════ RESULTADOS ══════════════ -->
<?php $ok_count = count(array_filter($resultados, fn($r) => $r['ok']));
      $fail_count = count($resultados) - $ok_count; ?>

<div class="card" style="margin-bottom:1.5rem;border-color:<?= $fail_count === 0 ? 'rgba(39,166,68,.3)' : 'rgba(245,158,11,.3)' ?>">
    <div class="card-header">
        <h2>Resultado del envío</h2>
        <div style="display:flex;gap:.5rem">
            <span class="badge" style="background:rgba(39,166,68,.15);color:var(--success)">✓ <?= $ok_count ?> enviados</span>
            <?php if ($fail_count): ?>
            <span class="badge" style="background:rgba(239,68,68,.15);color:var(--error)">✗ <?= $fail_count ?> fallidos</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Nombre</th><th>Correo</th><th>Estado</th></tr></thead>
            <tbody>
                <?php foreach ($resultados as $r): ?>
                <tr>
                    <td><?= e($r['nombre']) ?></td>
                    <td style="font-size:.85rem;color:var(--text2)"><?= e($r['correo']) ?></td>
                    <td>
                        <?php if ($r['ok']): ?>
                            <span class="badge" style="background:rgba(39,166,68,.15);color:var(--success)">✓ Enviado</span>
                        <?php else: ?>
                            <span class="badge" style="background:rgba(239,68,68,.15);color:var(--error)" title="<?= e($r['err'] ?? '') ?>">✗ Error</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<a href="<?= BASE_URL ?>/admin/enviar-articulo.php" class="btn btn-primary">Enviar otro artículo</a>

<?php else: ?>
<!-- ══════════════ FORMULARIO ══════════════ -->
<form method="post" id="send-form">
    <?= csrf_field() ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start">

    <!-- ── Col izq: Artículo ─────────────────── -->
    <div>
        <div class="card" style="margin-bottom:1.2rem">
            <div class="card-header"><h2>1 · Selecciona el artículo</h2></div>

            <?php if (empty($posts)): ?>
            <p style="color:var(--muted);padding:.5rem 0">No se pudieron cargar los artículos de WordPress.</p>
            <?php else: ?>
            <select name="wp_post_id" id="post-select" class="form-control" required onchange="updatePreview()">
                <option value="">— Elige un artículo —</option>
                <?php foreach ($posts as $p):
                    $t = html_entity_decode(strip_tags($p['title']['rendered'] ?? ''), ENT_QUOTES, 'UTF-8');
                ?>
                <option value="<?= $p['id'] ?>"
                        data-img="<?= htmlspecialchars($p['_embedded']['wp:featuredmedia'][0]['source_url'] ?? '') ?>"
                        data-excerpt="<?= e(strip_tags($p['excerpt']['rendered'] ?? '')) ?>"
                        data-cat="<?= e($p['_embedded']['wp:term'][0][0]['name'] ?? '') ?>"
                        data-link="<?= e($p['link'] ?? '') ?>">
                    <?= e($t) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
        </div>

        <!-- Preview del artículo seleccionado -->
        <div id="post-preview" style="display:none" class="card">
            <div id="prev-img-wrap" style="margin:-1.5rem -1.5rem 1.2rem;border-radius:12px 12px 0 0;overflow:hidden;height:160px;background:var(--surface2)">
                <img id="prev-img" src="" alt="" style="width:100%;height:160px;object-fit:cover;opacity:.8">
            </div>
            <span id="prev-cat" class="badge" style="margin-bottom:.6rem"></span>
            <h3 id="prev-title" style="font-size:1.05rem;font-weight:600;color:var(--text);margin-bottom:.5rem;line-height:1.3"></h3>
            <p  id="prev-exc"   style="font-size:.82rem;color:var(--text2);line-height:1.6;margin-bottom:.8rem"></p>
            <a  id="prev-link"  href="#" target="_blank" style="font-size:.75rem;color:var(--accent)">Ver artículo ↗</a>
        </div>

        <!-- Modo de contenido -->
        <div class="card" style="margin-top:1.2rem">
            <div class="card-header"><h2>3 · Contenido a enviar</h2></div>
            <div style="display:flex;flex-direction:column;gap:.6rem">
                <label style="display:flex;align-items:flex-start;gap:.75rem;padding:.9rem;background:var(--surface2);border:1px solid var(--border);border-radius:10px;cursor:pointer">
                    <input type="radio" name="modo" value="fragmento" checked style="margin-top:.2rem;accent-color:var(--accent)">
                    <div>
                        <div style="font-weight:600;font-size:.9rem">Fragmento</div>
                        <div style="font-size:.78rem;color:var(--text2);margin-top:.15rem">Solo el extracto + botón para leer el artículo completo en el sitio.</div>
                    </div>
                </label>
                <label style="display:flex;align-items:flex-start;gap:.75rem;padding:.9rem;background:var(--surface2);border:1px solid var(--border);border-radius:10px;cursor:pointer">
                    <input type="radio" name="modo" value="completo" style="margin-top:.2rem;accent-color:var(--accent)">
                    <div>
                        <div style="font-weight:600;font-size:.9rem">Artículo completo</div>
                        <div style="font-size:.78rem;color:var(--text2);margin-top:.15rem">El texto completo del artículo va incluido en el correo.</div>
                    </div>
                </label>
            </div>
        </div>
    </div>

    <!-- ── Col der: Destinatarios ────────────── -->
    <div class="card">
        <div class="card-header">
            <h2>2 · Destinatarios</h2>
            <span class="badge" id="dest-count">0 seleccionados</span>
        </div>

        <!-- Opción "Todos" -->
        <label style="display:flex;align-items:center;gap:.75rem;padding:.85rem 1rem;background:rgba(94,106,210,.08);border:1px solid rgba(94,106,210,.25);border-radius:10px;cursor:pointer;margin-bottom:1rem">
            <input type="checkbox" name="todos_dest" id="todos-chk" value="1"
                   style="width:17px;height:17px;accent-color:var(--accent)" onchange="toggleTodos(this)">
            <div>
                <div style="font-weight:600;font-size:.9rem;color:var(--accent-h)">Enviar a todos</div>
                <div style="font-size:.75rem;color:var(--text2)"><?= count($todos_s) ?> suscriptores</div>
            </div>
        </label>

        <!-- Buscador de destinatarios -->
        <input type="text" id="dest-search" placeholder="Buscar suscriptor…"
               class="form-control" style="margin-bottom:.75rem" oninput="filterDest(this.value)">

        <!-- Lista de suscriptores -->
        <div id="dest-list" style="max-height:380px;overflow-y:auto;display:flex;flex-direction:column;gap:2px">
            <?php foreach ($todos_s as $s): ?>
            <label class="dest-row" style="display:flex;align-items:center;gap:.7rem;padding:.5rem .65rem;border-radius:8px;cursor:pointer;transition:background .12s"
                   onmouseover="this.style.background='var(--surface2)'" onmouseout="this.style.background=''">
                <input type="checkbox" name="dest_ids[]"
                       value="<?= $s['id'] ?>"
                       class="dest-chk"
                       <?= in_array((int)$s['id'], $presel_ids) ? 'checked' : '' ?>
                       style="width:16px;height:16px;flex-shrink:0;accent-color:var(--accent)"
                       onchange="updateDestCount()">
                <div style="min-width:0">
                    <div style="font-size:.87rem;font-weight:500;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($s['nombre']) ?></div>
                    <div style="font-size:.75rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($s['correo']) ?></div>
                </div>
            </label>
            <?php endforeach; ?>
        </div>

        <div style="border-top:1px solid var(--border);margin-top:.8rem;padding-top:.8rem;display:flex;gap:.5rem">
            <button type="button" class="btn btn-secondary btn-xs" onclick="selAll(true)">Seleccionar todos</button>
            <button type="button" class="btn btn-secondary btn-xs" onclick="selAll(false)">Deseleccionar</button>
        </div>
    </div>

    </div><!-- /grid -->

    <!-- Info anti-spam -->
    <div style="margin-top:1.2rem;padding:.85rem 1rem;background:rgba(94,106,210,.07);border:1px solid rgba(94,106,210,.2);border-radius:10px;font-size:.8rem;color:var(--text2);line-height:1.6">
        <strong style="color:var(--accent-h)">📬 Envío responsable:</strong>
        Los correos se envían con un intervalo de ~1 segundo entre cada uno para evitar filtros de spam.
        Se agrupan en lotes de 10 con pausa de 3s entre lotes.
        Cada correo incluye un link de desuscripción y los headers <code style="font-size:.75rem;color:var(--muted)">List-Unsubscribe</code> / <code style="font-size:.75rem;color:var(--muted)">Precedence: bulk</code>.
    </div>

    <!-- Botón enviar -->
    <div style="margin-top:1.2rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary" id="btn-send" style="padding:.65rem 2rem;font-size:1rem"
                onclick="return confirmSend()">
            ✉ Enviar ahora
        </button>
        <span id="send-hint" style="font-size:.82rem;color:var(--muted)">
            Selecciona un artículo y al menos un destinatario.
        </span>
    </div>
</form>

<!-- Overlay de progreso durante el envío -->
<div id="sending-overlay" style="display:none;position:fixed;inset:0;background:rgba(8,9,10,.9);z-index:9999;align-items:center;justify-content:center;flex-direction:column;gap:1.5rem;backdrop-filter:blur(8px)">
    <div style="text-align:center">
        <div style="font-size:2rem;margin-bottom:1rem;animation:spin 2s linear infinite">📨</div>
        <h2 style="font-family:'Geist',sans-serif;font-size:1.4rem;font-weight:600;color:#f7f8f8;margin-bottom:.5rem">Enviando correos…</h2>
        <p style="font-size:.9rem;color:#a0a4ab;margin-bottom:1.5rem">No cierres esta ventana. El proceso puede tardar varios segundos.</p>

        <!-- Barra de progreso animada indeterminada -->
        <div style="width:320px;height:6px;background:rgba(255,255,255,.1);border-radius:3px;overflow:hidden;margin:0 auto 1rem">
            <div id="overlay-bar" style="height:100%;background:linear-gradient(90deg,#5e6ad2,#828fff,#5e6ad2);background-size:200%;border-radius:3px;animation:shimmer 1.5s linear infinite"></div>
        </div>

        <p id="overlay-count" style="font-family:monospace;font-size:.7rem;color:#62666d;letter-spacing:.05em"></p>
    </div>
</div>

<style>
@keyframes spin    { to { transform: rotate(360deg); } }
@keyframes shimmer { 0%{background-position:100%} 100%{background-position:-100%} }
</style>
<script>
document.getElementById('send-form').addEventListener('submit', function(e) {
    if (!confirmSend()) { e.preventDefault(); return; }
    const n     = document.getElementById('todos-chk').checked
                  ? <?= count($todos_s) ?>
                  : [...document.querySelectorAll('.dest-chk:checked')].length;
    const modo  = document.querySelector('input[name="modo"]:checked')?.value || 'fragmento';
    const secs  = Math.ceil(n * 1 + Math.ceil(n / 10) * 3);
    document.getElementById('overlay-count').textContent =
        n + ' destinatario' + (n!==1?'s':'') + ' · modo ' + modo + ' · ~' + secs + 's estimados';
    document.getElementById('sending-overlay').style.display = 'flex';
});
</script>

<script>
// ── Preview del artículo ─────────────────────────────────────────
function updatePreview() {
    const sel = document.getElementById('post-select');
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) { document.getElementById('post-preview').style.display = 'none'; return; }

    const img  = opt.dataset.img;
    const wrap = document.getElementById('prev-img-wrap');
    const imgEl= document.getElementById('prev-img');
    if (img) { imgEl.src = img; wrap.style.display = ''; }
    else     { wrap.style.display = 'none'; }

    document.getElementById('prev-cat').textContent   = opt.dataset.cat || '';
    document.getElementById('prev-title').textContent  = opt.text;
    document.getElementById('prev-exc').textContent    = opt.dataset.excerpt || '';
    document.getElementById('prev-link').href          = opt.dataset.link || '#';
    document.getElementById('post-preview').style.display = '';
    updateHint();
}

// ── Todos los destinatarios ──────────────────────────────────────
function toggleTodos(chk) {
    document.querySelectorAll('.dest-chk').forEach(c => { c.checked = chk.checked; c.disabled = chk.checked; });
    updateDestCount();
}

function updateDestCount() {
    const todosChk = document.getElementById('todos-chk');
    const n = todosChk.checked
        ? <?= count($todos_s) ?>
        : [...document.querySelectorAll('.dest-chk:checked')].length;
    document.getElementById('dest-count').textContent = n + ' seleccionado' + (n !== 1 ? 's' : '');
    updateHint();
}

function selAll(v) {
    document.getElementById('todos-chk').checked = false;
    document.querySelectorAll('.dest-chk').forEach(c => { c.checked = v; c.disabled = false; });
    updateDestCount();
}

// ── Buscar en la lista ───────────────────────────────────────────
function filterDest(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.dest-row').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

// ── Hint y confirmación ──────────────────────────────────────────
function updateHint() {
    const postOk = !!document.getElementById('post-select').value;
    const todosChk = document.getElementById('todos-chk');
    const destOk = todosChk.checked || [...document.querySelectorAll('.dest-chk:checked')].length > 0;
    const n = todosChk.checked ? <?= count($todos_s) ?> : [...document.querySelectorAll('.dest-chk:checked')].length;
    const hint = document.getElementById('send-hint');
    if (!postOk) hint.textContent = 'Selecciona un artículo primero.';
    else if (!destOk) hint.textContent = 'Selecciona al menos un destinatario.';
    else hint.textContent = 'Listo para enviar a ' + n + ' destinatario' + (n !== 1 ? 's' : '') + '.';
}

function confirmSend() {
    const sel  = document.getElementById('post-select');
    if (!sel.value) { alert('Selecciona un artículo.'); return false; }
    const todosChk = document.getElementById('todos-chk');
    const n = todosChk.checked ? <?= count($todos_s) ?> : [...document.querySelectorAll('.dest-chk:checked')].length;
    if (n === 0) { alert('Selecciona al menos un destinatario.'); return false; }
    const modo = document.querySelector('input[name="modo"]:checked').value;
    return confirm('¿Enviar el artículo en modo "' + modo + '" a ' + n + ' suscriptor' + (n!==1?'es':'') + '?\n\nEsta acción enviará correos reales.');
}

// Aplicar preselección si viene desde suscriptores
<?php if ($presel_ids): ?>
updateDestCount();
<?php endif; ?>
updateDestCount();
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
