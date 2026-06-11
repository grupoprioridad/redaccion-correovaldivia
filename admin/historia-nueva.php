<?php
$titulo = 'Nueva Historia';
require_once __DIR__ . '/header.php';

$db = getDB();
$categorias = $db->query("SELECT id, nombre FROM categorias_redaccion WHERE activo=1 ORDER BY nombre")->fetchAll();
$periodistas = $db->query("SELECT id, nombre, email FROM usuarios WHERE rol='periodista' AND activo=1 AND aprobado=1 ORDER BY nombre")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $tit = trim($_POST['titulo'] ?? '');
    $desc = trim($_POST['descripcion'] ?? '');
    $foco = trim($_POST['foco'] ?? '');
    $ext = trim($_POST['extension'] ?? '');
    $fecha = $_POST['fecha_entrega'] ?? '';
    $presupuesto = (int)($_POST['presupuesto'] ?? 0);
    $monto_total_a_pagar = isset($_POST['monto_total_a_pagar']) && $_POST['monto_total_a_pagar'] !== '' ? (int)$_POST['monto_total_a_pagar'] : $presupuesto;
    $categoria_id = !empty($_POST['categoria_id']) ? (int)$_POST['categoria_id'] : null;
    $visible_todos = isset($_POST['visible_todos']) ? 1 : 0;
    $periodistas_sel = $_POST['periodistas'] ?? [];
    
    if (empty($tit) || empty($fecha)) {
        flash('error', 'El título y la fecha de entrega son obligatorios.');
    } else {
        $stmt = $db->prepare("INSERT INTO historias (categoria_id, titulo, descripcion, foco_periodistico, extension_esperada, fecha_entrega, presupuesto, monto_total_a_pagar, visible_para_todos, creada_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$categoria_id, $tit, $desc, $foco, $ext, $fecha, $presupuesto, $monto_total_a_pagar, $visible_todos, $_SESSION['usuario_id']]);
        $historia_id = $db->lastInsertId();
        $codigo = 'cdv' . str_pad($historia_id, 3, '0', STR_PAD_LEFT);
        $db->prepare("UPDATE historias SET codigo=? WHERE id=?")->execute([$codigo, $historia_id]);
        
        // Si no es visible para todos, guardar visibilidad selectiva
        if (!$visible_todos && !empty($periodistas_sel)) {
            $stmt_vis = $db->prepare("INSERT INTO historia_visibilidad (historia_id, usuario_id) VALUES (?, ?)");
            foreach ($periodistas_sel as $pid) {
                $stmt_vis->execute([$historia_id, (int)$pid]);
            }
        }
        
        // Notificar a los periodistas sobre la nueva historia
        $admin_nombre = $_SESSION['usuario_nombre'];
        if ($visible_todos) {
            $destinatarios = $db->query("SELECT email, nombre FROM usuarios WHERE rol='periodista' AND activo=1 AND aprobado=1")->fetchAll();
        } else {
            $ids = array_map('intval', $periodistas_sel);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $destinatarios = $db->prepare("SELECT email, nombre FROM usuarios WHERE id IN ($placeholders) AND activo=1 AND aprobado=1");
            $destinatarios->execute($ids);
            $destinatarios = $destinatarios->fetchAll();
        }
        
        $subject = "Nueva historia disponible: " . preg_replace('/[\r\n]+/', ' ', mb_substr($tit, 0, 100));
        $titSafe        = e($tit);
        $descSafe       = nl2br(e(mb_substr($desc, 0, 200)));
        $extSafe        = e($ext);
        $presupuestoSafe = (int)($monto_total_a_pagar ?? $presupuesto);
        $fechaSafe      = date('d/m/Y', strtotime($fecha));

        foreach ($destinatarios as $dest) {
            $nombreSafe = e($dest['nombre']);
            $msg = "
            <div style='font-family:sans-serif;max-width:600px;margin:0 auto;background:#111214;padding:2rem;border-radius:12px;border:1px solid rgba(255,255,255,0.08)'>
                <h2 style='color:#5e6ad2;margin-bottom:1rem'>📢 Nueva historia disponible</h2>
                <p style='color:#f7f8f8;margin-bottom:1rem'>Hola <strong>{$nombreSafe}</strong>,</p>
                <p style='color:#a0a4ab;line-height:1.6'>Se ha publicado una nueva historia en la plataforma de redacción de El Correo de Valdivia.</p>
                <div style='background:#191a1c;border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:1.2rem;margin:1rem 0'>
                    <h3 style='color:#f7f8f8;font-size:1.05rem;margin-bottom:.5rem'>{$titSafe}</h3>
                    <p style='color:#a0a4ab;font-size:.85rem;line-height:1.5'>{$descSafe}</p>
                    <div style='display:flex;gap:1rem;margin-top:.8rem;font-size:.8rem;color:#62666d'>
                        <span>⏱ Entrega: {$fechaSafe}</span>
                        <span>💰 \${$presupuestoSafe}</span>
                        <span>📄 {$extSafe}</span>
                    </div>
                </div>
                <p style='margin:1.5rem 0'><a href='" . BASE_URL . "/periodista/index.php' style='display:inline-block;padding:12px 24px;background:#5e6ad2;color:#fff;text-decoration:none;border-radius:8px;font-size:.95rem'>Ver historias disponibles →</a></p>
                <hr style='border-color:rgba(255,255,255,0.08);margin:1.5rem 0'>
                <p style='font-size:.8rem;color:#62666d'>El Correo de Valdivia · Sistema de Redacción</p>
            </div>";
            enviarCorreo($dest['email'], $subject, $msg);
        }
        
        flash('success', 'Historia creada exitosamente. Se notificó a ' . count($destinatarios) . ' periodista(s) por email.');
        header('Location: ' . BASE_URL . '/admin/index.php');
        exit;
    }
}
?>

