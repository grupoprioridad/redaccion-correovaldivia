<?php
$titulo = 'Mis Historias';
require_once __DIR__ . '/header.php';

$db = getDB();
$user_id = $_SESSION['usuario_id'];

$mis_historias = $db->prepare("
    SELECT h.*, c.nombre AS categoria_nombre
    FROM historias h
    LEFT JOIN categorias_redaccion c ON h.categoria_id = c.id
    WHERE periodista_asignado = ? 
    ORDER BY FIELD(estado,'asignada','en_curso','entregada','revisada','pagada'), fecha_entrega ASC
");
$mis_historias->execute([$user_id]);
$mis_h = $mis_historias->fetchAll();

// Cargar IDs de historias bloqueadas por admin (última actividad < 10 min)
$historias_bloqueadas = [];
try {
    $db->exec("CREATE TABLE IF NOT EXISTS historia_locks (historia_id INT NOT NULL PRIMARY KEY, admin_id INT NOT NULL, locked_at DATETIME NOT NULL)");
    $bloqRows = $db->query("SELECT historia_id FROM historia_locks WHERE locked_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)")->fetchAll(PDO::FETCH_COLUMN);
    $historias_bloqueadas = array_flip(array_map('intval', $bloqRows));
} catch (Throwable $e) {}

$disponibles = $db->prepare("
    SELECT h.*, u.nombre AS creador_nombre, c.nombre AS categoria_nombre
    FROM historias h
    JOIN usuarios u ON h.creada_por = u.id
    LEFT JOIN categorias_redaccion c ON h.categoria_id = c.id
    WHERE h.estado = 'disponible'
    AND (
        h.visible_para_todos = 1
        OR h.id IN (SELECT historia_id FROM historia_visibilidad WHERE usuario_id = ?)
    )
    ORDER BY h.fecha_entrega ASC
");
$disponibles->execute([$user_id]);
$disps = $disponibles->fetchAll();

function gradientForId($id) {
    $palettes = [
        ['from' => '#5e6ad2', 'to' => '#828fff'],
        ['from' => '#7c3aed', 'to' => '#a78bfa'],
        ['from' => '#2563eb', 'to' => '#60a5fa'],
        ['from' => '#0891b2', 'to' => '#22d3ee'],
        ['from' => '#059669', 'to' => '#34d399'],
        ['from' => '#d97706', 'to' => '#fbbf24'],
        ['from' => '#dc2626', 'to' => '#f87171'],
        ['from' => '#db2777', 'to' => '#f472b6'],
    ];
    return $palettes[$id % count($palettes)];
}

function initials($str) {
    $words = explode(' ', $str);
    $inits = '';
    foreach ($words as $w) {
        if (!empty($w)) $inits .= mb_strtoupper(mb_substr($w, 0, 1));
        if (mb_strlen($inits) >= 2) break;
    }
    return $inits ?: '?';
}
?>

<div class="page-header">
    <h1>Mis Historias</h1>
</div>

<?php if (!empty($mis_h)): ?>
<div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr))">
    <?php 
    $counts = ['asignada'=>0,'en_curso'=>0,'entregada'=>0,'revisada'=>0,'pagada'=>0];
    foreach ($mis_h as $h) $counts[$h['estado']]++;
    ?>
    <div class="stat-card"><div class="stat-value"><?= $counts['asignada']+$counts['en_curso'] ?></div><div class="stat-label">En curso</div></div>
    <div class="stat-card"><div class="stat-value"><?= $counts['entregada'] ?></div><div class="stat-label">Entregadas</div></div>
    <div class="stat-card"><div class="stat-value"><?= $counts['revisada'] ?></div><div class="stat-label">Aprobadas</div></div>
    <div class="stat-card"><div class="stat-value"><?= $counts['pagada'] ?></div><div class="stat-label">Pagadas</div></div>
</div>

