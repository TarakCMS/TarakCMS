<?php
require dirname(__DIR__) . '/core/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'login') {
        $stmt = $db->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([trim((string)($_POST['username'] ?? ''))]);
        $user = $stmt->fetch();
        if ($user && password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            header('Location: /admin.php'); exit;
        }
        $error = 'Невалидно потребителско име или парола.';
    } elseif ($action === 'logout' && is_admin()) {
        $_SESSION = [];
        session_destroy();
        header('Location: /admin.php'); exit;
    } elseif (is_admin()) {
        if ($action === 'discover_plugins') {
            $pluginManager->syncDiscovered();
            $notice = 'Плъгините са открити.';
        } elseif ($action === 'toggle_plugin') {
            $pluginManager->setActive((string)($_POST['slug'] ?? ''), ($_POST['active'] ?? '') === '1');
            header('Location: /admin.php?tab=plugins'); exit;
        } elseif ($action === 'save_page') {
            $title = trim((string)($_POST['title'] ?? ''));
            $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
            $slug = trim((string)preg_replace('/[^a-z0-9-]+/', '-', $slug), '-');
            $content = (string)($_POST['content'] ?? '');
            if ($title !== '' && $slug !== '') {
                $stmt = $db->prepare("INSERT INTO pages (title, slug, content, status) VALUES (?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE title=VALUES(title), content=VALUES(content), status=VALUES(status)");
                $stmt->execute([$title, $slug, $content, ($_POST['status'] ?? '') === 'published' ? 'published' : 'draft']);
                $notice = 'Страницата е запазена.';
            } else { $error = 'Заглавието и URL адресът са задължителни.'; }
        }
    }
}

if (!is_admin()):
?>
<!doctype html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Вход — TarıkCMS</title><link rel="stylesheet" href="/assets/style.css"></head>
<body><main class="login"><p class="eyebrow">TARIKCMS / ADMIN</p><h1>Вход</h1>
<?php if (!empty($error)): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
<form method="post"><input type="hidden" name="action" value="login"><label>Потребителско име<input name="username" required autocomplete="username"></label><label>Парола<input type="password" name="password" required autocomplete="current-password"></label><button>ВЛИЗАНЕ →</button></form>
<p><a href="/">← Към сайта</a></p></main></body></html>
<?php exit; endif;

$tab = (string)($_GET['tab'] ?? 'pages');
?>
<!doctype html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Администрация — TarıkCMS</title><link rel="stylesheet" href="/assets/style.css"></head>
<body><div class="admin-shell"><aside class="sidebar"><a class="brand" href="/">TARIKCMS</a><p class="eyebrow">CONTROL PANEL</p><a href="/admin.php?tab=pages">Страници</a><a href="/admin.php?tab=plugins">Плъгини</a><a href="/">Преглед на сайта</a><form method="post"><input type="hidden" name="action" value="logout"><button class="secondary">Изход</button></form></aside>
<main class="admin-main"><p class="eyebrow">DASHBOARD / <?= e(strtoupper($tab)) ?></p><h1><?= $tab === 'plugins' ? 'Плъгини' : 'Страници' ?></h1>
<?php if (!empty($notice)): ?><p class="notice"><?= e($notice) ?></p><?php endif; ?><?php if (!empty($error)): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
<?php if ($tab === 'plugins'): ?>
<form method="post" class="inline-form"><input type="hidden" name="action" value="discover_plugins"><button>ОТКРИЙ ПЛЪГИНИ</button></form>
<?php foreach ($pluginManager->discover() as $p): ?><section class="panel"><div><h2><?= e($p['name'] ?? $p['slug']) ?></h2><p><?= e($p['description'] ?? '') ?></p><small>Версия <?= e($p['version'] ?? '?') ?> · <?= e($p['slug']) ?></small></div><form method="post"><input type="hidden" name="action" value="toggle_plugin"><input type="hidden" name="slug" value="<?= e($p['slug']) ?>"><input type="hidden" name="active" value="<?= $p['active'] ? '0' : '1' ?>"><button class="<?= $p['active'] ? 'secondary' : '' ?>"><?= $p['active'] ? 'ДЕАКТИВИРАЙ' : 'АКТИВИРАЙ' ?></button></form></section><?php endforeach; ?>
<p class="muted">Плъгините са PHP код. Инсталирайте само код, на който имате доверие.</p>
<?php else: ?>
<form method="post" class="panel form-grid"><input type="hidden" name="action" value="save_page"><label>Заглавие<input name="title" required></label><label>URL slug<input name="slug" placeholder="za-nas" required pattern="[a-zA-Z0-9-]+"></label><label class="full">Съдържание<textarea name="content" rows="8" placeholder="HTML съдържание"></textarea></label><label>Статус<select name="status"><option value="draft">Чернова</option><option value="published">Публикувана</option></select></label><div><button>ЗАПАЗИ СТРАНИЦА</button></div></form>
<h2>Съществуващи страници</h2><div class="panel"><?php foreach ($db->query('SELECT title, slug, status FROM pages ORDER BY id DESC') as $p): ?><p><strong><?= e($p['title']) ?></strong> — <a href="/?page=<?= e($p['slug']) ?>">/<?= e($p['slug']) ?></a> <span class="muted">(<?= e($p['status']) ?>)</span></p><?php endforeach; ?></div>
<?php endif; ?></main></div></body></html>
