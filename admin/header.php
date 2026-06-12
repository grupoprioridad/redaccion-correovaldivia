<?php
require_once __DIR__ . '/../includes/auth.php';
requerirAdmin();
securityHeaders();

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
            <div class="tagline">Administración</div>
        </div>
        <nav>
            <div class="nav-section">Gestión</div>
            <a href="<?= BASE_URL ?>/admin/index.php" class="<?= $current === 'index.php' ? 'active' : '' ?>">
                📊 <span>Dashboard</span>
            </a>
            <a href="<?= BASE_URL ?>/admin/historia-nueva.php" class="<?= $current === 'historia-nueva.php' ? 'active' : '' ?>">
                ➕ <span>Nueva Historia</span>
            </a>
            <a href="<?= BASE_URL ?>/admin/usuarios.php" class="<?= str_contains($current, 'usuario') ? 'active' : '' ?>">
                👥 <span>Usuarios</span>
            </a>
            <a href="<?= BASE_URL ?>/admin/suscriptores.php" class="<?= $current === 'suscriptores.php' ? 'active' : '' ?>">
                📧 <span>Suscriptores</span>
            </a>
            <a href="<?= BASE_URL ?>/admin/denuncias.php" class="<?= $current === 'denuncias.php' ? 'active' : '' ?>">
                🔒 <span>Denuncias</span>
            </a>
            <a href="<?= BASE_URL ?>/admin/pagos.php" class="<?= $current === 'pagos.php' ? 'active' : '' ?>">
                💰 <span>Pagos</span>
            </a>
            <a href="<?= BASE_URL ?>/admin/categorias.php" class="<?= $current === 'categorias.php' ? 'active' : '' ?>">
                🏷️ <span>Categorías</span>
            </a>
            <div class="nav-section">IA</div>
            <a href="<?= BASE_URL ?>/admin/proponer.php" class="<?= $current === 'proponer.php' ? 'active' : '' ?>">
                💡 <span>Proponer Historias</span>
            </a>
            <a href="<?= BASE_URL ?>/admin/scraper-config.php" class="<?= $current === 'scraper-config.php' ? 'active' : '' ?>">
                📡 <span>Scraper</span>
            </a>
            <div class="nav-section">Publicación</div>
            <a href="<?= BASE_URL ?>/admin/wordpress.php" class="<?= $current === 'wordpress.php' ? 'active' : '' ?>">
                🌐 <span>WordPress</span>
            </a>
            <a href="<?= BASE_URL ?>/admin/enviar-articulo.php" class="<?= $current === 'enviar-articulo.php' ? 'active' : '' ?>">
                ✉ <span>Enviar artículo</span>
            </a>
            <a href="<?= BASE_URL ?>/admin/redaccion-rapida.php" class="<?= $current === 'redaccion-rapida.php' ? 'active' : '' ?>">
                ⚡ <span>Redacción Rápida</span>
            </a>
        </nav>
        <div class="user-info">
            <div class="name"><?= e($user['nombre']) ?></div>
            <div class="role">Admin</div>
            <a href="<?= BASE_URL ?>/logout.php" style="font-size:.7rem;margin-top:6px;display:block">Cerrar sesión</a>
        </div>
    </aside>
    <main class="main">
        <?php notificarFlash(); ?>
