<?php
$titulo = 'WordPress · Publicación';
require_once __DIR__ . '/header.php';
require_once ROOT_PATH . '/includes/wordpress-export.php';

$db = getDB();

// Acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle') {
        $actual = wp_config_get('export_activo');
        $nuevo  = $actual === '1' ? '0' : '1';
        $db->prepare("INSERT INTO wp_config (clave, valor) VALUES ('export_activo', ?) ON DUPLICATE KEY UPDATE valor=?")->execute([$nuevo, $nuevo]);
        $estado_str = $nuevo === '1' ? 'activada' : 'desactivada';
        flash('success', 'Exportación automática ' . $estado_str . '.');
        header('Location: ' . BASE_URL . '/admin/wordpress.php');
        exit;
    }

    if ($action === 'exportar_manual') {
        $historia_id = (int)($_POST['historia_id'] ?? 0);
        if ($historia_id > 0) {
            // Forzar exportación aunque esté desactivada la automática
            $db->prepare("INSERT INTO wp_config (clave, valor) VALUES ('export_activo', '1') ON DUPLICATE KEY UPDATE valor='1'")->execute([]);
            $res = wp_exportar_entrega($historia_id, $db);
            // Restaurar estado previo si era manual
            if (!wp_export_activo()) {
                // no restaurar, ya lo cambiamos solo para esto
            }
            flash($res['ok'] ? 'success' : 'error', $res['mensaje']);
        }
        header('Location: ' . BASE_URL . '/admin/wordpress.php');
        exit;
    }

    if ($action === 'guardar_config') {
        $campos = ['wp_url', 'wp_user', 'wp_app_password', 'exportar_como'];
        foreach ($campos as $c) {
            if (isset($_POST[$c])) {
                $v = trim($_POST[$c]);
                $db->prepare("INSERT INTO wp_config (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor=?")->execute([$c, $v, $v]);
            }
        }
        flash('success', 'Configuración guardada.');
        header('Location: ' . BASE_URL . '/admin/wordpress.php');
        exit;
    }
}

$activo   = wp_config_get('export_activo') === '1';
$wp_url   = wp_config_get('wp_url');
$wp_user  = wp_config_get('wp_user');
$wp_pass  = wp_config_get('wp_app_password');
$exp_como = wp_config_get('exportar_como') ?: 'draft';

