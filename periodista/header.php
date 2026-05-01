<?php
require_once __DIR__ . '/../includes/auth.php';
requerirPeriodista();

$current = basename($_SERVER['PHP_SELF']);
$user = usuarioActual();
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $titulo ?? 'Panel' ?> · Redacción · El Correo de Valdivia</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700&family=Geist+Mono:wght@400;500&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css">
</head>
<body>
<div class="wrapper">
    <aside class="sidebar">
        <div class="logo">
            <?php include ROOT_PATH . '/includes/logo.svg'; ?>
            <div class="tagline">Periodista</div>
        </div>
        <nav>
            <div class="nav-section">Mi Trabajo</div>
            <a href="<?= BASE_URL ?>/periodista/index.php" class="<?= $current === 'index.php' ? 'active' : '' ?>">
                📋 <span>Mis Historias</span>
            </a>
            <a href="<?= BASE_URL ?>/periodista/historial.php" class="<?= $current === 'historial.php' ? 'active' : '' ?>">
                📚 <span>Historial</span>
            </a>
            <div class="nav-section">Mi Cuenta</div>
            <a href="<?= BASE_URL ?>/periodista/perfil.php" class="<?= $current === 'perfil.php' ? 'active' : '' ?>">
                👤 <span>Mi Perfil</span>
            </a>
        </nav>
        <div class="user-info">
            <div class="name"><?= e($user['nombre']) ?></div>
            <div class="role">Periodista</div>
            <a href="<?= BASE_URL ?>/logout.php" style="font-size:.7rem;margin-top:6px;display:block">Cerrar sesión</a>
        </div>
    </aside>
    <main class="main">
        <?php notificarFlash(); ?>
