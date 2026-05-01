<?php
$titulo_pagina = 'Condiciones y Funcionamiento';
require_once __DIR__ . '/includes/config.php';
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Condiciones · Redacción · El Correo de Valdivia</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700&family=Geist+Mono:wght@400;500&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css">
<style>
.cond-page{display:flex;justify-content:center;padding:2rem;background:var(--bg);min-height:100vh}
.cond-card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:2.5rem;width:100%;max-width:800px;box-shadow:0 0 0 1px rgba(94,106,210,0.05),0 8px 40px rgba(0,0,0,0.4)}
.cond-card .logo{text-align:center;margin-bottom:1.5rem}
.cond-card .logo svg{color:var(--text);width:200px;height:auto}
.cond-card h1{font-family:'Geist',system-ui,sans-serif;font-size:1.6rem;font-weight:600;text-align:center;margin-bottom:.5rem;color:var(--white);letter-spacing:-1px}
.cond-card .meta{text-align:center;font-size:.8rem;color:var(--muted);margin-bottom:2rem}
.cond-section{margin-bottom:2rem;padding-bottom:1.5rem;border-bottom:1px solid var(--border)}
.cond-section:last-child{border-bottom:none;margin-bottom:0;padding-bottom:0}
.cond-section h2{font-size:1.1rem;font-weight:600;color:var(--accent);margin-bottom:1rem;display:flex;align-items:center;gap:.6rem}
.cond-section h3{font-size:.9rem;font-weight:600;color:var(--white);margin:.8rem 0 .4rem}
.cond-section p,.cond-section li{font-size:.85rem;color:var(--text2);line-height:1.7}
.cond-section ul,.cond-section ol{padding-left:1.3rem;margin:.5rem 0}
.cond-section li{margin-bottom:.3rem}
.cond-section .highlight{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:1rem;margin:.8rem 0}
.cond-section .highlight strong{color:var(--text)}
.cond-section .tag{display:inline-block;background:var(--surface3);padding:2px 10px;border-radius:6px;font-size:.7rem;font-family:'Geist Mono',monospace;color:var(--accent);margin-right:4px}
.back-link{text-align:center;margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border)}
</style>
</head>
<body class="cond-page">
<div class="cond-card">
<div class="logo"><?php include ROOT_PATH.'/includes/logo.svg'; ?></div>
<h1>Condiciones y Funcionamiento</h1>
<p class="meta">Plataforma de Redacción · El Correo de Valdivia · Versión 1.0</p>

<div class="cond-section">
<h2>📖 ¿Cómo funciona la plataforma?</h2>
<p>El Correo de Valdivia utiliza esta plataforma para gestionar la creación de historias periodísticas. El flujo es simple:</p>
<ol>
<li><strong>El administrador</strong> publica historias con descripción, foco periodístico, extensión, fecha de entrega y presupuesto.</li>
<li><strong>Los periodistas</strong> ven las historias disponibles y eligen las que quieren cubrir.</li>
<li>Al tomar una historia, <strong>comienza el plazo de entrega</strong>.</li>
<li>El periodista redacta el contenido en el editor integrado y lo entrega antes de la fecha límite.</li>
<li>Al entregar, debe <strong>firmar digitalmente la cesión de derechos</strong>.</li>
<li>El administrador revisa, aprueba o rechaza, y registra el pago.</li>
</ol>
</div>

<div class="cond-section">
<h2>✍️ Cesión de Derechos de Contenido</h2>
<p>Cada entrega de una historia implica la firma digital de un documento de cesión de derechos. Al aceptar y entregar:</p>
<ul>
<li><strong>El periodista cede los derechos de publicación</strong> del contenido a El Correo de Valdivia para su edición, publicación y distribución en todas las plataformas del medio.</li>
<li>El periodista <strong>declara que el contenido es original y de su autoría</strong>.</li>
<li>La cesión <strong>no impide</strong> que el periodista pueda republicar el contenido en su portafolio personal, dando crédito a El Correo de Valdivia como medio original.</li>
<li>El documento de cesión queda almacenado en la plataforma.</li>
</ul>
<div class="highlight"><strong>📄 Importante:</strong> No se puede entregar una historia sin firmar la cesión de derechos. Es un paso obligatorio del proceso.</div>
</div>