<div class="card" style="border:none;background:transparent;padding:0">
    <div class="card-header" style="padding:.5rem 0;margin-bottom:1.2rem">
        <h2 style="font-size:1.1rem">📋 Mis Historias</h2>
    </div>
    <div class="card-grid" style="grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:1.5rem">
        <?php foreach ($mis_h as $h): 
            $pal = gradientForId($h['id']);
        ?>
        <?php
            // Paso actual del flujo
            $paso_c = 1;
            if ($h['estado'] === 'entregada') $paso_c = 2;
            elseif ($h['estado'] === 'revisada' && empty($h['boleta_path'])) $paso_c = 3;
            elseif ($h['estado'] === 'revisada' && !empty($h['boleta_path'])) $paso_c = 4;
            elseif ($h['estado'] === 'pagada') $paso_c = 5;
            $steps_c = [1=>'Escribir',2=>'Entregada',3=>'Aprobada',4=>'Boleta',5=>'Pagada'];
        ?>
        <div class="historia-card" data-id="<?= $h['id'] ?>">
            <div class="hc-head" style="background:linear-gradient(135deg,<?= $pal['from'] ?>,<?= $pal['to'] ?>);padding-bottom:2.8rem">
                <div class="hc-initials"><?= initials($h['titulo']) ?></div>
                <div class="hc-badge-row">
                    <span class="badge badge-<?= $h['estado'] ?>"><?= str_replace('_', ' ', $h['estado']) ?></span>
                </div>
                <!-- Mini stepper -->
                <div style="position:absolute;bottom:.6rem;left:1.2rem;right:1.2rem">
                    <div style="display:flex;align-items:center">
                    <?php for ($n = 1; $n <= 5; $n++):
                        $done    = $paso_c > $n;
                        $current = $paso_c === $n;
                        $dotBg   = ($done || $current) ? 'rgba(255,255,255,<?= $done ? ".85" : "1" ?>)' : 'transparent';
                        $dotBd   = $done ? 'rgba(255,255,255,.85)' : ($current ? 'rgba(255,255,255,1)' : 'rgba(255,255,255,.3)');
                        $lineBg  = $done ? 'rgba(255,255,255,.7)' : 'rgba(255,255,255,.2)';
                        $labelCol = $done ? 'rgba(255,255,255,.75)' : ($current ? 'rgba(255,255,255,1)' : 'rgba(255,255,255,.35)');
                    ?>
                        <div style="display:flex;flex-direction:column;align-items:center;gap:3px;flex-shrink:0">
                            <div style="width:11px;height:11px;border-radius:50%;
                                        background:<?= ($done||$current)?'rgba(255,255,255,'.($done?.75:1).')':'transparent' ?>;
                                        border:1.5px solid <?= $dotBd ?>;
                                        <?= $current ? 'box-shadow:0 0 0 3px rgba(255,255,255,.25)' : '' ?>;
                                        display:flex;align-items:center;justify-content:center">
                                <?php if ($done): ?>
                                <svg width="6" height="6" viewBox="0 0 24 24" fill="none" stroke="<?= $pal['from'] ?>" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                <?php endif; ?>
                            </div>
                            <span style="font-size:.48rem;color:<?= $labelCol ?>;font-weight:<?= $current?'700':'400' ?>;white-space:nowrap;line-height:1"><?= $steps_c[$n] ?></span>
                        </div>
                        <?php if ($n < 5): ?>
                        <div style="flex:1;height:1.5px;background:<?= $lineBg ?>;margin-bottom:11px;min-width:4px"></div>
                        <?php endif; ?>
                    <?php endfor; ?>
                    </div>
                </div>
            </div>
            <div class="hc-body">
                <h3 class="hc-title"><?= e($h['titulo']) ?></h3>
                <?php if ($h['foco_periodistico']): ?>
                <p class="hc-desc">
                    <?= nl2br(e(mb_substr($h['foco_periodistico'], 0, 120))) ?><?= mb_strlen($h['foco_periodistico']) > 120 ? '...' : '' ?>
                </p>
                <?php endif; ?>
                <div class="hc-meta">
                <?php if ($h['categoria_nombre']): ?>
                    <span class="hc-meta-item">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9h16"/><path d="M4 15h16"/><path d="M10 3 8 21"/><path d="M16 3l-2 18"/></svg>
                        <?= e($h['categoria_nombre']) ?>
                    </span>
                    <?php endif; ?>
                    <span class="hc-meta-item">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <?= date('d/m/Y', strtotime($h['fecha_entrega'])) ?>
                    </span>
                    <?php if ($h['monto_total_a_pagar'] ?? $h['presupuesto']): ?>
                    <span class="hc-meta-item">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                        $<?= number_format($h['monto_total_a_pagar'] ?? $h['presupuesto'], 0, ',', '.') ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($h['extension_esperada']): ?>
                    <span class="hc-meta-item">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        <?= e($h['extension_esperada']) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <?php if ($h['asignada_en']): ?>
                <?php
                $inicio = new DateTime($h['asignada_en']);
                $entrega = new DateTime($h['fecha_entrega']);
                $ahora = new DateTime();
                $total_dias = max(1, $inicio->diff($entrega)->days);
                $transcurridos = max(0, $inicio->diff($ahora)->days);
                $pct = min(100, round(($transcurridos / $total_dias) * 100));
                ?>
                <div class="hc-timing">
                    <div class="hc-timing-label">Tomada el <?= date('d/m/Y', strtotime($h['asignada_en'])) ?></div>
                    <div class="hc-progress">
                        <div class="hc-progress-bar" style="width:<?= $pct ?>%;background:<?= $pct > 80 ? '#ef4444' : ($pct > 50 ? '#f59e0b' : $pal['from']) ?>"></div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="hc-actions">
                    <a href="<?= BASE_URL ?>/periodista/historia.php?id=<?= $h['id'] ?>" class="hc-btn hc-btn-primary">Ver detalle</a>
                    <?php if (in_array($h['estado'], ['asignada','en_curso'])): ?>
                    <a href="<?= BASE_URL ?>/periodista/entregar.php?id=<?= $h['id'] ?>" class="hc-btn hc-btn-accent" style="background:<?= $pal['from'] ?>">Entregar</a>
                    <?php elseif ($h['estado'] === 'entregada'): ?>
                    <?php if (!isset($historias_bloqueadas[(int)$h['id']])): ?>
                    <a href="<?= BASE_URL ?>/periodista/entregar.php?id=<?= $h['id'] ?>" class="hc-btn hc-btn-accent" style="background:#5e6ad2">✏️ Editar</a>
                    <?php else: ?>
                    <span class="hc-btn" style="opacity:.55;cursor:not-allowed;background:var(--surface2)" title="El administrador tiene esta historia abierta">🔒 En revisión</span>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php elseif (empty($disps)): ?>
    <div class="empty-state">
        <div class="icon">📭</div>
        <p>No tienes historias asignadas ni disponibles. Cuando el administrador te asigne una, aparecerá aquí.</p>
    </div>
