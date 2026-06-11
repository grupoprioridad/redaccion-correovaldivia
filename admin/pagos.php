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

// Historias revisadas con boleta recibida (listas para pagar)
$con_boleta = $db->query("
    SELECT h.id, h.codigo, h.titulo, h.presupuesto, h.monto_total_a_pagar, h.boleta_path, h.boleta_subida_en,
           u.nombre AS periodista_nombre, u.banco, u.tipo_cuenta, u.numero_cuenta, u.rut,
           dc.pdf_generado AS tiene_cesion
    FROM historias h
    JOIN usuarios u ON h.periodista_asignado = u.id
    LEFT JOIN entregas e ON e.historia_id = h.id
    LEFT JOIN documentos_cesion dc ON dc.entrega_id = e.id
    WHERE h.estado = 'revisada' AND h.boleta_path IS NOT NULL
    ORDER BY h.boleta_subida_en ASC
")->fetchAll();

// Historias revisadas sin boleta (esperando)
$sin_boleta = $db->query("
    SELECT h.id, h.codigo, h.titulo, h.presupuesto, h.monto_total_a_pagar,
           u.nombre AS periodista_nombre,
           dc.pdf_generado AS tiene_cesion
    FROM historias h
    JOIN usuarios u ON h.periodista_asignado = u.id
    LEFT JOIN entregas e ON e.historia_id = h.id
    LEFT JOIN documentos_cesion dc ON dc.entrega_id = e.id
    WHERE h.estado = 'revisada' AND (h.boleta_path IS NULL OR h.boleta_path = '')
    ORDER BY h.updated_at ASC
")->fetchAll();

// Últimos pagos
$ultimos_pagos = $db->query("
    SELECT p.*, h.titulo, h.codigo, h.boleta_path, u.nombre AS periodista_nombre
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

<?php if (!empty($con_boleta)): ?>
<div class="card" style="border-color:rgba(39,166,68,.35)">
    <div class="card-header">
        <h2 style="color:var(--success)">🧾 Boletas recibidas — Listas para pagar</h2>
        <span class="badge badge-revisada"><?= count($con_boleta) ?> historias</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Ref.</th>
                    <th>Historia</th>
                    <th>Periodista</th>
                    <th>Datos de transferencia</th>
                    <th>Cesión</th>
                    <th>Líquido a pagar</th>
                    <th>Boleta</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($con_boleta as $p):
                    $mt  = (int)($p['monto_total_a_pagar'] ?? $p['presupuesto']);
                    $liq = $mt - (int)round($mt * 0.1525);
                    $bext = strtolower(pathinfo($p['boleta_path'], PATHINFO_EXTENSION));
                ?>
                <tr>
                    <td><span style="font-family:monospace;font-size:.8rem;font-weight:700;color:var(--accent)"><?= e($p['codigo'] ?? '—') ?></span></td>
                    <td><strong><?= e($p['titulo']) ?></strong></td>
                    <td>
                        <?= e($p['periodista_nombre']) ?>
                        <?php if ($p['rut']): ?><br><span style="font-size:.72rem;color:var(--muted)"><?= e($p['rut']) ?></span><?php endif; ?>
                    </td>
                    <td style="font-size:.8rem">
                        <?php if ($p['banco']): ?>
                            <?= e($p['banco']) ?><br>
                            <?= e($p['tipo_cuenta'] ?? '') ?><?= $p['numero_cuenta'] ? ' · ' . e($p['numero_cuenta']) : '' ?>
                        <?php else: ?>
                            <span style="color:var(--warning)">Sin datos bancarios</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center">
                        <?= $p['tiene_cesion'] ? '<span style="color:var(--success)">✅</span>' : '<span style="color:var(--error)">⚠️</span>' ?>
                    </td>
                    <td><strong style="color:var(--success)">$<?= number_format($liq, 0, ',', '.') ?></strong></td>
                    <td>
                        <a href="<?= e(urlImagen($p['boleta_path'])) ?>" target="_blank" class="btn btn-secondary btn-xs">
                            <?= $bext === 'pdf' ? '📄 PDF' : '🖼 Ver' ?>
                        </a>
                        <span style="display:block;font-size:.68rem;color:var(--muted)"><?= date('d/m/Y', strtotime($p['boleta_subida_en'])) ?></span>
                    </td>
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

<?php if (!empty($sin_boleta)): ?>
<div class="card" style="border-color:rgba(245,158,11,.3)">
    <div class="card-header">
        <h2 style="color:var(--warning)">⏳ Esperando boleta del periodista</h2>
        <span class="badge badge-pendiente"><?= count($sin_boleta) ?> historias</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Ref.</th>
                    <th>Historia</th>
                    <th>Periodista</th>
                    <th>Cesión</th>
                    <th>Monto bruto</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sin_boleta as $p): ?>
                <tr>
                    <td><span style="font-family:monospace;font-size:.8rem;font-weight:700;color:var(--accent)"><?= e($p['codigo'] ?? '—') ?></span></td>
                    <td><?= e($p['titulo']) ?></td>
                    <td><?= e($p['periodista_nombre']) ?></td>
                    <td style="text-align:center">
                        <?= $p['tiene_cesion'] ? '<span style="color:var(--success)">✅</span>' : '<span style="color:var(--error)">⚠️</span>' ?>
                    </td>
                    <td>$<?= number_format($p['monto_total_a_pagar'] ?? $p['presupuesto'], 0, ',', '.') ?></td>
                    <td>
                        <a href="<?= BASE_URL ?>/admin/historia-editar.php?id=<?= $p['id'] ?>" class="btn btn-secondary btn-xs">Ver</a>
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
                        <th>Ref.</th>
                        <th>Historia</th>
                        <th>Periodista</th>
                        <th>Bruto</th>
                        <th>Retención</th>
                        <th>Líquido</th>
                        <th>Fecha</th>
                        <th>Boleta</th>
                        <th>Comprobante transferencia</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ultimos_pagos as $p): ?>
                    <tr>
                        <td><span style="font-family:monospace;font-size:.8rem;font-weight:700;color:var(--accent)"><?= e($p['codigo'] ?? '—') ?></span></td>
                        <td><a href="<?= BASE_URL ?>/admin/historia-editar?id=<?= (int)$p['historia_id'] ?>" style="color:var(--text)"><?= e($p['titulo']) ?></a></td>
                        <td><?= e($p['periodista_nombre']) ?></td>
                        <td>$<?= number_format($p['monto_total'], 0, ',', '.') ?></td>
                        <td style="color:var(--warning)">$<?= number_format($p['retencion'], 0, ',', '.') ?></td>
                        <td><strong style="color:var(--success)">$<?= number_format($p['liquido'], 0, ',', '.') ?></strong></td>
                        <td><?= date('d/m/Y', strtotime($p['fecha_pago'])) ?></td>
                        <td>
                            <?php if ($p['boleta_path']): ?>
                                <?php $extb = strtolower(pathinfo($p['boleta_path'], PATHINFO_EXTENSION)); ?>
                                <a href="<?= e(urlImagen($p['boleta_path'])) ?>" target="_blank" class="btn btn-secondary btn-xs">
                                    <?= $extb === 'pdf' ? '📄 PDF' : '🖼 Ver' ?>
                                </a>
                            <?php else: ?>
                                <span style="color:var(--muted);font-size:.8rem">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($p['comprobante']): ?>
                                <?php $ext = strtolower(pathinfo($p['comprobante'], PATHINFO_EXTENSION)); ?>
                                <a href="<?= e(urlImagen($p['comprobante'])) ?>" target="_blank" class="btn btn-secondary btn-xs">
                                    <?= $ext === 'pdf' ? '📄 PDF' : '🖼 Ver' ?>
                                </a>
                            <?php else: ?>
                                <span style="color:var(--muted);font-size:.8rem">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
