<?php
$titulo = 'Suscriptores';
require_once __DIR__ . '/header.php';

// getSiteDB() se define en includes/config.php
$site = getSiteDB();

// ── Exportar CSV ─────────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $todos = $site->query("SELECT id, nombre, correo, telefono, fecha_registro, activo FROM suscriptores ORDER BY fecha_registro DESC")->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="suscriptores_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Nombre', 'Correo', 'Teléfono', 'Fecha de registro', 'Estado']);
    foreach ($todos as $s) fputcsv($out, [$s['id'], $s['nombre'], $s['correo'], $s['telefono'], $s['fecha_registro'], (int)$s['activo'] === 1 ? 'Activo' : 'Desactivado']);
    fclose($out);
    exit;
}

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'eliminar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) { $site->prepare("DELETE FROM suscriptores WHERE id = ?")->execute([$id]); flash('success', 'Suscriptor eliminado.'); }
    }

    if ($action === 'eliminar_multiple') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        $ids = array_filter($ids);
        if ($ids) {
            $in = implode(',', $ids);
            $site->exec("DELETE FROM suscriptores WHERE id IN ($in)");
            flash('success', count($ids) . ' suscriptor(es) eliminado(s).');
        } else {
            flash('error', 'No seleccionaste ningún suscriptor.');
        }
    }

    if ($action === 'reactivar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $site->prepare("UPDATE suscriptores SET activo = 1, desactivado_en = NULL WHERE id = ?")->execute([$id]);
            flash('success', 'Suscriptor reactivado. Su vínculo fue restablecido.');
        }
        header('Location: ' . BASE_URL . '/admin/suscriptores.php?estado=' . urlencode($_POST['volver'] ?? 'inactivos'));
        exit;
    }

    if ($action === 'desactivar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $site->prepare("UPDATE suscriptores SET activo = 0, desactivado_en = NOW() WHERE id = ?")->execute([$id]);
            flash('success', 'Suscriptor desactivado (no se borró). Puedes reactivarlo cuando quieras.');
        }
        header('Location: ' . BASE_URL . '/admin/suscriptores.php');
        exit;
    }

    if ($action === 'importar') {
        $insertados = 0; $duplicados = 0; $invalidos = 0;
        $err = '';

        if (empty($_FILES['archivo']) || ($_FILES['archivo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = 'No se recibió el archivo o hubo un error al subirlo.';
        } elseif (($_FILES['archivo']['size'] ?? 0) > 5 * 1024 * 1024) {
            $err = 'El archivo supera el límite de 5 MB.';
        } else {
            $contenido = (string)file_get_contents($_FILES['archivo']['tmp_name']);
            $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);         // quitar BOM
            $contenido = str_replace(["\r\n", "\r"], "\n", $contenido);            // normalizar saltos
            $lineas    = array_values(array_filter(explode("\n", $contenido), fn($l) => trim($l) !== ''));

            if (!$lineas) {
                $err = 'El archivo está vacío.';
            } else {
                // Detectar separador (coma o punto y coma, común en Excel en español)
                $delim = substr_count($lineas[0], ';') > substr_count($lineas[0], ',') ? ';' : ',';
                $filas = array_map(fn($l) => str_getcsv($l, $delim), $lineas);

                // Detectar cabecera y mapear columnas por nombre
                $idx    = ['nombre' => null, 'correo' => null, 'telefono' => null, 'fecha' => null];
                $inicio = 0;
                $cab    = array_map(fn($c) => mb_strtolower(trim((string)$c)), $filas[0]);
                foreach ($cab as $i => $val) {
                    if (in_array($val, ['correo', 'email', 'e-mail', 'mail'], true))                        { $idx['correo'] = $i;   $inicio = 1; }
                    elseif (in_array($val, ['nombre', 'name'], true))                                       { $idx['nombre'] = $i;   $inicio = 1; }
                    elseif (in_array($val, ['telefono', 'teléfono', 'fono', 'phone'], true))                 { $idx['telefono'] = $i; $inicio = 1; }
                    elseif (in_array($val, ['start date', 'fecha', 'fecha_registro', 'fecha de registro', 'date'], true)) { $idx['fecha'] = $i; $inicio = 1; }
                }
                // Sin cabecera reconocible: asumir orden (nombre, correo, telefono) o solo correo
                if ($idx['correo'] === null) {
                    if (count($filas[0]) <= 1) { $idx['correo'] = 0; }
                    else { $idx['nombre'] = 0; $idx['correo'] = 1; $idx['telefono'] = 2; }
                }

                $existe = $site->prepare("SELECT 1 FROM suscriptores WHERE correo = ? LIMIT 1");
                // COALESCE: si el CSV trae fecha de alta se conserva; si no, usa la fecha actual.
                $ins    = $site->prepare("INSERT INTO suscriptores (nombre, correo, telefono, fecha_registro) VALUES (?, ?, ?, COALESCE(?, CURRENT_TIMESTAMP))");
                $vistos = [];

                for ($r = $inicio; $r < count($filas); $r++) {
                    $fila   = $filas[$r];
                    $correo = mb_strtolower(trim((string)($fila[$idx['correo']] ?? '')));
                    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) { $invalidos++; continue; }
                    if (isset($vistos[$correo])) { $duplicados++; continue; }
                    $vistos[$correo] = true;

                    $existe->execute([$correo]);
                    if ($existe->fetchColumn()) { $duplicados++; continue; }

                    $nombre = $idx['nombre']   !== null ? trim((string)($fila[$idx['nombre']] ?? '')) : '';
                    $tel    = $idx['telefono'] !== null ? trim((string)($fila[$idx['telefono']] ?? '')) : '';
                    if ($nombre === '') $nombre = strstr($correo, '@', true) ?: $correo;

                    // Fecha de alta original (ISO 8601 u otro formato reconocible)
                    $fecha = null;
                    if ($idx['fecha'] !== null) {
                        $raw = trim((string)($fila[$idx['fecha']] ?? ''));
                        if ($raw !== '' && ($ts = strtotime($raw)) !== false) {
                            $fecha = date('Y-m-d H:i:s', $ts);
                        }
                    }

                    try {
                        $ins->execute([mb_substr($nombre, 0, 255), mb_substr($correo, 0, 255), mb_substr($tel, 0, 20), $fecha]);
                        $insertados++;
                    } catch (Throwable $ex) {
                        $duplicados++; // choque con UNIQUE(correo)
                    }
                }
            }
        }

        if ($err) {
            flash('error', $err);
        } else {
            flash('success', "Importación: {$insertados} nuevo(s), {$duplicados} duplicado(s) omitido(s), {$invalidos} inválido(s).");
        }
    }

    header('Location: ' . BASE_URL . '/admin/suscriptores.php');
    exit;
}

