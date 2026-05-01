<?php
/**
 * Cron: Recordatorio a mitad del plazo
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
           u.nombre AS periodista_nombre, u.email AS periodista_email,
           DATEDIFF(h.fecha_entrega, h.asignada_en) AS plazo_total,
           DATEDIFF(NOW(), h.asignada_en) AS dias_transcurridos
    FROM historias h
    JOIN usuarios u ON h.periodista_asignado = u.id
    WHERE h.estado IN ('asignada', 'en_curso')
    AND h.asignada_en IS NOT NULL
    HAVING plazo_total > 3
    AND dias_transcurridos >= ROUND(plazo_total * 0.45)
    AND dias_transcurridos <= ROUND(plazo_total * 0.55)
");

$contador = 0;
while ($h = $stmt->fetch()) {
    $dias_restantes = max(1, (new DateTime($h['fecha_entrega']))->diff(new DateTime())->days);

    $titSafe    = e($h['titulo']);
    $nombreSafe = e($h['periodista_nombre']);
    $idSafe     = (int)$h['id'];

    $subject = "Recordatorio: mitad del plazo para «" . preg_replace('/[\r\n]+/', ' ', mb_substr($h['titulo'], 0, 100)) . "»";
    $msg = "
    <div style='font-family:sans-serif;max-width:600px;margin:0 auto'>
        <h2 style='color:#5e6ad2'>⏰ Recordatorio de plazo</h2>
        <p>Hola <strong>{$nombreSafe}</strong>,</p>
        <p>Has llegado a la <strong>mitad del plazo</strong> para la historia:</p>
        <blockquote style='border-left:3px solid #5e6ad2;padding:1rem;margin:1rem 0;background:#111214'>
            <strong>{$titSafe}</strong>
        </blockquote>
        <p>Te quedan <strong>{$dias_restantes} días</strong> para la entrega.</p>
        <p>Recuerda enfocarte en el foco periodístico definido y la extensión solicitada.</p>
        <p><a href='" . BASE_URL . "/periodista/historia.php?id={$idSafe}' style='display:inline-block;padding:10px 20px;background:#5e6ad2;color:#fff;text-decoration:none;border-radius:8px'>Ver historia</a></p>
        <hr style='border-color:rgba(255,255,255,0.08)'>
        <p style='font-size:.8rem;color:#62666d'>El Correo de Valdivia · Sistema de Redacción</p>
    </div>";

    if (enviarCorreo($h['periodista_email'], $subject, $msg)) {
        $contador++;
    }
}

echo "Recordatorios de mitad enviados: $contador\n";
