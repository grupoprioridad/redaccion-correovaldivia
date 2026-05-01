<?php
$titulo = 'Ver Historia';
require_once __DIR__ . '/header.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

$historia = $db->prepare("
    SELECT h.*, u.nombre AS creador_nombre, p.nombre AS periodista_nombre, c.nombre AS categoria_nombre
    FROM historias h
    LEFT JOIN usuarios u ON h.creada_por = u.id
    LEFT JOIN usuarios p ON h.periodista_asignado = p.id
    LEFT JOIN categorias_redaccion c ON h.categoria_id = c.id
    WHERE h.id = ?
");
$historia->execute([$id]);
$h = $historia->fetch();

$categorias = $db->query("SELECT id, nombre FROM categorias_redaccion WHERE activo=1 ORDER BY nombre")->fetchAll();
$periodistas = $db->query("SELECT id, nombre, email FROM usuarios WHERE rol='periodista' AND activo=1 AND aprobado=1 ORDER BY nombre")->fetchAll();
$visStmt = $db->prepare("SELECT usuario_id FROM historia_visibilidad WHERE historia_id = ?");
$visStmt->execute([$id]);
$visibilidad_actual = array_map('intval', $visStmt->fetchAll(PDO::FETCH_COLUMN));

if (!$h) {
    flash('error', 'Historia no encontrada.');
    header('Location: ' . BASE_URL . '/admin/index.php');
    exit;
}

// Acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'revisar') {
        $estado = $_POST['estado'] ?? '';
        $notas = trim($_POST['notas'] ?? '');
        if (in_array($estado, ['aprobado', 'rechazado'], true)) {
            $nuevo_estado = $estado === 'aprobado' ? 'revisada' : 'entregada';
            $db->prepare("UPDATE entregas SET estado=?, notas_revision=?, revisado_por=? WHERE historia_id=? AND estado='pendiente_revision'")->execute([$estado, $notas, $_SESSION['usuario_id'], $id]);
            $db->prepare("UPDATE historias SET estado=? WHERE id=?")->execute([$nuevo_estado, $id]);
            flash('success', 'Historia ' . ($estado === 'aprobado' ? 'aprobada' : 'rechazada') . '.');
            header('Location: ' . BASE_URL . '/admin/historia-editar?id=' . $id);
            exit;
        }
    }

    if ($action === 'editar') {
        $tit = trim($_POST['titulo'] ?? '');
        $desc = trim($_POST['descripcion'] ?? '');
        $foco = trim($_POST['foco'] ?? '');
        $ext = trim($_POST['extension'] ?? '');
        $fecha = $_POST['fecha_entrega'] ?? '';
        $presupuesto = (int)($_POST['presupuesto'] ?? 0);
        $categoria_id = !empty($_POST['categoria_id']) ? (int)$_POST['categoria_id'] : null;
        $visible_todos = (($_POST['visible_todos'] ?? '1') === '1') ? 1 : 0;
        $periodistas_sel = $_POST['periodistas'] ?? [];
        $periodista_nuevo = !empty($_POST['periodista_asignado']) ? (int)$_POST['periodista_asignado'] : null;
        $estadosValidos = ['disponible','asignada','en_curso','entregada','revisada','pagada'];
        $estado = $_POST['estado'] ?? $h['estado'];
        if (!in_array($estado, $estadosValidos, true)) $estado = $h['estado'];

        if (empty($tit) || empty($fecha)) {
            flash('error', 'Título y fecha son obligatorios.');
        } else {
            $monto_total_a_pagar = isset($_POST['monto_total_a_pagar']) && $_POST['monto_total_a_pagar'] !== '' ? (int)$_POST['monto_total_a_pagar'] : $presupuesto;

            $periodista_actual = $h['periodista_asignado'] ? (int)$h['periodista_asignado'] : null;
            $asignada_en_sql = 'asignada_en';
            $asignada_en_param = null;
            $usar_param_asignada = false;
            if ($periodista_nuevo !== $periodista_actual) {
                if ($periodista_nuevo === null) {
                    $asignada_en_sql = 'NULL';
                    if ($estado === 'asignada') $estado = 'disponible';
                } else {
                    $asignada_en_sql = '?';
                    $asignada_en_param = date('Y-m-d H:i:s');
                    $usar_param_asignada = true;
                    if ($estado === 'disponible') $estado = 'asignada';
                }
            }

            $sql = "UPDATE historias SET titulo=?, descripcion=?, foco_periodistico=?, extension_esperada=?, fecha_entrega=?, presupuesto=?, monto_total_a_pagar=?, estado=?, categoria_id=?, visible_para_todos=?, periodista_asignado=?, asignada_en={$asignada_en_sql} WHERE id=?";
            $params = [$tit, $desc, $foco, $ext, $fecha, $presupuesto, $monto_total_a_pagar, $estado, $categoria_id, $visible_todos, $periodista_nuevo];
            if ($usar_param_asignada) $params[] = $asignada_en_param;
            $params[] = $id;
            $db->prepare($sql)->execute($params);

            $db->prepare("DELETE FROM historia_visibilidad WHERE historia_id = ?")->execute([$id]);
            if (!$visible_todos && !empty($periodistas_sel)) {
                $stmt_vis = $db->prepare("INSERT INTO historia_visibilidad (historia_id, usuario_id) VALUES (?, ?)");
                foreach ($periodistas_sel as $pid) {
                    $stmt_vis->execute([$id, (int)$pid]);
                }
            }

            flash('success', 'Historia actualizada.');
        }
        header('Location: ' . BASE_URL . '/admin/historia-editar?id=' . $id);
        exit;
    }

    if ($action === 'marcar_pagado') {
        if ($h['estado'] !== 'revisada' || !$h['periodista_asignado']) {
            flash('error', 'La historia no está en estado para pagar.');
            header('Location: ' . BASE_URL . '/admin/historia-editar?id=' . $id);
            exit;
        }
        $monto_total = (int)($h['monto_total_a_pagar'] ?? $h['presupuesto']);
        $retencion = max(0, min($monto_total, (int)($_POST['retencion'] ?? 0)));
        $honorarios = $monto_total - $retencion;
        $liquido = $honorarios;

        $db->prepare("INSERT INTO pagos (historia_id, periodista_id, monto_total, honorarios, retencion, liquido, estado, fecha_pago) VALUES (?,?,?,?,?,?,'pagado',NOW())")
            ->execute([$id, $h['periodista_asignado'], $monto_total, $honorarios, $retencion, $liquido]);
        $db->prepare("UPDATE historias SET estado='pagada' WHERE id=?")->execute([$id]);
        flash('success', 'Pago registrado.');
        header('Location: ' . BASE_URL . '/admin/historia-editar?id=' . $id);
        exit;
    }
}