// ── Búsqueda + filtro por estado + paginación ─────────────────────────────────
$q      = trim($_GET['q'] ?? '');
$estado = $_GET['estado'] ?? 'todos';                       // todos | activos | inactivos
$estado = in_array($estado, ['todos', 'activos', 'inactivos'], true) ? $estado : 'todos';
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 50;
$off    = ($page - 1) * $per;

// Cláusulas dinámicas
$where  = [];
$params = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(nombre LIKE ? OR correo LIKE ? OR telefono LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($estado === 'activos')   $where[] = 'activo = 1';
if ($estado === 'inactivos') $where[] = 'activo = 0';
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$cnt = $site->prepare("SELECT COUNT(*) FROM suscriptores $where_sql");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();

$stmt = $site->prepare("SELECT * FROM suscriptores $where_sql ORDER BY fecha_registro DESC LIMIT $per OFFSET $off");
$stmt->execute($params);

$suscriptores = $stmt->fetchAll();
$pages        = (int)ceil($total / $per);
$stats        = $site->query("SELECT
        COUNT(*) AS total,
        SUM(activo = 1) AS activos,
        SUM(activo = 0) AS inactivos,
        SUM(activo = 1 AND fecha_registro >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS semana
    FROM suscriptores")->fetch();
?>

<div class="page-header">
    <div>
        <h1>📧 Suscriptores</h1>
        <div class="subtitle">Lectores registrados en El Correo de Valdivia</div>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a href="<?= BASE_URL ?>/admin/enviar-articulo.php" class="btn btn-primary">✉ Enviar artículo</a>
        <button type="button" class="btn btn-secondary" onclick="toggleImport()">↑ Importar CSV</button>
        <a href="?export=csv" class="btn btn-secondary">↓ Exportar CSV</a>
    </div>
</div>

<!-- Panel de importación CSV -->
<div id="import-panel" class="card" style="display:none;margin-bottom:1.5rem;border:1px solid rgba(94,106,210,.35)">
    <div class="card-header"><h2>↑ Importar suscriptores desde CSV</h2></div>
    <form method="post" enctype="multipart/form-data" style="padding:1rem 0 .25rem">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="importar">
        <p style="font-size:.85rem;color:var(--text2);margin-bottom:1rem;line-height:1.6">
            El archivo <strong>.csv</strong> debe traer una columna de correo (<code>correo</code> o <code>email</code>).
            Opcionalmente <code>nombre</code>/<code>name</code>, <code>telefono</code> y la fecha de alta
            (<code>fecha</code> o <code>start date</code>, que se conserva).
            La primera fila puede ser el encabezado; se acepta separador coma o punto y coma.
            Los correos ya existentes, repetidos o inválidos se omiten automáticamente.
        </p>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
            <input type="file" name="archivo" accept=".csv,text/csv" required class="form-control" style="max-width:360px">
            <button type="submit" class="btn btn-primary">Importar</button>
            <button type="button" class="btn btn-secondary" onclick="toggleImport()">Cancelar</button>
        </div>
    </form>
</div>

<div class="stats-grid" style="margin-bottom:1.5rem">
    <div class="stat-card"><div class="stat-value"><?= number_format((int)$stats['total']) ?></div><div class="stat-label">Total</div></div>
    <div class="stat-card"><div class="stat-value" style="color:var(--success)"><?= number_format((int)$stats['activos']) ?></div><div class="stat-label">Activos</div></div>
    <div class="stat-card"><div class="stat-value" style="color:var(--error)"><?= number_format((int)$stats['inactivos']) ?></div><div class="stat-label">Desactivados</div></div>
    <div class="stat-card"><div class="stat-value" style="color:var(--accent)"><?= (int)$stats['semana'] ?></div><div class="stat-label">Nuevos (7 días)</div></div>
</div>

<!-- Filtro por estado -->
<div style="display:flex;gap:.4rem;margin-bottom:1rem;flex-wrap:wrap">
    <?php
    $filtros = ['todos' => 'Todos', 'activos' => 'Activos', 'inactivos' => 'Desactivados'];
    foreach ($filtros as $key => $lbl):
        $qs = http_build_query(array_filter(['q' => $q, 'estado' => $key === 'todos' ? null : $key]));
    ?>
    <a href="?<?= $qs ?>" class="btn btn-xs <?= $estado === $key ? 'btn-primary' : 'btn-secondary' ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
</div>

<form method="get" style="margin-bottom:1.25rem;display:flex;gap:.6rem;flex-wrap:wrap">
    <?php if ($estado !== 'todos'): ?><input type="hidden" name="estado" value="<?= e($estado) ?>"><?php endif; ?>
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar por nombre, correo o teléfono…" class="form-control" style="max-width:360px">
    <button type="submit" class="btn btn-secondary">Buscar</button>
    <?php if ($q): ?><a href="?<?= $estado !== 'todos' ? 'estado=' . e($estado) : '' ?>" class="btn btn-secondary">✕</a><?php endif; ?>
</form>

<!-- Acciones masivas (sobre la tabla) -->
<div id="bulk-bar" style="display:none;align-items:center;gap:.75rem;margin-bottom:.75rem;padding:.7rem 1rem;background:var(--surface2);border:1px solid rgba(94,106,210,.3);border-radius:10px">
    <span id="bulk-count" style="font-size:.85rem;color:var(--accent-h);font-weight:600">0 seleccionados</span>
    <button type="button" class="btn btn-danger btn-sm" onclick="bulkDelete()">🗑 Eliminar seleccionados</button>
    <a id="bulk-send-link" href="<?= BASE_URL ?>/admin/enviar-articulo.php" class="btn btn-primary btn-sm">✉ Enviar artículo a seleccionados</a>
    <button type="button" class="btn btn-secondary btn-sm" onclick="clearSelection()">Deseleccionar todo</button>
</div>

<!-- Formulario de eliminación masiva (oculto) -->
<form id="bulk-form" method="post" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="eliminar_multiple">
    <div id="bulk-ids"></div>
</form>

<div class="card">
    <div class="card-header">
        <h2><?= $q ? 'Resultados para "' . e($q) . '"' : 'Todos los suscriptores' ?></h2>
        <span class="badge"><?= $total ?> <?= $total === 1 ? 'registro' : 'registros' ?></span>
    </div>

    <?php if (empty($suscriptores)): ?>
    <div class="empty-state"><div style="font-size:2rem;margin-bottom:.5rem">📭</div><p>Sin resultados.</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:36px">
                        <input type="checkbox" id="chk-all" title="Seleccionar todos"
                               style="width:16px;height:16px;cursor:pointer;accent-color:var(--accent)">
                    </th>
                    <th>#</th>
                    <th>Nombre</th>
                    <th>Correo</th>
                    <th>Teléfono</th>
                    <th>Registro</th>
                    <th>Estado</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($suscriptores as $s): ?>
                <tr>
                    <td>
                        <input type="checkbox" class="row-chk"
                               data-id="<?= $s['id'] ?>"
                               data-correo="<?= e($s['correo']) ?>"
                               style="width:16px;height:16px;cursor:pointer;accent-color:var(--accent)">
                    </td>
                    <td style="color:var(--muted);font-size:.8rem"><?= $s['id'] ?></td>
                    <td><strong><?= e($s['nombre']) ?></strong></td>
                    <td><a href="mailto:<?= e($s['correo']) ?>" style="color:var(--accent);font-size:.88rem"><?= e($s['correo']) ?></a></td>
                    <td style="color:var(--text2);font-size:.88rem"><?= e($s['telefono']) ?: '—' ?></td>
                    <td style="color:var(--muted);font-size:.8rem;white-space:nowrap"><?= date('d/m/Y H:i', strtotime($s['fecha_registro'])) ?></td>
                    <td>
                        <?php if ((int)($s['activo'] ?? 1) === 1): ?>
                            <span class="badge" style="background:rgba(39,166,68,.15);color:var(--success)">● Activo</span>
                        <?php else: ?>
                            <span class="badge" style="background:rgba(239,68,68,.15);color:var(--error)"
                                  title="<?= !empty($s['desactivado_en']) ? 'Desactivado el ' . date('d/m/Y H:i', strtotime($s['desactivado_en'])) : '' ?>">○ Desactivado</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap">
                        <?php if ((int)($s['activo'] ?? 1) === 0): ?>
                            <!-- Reactivar (restablecer vínculo) -->
                            <form method="post" style="display:inline"
                                  onsubmit="return confirm('¿Reactivar a <?= e(addslashes($s['nombre'])) ?> y restablecer su vínculo?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reactivar">
                                <input type="hidden" name="id"     value="<?= $s['id'] ?>">
                                <input type="hidden" name="volver"  value="<?= e($estado) ?>">
                                <button type="submit" class="btn btn-xs" style="background:rgba(39,166,68,.15);color:var(--success)" title="Reactivar suscriptor">🔔 Reactivar</button>
                            </form>
                            <!-- Contactar directamente para sumarlo de nuevo -->
                            <a class="btn btn-secondary btn-xs"
                               href="mailto:<?= e($s['correo']) ?>?subject=<?= rawurlencode('Vuelve a El Correo de Valdivia') ?>&body=<?= rawurlencode('Hola ' . ($s['nombre'] ?: '') . ",\n\nNotamos que diste de baja las alertas de El Correo de Valdivia. Nos encantaría que sigas con nosotros. Si quieres, te reactivamos tu suscripción.\n\nSaludos,\nEquipo El Correo de Valdivia") ?>"
                               title="Contactar directamente">✉ Contactar</a>
                        <?php else: ?>
                            <!-- Desactivar sin borrar -->
                            <form method="post" style="display:inline"
                                  onsubmit="return confirm('¿Desactivar a <?= e(addslashes($s['nombre'])) ?>?\nNo se borra: dejará de recibir alertas y podrás reactivarlo.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="desactivar">
                                <input type="hidden" name="id"     value="<?= $s['id'] ?>">
                                <button type="submit" class="btn btn-secondary btn-xs" title="Desactivar (no borra)">○ Desactivar</button>
                            </form>
                            <!-- Eliminar definitivamente -->
                            <form method="post" style="display:inline"
                                  onsubmit="return confirm('¿ELIMINAR definitivamente a <?= e(addslashes($s['nombre'])) ?>?\n<?= e(addslashes($s['correo'])) ?>\n\nEsto borra el registro. Si solo quieres que deje de recibir correos, usa Desactivar.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="eliminar">
                                <input type="hidden" name="id"     value="<?= $s['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-xs">🗑</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
    <div style="display:flex;justify-content:center;gap:.4rem;padding:1.2rem 0 .4rem;flex-wrap:wrap">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a href="?<?= http_build_query(array_filter(['q' => $q ?: null, 'estado' => $estado !== 'todos' ? $estado : null, 'page' => $i])) ?>"
           class="btn btn-xs <?= $i === $page ? 'btn-primary' : 'btn-secondary' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
function toggleImport() {
    const p = document.getElementById('import-panel');
    p.style.display = (p.style.display === 'none' || !p.style.display) ? 'block' : 'none';
    if (p.style.display === 'block') p.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

const chkAll  = document.getElementById('chk-all');
const bulkBar = document.getElementById('bulk-bar');
const bulkCnt = document.getElementById('bulk-count');
const bulkIds = document.getElementById('bulk-ids');
const sendLink= document.getElementById('bulk-send-link');
const rows    = () => [...document.querySelectorAll('.row-chk')];

function updateBulkBar() {
    const checked = rows().filter(c => c.checked);
    const n       = checked.length;
    bulkBar.style.display = n > 0 ? 'flex' : 'none';
    bulkCnt.textContent   = n + ' seleccionado' + (n !== 1 ? 's' : '');
    chkAll.indeterminate  = n > 0 && n < rows().length;
    chkAll.checked        = n > 0 && n === rows().length;

    // Pasar IDs al link de envío
    const correos = checked.map(c => c.dataset.correo).join(',');
    const ids     = checked.map(c => c.dataset.id).join(',');
    sendLink.href = '<?= BASE_URL ?>/admin/enviar-articulo.php?ids=' + ids;
}

chkAll.addEventListener('change', () => {
    rows().forEach(c => c.checked = chkAll.checked);
    updateBulkBar();
});

document.querySelectorAll('.row-chk').forEach(c =>
    c.addEventListener('change', updateBulkBar)
);

function bulkDelete() {
    const checked = rows().filter(c => c.checked);
    if (!checked.length) return;
    if (!confirm('¿Eliminar ' + checked.length + ' suscriptor(es)?\n\nEsta acción no se puede deshacer.')) return;
    bulkIds.innerHTML = '';
    checked.forEach(c => {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = c.dataset.id;
        bulkIds.appendChild(inp);
    });
    document.getElementById('bulk-form').submit();
}

function clearSelection() {
    rows().forEach(c => c.checked = false);
    chkAll.checked = false;
    updateBulkBar();
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
