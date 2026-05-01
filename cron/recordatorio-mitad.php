<?php
/**
 * Cron: Recordatorio a mitad del plazo
 * Ejecutar diariamente
 */

require_once __DIR__ . '/../includes/config.php';

$db = getDB();

// Buscar historias asignadas donde haya pasado el 50% del plazo pero no el 90%
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
    
    $subject = "⏰ Recordatorio: Llevas la mitad del plazo para «{$h['titulo']}»";
    $msg = "
    <div style='font-family:sans-serif;max-width:600px;margin:0 auto'>
        <h2 style='color:#5e6ad2'>⏰ Recordatorio de plazo</h2>
        <p>Hola <strong>{$h['periodista_nombre']}</strong>,</p>
        <p>Has llegado a la <strong>mitad del plazo</strong> para la historia:</p>
        <blockquote style='border-left:3px solid #5e6ad2;padding:1rem;margin:1rem 0;background:#111214'>
            <strong>{$h['titulo']}</strong>
        </blockquote>
        <p>Te quedan <strong>{$dias_restantes} días</strong> para la entrega.</p>
        <p>Recuerda enfocarte en el foco periodístico definido y la extensión solicitada.</p>
        <p><a href='" . BASE_URL . "/periodista/historia.php?id={$h['id']}' style='display:inline-block;padding:10px 20px;background:#5e6ad2;color:#fff;text-decoration:none;border-radius:8px'>Ver historia</a></p>
        <hr style='border-color:rgba(255,255,255,0.08)'>
        <p style='font-size:.8rem;color:#62666d'>El Correo de Valdivia · Sistema de Redacción</p>
    </div>";
    
    if (enviarCorreo($h['periodista_email'], $subject, $msg)) {
        $contador++;
    }
}

echo "Recordatorios de mitad enviados: $contador\n";
