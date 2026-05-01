<?php
require_once __DIR__ . '/../includes/auth.php';
requerirPeriodista();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/periodista/index.php');
    exit;
}

$db = getDB();
$historia_id = (int)($_POST['historia_id'] ?? 0);
$user_id = $_SESSION['usuario_id'];

// Verificar que la historia esté disponible
$stmt = $db->prepare("SELECT * FROM historias WHERE id = ? AND estado = 'disponible'");
$stmt->execute([$historia_id]);
$historia = $stmt->fetch();

if (!$historia) {
    flash('error', 'Esta historia ya no está disponible.');
    header('Location: ' . BASE_URL . '/periodista/index.php');
    exit;
}

// Verificar visibilidad
if (!$historia['visible_para_todos']) {
    $vis = $db->prepare("SELECT COUNT(*) FROM historia_visibilidad WHERE historia_id = ? AND usuario_id = ?");
    $vis->execute([$historia_id, $user_id]);
    if (!$vis->fetchColumn()) {
        flash('error', 'No tienes permiso para tomar esta historia.');
        header('Location: ' . BASE_URL . '/periodista/index.php');
        exit;
    }
}

// Tomar la historia
$stmt = $db->prepare("UPDATE historias SET estado = 'asignada', periodista_asignado = ?, asignada_en = NOW() WHERE id = ?");
$stmt->execute([$user_id, $historia_id]);

// Notificar al admin
$admin = $db->query("SELECT email FROM usuarios WHERE rol='admin' AND activo=1 LIMIT 1")->fetch();
if ($admin) {
    $nombre = $_SESSION['usuario_nombre'];
    $subject = "🔔 Historia tomada: {$historia['titulo']}";
    $msg = "<p><strong>{$nombre}</strong> ha tomado la historia <strong>{$historia['titulo']}</strong>.</p>";
    $msg .= "<p>Fecha de entrega: " . date('d/m/Y', strtotime($historia['fecha_entrega'])) . "</p>";
    $msg .= "<p><a href='" . BASE_URL . "/admin/historia-editar.php?id={$historia_id}'>Ver en el panel</a></p>";
    enviarCorreo($admin['email'], $subject, $msg);
}

flash('success', '🎉 Historia tomada con éxito. ¡A escribir!');
header('Location: ' . BASE_URL . '/periodista/historia.php?id=' . $historia_id);
exit;
