<?php
$titulo = 'Proponer Historias';
require_once __DIR__ . '/header.php';

$db = getDB();

// ── Acciones POST ──────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // Generar propuestas vía Groq
    if ($action === 'generar') {
        $archivo = ROOT_PATH . '/datos/noticias_scrapeadas.json';
        if (!file_exists($archivo)) {
            flash('error', 'No hay noticias scrapeadas. Ejecuta el scraper primero.');
            header('Location: ' . BASE_URL . '/admin/proponer.php');
            exit;
        }

        $noticias = json_decode(file_get_contents($archivo), true) ?: [];
        if (!is_array($noticias) || empty($noticias)) {
            flash('error', 'El archivo de noticias está vacío. Ejecuta el scraper.');
            header('Location: ' . BASE_URL . '/admin/proponer.php');
            exit;
        }

        // Seleccionar hasta 20 noticias al azar para dar variedad al prompt
        shuffle($noticias);
        $seleccion = array_slice($noticias, 0, 20);

        $lista_noticias = "";
        foreach ($seleccion as $n) {
            $fuente = e($n['fuente'] ?? 'Desconocido');
            $titulo = e($n['titulo'] ?? '');
            $resumen = e($n['resumen'] ?? '');
            $lista_noticias .= "- [{$fuente}] {$titulo}" . ($resumen ? ": {$resumen}" : "") . "\n";
        }

        $prompt = <<<PROMPT
Eres un editor jefe de El Correo de Valdivia, un medio de periodismo lento, profundo e investigativo de la Región de Los Ríos, Chile.

Tu trabajo es analizar noticias de otros medios y proponer ideas de historias originales para profundizar los temas desde una perspectiva única de El Correo de Valdivia.

A continuación tienes noticias recientes de la región:

{$lista_noticias}

Genera exactamente 10 propuestas de historias. Para cada una:
1. **titulo**: Título tentativo atractivo (máx 100 chars)
2. **foco**: Enfoque periodístico detallado (2-3 párrafos breves)
3. **extension**: Extensión sugerida (ej: "800-1200 palabras", "1200-1800 palabras", "Serie de 3 entregas")
4. **inspiracion**: Qué noticia(s) de la lista inspiraron esta propuesta

IMPORTANTE:
- Las propuestas deben ser originales, no simples resúmenes de las noticias
- Piensa en ángulos que otros medios no cubren: contexto histórico, datos locales, voces no escuchadas, impacto comunitario
- Prefiere temas que importen a Valdivia y la Región de Los Ríos
- Varía entre política, cultura, medio ambiente, economía, sociedad, turismo

Responde SOLO con un JSON válido en este formato exacto:
{"propuestas": [{"titulo": "...", "foco": "...", "extension": "...", "inspiracion": "..."}]}

No incluyas nada más que el JSON.
PROMPT;

        $api_key = defined('GROQ_API_KEY') ? GROQ_API_KEY : '';
        $url_api = 'https://api.groq.com/openai/v1/chat/completions';

        $payload = json_encode([
            'model' => 'llama-3.1-70b-versatile',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.8,
            'max_tokens' => 4096,
        ]);

        $ch = curl_init($url_api);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key,
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $propuestas = [];

        if ($http_code === 200 && $response) {
            $data = json_decode($response, true);
            $content = $data['choices'][0]['message']['content'] ?? '';
            // Intentar parsear JSON de la respuesta
            $content = trim($content);
            // A veces Groq envuelve en ```json ... ```
            if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $m)) {
                $content = $m[1];
            }
            $parsed = json_decode($content, true);
            if ($parsed && isset($parsed['propuestas']) && is_array($parsed['propuestas'])) {
                $propuestas = $parsed['propuestas'];
            } else {
                // Fallback: intentar parsear el JSON directamente
                $parsed2 = json_decode($content, true);
                if ($parsed2 && isset($parsed2['propuestas'])) {
                    $propuestas = $parsed2['propuestas'];
                }
            }
        }

        if (empty($propuestas)) {
            $error_msg = "Error generando propuestas. HTTP $http_code";
            if ($error) $error_msg .= " - $error";
            if ($response) $error_msg .= " - " . substr($response, 0, 300);
            flash('error', $error_msg);
        } else {
            // Guardar propuestas en sesión
            $_SESSION['propuestas_generadas'] = $propuestas;
            
            // Guardar en BD
            $stmt = $db->prepare("INSERT INTO propuestas_ia (titulo, foco, extension_sugerida, inspiracion, fuente_origen) VALUES (?, ?, ?, ?, 'IA Generativa')");
            foreach ($propuestas as $p) {
                $stmt->execute([
                    $p['titulo'] ?? 'Sin título',
                    ($p['foco'] ?? '') . "\n\nInspiración: " . ($p['inspiracion'] ?? ''),
                    $p['extension'] ?? null,
                    $p['inspiracion'] ?? '',
                ]);
            }
            
            flash('success', count($propuestas) . ' propuestas generadas por IA.');
        }

        header('Location: ' . BASE_URL . '/admin/proponer.php');
        exit;
    }

    // Aceptar una propuesta -> crear historia
    if ($action === 'aceptar') {
        $idx = (int)($_POST['idx'] ?? -1);
        $propuestas = $_SESSION['propuestas_generadas'] ?? [];
        if ($idx >= 0 && $idx < count($propuestas)) {
            $p = $propuestas[$idx];
            // Crear historia directamente
            $stmt = $db->prepare("INSERT INTO historias (titulo, descripcion, foco_periodistico, extension_esperada, fecha_entrega, presupuesto, monto_total_a_pagar, visible_para_todos, creada_por, estado) VALUES (?, ?, ?, ?, DATE_ADD(CURDATE(), INTERVAL 14 DAY), 0, 0, 1, ?, 'disponible')");
            $stmt->execute([
                mb_substr($p['titulo'] ?? 'Sin título', 0, 300),
                '',
                ($p['foco'] ?? '') . "\n\n---\n💡 Propuesta generada por IA. Inspiración: " . ($p['inspiracion'] ?? ''),
                $p['extension'] ?? '800-1200 palabras',
                $_SESSION['usuario_id'],
            ]);
            $historia_id = $db->lastInsertId();

            // Marcar propuesta como aceptada
            $db->prepare("UPDATE propuestas_ia SET estado='aceptada', historia_creada_id=? WHERE titulo=? AND estado='pendiente' ORDER BY id DESC LIMIT 1")
                ->execute([$historia_id, mb_substr($p['titulo'] ?? '', 0, 300)]);

            // Quitar de la sesión
            unset($_SESSION['propuestas_generadas'][$idx]);
            $_SESSION['propuestas_generadas'] = array_values($_SESSION['propuestas_generadas']);

            flash('success', '✅ Historia creada desde propuesta. <a href="' . BASE_URL . '/admin/historia-editar.php?id=' . $historia_id . '" style="color:var(--accent)">Ver historia →</a>');
        }
        header('Location: ' . BASE_URL . '/admin/proponer.php');
        exit;
    }

    // Descartar propuesta
    if ($action === 'descartar') {
        $idx = (int)($_POST['idx'] ?? -1);
        $propuestas = $_SESSION['propuestas_generadas'] ?? [];
        if ($idx >= 0 && $idx < count($propuestas)) {
            $p = $propuestas[$idx];
            $db->prepare("UPDATE propuestas_ia SET estado='descartada' WHERE titulo=? AND estado='pendiente' ORDER BY id DESC LIMIT 1")
                ->execute([mb_substr($p['titulo'] ?? '', 0, 300)]);
            unset($_SESSION['propuestas_generadas'][$idx]);
            $_SESSION['propuestas_generadas'] = array_values($_SESSION['propuestas_generadas']);
            flash('info', 'Propuesta descartada.');
        }
        header('Location: ' . BASE_URL . '/admin/proponer.php');
        exit;
    }

    // Descartar todas
    if ($action === 'descartar_todas') {
        $_SESSION['propuestas_generadas'] = [];
        $db->query("UPDATE propuestas_ia SET estado='descartada' WHERE estado='pendiente'");
        flash('info', 'Todas las propuestas descartadas.');
        header('Location: ' . BASE_URL . '/admin/proponer.php');
        exit;
    }
}

