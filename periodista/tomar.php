<?php
require_once __DIR__ . '/../includes/auth.php';
requerirPeriodista();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/periodista/index.php');
    exit;
}

csrf_verify();

$db = getDB();
$historia_id = (int)($_POST['historia_id'] ?? 0);
$user_id = $_SESSION['usuario_id'];

// Verificar que la historia exista y la visibilidad antes de reclamar.
$stmt = $db->prepare("SELECT * FROM historias WHERE id = ?");
$stmt->execute([$historia_id]);
$historia = $stmt->fetch();

if (!$historia || $historia['estado'] !== 'disponible') {
    flash('error', 'Esta historia ya no está disponible.');
    header('Location: ' . BASE_URL . '/periodista/index.php');
    exit;
}

if (!$historia['visible_para_todos']) {
    $vis = $db->prepare("SELECT COUNT(*) FROM historia_visibilidad WHERE historia_id = ? AND usuario_id = ?");
    $vis->execute([$historia_id, $user_id]);
    if (!$vis->fetchColumn()) {
        flash('error', 'No tienes permiso para tomar esta historia.');
        header('Location: ' . BASE_URL . '/periodista/index.php');
        exit;
    }
}

// Reclamo atómico: solo se asigna si todavía está disponible y libre.
$claim = $db->prepare("
    UPDATE historias
    SET estado = 'asignada', periodista_asignado = ?, asignada_en = NOW()
    WHERE id = ? AND estado = 'disponible' AND periodista_asignado IS NULL
");
$claim->execute([$user_id, $historia_id]);

if ($claim->rowCount() === 0) {
    flash('error', 'Otro periodista tomó esta historia primero.');
    header('Location: ' . BASE_URL . '/periodista/index.php');
    exit;
}

// Notificar al admin
$admin = $db->query("SELECT email FROM usuarios WHERE rol='admin' AND activo=1 LIMIT 1")->fetch();
if ($admin) {
    $nombreSafe = e($_SESSION['usuario_nombre'] ?? '');
    $titSafe    = e($historia['titulo']);
    $idSafe     = (int)$historia_id;
    $fechaSafe  = date('d/m/Y', strtotime($historia['fecha_entrega']));
    $subject = "Historia tomada: " . preg_replace('/[\r\n]+/', ' ', mb_substr($historia['titulo'], 0, 100));
    $msg = "<p><strong>{$nombreSafe}</strong> ha tomado la historia <strong>{$titSafe}</strong>.</p>";
    $msg .= "<p>Fecha de entrega: {$fechaSafe}</p>";
    $msg .= "<p><a href='" . BASE_URL . "/admin/historia-editar.php?id={$idSafe}'>Ver en el panel</a></p>";
    enviarCorreo($admin['email'], $subject, $msg);
}

flash('success', '🎉 Historia tomada con éxito. ¡A escribir!');
header('Location: ' . BASE_URL . '/periodista/historia.php?id=' . $historia_id);
exit;
