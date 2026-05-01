<?php
/**
 * Autenticación y control de acceso
 */

require_once __DIR__ . '/config.php';

function usuarioLogueado() {
    return isset($_SESSION['usuario_id']);
}

function usuarioActual() {
    if (!isset($_SESSION['usuario_id'])) return null;
    return $_SESSION['usuario'];
}

function esAdmin() {
    return isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'admin';
}

function esPeriodista() {
    return isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'periodista';
}

function requerirLogin() {
    if (!usuarioLogueado()) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

function requerirAdmin() {
    requerirLogin();
    if (!esAdmin()) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

function requerirPeriodista() {
    requerirLogin();
    if (!esPeriodista()) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

function autenticar($email, $password) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM usuarios WHERE email = ? AND activo = 1 LIMIT 1");
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();
    
    if (!$usuario || !password_verify($password, $usuario['password'])) {
        return false;
    }
    
    // Periodistas no aprobados no pueden ingresar
    if ($usuario['rol'] === 'periodista' && !$usuario['aprobado']) {
        return 'no_aprobado';
    }
    
    $_SESSION['usuario_id'] = $usuario['id'];
    $_SESSION['usuario'] = $usuario;
    $_SESSION['usuario_rol'] = $usuario['rol'];
    $_SESSION['usuario_nombre'] = $usuario['nombre'];
    
    return $usuario;
}

function cerrarSesion() {
    session_destroy();
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

function notificarFlash() {
    $tipos = ['success', 'error', 'info', 'warning'];
    foreach ($tipos as $tipo) {
        if (hasFlash($tipo)) {
            echo '<div class="alert alert-' . $tipo . '">' . e(flash($tipo)) . '</div>';
        }
    }
}