<?php endif; ?>

<?php if (!empty($disps)): ?>
<div style="margin-top:2.5rem">
    <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem">
        <h2 style="font-size:1.1rem;font-weight:600">📢 Historias Disponibles</h2>
        <span class="badge badge-disponible" style="font-size:.75rem;padding:4px 14px"><?= count($disps) ?> disponibles</span>
    </div>
    <div class="card-grid" style="grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:1.5rem">
        <?php foreach ($disps as $h): 
            $pal = gradientForId($h['id']);
        ?>
        <div class="historia-card historia-card-disponible" data-id="<?= $h['id'] ?>">
            <div class="hc-head" style="background:linear-gradient(135deg,<?= $pal['from'] ?>,<?= $pal['to'] ?>)">
                <div class="hc-initials"><?= initials($h['titulo']) ?></div>
                <div class="hc-badge-row">
                    <span class="badge badge-disponible">Disponible</span>
                </div>
                <div class="hc-pulse"></div>
            </div>
            <div class="hc-body">
                <h3 class="hc-title"><?= e($h['titulo']) ?></h3>
                <?php if ($h['descripcion']): ?>
                <p class="hc-desc">
                    <?= nl2br(e(mb_substr($h['descripcion'], 0, 150))) ?><?= mb_strlen($h['descripcion']) > 150 ? '...' : '' ?>
                </p>
                <?php endif; ?>
                <div class="hc-meta">
                    <?php if ($h['categoria_nombre']): ?>
                    <span class="hc-meta-item">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9h16"/><path d="M4 15h16"/><path d="M10 3 8 21"/><path d="M16 3l-2 18"/></svg>
                        <?= e($h['categoria_nombre']) ?>
                    </span>
                    <?php endif; ?>
                    <span class="hc-meta-item">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Entrega: <?= date('d/m/Y', strtotime($h['fecha_entrega'])) ?>
                    </span>
                    <?php if ($h['monto_total_a_pagar'] ?? $h['presupuesto']): ?>
                    <span class="hc-meta-item">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                        $<?= number_format($h['monto_total_a_pagar'] ?? $h['presupuesto'], 0, ',', '.') ?>
                    </span>
                    <?php endif; ?>
                </div>
                <form method="post" action="<?= BASE_URL ?>/periodista/tomar" style="margin-top:auto">
                    <?= csrf_field() ?>
                    <input type="hidden" name="historia_id" value="<?= $h['id'] ?>">
                    <button type="submit" class="hc-btn hc-btn-primary hc-btn-full" onclick="return confirm('¿Tomar esta historia? El timing de entrega comenzará.')">Tomar Historia</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<style>