<div class="cond-section">
<h2>⏰ Plazos y Recordatorios</h2>
<h3>Durante el plazo</h3>
<ul>
<li><span class="tag">Mitad del plazo</span> Recibirás un correo recordatorio indicando que llevas el 50% del tiempo.</li>
<li><span class="tag">Día antes</span> Recibirás un correo avisando que la entrega es al día siguiente.</li>
</ul>
<h3>Post-vencimiento (si no entregas a tiempo)</h3>
<ul>
<li><span class="tag">Día 1 de atraso</span> Correo: "⚠️ Tu historia lleva 1 día de atraso".</li>
<li><span class="tag">Día 3 de atraso</span> Correo: "🔴 Atraso crítico — 3 días. Responde o tu historia será reasignada".</li>
<li><span class="tag">Día 7 de atraso</span> La historia se <strong>reasigna automáticamente</strong> y queda disponible para otros periodistas.</li>
</ul>
</div>

<div class="cond-section">
<h2>⚖️ Penalización por Atraso</h2>
<p>Para mantener la disciplina en las entregas, se aplican las siguientes penalizaciones:</p>
<div class="highlight">
<strong>💰 Descuento diario:</strong> Se descuenta un <strong>10% del presupuesto por cada día de atraso</strong>, con un tope máximo del <strong>50% del presupuesto total</strong>.<br><br>
<strong>Ejemplo:</strong> Si una historia tiene un presupuesto de $100.000 y la entregas 3 días tarde, el descuento es de $30.000 (10% × 3). Recibirás $70.000.<br>
Si la entregas 7 días tarde, el descuento llega al tope del 50%: $50.000. Recibirás $50.000.
</div>
</div>

<div class="cond-section">
<h2>🚫 Sistema de Strikes</h2>
<p>Cuando una historia <strong>se reasigna por atraso de 7 días</strong>, el periodista acumula un <strong>strike</strong>:</p>
<ul>
<li><span class="tag">1 strike</span> Advertencia por correo.</li>
<li><span class="tag">2 strikes</span> Segunda advertencia. El administrador es notificado.</li>
<li><span class="tag">3 strikes</span> La cuenta del periodista queda <strong>automáticamente desactivada</strong>. No podrá tomar nuevas historias hasta que el administrador lo reactive manualmente.</li>
</ul>
</div>

<div class="cond-section">
<h2>💰 Pagos</h2>
<ul>
<li>Cada historia tiene un <strong>presupuesto asignado</strong> por el administrador al crearla.</li>
<li>El monto final a pagar considera las <strong>penalizaciones por atraso</strong> (si corresponde).</li>
<li>El administrador registra el pago manualmente cuando aprueba la historia.</li>
<li>Los pagos se realizan según el flujo definido por la administración de El Correo de Valdivia.</li>
<li>En tu perfil puedes ver el resumen de todos tus pagos: bruto, retenciones y líquido recibido.</li>
</ul>
</div>

<div class="cond-section">
<h2>👤 Datos Personales</h2>
<ul>
<li>Debes mantener tus datos actualizados en la sección <strong>Mi Perfil</strong> (nombre, email, RUT, datos bancarios).</li>
<li>Los datos bancarios son necesarios para procesar los pagos.</li>
<li>El Correo de Valdivia no compartirá tus datos con terceros sin tu autorización.</li>
</ul>
</div>

<div class="cond-section">
<h2>📮 Comunicaciones</h2>
<ul>
<li>Recibirás correos automáticos cuando: haya nuevas historias disponibles, se acerque la fecha de entrega, tu historia esté vencida, seas aprobado como periodista, o tu cuenta sea desactivada.</li>
<li>Para comunicarte con la administración, responde cualquier correo recibido o escribe a <strong>director@elcorreodevaldivia.cl</strong>.</li>
</ul>
</div>

<div class="back-link">
<a href="<?= BASE_URL ?>/inscribirse.php" class="btn btn-primary">← Volver al formulario de inscripción</a>
</div>
</div>
</body>
</html>
