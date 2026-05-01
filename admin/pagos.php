<?php
$titulo = 'Pagos';
require_once __DIR__ . '/header.php';

$db = getDB();

// Resumen por periodista
$resumen = $db->query("
    SELECT 
        u.id,
        u.nombre,
        u.rut,
        u.banco,
        u.tipo_cuenta,
        u.numero_cuenta,
        COUNT(p.id) AS pagos_realizados,
        COALESCE(SUM(p.liquido), 0) AS total_liquido,
        COALESCE(SUM(p.retencion), 0) AS total_retencion,
        COALESCE(SUM(p.monto_total), 0) AS total_bruto
    FROM usuarios u
    LEFT JOIN pagos p ON u.id = p.periodista_id AND p.estado = 'pagado'
    WHERE u.rol = 'periodista'
    GROUP BY u.id
    ORDER BY u.nombre
")->fetchAll();

// Historias pendientes de pago (revisadas pero no pagadas)
$pendientes = $db->query("
    SELECT h.id, h.titulo, h.presupuesto, u.nombre AS periodista_nombre, h.updated_at
    FROM historias h
    JOIN usuarios u ON h.periodista_asignado = u.id
    WHERE h.estado = 'revisada'
    ORDER BY h.updated_at DESC
")->fetchAll();

// Últimos pagos
$ultimos_pagos = $db->query("
    SELECT p.*, h.titulo, u.nombre AS periodista_nombre
    FROM pagos p
    JOIN historias h ON p.historia_id = h.id
    JOIN usuarios u ON p.periodista_id = u.id
    ORDER BY p.created_at DESC
    LIMIT 20
")->fetchAll();
?>

<div class="page-header">
    <div>
        <h1>💰 Pagos</h1>
        <div class="subtitle">Panel de control de pagos a periodistas</div>
    </div>
</div>

<?php if (!empty($pendientes)): ?>
<div class="card" style="border-color:rgba(245,158,11,.3)">
    <div class="card-header">
        <h2 style="color:var(--warning)">⚠️ Pendientes de Pago</h2>
        <span class="badge badge-pendiente"><?= count($pendientes) ?> historias</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Historia</th>
                    <th>Periodista</th>
                    <th>Monto</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pendientes as $p): ?>
                <tr>
                    <td><?= e($p['titulo']) ?></td>
                    <td><?= e($p['periodista_nombre']) ?></td>
                    <td><strong>$<?= number_format($p['presupuesto'], 0, ',', '.') ?></strong></td>
                    <td>
                        <a href="<?= BASE_URL ?>/admin/historia-editar.php?id=<?= $p['id'] ?>" class="btn btn-success btn-xs">Pagar</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>Resumen por Periodista</h2>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Periodista</th>
                    <th>RUT</th>
                    <th>Banco / Cuenta</th>
                    <th>Pagos</th>
                    <th>Bruto</th>
                    <th>Retención</th>
                    <th>Líquido</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($resumen as $r): ?>
                <tr>
                    <td><strong><?= e($r['nombre']) ?></strong></td>
                    <td><?= e($r['rut'] ?? '—') ?></td>
                    <td style="font-size:.8rem">
                        <?= e($r['banco'] ?? '—') ?>
                        <?php if ($r['numero_cuenta']): ?>
                            <br><?= e($r['tipo_cuenta'] ?? '') ?> · <?= e($r['numero_cuenta']) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= $r['pagos_realizados'] ?></td>
                    <td>$<?= number_format($r['total_bruto'], 0, ',', '.') ?></td>
                    <td style="color:var(--warning)">$<?= number_format($r['total_retencion'], 0, ',', '.') ?></td>
                    <td style="color:var(--success);font-weight:600">$<?= number_format($r['total_liquido'], 0, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($resumen)): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--muted)">No hay periodistas registrados.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Últimos Pagos Realizados</h2>
    </div>
    <?php if (empty($ultimos_pagos)): ?>
        <div class="empty-state"><p>No se han realizado pagos aún.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Historia</th>
                        <th>Periodista</th>
                        <th>Bruto</th>
                        <th>Retención</th>
                        <th>Líquido</th>
                        <th>Fecha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ultimos_pagos as $p): ?>
                    <tr>
                        <td><?= e($p['titulo']) ?></td>
                        <td><?= e($p['periodista_nombre']) ?></td>
                        <td>$<?= number_format($p['monto_total'], 0, ',', '.') ?></td>
                        <td>$<?= number_format($p['retencion'], 0, ',', '.') ?></td>
                        <td><strong>$<?= number_format($p['liquido'], 0, ',', '.') ?></strong></td>
                        <td><?= date('d/m/Y', strtotime($p['fecha_pago'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
