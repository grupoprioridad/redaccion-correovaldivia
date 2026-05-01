<?php
/**
 * Autenticación y control de acceso
 */

require_once __DIR__ . '/config.php';

function usuarioLogueado(): bool {
    return isset($_SESSION['usuario_id']);
}

function usuarioActual(): ?array {
    if (!isset($_SESSION['usuario_id'])) return null;

    static $cached = null;
    if ($cached !== null && $cached['id'] == $_SESSION['usuario_id']) return $cached;

    try {
        $stmt = getDB()->prepare("SELECT id, nombre, email, rol, rut, telefono, banco, tipo_cuenta, numero_cuenta, activo, aprobado FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->execute([$_SESSION['usuario_id']]);
        $u = $stmt->fetch();
    } catch (Throwable $e) {
        $u = null;
    }
    $cached = $u ?: null;
    return $cached;
}

function esAdmin(): bool {
    $u = usuarioActual();
    return $u && $u['rol'] === 'admin' && (int)$u['activo'] === 1;
}

function esPeriodista(): bool {
    $u = usuarioActual();
    return $u && $u['rol'] === 'periodista' && (int)$u['activo'] === 1 && (int)$u['aprobado'] === 1;
}

function requerirLogin(): void {
    if (!usuarioLogueado()) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
    // Si el usuario fue desactivado o cambiado de rol, cerrar sesión.
    $u = usuarioActual();
    if (!$u || (int)$u['activo'] !== 1) {
        cerrarSesion();
    }
}

function requerirAdmin(): void {
    requerirLogin();
    if (!esAdmin()) {
        cerrarSesion();
    }
}

function requerirPeriodista(): void {
    requerirLogin();
    if (!esPeriodista()) {
        cerrarSesion();
    }
}

function autenticar(string $email, string $password) {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, nombre, email, password, rol, activo, aprobado FROM usuarios WHERE email = ? AND activo = 1 LIMIT 1");
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();

    if (!$usuario || !password_verify($password, $usuario['password'])) {
        return false;
    }

    if ($usuario['rol'] === 'periodista' && !$usuario['aprobado']) {
        return 'no_aprobado';
    }

    // Endurecer sesión post-login: previene fixation.
    session_regenerate_id(true);

    $_SESSION['usuario_id']     = $usuario['id'];
    $_SESSION['usuario_rol']    = $usuario['rol'];
    $_SESSION['usuario_nombre'] = $usuario['nombre'];
    $_SESSION['login_at']       = time();
    // Token CSRF nuevo por sesión.
    unset($_SESSION['csrf']);

    return $usuario;
}

function cerrarSesion(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

function notificarFlash(): void {
    $tipos = ['success', 'error', 'info', 'warning'];
    foreach ($tipos as $tipo) {
        if (hasFlash($tipo)) {
            echo '<div class="alert alert-' . $tipo . '">' . e(flash($tipo)) . '</div>';
        }
    }
}
