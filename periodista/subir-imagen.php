<?php
/**
 * Endpoint AJAX para subir imágenes desde el editor Quill
 */
require_once __DIR__ . '/../includes/auth.php';
requerirPeriodista();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['imagen'])) {
    echo json_encode(['error' => 'No se recibió imagen']);
    exit;
}

$archivo = $_FILES['imagen'];
$resultado = subirImagen($archivo, 'historias');

if (!$resultado) {
    echo json_encode(['error' => 'Error al subir la imagen. Formatos permitidos: JPG, PNG, GIF, WebP']);
    exit;
}

$url = urlImagen($resultado);
echo json_encode(['url' => $url]);
