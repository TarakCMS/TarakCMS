<?php
declare(strict_types=1);
$configPath = __DIR__ . '/config/config.php';
if (!is_file($configPath)) exit('Първо създайте config/config.php от config.example.php.');
require __DIR__ . '/core/Database.php';
$config = require $configPath;
try {
    $db = Database::connection($config);
    $schema = file_get_contents(__DIR__ . '/database/schema.sql');

    foreach (array_filter(array_map('trim', explode(';', (string)$schema))) as $sql) {
        $sql = preg_replace('/^CREATE TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $sql, 1);
        $db->exec($sql);
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit(
        '<pre>Грешка: ' .
        htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') .
        '</pre>'
    );
}
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,80}$/', $username) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
        $message = 'Въведете валидно потребителско име, имейл и парола от поне 12 символа.';
    } else {
        try {
            $stmt = $db->prepare("INSERT INTO users (username,email,password_hash,role) VALUES (?,?,?,'admin')");
            $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $message = 'Администраторът е създаден. Изтрийте public/install.php веднага и влезте в /admin.php.';
        } catch (Throwable $e) { $message = 'Неуспешно създаване. Проверете дали потребителят вече съществува.'; }
    }
}
?><!doctype html><html lang="bg"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Инсталация TarakCMS</title><link rel="stylesheet" href="/assets/style.css"><body><main class="login"><p class="eyebrow">INSTALLER</p><h1>Създай администратор</h1><?php if ($message): ?><p><?php htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?><form method="post"><label>Потребителско име<input name="username" required minlength="3"></label><label>Имейл<input type="email" name="email" required></label><label>Парола (минимум 12 символа)<input type="password" name="password" required minlength="12"></label><button>СЪЗДАЙ АДМИНИСТРАТОР</button></form></main></body></html>