// Últimos exports
$exports = $db->query("
    SELECT x.*, h.titulo, u.nombre AS exportado_por_nombre
    FROM wp_exports x
    JOIN historias h ON h.id = x.historia_id
    LEFT JOIN usuarios u ON u.id = x.exportado_por
    ORDER BY x.created_at DESC LIMIT 30
")->fetchAll();

// Historias revisadas aún no exportadas
$pendientes = $db->query("
    SELECT h.id, h.titulo, h.updated_at, u.nombre AS periodista
    FROM historias h
    LEFT JOIN usuarios u ON u.id = h.periodista_asignado
    WHERE h.estado = 'revisada'
    AND h.id NOT IN (SELECT historia_id FROM wp_exports WHERE estado='ok')
    ORDER BY h.updated_at DESC
")->fetchAll();
?>

<div style="max-width:900px">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem">
    <div>
        <h1 style="font-size:1.4rem;font-weight:600;color:#f7f8f8;margin:0">WordPress · Publicación automática</h1>
        <p style="color:#62666d;font-size:.85rem;margin:.3rem 0 0">Las entregas aprobadas se envían como borrador a WordPress.</p>
    </div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="toggle">
        <button type="submit" class="btn <?= $activo ? 'btn-danger' : 'btn-success' ?>" style="font-size:.9rem;padding:.5rem 1.2rem">
            <?= $activo ? '⏸ Desactivar exportación' : '▶ Activar exportación' ?>
        </button>
    </form>
</div>

<!-- Estado -->
<div class="card" style="margin-bottom:1.5rem;padding:1rem 1.5rem;border-left:4px solid <?= $activo ? '#22c55e' : '#ef4444' ?>">
    <div style="display:flex;align-items:center;gap:.6rem">
        <span style="font-size:1.2rem"><?= $activo ? '🟢' : '🔴' ?></span>
        <div>
            <strong style="color:#f7f8f8">Exportación automática <?= $activo ? 'ACTIVA' : 'INACTIVA' ?></strong>
            <p style="color:#62666d;font-size:.8rem;margin:.1rem 0 0">
                <?php if ($activo): ?>
                    Al aprobar una entrega en redacción, se publicará automáticamente como borrador en WordPress.
                <?php else: ?>
                    Las aprobaciones no se envían a WordPress. Puedes exportar manualmente desde el listado de pendientes.
                <?php endif ?>
            </p>
        </div>
    </div>
</div>

<!-- Pendientes de exportar -->
<?php if ($pendientes): ?>
<div class="card" style="margin-bottom:1.5rem">
    <h3 style="font-size:1rem;font-weight:600;color:#f7f8f8;margin:0 0 1rem">
        Historias aprobadas pendientes de exportar (<?= count($pendientes) ?>)
    </h3>
    <table style="width:100%;border-collapse:collapse;font-size:.85rem">
        <thead>
            <tr style="color:#62666d;border-bottom:1px solid rgba(255,255,255,.06)">
                <th style="text-align:left;padding:.4rem .5rem">#</th>
                <th style="text-align:left;padding:.4rem .5rem">Título</th>
                <th style="text-align:left;padding:.4rem .5rem">Periodista</th>
                <th style="text-align:left;padding:.4rem .5rem">Aprobada</th>
                <th style="padding:.4rem .5rem"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($pendientes as $p): ?>
            <tr style="border-bottom:1px solid rgba(255,255,255,.04);color:#a0a4ab">
                <td style="padding:.5rem"><?= $p['id'] ?></td>
                <td style="padding:.5rem;color:#f7f8f8"><?= e($p['titulo']) ?></td>
                <td style="padding:.5rem"><?= e($p['periodista'] ?? '—') ?></td>
                <td style="padding:.5rem"><?= date('d/m/Y', strtotime($p['updated_at'])) ?></td>
                <td style="padding:.5rem">
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="exportar_manual">
                        <input type="hidden" name="historia_id" value="<?= $p['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-primary"
                                onclick="return confirm('¿Exportar «<?= e(addslashes($p['titulo'])) ?>» a WordPress?')">
                            → Exportar ahora
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php endif ?>

<!-- Configuración -->
<details class="card" style="margin-bottom:1.5rem">
    <summary style="cursor:pointer;font-size:.95rem;font-weight:600;color:#f7f8f8;padding:.1rem 0">
        ⚙️ Configuración de conexión
    </summary>
    <form method="post" style="margin-top:1.2rem">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="guardar_config">
        <div style="display:grid;gap:1rem">
            <div>
                <label style="font-size:.8rem;color:#62666d;display:block;margin-bottom:.3rem">URL de WordPress</label>
                <input type="url" name="wp_url" value="<?= e($wp_url) ?>" class="form-control" placeholder="https://www.elcorreodevaldivia.cl/leer">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div>
                    <label style="font-size:.8rem;color:#62666d;display:block;margin-bottom:.3rem">Usuario WordPress</label>
                    <input type="text" name="wp_user" value="<?= e($wp_user) ?>" class="form-control">
                </div>
                <div>
                    <label style="font-size:.8rem;color:#62666d;display:block;margin-bottom:.3rem">Application Password</label>
                    <input type="password" name="wp_app_password" value="<?= e($wp_pass) ?>" class="form-control" autocomplete="new-password">
                </div>
            </div>
            <div>
                <label style="font-size:.8rem;color:#62666d;display:block;margin-bottom:.3rem">Publicar como</label>
                <select name="exportar_como" class="form-control" style="max-width:200px">
                    <option value="draft" <?= $exp_como==='draft'?'selected':'' ?>>Borrador</option>
                    <option value="pending" <?= $exp_como==='pending'?'selected':'' ?>>Pendiente de revisión</option>
                    <option value="publish" <?= $exp_como==='publish'?'selected':'' ?>>Publicado directamente</option>
                </select>
            </div>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:1rem;font-size:.85rem">Guardar configuración</button>
    </form>
</details>

<!-- Historial -->
<div class="card">
    <h3 style="font-size:1rem;font-weight:600;color:#f7f8f8;margin:0 0 1rem">Historial de exportaciones</h3>
    <?php if (empty($exports)): ?>
        <p style="color:#62666d;font-size:.85rem">Aún no hay exportaciones registradas.</p>
    <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:.82rem">
        <thead>
            <tr style="color:#62666d;border-bottom:1px solid rgba(255,255,255,.06)">
                <th style="text-align:left;padding:.4rem .5rem">Historia</th>
                <th style="text-align:left;padding:.4rem .5rem">Estado</th>
                <th style="text-align:left;padding:.4rem .5rem">Post WP</th>
                <th style="text-align:left;padding:.4rem .5rem">Exportado por</th>
                <th style="text-align:left;padding:.4rem .5rem">Fecha</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($exports as $x): ?>
            <tr style="border-bottom:1px solid rgba(255,255,255,.04);color:#a0a4ab">
                <td style="padding:.5rem;color:#f7f8f8;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?= e($x['titulo']) ?>
                </td>
                <td style="padding:.5rem">
                    <?php if ($x['estado'] === 'ok'): ?>
                        <span style="color:#22c55e;font-weight:600">✓ OK</span>
                    <?php else: ?>
                        <span style="color:#ef4444;font-weight:600" title="<?= e($x['mensaje']) ?>">✗ Error</span>
                    <?php endif ?>
                </td>
                <td style="padding:.5rem">
                    <?php if ($x['wp_post_id']): ?>
                        <a href="<?= e($wp_url) ?>/?p=<?= $x['wp_post_id'] ?>" target="_blank" style="color:#5e6ad2">#<?= $x['wp_post_id'] ?></a>
                    <?php else: ?>—<?php endif ?>
                </td>
                <td style="padding:.5rem"><?= e($x['exportado_por_nombre'] ?? 'Sistema') ?></td>
                <td style="padding:.5rem"><?= date('d/m/Y H:i', strtotime($x['created_at'])) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    <?php endif ?>
</div>

</div>

<?php require_once __DIR__ . '/footer.php'; ?>