// Obtener entrega
$entrega = $db->prepare("SELECT e.*, u.nombre AS periodista_nombre FROM entregas e JOIN usuarios u ON e.periodista_id = u.id WHERE e.historia_id = ? ORDER BY e.created_at DESC LIMIT 1");
$entrega->execute([$id]);
$ent = $entrega->fetch();

// Documento cesión
$doc = null;
if ($ent) {
    $docStmt = $db->prepare("SELECT * FROM documentos_cesion WHERE entrega_id = ?");
    $docStmt->execute([$ent['id']]);
    $doc = $docStmt->fetch();
}

// Pago existente
$pago = $db->prepare("SELECT * FROM pagos WHERE historia_id = ? ORDER BY created_at DESC LIMIT 1");
$pago->execute([$id]);
$pag = $pago->fetch();
?>

<div class="page-header">
    <div>
        <h1><?= e($h['titulo']) ?></h1>
        <div class="subtitle">
            Creada por <?= e($h['creador_nombre']) ?> · 
            <span class="badge badge-<?= $h['estado'] ?>"><?= $h['estado'] ?></span>
        </div>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-secondary btn-sm">← Volver</a>
        <button onclick="toggleEdit()" class="btn btn-primary btn-sm">✏️ Editar Historia</button>
    </div>
