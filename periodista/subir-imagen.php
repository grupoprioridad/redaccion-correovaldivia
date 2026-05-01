<?php
/**
 * Endpoint AJAX para subir imágenes desde el editor Quill.
 * Requiere sesión periodista + token CSRF + archivo válido (validado por subirImagen).
 */
require_once __DIR__ . '/../includes/auth.php';
requerirPeriodista();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

csrf_verify();

if (!isset($_FILES['imagen'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No se recibió imagen']);
    exit;
}

$resultado = subirImagen($_FILES['imagen'], 'historias');
if (!$resultado) {
    http_response_code(400);
    echo json_encode(['error' => 'Imagen no válida. Formatos: JPG, PNG, GIF, WebP. Máx 8MB.']);
    exit;
}

echo json_encode(['url' => urlImagen($resultado)]);
