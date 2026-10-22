<?php
require __DIR__ . '/core/bootstrap.php';

$slug = trim((string)($_GET['page'] ?? ''), '/');
if ($slug === '') {
    $stmt = $db->prepare("SELECT * FROM pages WHERE status='published' ORDER BY id DESC LIMIT 1");
    $stmt->execute();
} else {
    $stmt = $db->prepare("SELECT * FROM pages WHERE slug = ? AND status='published' LIMIT 1");
    $stmt->execute([$slug]);
}
$page = $stmt->fetch();
$title = $page['title'] ?? ($config['app_name'] ?? 'TarıkCMS');
$body = $page ? (string)$page['content'] : '<p>Добре дошли в TarıkCMS. Влезте в администрацията, за да създадете първата си страница.</p>';
$body = apply_filters('page_content', $body, $page);
$footer = apply_filters('site_footer', '<p>Създадено с TarıkCMS</p>');
?>
<!doctype html>
<html lang="bg">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="site-header"><a class="brand" href="/"><?= e($config['app_name'] ?? 'TarıkCMS') ?></a><a href="/admin.php">Администрация</a></header>
<main class="content"><h1><?= e($title) ?></h1><article><?= $body ?></article></main>
<footer class="site-footer"><?= $footer ?></footer>
</body></html>
