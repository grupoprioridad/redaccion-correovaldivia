<?php
$titulo = 'Denuncias';
require_once __DIR__ . '/header.php';

// getSiteDB() se define en includes/config.php
$site        = getSiteDB();
$site_root   = '/var/www/elcorreodevaldivia/';

// ── Eliminar denuncia + archivos ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'eliminar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $archivos = $site->prepare("SELECT ruta FROM denuncia_archivos WHERE denuncia_id = ?");
            $archivos->execute([$id]);
            foreach ($archivos->fetchAll() as $a) {
                $path = $site_root . ltrim($a['ruta'], '/');
                if (file_exists($path)) @unlink($path);
            }
            $site->prepare("DELETE FROM denuncia_archivos WHERE denuncia_id = ?")->execute([$id]);
            $site->prepare("DELETE FROM denuncias WHERE id = ?")->execute([$id]);
            flash('success', 'Denuncia eliminada.');
        }
    }
    header('Location: ' . BASE_URL . '/admin/denuncias.php');
    exit;
}

// ── Ver detalle de una denuncia ───────────────────────────────────────────────
$ver = (int)($_GET['ver'] ?? 0);
$detalle = null;
$archivos_detalle = [];
if ($ver > 0) {
    $stmt = $site->prepare("SELECT * FROM denuncias WHERE id = ?");
    $stmt->execute([$ver]);
    $detalle = $stmt->fetch();
    if ($detalle) {
        $stmt2 = $site->prepare("SELECT * FROM denuncia_archivos WHERE denuncia_id = ? ORDER BY fecha");
        $stmt2->execute([$ver]);
        $archivos_detalle = $stmt2->fetchAll();
    }
}

// ── Listado ───────────────────────────────────────────────────────────────────
$denuncias = $site->query("
    SELECT d.*,
           (SELECT COUNT(*) FROM denuncia_archivos WHERE denuncia_id = d.id) AS num_archivos
    FROM denuncias d
    ORDER BY d.fecha DESC
")->fetchAll();

$total      = count($denuncias);
$anonimas   = count(array_filter($denuncias, fn($d) => $d['anonimo']));
$con_datos  = $total - $anonimas;
?>

<div class="page-header">
    <div>
        <h1>🔒 Denuncias</h1>
        <div class="subtitle">Historias e información recibida de lectores</div>
    </div>
</div>

<!-- Stats -->
<div class="stats-grid" style="margin-bottom:1.5rem">
    <div class="stat-card">
        <div class="stat-value"><?= $total ?></div>
        <div class="stat-label">Total recibidas</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="color:var(--warning)"><?= $anonimas ?></div>
        <div class="stat-label">Anónimas</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="color:var(--accent)"><?= $con_datos ?></div>
        <div class="stat-label">Con datos de contacto</div>
    </div>
</div>

<?php if ($detalle): ?>
<!-- ── Panel de detalle ───────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:1.5rem;border-color:rgba(94,106,210,.25)">
    <div class="card-header" style="align-items:flex-start">
        <div>
            <h2 style="margin-bottom:.3rem">
                Denuncia #<?= $detalle['id'] ?>
                <?php if ($detalle['anonimo']): ?>
                    <span class="badge" style="background:rgba(245,158,11,.15);color:var(--warning);margin-left:.5rem">Anónima</span>
                <?php else: ?>
                    <span class="badge" style="background:rgba(59,130,246,.15);color:#60a5fa;margin-left:.5rem">Con datos</span>
                <?php endif; ?>
            </h2>
            <div style="font-size:.75rem;color:var(--muted)">
                Recibida el <?= date('d/m/Y \a \l\a\s H:i', strtotime($detalle['fecha'])) ?>
            </div>
        </div>
        <a href="?" class="btn btn-secondary btn-sm">✕ Cerrar</a>
    </div>

    <?php if (!$detalle['anonimo'] && ($detalle['nombre'] || $detalle['email'] || $detalle['telefono'])): ?>
    <div style="display:flex;gap:2rem;flex-wrap:wrap;padding:.8rem 0;border-bottom:1px solid var(--border);margin-bottom:1rem">
        <?php if ($detalle['nombre']): ?>
        <div>
            <div style="font-size:.62rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:.2rem">Nombre</div>
            <div style="font-weight:600"><?= e($detalle['nombre']) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($detalle['email']): ?>
        <div>
            <div style="font-size:.62rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:.2rem">Correo</div>
            <div><a href="mailto:<?= e($detalle['email']) ?>" style="color:var(--accent)"><?= e($detalle['email']) ?></a></div>
        </div>
        <?php endif; ?>
        <?php if ($detalle['telefono']): ?>
        <div>
            <div style="font-size:.62rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:.2rem">Teléfono</div>
            <div><?= e($detalle['telefono']) ?></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div style="background:var(--surface2);border-radius:8px;padding:1.2rem;line-height:1.75;color:var(--text2);font-size:.95rem;white-space:pre-wrap;margin-bottom:1.2rem"><?= e($detalle['descripcion']) ?></div>

    <?php if ($archivos_detalle): ?>
    <div style="margin-bottom:1.2rem">
        <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);margin-bottom:.6rem">
            Archivos adjuntos (<?= count($archivos_detalle) ?>)
        </div>
        <div style="display:flex;flex-direction:column;gap:.4rem">
            <?php foreach ($archivos_detalle as $a):
                $ext  = strtolower(pathinfo($a['nombre_archivo'], PATHINFO_EXTENSION));
                $icon = in_array($ext, ['jpg','jpeg','png','gif','webp']) ? '🖼' :
                       ($ext === 'pdf' ? '📄' : (in_array($ext, ['mp3','mp4']) ? '🎬' : '📎'));
                $url  = 'https://www.elcorreodevaldivia.cl/' . ltrim($a['ruta'], '/');
            ?>
            <div style="display:flex;align-items:center;gap:.7rem;padding:.5rem .8rem;background:var(--surface);border:1px solid var(--border);border-radius:8px;font-size:.85rem">
                <span><?= $icon ?></span>
                <span style="flex:1;color:var(--text2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($a['nombre_archivo']) ?></span>
                <a href="<?= e($url) ?>" target="_blank" rel="noopener"
                   class="btn btn-secondary btn-xs">Ver ↗</a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <form method="post" onsubmit="return confirm('¿Eliminar esta denuncia y todos sus archivos?\n\nEsta acción no se puede deshacer.')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="eliminar">
        <input type="hidden" name="id"     value="<?= $detalle['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm">🗑 Eliminar denuncia</button>
    </form>
