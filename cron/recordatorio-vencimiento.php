<?php
/**
 * Cron: Recordatorio día antes del vencimiento
 * Ejecutar diariamente. Solo CLI.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';

$db = getDB();

$stmt = $db->query("
    SELECT h.id, h.titulo, h.fecha_entrega, h.asignada_en,
           u.nombre AS periodista_nombre, u.email AS periodista_email
    FROM historias h
    JOIN usuarios u ON h.periodista_asignado = u.id
    WHERE h.estado IN ('asignada', 'en_curso')
    AND h.fecha_entrega = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
");

$contador = 0;
while ($h = $stmt->fetch()) {
    $titSafe    = e($h['titulo']);
    $nombreSafe = e($h['periodista_nombre']);
    $idSafe     = (int)$h['id'];
    $fechaSafe  = date('d/m/Y', strtotime($h['fecha_entrega']));

    $subject = "Entrega manana: «" . preg_replace('/[\r\n]+/', ' ', mb_substr($h['titulo'], 0, 100)) . "» vence";
    $msg = "
    <div style='font-family:sans-serif;max-width:600px;margin:0 auto'>
        <h2 style='color:#5e6ad2'>🔔 Entrega mañana</h2>
        <p>Hola <strong>{$nombreSafe}</strong>,</p>
        <p>La historia <strong>{$titSafe}</strong> debe ser entregada <strong>mañana</strong>.</p>
        <blockquote style='border-left:3px solid #5e6ad2;padding:1rem;margin:1rem 0;background:#111214'>
            <strong>{$titSafe}</strong><br>
            Fecha de entrega: {$fechaSafe}
        </blockquote>
        <p>Si ya tienes el borrador listo, ingresa a la plataforma y súbelo.</p>
        <p><a href='" . BASE_URL . "/periodista/entregar.php?id={$idSafe}' style='display:inline-block;padding:10px 20px;background:#5e6ad2;color:#fff;text-decoration:none;border-radius:8px'>Entregar ahora</a></p>
        <hr style='border-color:rgba(255,255,255,0.08)'>
        <p style='font-size:.8rem;color:#62666d'>El Correo de Valdivia · Sistema de Redacción</p>
    </div>";

    if (enviarCorreo($h['periodista_email'], $subject, $msg)) {
        $contador++;
    }
}

$admin = $db->query("SELECT email FROM usuarios WHERE rol='admin' AND activo=1 LIMIT 1")->fetch();
$vencimientos = $db->query("
    SELECT h.titulo, u.nombre FROM historias h
    JOIN usuarios u ON h.periodista_asignado = u.id
    WHERE h.estado IN ('asignada', 'en_curso')
    AND h.fecha_entrega = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
")->fetchAll();

if ($admin && !empty($vencimientos)) {
    $lista = '';
    foreach ($vencimientos as $v) {
        $lista .= "<li>" . e($v['titulo']) . " — " . e($v['nombre']) . "</li>";
    }
    $subject = "Vencimientos manana — " . count($vencimientos) . " historias";
    $msg = "<div style='font-family:sans-serif;max-width:600px;margin:0 auto'>
        <h2 style='color:#5e6ad2'>📋 Historias que vencen mañana</h2>
        <ul>$lista</ul>
        <p><a href='" . BASE_URL . "/admin/index.php' style='display:inline-block;padding:10px 20px;background:#5e6ad2;color:#fff;text-decoration:none;border-radius:8px'>Ir al panel</a></p>
    </div>";
    enviarCorreo($admin['email'], $subject, $msg);
}

echo "Recordatorios de vencimiento enviados: $contador\n";