<div class="page-header">
    <h1>Nueva Historia</h1>
</div>

<div class="card" style="max-width:700px">
    <form method="post">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="titulo">Título de la historia *</label>
            <input type="text" id="titulo" name="titulo" required placeholder="Ej: El auge de la construcción en Valdivia" value="<?= e($_POST['titulo'] ?? '') ?>">
        </div>
        
        <div class="form-group">
            <label for="descripcion">Descripción</label>
            <textarea id="descripcion" name="descripcion" rows="3" placeholder="Breve descripción de la historia..."><?= e($_POST['descripcion'] ?? '') ?></textarea>
        </div>
        
        <div class="form-group">
            <label for="foco">Foco periodístico</label>
            <textarea id="foco" name="foco" rows="3" placeholder="¿Qué ángulo debe abordar el periodista? ¿Qué aspectos investigar?"><?= e($_POST['foco'] ?? '') ?></textarea>
            <div class="hint">Instrucciones específicas sobre el enfoque, fuentes a consultar, etc.</div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="extension">Extensión esperada</label>
                <input type="text" id="extension" name="extension" placeholder="Ej: 800-1200 palabras" value="<?= e($_POST['extension'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="fecha_entrega">Fecha de entrega *</label>
                <input type="date" id="fecha_entrega" name="fecha_entrega" required value="<?= e($_POST['fecha_entrega'] ?? '') ?>">
            </div>
        </div>
        
        <div class="form-group">
            <label for="monto_total_a_pagar">Monto total a pagar ($)</label>
            <input type="number" id="monto_total_a_pagar" name="monto_total_a_pagar" min="0" placeholder="Igual al presupuesto si se deja vacío" value="">
            <div class="hint">Dejar vacío para usar el mismo valor del presupuesto.</div>
        </div>
        
        <div class="form-group">
            <label for="categoria_id">Categoría / Tema de interés</label>
            <select id="categoria_id" name="categoria_id">
                <option value="">Sin categoría</option>
                <?php foreach ($categorias as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= ($_POST['categoria_id']??'')==(string)$cat['id']?'selected':'' ?>><?= e($cat['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="hint">Los periodistas interesados en esta categoría recibirán prioridad en la notificación.</div>
        </div>
        
        <div class="form-group">
            <label>Visibilidad</label>
            <div class="checkbox-group">
                <label class="checkbox-item">
                    <input type="checkbox" name="visible_todos" value="1" <?= !isset($_POST['visible_todos']) || !empty($_POST['visible_todos']) ? 'checked' : '' ?> onchange="togglePeriodistas(this)">
                    <span class="label">Visible para todos los periodistas</span>
                </label>
            </div>
            <div id="periodistas-select" style="<?= !empty($_POST['visible_todos']) ? 'display:none' : '' ?>">
                <p style="font-size:.8rem;color:var(--muted);margin-bottom:.5rem">Selecciona los periodistas que pueden ver esta historia:</p>
                <?php foreach ($periodistas as $p): ?>
                <label class="checkbox-item">
                    <input type="checkbox" name="periodistas[]" value="<?= $p['id'] ?>" <?= in_array((string)$p['id'], $_POST['periodistas'] ?? []) ? 'checked' : '' ?>>
                    <span class="label"><?= e($p['nombre']) ?> · <?= e($p['email']) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        
        <script>
        function togglePeriodistas(checkbox) {
            document.getElementById('periodistas-select').style.display = checkbox.checked ? 'none' : 'block';
        }
        </script>
        
        <div style="display:flex;gap:1rem;margin-top:1.5rem">
            <button type="submit" class="btn btn-primary">Crear Historia</button>
            <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