</div>
<?php endif; ?>

<!-- ── Listado ─────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <h2>Todas las denuncias</h2>
        <span class="badge"><?= $total ?> <?= $total === 1 ? 'denuncia' : 'denuncias' ?></span>
    </div>

    <?php if (empty($denuncias)): ?>
    <div class="empty-state">
        <div style="font-size:2rem;margin-bottom:.5rem">📬</div>
        <p>No hay denuncias recibidas todavía.</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Descripción</th>
                    <th>Remitente</th>
                    <th>Archivos</th>
                    <th>Fecha</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($denuncias as $d): ?>
                <tr <?= $ver === (int)$d['id'] ? 'style="background:rgba(94,106,210,.07)"' : '' ?>>
                    <td style="color:var(--muted);font-size:.8rem"><?= $d['id'] ?></td>
                    <td style="max-width:320px">
                        <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.88rem">
                            <?= e(mb_substr($d['descripcion'], 0, 100)) ?><?= mb_strlen($d['descripcion']) > 100 ? '…' : '' ?>
                        </div>
                    </td>
                    <td>
                        <?php if ($d['anonimo']): ?>
                            <span class="badge" style="background:rgba(245,158,11,.12);color:var(--warning);font-size:.62rem">Anónima</span>
                        <?php elseif ($d['nombre'] || $d['email']): ?>
                            <div style="font-size:.82rem;font-weight:600"><?= e($d['nombre']) ?></div>
                            <?php if ($d['email']): ?>
                            <div style="font-size:.75rem;color:var(--muted)"><?= e($d['email']) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--muted);font-size:.8rem">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center">
                        <?php if ($d['num_archivos'] > 0): ?>
                            <span class="badge" style="background:rgba(94,106,210,.12);color:var(--accent-h)">
                                📎 <?= $d['num_archivos'] ?>
                            </span>
                        <?php else: ?>
                            <span style="color:var(--muted);font-size:.8rem">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:var(--muted);font-size:.8rem;white-space:nowrap">
                        <?= date('d/m/Y H:i', strtotime($d['fecha'])) ?>
                    </td>
                    <td style="white-space:nowrap">
                        <a href="?ver=<?= $d['id'] ?>" class="btn btn-secondary btn-xs">👁 Ver</a>
                        <form method="post" style="display:inline"
                              onsubmit="return confirm('¿Eliminar denuncia #<?= $d['id'] ?>?\n\nEsta acción no se puede deshacer.')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="eliminar">
                            <input type="hidden" name="id"     value="<?= $d['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-xs">🗑</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
