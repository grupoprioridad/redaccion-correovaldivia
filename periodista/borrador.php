<?php
/**
 * Endpoint AJAX: guarda/elimina borrador de entrega.
 * También actúa como keepalive de sesión y renueva el token CSRF.
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

$db = getDB();

// Crear tabla si no existe (idempotente)
$db->exec("CREATE TABLE IF NOT EXISTS borradores (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    historia_id INT UNSIGNED NOT NULL,
    periodista_id INT UNSIGNED NOT NULL,
    contenido MEDIUMTEXT,
    imagenes JSON,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_historia_periodista (historia_id, periodista_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$user_id    = $_SESSION['usuario_id'];
$historia_id = (int)($_POST['historia_id'] ?? 0);
$action     = $_POST['action'] ?? 'save';

if ($action === 'delete') {
    $db->prepare("DELETE FROM borradores WHERE historia_id=? AND periodista_id=?")
       ->execute([$historia_id, $user_id]);
    echo json_encode(['ok' => true]);
    exit;
}

// action = save: verificar acceso
$check = $db->prepare("SELECT id FROM historias WHERE id=? AND periodista_asignado=? AND estado IN ('asignada','en_curso')");
$check->execute([$historia_id, $user_id]);
if (!$check->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Sin acceso']);
    exit;
}

$contenido = $_POST['contenido'] ?? '';
$imagenes  = $_POST['imagenes']  ?? '[]';

$db->prepare("INSERT INTO borradores (historia_id, periodista_id, contenido, imagenes)
              VALUES (?,?,?,?)
              ON DUPLICATE KEY UPDATE contenido=VALUES(contenido), imagenes=VALUES(imagenes), updated_at=NOW()")
   ->execute([$historia_id, $user_id, $contenido, $imagenes]);

// Devolver token CSRF renovado para que el cliente lo use en siguientes peticiones
echo json_encode(['ok' => true, 'csrf' => csrf_token()]);