.historia-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    overflow: hidden;
    transition: all .35s cubic-bezier(.16,1,.3,1);
    display: flex;
    flex-direction: column;
    position: relative;
}
.historia-card:hover {
    transform: translateY(-4px);
    border-color: rgba(255,255,255,0.12);
    box-shadow: 0 12px 48px rgba(0,0,0,0.5), 0 0 0 1px rgba(94,106,210,0.08);
}
.historia-card-disponible {
    border-color: rgba(94,106,210,0.15);
    box-shadow: 0 0 0 1px rgba(94,106,210,0.03);
}
.historia-card-disponible:hover {
    border-color: rgba(94,106,210,0.35);
    box-shadow: 0 12px 48px rgba(0,0,0,0.5), 0 0 20px rgba(94,106,210,0.08);
}
.hc-head {
    padding: 1.5rem 1.5rem 1rem;
    position: relative;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    min-height: 100px;
}
.hc-initials {
    font-family: 'Geist', system-ui, sans-serif;
    font-size: 2rem;
    font-weight: 700;
    color: rgba(255,255,255,0.9);
    letter-spacing: -2px;
    line-height: 1;
    text-shadow: 0 2px 8px rgba(0,0,0,0.2);
}
.hc-badge-row { display: flex; gap: .3rem; }
.hc-pulse {
    position: absolute; top: 1rem; right: 4rem;
    width: 8px; height: 8px; border-radius: 50%;
    background: rgba(255,255,255,0.6);
    animation: hc-pulse 2s ease-in-out infinite;
}
@keyframes hc-pulse {
    0%, 100% { opacity: .4; transform: scale(1); }
    50% { opacity: 1; transform: scale(1.3); }
}
.hc-body { padding: 1.2rem 1.5rem 1.5rem; display: flex; flex-direction: column; flex: 1; }
.hc-title {
    font-family: 'Geist', system-ui, sans-serif;
    font-size: 1.15rem; font-weight: 650;
    color: var(--white); letter-spacing: -.5px;
    line-height: 1.3; margin-bottom: .6rem;
}
.hc-desc { font-size: .82rem; color: var(--text2); line-height: 1.6; margin-bottom: 1rem; flex: 1; }
.hc-meta {
    display: flex; flex-wrap: wrap; gap: .8rem;
    margin-bottom: 1rem; padding: .7rem 0;
    border-top: 1px solid var(--border-subtle);
    border-bottom: 1px solid var(--border-subtle);
}
.hc-meta-item {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: .75rem; color: var(--muted);
    font-family: 'Geist Mono', monospace;
}
.hc-meta-item svg { opacity: .6; flex-shrink: 0; }
.hc-timing { margin-bottom: 1rem; }
.hc-timing-label { font-size: .7rem; color: var(--muted); margin-bottom: .4rem; font-family: 'Geist Mono', monospace; }
.hc-progress { height: 4px; background: var(--surface2); border-radius: 99px; overflow: hidden; }
.hc-progress-bar { height: 100%; border-radius: 99px; transition: width .5s ease; }
.hc-actions { display: flex; gap: .6rem; margin-top: auto; }
.hc-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: .55rem 1rem; border-radius: 10px;
    font-size: .8rem; font-weight: 500; cursor: pointer;
    border: none; transition: all .25s; font-family: inherit; text-decoration: none;
}
.hc-btn-primary {
    background: var(--surface2); color: var(--text); border: 1px solid var(--border);
}
.hc-btn-primary:hover { background: var(--surface3); color: var(--white); transform: translateY(-1px); }
.hc-btn-accent { color: #fff; border: none; }
.hc-btn-accent:hover { filter: brightness(1.15); transform: translateY(-1px); box-shadow: 0 4px 16px rgba(0,0,0,0.3); }
.hc-btn-full { width: 100%; justify-content: center; padding: .7rem; font-size: .85rem; }
@media (max-width: 768px) { .card-grid { grid-template-columns: 1fr !important; } }
</style>

<?php require_once __DIR__ . '/footer.php'; ?>
