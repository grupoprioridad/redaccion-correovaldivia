<?php
/**
 * Script de prueba: envía los correos que reciben los periodistas.
 * Uso: php scripts/test-emails-periodista.php
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/includes/config.php';

$to = 'esteban@prioridad.cl';

$historia_titulo = 'El renacer del río Calle-Calle: comunidades indígenas y el agua';
$periodista_nombre = 'María Paz González';
$monto    = 80000;
$retencion = (int)round($monto * 0.1525);
$liquido  = $monto - $retencion;
$id_historia = 42;
$enlace_boleta = BASE_URL . '/periodista/subir-boleta.php?id=' . $id_historia;

// ──────────────────────────────────────────────────────────────────
// EMAIL 1: Historia aprobada — datos de facturación
// ──────────────────────────────────────────────────────────────────
$subject1 = "Historia aprobada — Genera tu boleta de honorarios";
$msg1 = "
<p>Hola <strong>" . htmlspecialchars($periodista_nombre) . "</strong>,</p>
<p>¡Tu historia <strong>«" . htmlspecialchars($historia_titulo) . "»</strong> fue <span style='color:#27a644;font-weight:bold'>aprobada</span>! Para procesar el pago necesitas generar una <strong>Boleta de Honorarios Electrónica</strong> en <a href='https://homer.sii.cl/'>SII.cl</a> con los siguientes datos y luego subirla a nuestra plataforma.</p>

<h3 style='margin-top:1.2rem;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666'>Emite la boleta a nombre de</h3>
<table style='border-collapse:collapse;font-size:14px;margin:8px 0;width:100%'>
  <tr><td style='padding:4px 12px 4px 0;color:#888;white-space:nowrap'>Empresa</td><td style='font-weight:bold'>" . EMPRESA_NOMBRE . "</td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#888'>RUT</td><td style='font-weight:bold'>" . EMPRESA_RUT . "</td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#888'>Giro</td><td>" . EMPRESA_GIRO . "</td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#888'>Dirección</td><td>" . EMPRESA_DIRECCION . "</td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#888'>Concepto</td><td>Honorarios periodísticos · " . htmlspecialchars($historia_titulo) . "</td></tr>
</table>

<h3 style='margin-top:1.2rem;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666'>Montos</h3>
<table style='border-collapse:collapse;font-size:14px;margin:8px 0'>
  <tr><td style='padding:4px 12px 4px 0;color:#888'>Monto bruto (valor de la boleta)</td><td style='font-weight:bold;font-size:16px'>$" . number_format($monto, 0, ',', '.') . "</td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#888'>Retención segunda categoría (15,25%)</td><td style='color:#f59e0b'>− $" . number_format($retencion, 0, ',', '.') . "</td></tr>
  <tr style='border-top:1px solid #eee'><td style='padding:8px 12px 4px 0;color:#888;font-weight:bold'>Líquido a recibir</td><td style='font-weight:bold;font-size:16px;color:#27a644'>$" . number_format($liquido, 0, ',', '.') . "</td></tr>
</table>
<p style='font-size:12px;color:#888'>Emite la boleta por el <strong>monto bruto</strong>. La retención es calculada y declarada por nosotros ante el SII.</p>

<p style='margin-top:1.4rem'>
  <a href='{$enlace_boleta}' style='background:#5e6ad2;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block'>📤 Subir mi boleta de honorarios</a>
</p>
<p style='font-size:12px;color:#888;margin-top:.5rem'>O ingresa a: {$enlace_boleta}</p>
<p style='margin-top:1.2rem'>Si tienes dudas sobre facturación escribe a <a href='mailto:" . EMPRESA_EMAIL_FINANZAS . "'>" . EMPRESA_EMAIL_FINANZAS . "</a>.</p>";

$ok1 = enviarCorreo($to, $subject1, $msg1);
echo ($ok1 ? "✅ Email 1 enviado" : "❌ Error email 1") . ": Historia aprobada — datos facturación\n";

// ──────────────────────────────────────────────────────────────────
// EMAIL 2: Pago procesado — agradecimiento + informe
// ──────────────────────────────────────────────────────────────────
$fecha_pago = date('d/m/Y');
$comprobante_url = BASE_URL . '/uploads/pagos/ejemplo-comprobante.pdf'; // ficticio para demo

$subject2 = "Pago procesado — " . mb_substr($historia_titulo, 0, 80);
$msg2 = "
<p>Hola <strong>" . htmlspecialchars($periodista_nombre) . "</strong>,</p>
<p>¡Muchas gracias por tu trabajo! Tu pago por la historia <strong>«" . htmlspecialchars($historia_titulo) . "»</strong> ha sido procesado exitosamente.</p>

<h3 style='margin-top:1.2rem;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#666'>Informe de pago</h3>
<table style='border-collapse:collapse;font-size:14px;margin:8px 0;width:100%;max-width:400px'>
  <tr><td style='padding:5px 16px 5px 0;color:#888'>Historia</td><td style='font-weight:bold'>" . htmlspecialchars($historia_titulo) . "</td></tr>
  <tr><td style='padding:5px 16px 5px 0;color:#888'>Fecha de pago</td><td>{$fecha_pago}</td></tr>
  <tr style='border-top:1px solid #eee'><td style='padding:8px 16px 5px 0;color:#888'>Honorarios brutos</td><td style='font-weight:bold'>$" . number_format($monto, 0, ',', '.') . "</td></tr>
  <tr><td style='padding:5px 16px 5px 0;color:#888'>Retención (15,25%)</td><td style='color:#f59e0b'>− $" . number_format($retencion, 0, ',', '.') . "</td></tr>
  <tr style='border-top:2px solid #eee'><td style='padding:8px 16px 5px 0;font-weight:bold'>Monto líquido transferido</td><td style='font-size:18px;font-weight:bold;color:#27a644'>$" . number_format($liquido, 0, ',', '.') . "</td></tr>
</table>

<p style='margin-top:1.2rem'>
  <a href='{$comprobante_url}' style='background:#5e6ad2;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block'>📎 Ver comprobante de transferencia</a>
</p>

<p style='margin-top:1.4rem;font-size:.85rem;color:#666'>Fue un placer trabajar contigo. Esperamos seguir contando con tus reportajes.</p>
<p style='font-size:.85rem;color:#666'>— El equipo de <strong>" . SITE_NAME . "</strong></p>";

$ok2 = enviarCorreo($to, $subject2, $msg2);
echo ($ok2 ? "✅ Email 2 enviado" : "❌ Error email 2") . ": Pago procesado — agradecimiento\n";
