<?php
/**
 * Configuración - Sistema Redacción El Correo de Valdivia
 *
 * Copia este archivo a includes/config.php y rellena los datos.
 * No subir config.php a git (está en .gitignore).
 */

// DB
define('DB_HOST', 'localhost');
define('DB_NAME', 'TU_BASE_DE_DATOS');
define('DB_USER', 'TU_USUARIO_DB');
define('DB_PASS', 'TU_PASSWORD_DB');

// URLs
define('BASE_URL', 'https://j.prioridad.cl/redaccion');
define('SITE_NAME', 'Redacción · El Correo de Valdivia');
define('SITE_EMAIL', 'robot@elcorreodevaldivia.cl');

// Paths
define('ROOT_PATH', dirname(__DIR__));
define('UPLOADS_PATH', ROOT_PATH . '/uploads');
define('UPLOADS_URL', BASE_URL . '/uploads');
define('CESIONES_PATH', ROOT_PATH . '/private/cesiones');

// S3 (pendiente para producción)
define('S3_ENABLED', false);
define('S3_ENDPOINT', 'https://us-mia-1.linodeobjects.com');
define('S3_REGION', 'us-mia-1');
define('S3_BUCKET', 'TU_BUCKET');
define('S3_KEY', 'TU_S3_KEY');
define('S3_SECRET', 'TU_S3_SECRET');
define('S3_URL', 'https://TU_BUCKET.us-mia-1.linodeobjects.com');

// SMTP
define('SMTP_HOST', 'mail.elcorreodevaldivia.cl;sub5.mail.dreamhost.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'TU_SMTP_USER');
define('SMTP_PASS', 'TU_SMTP_PASS');
define('SMTP_FROM', 'robot@elcorreodevaldivia.cl');
define('SMTP_FROM_NAME', 'Redacción · El Correo de Valdivia');

// Sesión: cookie endurecida + helpers de seguridad cargados antes que nada.
if (session_status() === PHP_SESSION_NONE) {
    $secure = (
        (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
    );
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('REDACCION_SID');
    session_start();
}

require_once __DIR__ . '/security.php';

// PDO
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            error_log('DB connect error: ' . $e->getMessage());
            http_response_code(500);
            exit('Servicio no disponible. Intenta más tarde.');
        }
    }
    return $pdo;
}

// Helpers básicos
function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function flash($key, $msg = null) {
    if ($msg !== null) {
        $_SESSION['flash'][$key] = $msg;
    } else {
        $m = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $m;
    }
}

function hasFlash($key) {
    return isset($_SESSION['flash'][$key]);
}

function enviarCorreo($to, $subject, $htmlBody) {
    require_once ROOT_PATH . '/includes/smtp.php';
    return enviarEmail($to, $subject, $htmlBody);
}

/**
 * Sube imagen validando extensión, MIME real y que sea imagen decodificable.
 * Limita tamaño a 8MB. Genera nombre aleatorio (no enumerable).
 */
function subirImagen($archivo, $carpeta = 'historias') {
    if (!is_array($archivo) || !isset($archivo['tmp_name']) || !is_uploaded_file($archivo['tmp_name'])) {
        return false;
    }
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return false;
    }
    if (($archivo['size'] ?? 0) <= 0 || $archivo['size'] > 8 * 1024 * 1024) {
        return false;
    }

    $ext = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    $extToMime = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
    ];
    if (!isset($extToMime[$ext])) return false;

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $archivo['tmp_name']) : null;
    if ($finfo) finfo_close($finfo);
    if (!$mime || !in_array($mime, $extToMime[$ext], true)) return false;

    $info = @getimagesize($archivo['tmp_name']);
    if (!$info || empty($info[0]) || empty($info[1])) return false;

    $nombre = bin2hex(random_bytes(16)) . '.' . $ext;
    $dir = UPLOADS_PATH . '/' . $carpeta;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ruta = $dir . '/' . $nombre;

    if (!move_uploaded_file($archivo['tmp_name'], $ruta)) return false;
    @chmod($ruta, 0644);

    return $carpeta . '/' . $nombre;
}

function urlImagen($path) {
    if (!$path) return '';
    if (str_starts_with($path, 'http')) return $path;
    return UPLOADS_URL . '/' . $path;
}
