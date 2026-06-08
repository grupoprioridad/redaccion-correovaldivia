<?php
require_once __DIR__ . '/../includes/auth.php';
requerirPeriodista();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit('No encontrado.');
}

$db = getDB();
$stmt = $db->prepare("SELECT pdf_path FROM documentos_cesion WHERE id = ? AND periodista_id = ?");
$stmt->execute([$id, $_SESSION['usuario_id']]);
$doc = $stmt->fetch();
if (!$doc) {
    http_response_code(404);
    exit('No encontrado.');
}

$base = realpath(CESIONES_PATH);
$path = realpath(CESIONES_PATH . '/' . basename($doc['pdf_path']));
if (!$base || !$path || (!str_starts_with($path, $base . DIRECTORY_SEPARATOR) && $path !== $base) || !is_file($path)) {
    http_response_code(404);
    exit('Archivo no disponible.');
}

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
