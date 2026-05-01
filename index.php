<?php
require_once __DIR__ . '/includes/auth.php';

if (usuarioLogueado()) {
    if (esAdmin()) {
        header('Location: ' . BASE_URL . '/admin/index.php');
    } else {
        header('Location: ' . BASE_URL . '/periodista/index.php');
    }
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'Ingresa tu email y contraseña.';
    } else {
        $usuario = autenticar($email, $password);
        if ($usuario === 'no_aprobado') {
            $error = 'Tu cuenta está pendiente de aprobación por el administrador. Te avisaremos cuando esté activa.';
        } elseif ($usuario) {
            if ($usuario['rol'] === 'admin') {
                header('Location: ' . BASE_URL . '/admin/index.php');
            } else {
                header('Location: ' . BASE_URL . '/periodista/index.php');
            }
            exit;
        } else {
            $error = 'Email o contraseña incorrectos.';
        }
    }
}
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Iniciar Sesión · Redacción · El Correo de Valdivia</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700&family=Geist+Mono:wght@400;500&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css">
</head>
<body class="login-page">
<div class="login-card">
    <div class="logo">
        <?php include ROOT_PATH . '/includes/logo.svg'; ?>
    </div>
    <h1>Redacción</h1>
    <p class="subtitle">Plataforma de control de historias</p>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>
    
    <form method="post">
        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" placeholder="tu@email.cl" required autofocus>
        </div>
        <div class="form-group">
            <label for="password">Contraseña</label>
            <input type="password" id="password" name="password" placeholder="••••••••" required>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:.8rem">
            Ingresar
        </button>
    </form>
    <p style="text-align:center;margin-top:1.5rem;font-size:.8rem;color:var(--muted)">
        ¿Eres periodista y quieres trabajar con nosotros?<br>
        <a href="<?= BASE_URL ?>/inscribirse.php" style="color:var(--accent);font-weight:500">Inscríbete aquí →</a>
    </p>
</div>
</body>
</html>
