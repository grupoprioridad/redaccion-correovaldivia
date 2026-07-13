<?php
/**
 * Migración: baja/reactivación de suscriptores por token.
 * Agrega columnas activo, token (único) y desactivado_en a `suscriptores`
 * y genera un token para cada suscriptor existente. Idempotente.
 *
 * Las credenciales se leen de includes/config.php (SITE_DB_*), que está
 * fuera de git. No hardcodear credenciales aquí.
 *
 * Uso:  php scripts/migracion-baja-suscriptores.php
 */
require_once dirname(__DIR__) . '/includes/config.php';

$pdo = new PDO(
    'mysql:host=' . SITE_DB_HOST . ';dbname=' . SITE_DB_NAME . ';charset=utf8mb4',
    SITE_DB_USER, SITE_DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

function colExists(PDO $pdo, string $col): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suscriptores' AND COLUMN_NAME = ?");
    $s->execute([$col]);
    return (bool)$s->fetchColumn();
}

if (!colExists($pdo, 'activo'))         $pdo->exec("ALTER TABLE suscriptores ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1");
if (!colExists($pdo, 'token'))          $pdo->exec("ALTER TABLE suscriptores ADD COLUMN token VARCHAR(64) NULL, ADD UNIQUE KEY uniq_token (token)");
if (!colExists($pdo, 'desactivado_en')) $pdo->exec("ALTER TABLE suscriptores ADD COLUMN desactivado_en DATETIME NULL");
echo "Columnas OK\n";

$sinToken = $pdo->query("SELECT id FROM suscriptores WHERE token IS NULL OR token = ''")->fetchAll();
$upd = $pdo->prepare("UPDATE suscriptores SET token = ? WHERE id = ?");
foreach ($sinToken as $r) $upd->execute([bin2hex(random_bytes(16)), $r['id']]);
echo "Tokens generados: " . count($sinToken) . "\n";
echo "Migración completa.\n";
