<?php
/**
 * Сид для Docker: создаёт пользователя admin/admin и группу по умолчанию
 * на чистой базе. Остальную схему config.php добьёт через CREATE TABLE IF NOT EXISTS
 * при первом веб-запросе. Запускается из docker-entrypoint.sh только если БД ещё нет.
 */
$db = '/var/www/html/cloudflare_panel.db';

$pdo = new PDO("sqlite:$db");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    name TEXT NOT NULL,
    UNIQUE(user_id, name)
)");

// Чистим и создаём admin/admin (тестовые креды)
$pdo->exec("DELETE FROM users");
$stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
$stmt->execute(['admin', password_hash('admin', PASSWORD_DEFAULT)]);

// Группа по умолчанию для user_id = 1
$pdo->exec("INSERT OR IGNORE INTO groups (user_id, name) VALUES (1, 'Default Group')");

echo "seeded admin/admin\n";