</div>

<!-- Edit Form (hidden by default) -->
<div id="edit-form-card" class="card" style="margin-bottom:1.2rem;display:none;border-color:var(--accent)">
    <div class="card-header"><h2>✏️ Editar Historia</h2></div>
    <form method="post" action="<?= BASE_URL ?>/admin/historia-editar?id=<?= $id ?>" style="max-width:600px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="editar">
        
        <div class="form-group">
            <label for="edit_titulo">Título</label>
            <input type="text" id="edit_titulo" name="titulo" required value="<?= e($h['titulo']) ?>">
        </div>
        <div class="form-group">
            <label for="edit_descripcion">Descripción</label>
            <textarea id="edit_descripcion" name="descripcion" rows="3"><?= e($h['descripcion'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
            <label for="edit_foco">Foco periodístico</label>
            <textarea id="edit_foco" name="foco" rows="3"><?= e($h['foco_periodistico'] ?? '') ?></textarea>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="edit_extension">Extensión esperada</label>
                <input type="text" id="edit_extension" name="extension" value="<?= e($h['extension_esperada'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="edit_fecha">Fecha de entrega</label>
                <input type="date" id="edit_fecha" name="fecha_entrega" required value="<?= e($h['fecha_entrega']) ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="edit_presupuesto">Presupuesto estimado ($)</label>
                <input type="number" id="edit_presupuesto" name="presupuesto" min="0" value="<?= (int)$h['presupuesto'] ?>">
            </div>
            <div class="form-group">
                <label for="edit_monto_total_a_pagar">Monto total a pagar ($)</label>
                <input type="number" id="edit_monto_total_a_pagar" name="monto_total_a_pagar" min="0" value="<?= (int)($h['monto_total_a_pagar'] ?? $h['presupuesto']) ?>">
                <div class="hint">Monto que efectivamente se pagará al periodista.</div>
            </div>
        </div>
        <div class="form-group">
            <label for="edit_categoria">Categoría / Tema de interés</label>
            <select id="edit_categoria" name="categoria_id">
                <option value="">Sin categoría</option>
                <?php foreach ($categorias as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= (int)$h['categoria_id']===(int)$cat['id']?'selected':'' ?>><?= e($cat['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="hint">Los periodistas interesados en esta categoría recibirán prioridad en la notificación.</div>
        </div>

        <div class="form-group">
            <label for="edit_periodista_asignado">Periodista asignado</label>
            <select id="edit_periodista_asignado" name="periodista_asignado">
                <option value="">— Sin asignar (disponible para tomar) —</option>
                <?php foreach ($periodistas as $p): ?>
                <option value="<?= $p['id'] ?>" <?= (int)$h['periodista_asignado']===(int)$p['id']?'selected':'' ?>><?= e($p['nombre']) ?> · <?= e($p['email']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="hint">Si asignas un periodista, la historia pasa a "asignada" automáticamente. Si lo quitas y estaba "asignada", vuelve a "disponible".</div>
        </div>

        <div class="form-group">
            <label>¿A quién mostramos esta historia?</label>
            <div class="hint" style="margin-bottom:.5rem">Quién puede verla en su panel mientras esté disponible (no afecta al ya asignado).</div>
            <div class="checkbox-group" style="margin-bottom:.6rem">
                <label class="checkbox-item">
                    <input type="radio" name="visible_todos" value="1" <?= !empty($h['visible_para_todos']) ? 'checked' : '' ?> onchange="toggleEditPeriodistas()">
                    <span class="label">A todos los periodistas</span>
                </label>
                <label class="checkbox-item">
                    <input type="radio" name="visible_todos" value="0" <?= empty($h['visible_para_todos']) ? 'checked' : '' ?> onchange="toggleEditPeriodistas()">
                    <span class="label">Solo a los periodistas que yo elija</span>
                </label>
            </div>
            <div id="edit-periodistas-select" style="padding:.6rem .8rem;border:1px solid var(--border);border-radius:8px;<?= !empty($h['visible_para_todos']) ? 'opacity:.5;pointer-events:none' : '' ?>">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem">
                    <p style="font-size:.8rem;color:var(--muted);margin:0">Marca uno o varios periodistas:</p>
                    <div style="display:flex;gap:.4rem">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAllPeriodistas(true)">Marcar todos</button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAllPeriodistas(false)">Desmarcar</button>
                    </div>
                </div>
                <?php foreach ($periodistas as $p): ?>
                <label class="checkbox-item">
                    <input type="checkbox" name="periodistas[]" value="<?= $p['id'] ?>" <?= in_array((int)$p['id'], $visibilidad_actual, true) ? 'checked' : '' ?>>
                    <span class="label"><?= e($p['nombre']) ?> · <?= e($p['email']) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <script>
        function toggleEditPeriodistas() {
            var radio = document.querySelector('input[name="visible_todos"]:checked');
            var box = document.getElementById('edit-periodistas-select');
            var todos = !radio || radio.value === '1';
            box.style.opacity = todos ? '.5' : '1';
            box.style.pointerEvents = todos ? 'none' : 'auto';
        }
        function toggleAllPeriodistas(check) {
            document.querySelectorAll('#edit-periodistas-select input[type="checkbox"]').forEach(function(cb){ cb.checked = check; });
        }
        </script>

        <div class="form-group">
            <label for="edit_estado">Estado</label>
            <select id="edit_estado" name="estado">
                <option value="disponible" <?= $h['estado']==='disponible'?'selected':'' ?>>Disponible</option>
                <option value="asignada" <?= $h['estado']==='asignada'?'selected':'' ?>>Asignada</option>
                <option value="en_curso" <?= $h['estado']==='en_curso'?'selected':'' ?>>En curso</option>
                <option value="entregada" <?= $h['estado']==='entregada'?'selected':'' ?>>Entregada</option>
                <option value="revisada" <?= $h['estado']==='revisada'?'selected':'' ?>>Revisada</option>
                <option value="pagada" <?= $h['estado']==='pagada'?'selected':'' ?>>Pagada</option>
            </select>
        </div>
        <div style="display:flex;gap:.5rem">
            <button type="submit" class="btn btn-primary">💾 Guardar Cambios</button>
            <button type="button" class="btn btn-secondary" onclick="toggleEdit()">Cancelar</button>
        </div>
    </form>
</div>

<script>
function toggleEdit() {
    var el = document.getElementById('edit-form-card');
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
    if (el.style.display === 'block') {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}
</script>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.2rem">
    <div class="card">
        <div class="card-header"><h2>Detalles</h2></div>
        <div class="detail-row">
            <span class="detail-label">Descripción</span>
            <span class="detail-value"><?= nl2br(e($h['descripcion'] ?? '—')) ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Foco periodístico</span>
            <span class="detail-value"><?= nl2br(e($h['foco_periodistico'] ?? '—')) ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Extensión</span>
            <span class="detail-value"><?= e($h['extension_esperada'] ?? '—') ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Categoría</span>
            <span class="detail-value"><?= e($h['categoria_nombre'] ?? '—') ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Fecha entrega</span>
            <span class="detail-value"><?= date('d/m/Y', strtotime($h['fecha_entrega'])) ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Presupuesto estimado</span>
            <span class="detail-value">$<?= number_format($h['presupuesto'], 0, ',', '.') ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Monto total a pagar</span>
            <span class="detail-value" style="font-weight:600;color:var(--success)">$<?= number_format($h['monto_total_a_pagar'] ?? $h['presupuesto'], 0, ',', '.') ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Periodista</span>
            <span class="detail-value"><?= e($h['periodista_nombre'] ?? '— (disponible)') ?></span>
        </div>
        <?php if ($h['asignada_en']): ?>
        <div class="detail-row">
            <span class="detail-label">Asignada</span>
            <span class="detail-value"><?= date('d/m/Y H:i', strtotime($h['asignada_en'])) ?></span>
        </div>
        <?php endif; ?>
    </div>
    
    <div>
        <?php if ($h['estado'] === 'pagada' && $pag): ?>
        <div class="card">
            <div class="card-header"><h2>💰 Pago</h2></div>
            <div class="detail-row">
                <span class="detail-label">Monto total</span>
                <span class="detail-value">$<?= number_format($pag['monto_total'], 0, ',', '.') ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Honorarios</span>
                <span class="detail-value">$<?= number_format($pag['honorarios'], 0, ',', '.') ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Retención</span>
                <span class="detail-value">$<?= number_format($pag['retencion'], 0, ',', '.') ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Líquido a pagar</span>
                <span class="detail-value" style="color:var(--success);font-weight:600">$<?= number_format($pag['liquido'], 0, ',', '.') ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Pagado el</span>
                <span class="detail-value"><?= date('d/m/Y', strtotime($pag['fecha_pago'])) ?></span>
            </div>
        </div>
        <?php elseif ($h['estado'] === 'revisada' && $h['periodista_asignado']): ?>
        <div class="card">
            <div class="card-header"><h2>💰 Registrar Pago</h2></div>
            <form method="post" action="<?= BASE_URL ?>/admin/historia-editar?id=<?= $id ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="marcar_pagado">
                <div class="form-group">
                    <label>Monto total</label>
                    <input type="text" value="$<?= number_format($h['monto_total_a_pagar'] ?? $h['presupuesto'], 0, ',', '.') ?>" disabled>
                </div>
                <div class="form-group">
                    <label for="retencion">Retención ($)</label>
                    <input type="number" id="retencion" name="retencion" value="0" min="0">
                    <div class="hint">Monto a retener (ej: retención de renta).</div>
                </div>
                <button type="submit" class="btn btn-success">✓ Marcar como Pagado</button>
            </form>
        </div>
        <?php endif; ?>
        
        <?php if ($h['estado'] === 'entregada' && $ent): ?>
        <div class="card">
            <div class="card-header">
                <h2>📄 Revisar Entrega</h2>
                <span class="badge badge-pendiente_revision">Pendiente</span>
            </div>
            <form method="post" action="<?= BASE_URL ?>/admin/historia-editar?id=<?= $id ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="revisar">
                <div style="margin-bottom:.8rem">
                    <a href="#" onclick="verEntrega(event, <?= $id ?>)" class="btn btn-secondary btn-sm">📖 Ver contenido</a>
                    <?php if ($doc && $doc['pdf_generado']): ?>
                    <a href="<?= BASE_URL ?>/admin/cesion.php?id=<?= (int)$doc['id'] ?>" target="_blank" class="btn btn-secondary btn-sm">📄 Ver cesión</a>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Decisión</label>
                    <div style="display:flex;gap:.5rem">
                        <button type="submit" name="estado" value="aprobado" class="btn btn-success btn-sm">✓ Aprobar</button>
                        <button type="submit" name="estado" value="rechazado" class="btn btn-danger btn-sm">✗ Rechazar</button>
                    </div>
                </div>
                <div class="form-group">
                    <label for="notas">Notas de revisión</label>
                    <textarea id="notas" name="notas" rows="3" placeholder="Comentarios para el periodista..."></textarea>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($ent && $ent['contenido']): ?>
<div class="card" style="margin-top:1.2rem" id="contenido-<?= $id ?>">
    <div class="card-header">
        <h2>📖 Contenido Entregado</h2>
        <span class="badge badge-<?= $ent['estado'] ?>"><?= $ent['estado'] ?></span>
    </div>
    <div style="line-height:1.8;font-size:.95rem;color:var(--text2)">
        <?= sanitizarHTMLEntrega($ent['contenido'] ?? '') ?>
    </div>
    <?php if ($ent['estado'] === 'pendiente_revision'): ?>
    <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border)">
        <p style="font-size:.8rem;color:var(--muted)">Entregado por <?= e($ent['periodista_nombre']) ?> el <?= date('d/m/Y H:i', strtotime($ent['fecha_entrega'])) ?></p>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
function verEntrega(e, id) {
    e.preventDefault();
    document.getElementById('contenido-' + id).scrollIntoView({ behavior: 'smooth' });
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
