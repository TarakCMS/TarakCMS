<?php
declare(strict_types=1);

session_start();
$configPath =  dirname(__DIR__) . '/config/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo $configPath;
    exit('Липсва config/config.php. Копирайте config.example.php и настройте базата данни.');
}
$config = require $configPath;
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Hooks.php';
require_once __DIR__ . '/PluginManager.php';

$db = Database::connection($config);
$pluginManager = new PluginManager($db, __DIR__ . '/plugins');
$pluginManager->loadActive();

function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function is_admin(): bool {
    return isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin';
}
function require_admin(): void {
    if (!is_admin()) {
        header('Location: /../admin.php');
        exit;
    }
}