// ── Cargar propuestas ──────────────────────────────────────────────────

$propuestas = $_SESSION['propuestas_generadas'] ?? [];
$hay_scrapeadas = file_exists(ROOT_PATH . '/datos/noticias_scrapeadas.json');
$noticias_count = 0;
if ($hay_scrapeadas) {
    $d = json_decode(file_get_contents(ROOT_PATH . '/datos/noticias_scrapeadas.json'), true);
    $noticias_count = is_array($d) ? count($d) : 0;
}

// Historial de propuestas aceptadas
$historial = $db->query("
    SELECT p.*, h.titulo AS historia_titulo
    FROM propuestas_ia p
    LEFT JOIN historias h ON p.historia_creada_id = h.id
    WHERE p.estado IN ('aceptada', 'descartada')
    ORDER BY p.created_at DESC
    LIMIT 20
")->fetchAll();
?>

<div class="page-header">
    <div>
        <h1>💡 Proponer Historias</h1>
        <div class="subtitle">Genera propuestas de profundización periodística usando IA a partir de noticias de medios regionales</div>
    </div>
</div>

<div style="display:flex;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap;align-items:center">
    <div class="stat-card" style="padding:.8rem 1.2rem">
        <div class="stat-value" style="font-size:1.2rem"><?= $noticias_count ?></div>
        <div class="stat-label">Noticias en caché</div>
    </div>
    <div class="stat-card" style="padding:.8rem 1.2rem">
        <div class="stat-value" style="font-size:1.2rem"><?= count($propuestas) ?></div>
        <div class="stat-label">Propuestas activas</div>
    </div>
    <form method="post" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="generar">
        <button type="submit" class="btn btn-primary" <?= !$noticias_count ? 'disabled' : '' ?>>
            🤖 Generar Propuestas (IA)
        </button>
    </form>
    <a href="<?= BASE_URL ?>/admin/scraper-config.php" class="btn btn-secondary">📡 Configurar fuentes</a>
    <?php if (!empty($propuestas)): ?>
    <form method="post" style="display:inline" onsubmit="return confirm('¿Descartar todas las propuestas actuales?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="descartar_todas">
        <button type="submit" class="btn btn-danger btn-sm">🗑️ Descartar todas</button>
    </form>
    <?php endif; ?>
</div>

<?php if (!$noticias_count): ?>
<div class="card" style="text-align:center;padding:3rem">
    <div style="font-size:3rem;margin-bottom:1rem">📭</div>
    <h2 style="margin-bottom:.5rem">No hay noticias scrapeadas</h2>
    <p style="color:var(--text2);margin-bottom:1.5rem">Primero debes ejecutar el scraper para recolectar noticias de los medios regionales.</p>
    <a href="<?= BASE_URL ?>/admin/scraper-config.php" class="btn btn-primary">📡 Ir a configuración del scraper</a>
</div>
<?php elseif (empty($propuestas)): ?>
<div class="card" style="text-align:center;padding:3rem">
    <div style="font-size:3rem;margin-bottom:1rem">🤖</div>
    <h2 style="margin-bottom:.5rem">Sin propuestas activas</h2>
    <p style="color:var(--text2);margin-bottom:1.5rem">Haz clic en "Generar Propuestas" para que la IA analice las <?= $noticias_count ?> noticias disponibles y proponga focos de profundización.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="generar">
        <button type="submit" class="btn btn-primary" style="padding:.8rem 2rem;font-size:1rem">
            🤖 Generar 10 Propuestas
        </button>
    </form>
</div>
<?php else: ?>
<div class="card" style="border:none;background:transparent;padding:0">
    <div class="card-header" style="padding:.5rem 0">
        <h2>🤖 Propuestas Generadas</h2>
        <span class="badge badge-disponible"><?= count($propuestas) ?> propuestas</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(380px,1fr));gap:1.2rem">
        <?php foreach ($propuestas as $idx => $p): 
            $gradients = ['#5e6ad2,#828fff','#7c3aed,#a78bfa','#2563eb,#60a5fa','#0891b2,#22d3ee','#059669,#34d399','#d97706,#fbbf24','#dc2626,#f87171','#db2777,#f472b6','#0ea5e9,#38bdf8','#8b5cf6,#a78bfa'];
            $g = $gradients[$idx % count($gradients)];
        ?>
        <div class="card" style="overflow:hidden;padding:0">
            <div style="background:linear-gradient(135deg,<?= $g ?>);padding:1.2rem 1.5rem">
                <span class="badge badge-disponible" style="background:rgba(255,255,255,0.2);color:#fff;font-size:.65rem">Propuesta #<?= $idx + 1 ?></span>
            </div>
            <div style="padding:1.2rem 1.5rem 1.5rem">
                <h3 style="font-size:1rem;font-weight:650;color:var(--white);margin-bottom:.8rem;line-height:1.3"><?= e($p['titulo'] ?? '') ?></h3>
                
                <div style="font-size:.8rem;color:var(--text2);line-height:1.6;margin-bottom:.8rem">
                    <?= nl2br(e(mb_substr($p['foco'] ?? '', 0, 300))) ?>
                    <?php if (mb_strlen($p['foco'] ?? '') > 300): ?>...<?php endif; ?>
                </div>
                
                <div style="display:flex;gap:.8rem;font-size:.75rem;color:var(--muted);margin-bottom:.8rem;flex-wrap:wrap">
                    <?php if ($p['extension'] ?? ''): ?>
                    <span>📄 <?= e($p['extension']) ?></span>
                    <?php endif; ?>
                    <?php if ($p['inspiracion'] ?? ''): ?>
                    <span>💡 <?= e(mb_substr($p['inspiracion'], 0, 80)) ?><?= mb_strlen($p['inspiracion']) > 80 ? '...' : '' ?></span>
                    <?php endif; ?>
                </div>
                
                <div style="display:flex;gap:.5rem;margin-top:.5rem">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="aceptar">
                        <input type="hidden" name="idx" value="<?= $idx ?>">
                        <button type="submit" class="btn btn-success btn-sm">✅ Crear Historia</button>
                    </form>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="descartar">
                        <input type="hidden" name="idx" value="<?= $idx ?>">
                        <button type="submit" class="btn btn-secondary btn-sm">✕ Descartar</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($historial)): ?>
<div class="card" style="margin-top:2rem">
    <div class="card-header">
        <h2>📋 Historial de Propuestas</h2>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Propuesta</th>
                    <th>Estado</th>
                    <th>Historia creada</th>
                    <th>Fecha</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($historial as $h): ?>
                <tr>
                    <td><?= e(mb_substr($h['titulo'], 0, 60)) ?><?= mb_strlen($h['titulo']) > 60 ? '...' : '' ?></td>
                    <td>
                        <?php if ($h['estado'] === 'aceptada'): ?>
                            <span style="color:var(--success)">✅ Aceptada</span>
                        <?php else: ?>
                            <span style="color:var(--muted)">✕ Descartada</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($h['historia_creada_id']): ?>
                            <a href="<?= BASE_URL ?>/admin/historia-editar.php?id=<?= $h['historia_creada_id'] ?>"><?= e($h['historia_titulo'] ?? 'Ver historia') ?></a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.8rem"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
