<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';

/*
|--------------------------------------------------------------------------
| TarıkCMS Advanced Admin
|--------------------------------------------------------------------------
| Existing dependencies:
| $db, $pluginManager, is_admin(), e()
|
| Expected pages columns:
| id, title, slug, content, status
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION['csrf_token'];
$error = '';
$notice = '';

/* Helpers */

function admin_h(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function admin_redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function admin_csrf_check(): void
{
    $token = (string) ($_POST['_csrf'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');

    if (
        $sessionToken === '' ||
        $token === '' ||
        !hash_equals($sessionToken, $token)
    ) {
        http_response_code(403);
        exit('Невалидна заявка. Презаредете страницата и опитайте отново.');
    }
}

function admin_slug(string $slug): string
{
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
    return trim($slug, '-');
}

function admin_flash(string $type, string $message): void
{
    $_SESSION['admin_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function admin_input(string $key, int $maxLength = 200): string
{
    $value = trim((string) ($_POST[$key] ?? ''));

    if (strlen($value) > $maxLength) {
        throw new LengthException(
            'Полето надвишава максималната допустима дължина.'
        );
    }

    return $value;
}

/* Read one-time notification */

if (isset($_SESSION['admin_flash'])) {
    $flash = $_SESSION['admin_flash'];
    unset($_SESSION['admin_flash']);

    $notice = (string) ($flash['message'] ?? '');

    if (($flash['type'] ?? '') === 'error') {
        $error = $notice;
        $notice = '';
    }
}

/* Authentication and POST actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        admin_csrf_check();

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'login') {
            $username = admin_input('username', 190);
            $password = (string) ($_POST['password'] ?? '');

            if (strlen($password) > 4096) {
                throw new RuntimeException(
                    'Невалидно потребителско име или парола.'
                );
            }

            $stmt = $db->prepare(
                'SELECT id, username, password_hash, role
                 FROM users
                 WHERE username = ?
                 LIMIT 1'
            );

            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                $user &&
                password_verify($password, (string) $user['password_hash']) &&
                (string) $user['role'] === 'admin'
            ) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['username'] = (string) $user['username'];
                $_SESSION['role'] = (string) $user['role'];
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                admin_redirect('/admin.php');
            }

            $error = 'Невалидно потребителско име или парола.';
        } elseif ($action === 'logout' && is_admin()) {
            $_SESSION = [];

            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();

                setcookie(session_name(), '', [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => (bool) $params['secure'],
                    'httponly' => (bool) $params['httponly'],
                    'samesite' => $params['samesite'] ?: 'Lax',
                ]);
            }

            session_destroy();
            admin_redirect('/admin.php');
        } elseif (is_admin()) {

            /* Save or update page */

            if ($action === 'save_page') {
                $id = filter_var(
                    $_POST['id'] ?? 0,
                    FILTER_VALIDATE_INT
                );

                if ($id === false || $id < 0) {
                    throw new RuntimeException('Невалиден ID на страницата.');
                }

                $title = admin_input('title', 255);
                $slug = admin_slug(admin_input('slug', 255));
                $content = (string) ($_POST['content'] ?? '');
                $status = (string) ($_POST['status'] ?? 'draft');

                if (strlen($content) > 1000000) {
                    throw new RuntimeException(
                        'Съдържанието е прекалено голямо.'
                    );
                }

                if ($title === '' || $slug === '') {
                    throw new RuntimeException(
                        'Заглавието и URL адресът са задължителни.'
                    );
                }

                if (!in_array($status, ['draft', 'published'], true)) {
                    throw new RuntimeException('Невалиден статус.');
                }

                if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                    throw new RuntimeException('Невалиден URL адрес.');
                }

                $stmt = $db->prepare(
                    'SELECT id FROM pages WHERE slug = ? LIMIT 1'
                );
                $stmt->execute([$slug]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existing && (int) $existing['id'] !== (int) $id) {
                    throw new RuntimeException(
                        'Този URL адрес вече се използва.'
                    );
                }

                if ($id > 0) {
                    $stmt = $db->prepare(
                        'SELECT id FROM pages WHERE id = ? LIMIT 1'
                    );
                    $stmt->execute([$id]);

                    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                        throw new RuntimeException(
                            'Страницата не съществува.'
                        );
                    }

                    $stmt = $db->prepare(
                        'UPDATE pages
                         SET title = ?, slug = ?, content = ?, status = ?
                         WHERE id = ?'
                    );

                    $stmt->execute([
                        $title,
                        $slug,
                        $content,
                        $status,
                        $id,
                    ]);

                    admin_flash('success', 'Страницата е актуализирана.');
                } else {
                    $stmt = $db->prepare(
                        'INSERT INTO pages (title, slug, content, status)
                         VALUES (?, ?, ?, ?)'
                    );

                    $stmt->execute([
                        $title,
                        $slug,
                        $content,
                        $status,
                    ]);

                    admin_flash('success', 'Страницата е създадена.');
                }

                admin_redirect('/admin.php?tab=pages');
            }

            /* Delete page */

            elseif ($action === 'delete_page') {
                $id = filter_var(
                    $_POST['id'] ?? null,
                    FILTER_VALIDATE_INT
                );

                if (!$id || $id < 1) {
                    throw new RuntimeException('Невалиден ID.');
                }

                $stmt = $db->prepare(
                    'DELETE FROM pages WHERE id = ?'
                );
                $stmt->execute([$id]);

                if ($stmt->rowCount() < 1) {
                    throw new RuntimeException(
                        'Страницата не е намерена.'
                    );
                }

                admin_flash('success', 'Страницата е изтрита.');
                admin_redirect('/admin.php?tab=pages');
            }

            /* Discover plugins */

            elseif ($action === 'discover_plugins') {
                $pluginManager->syncDiscovered();

                admin_flash('success', 'Плъгините са открити.');
                admin_redirect('/admin.php?tab=plugins');
            }

            /* Activate / deactivate plugin */

            elseif ($action === 'toggle_plugin') {
                $slug = admin_input('slug', 190);
                $activeValue = (string) ($_POST['active'] ?? '');

                if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
                    throw new RuntimeException('Невалиден плъгин.');
                }

                if (!in_array($activeValue, ['0', '1'], true)) {
                    throw new RuntimeException('Невалидно действие.');
                }

                $pluginManager->setActive(
                    $slug,
                    $activeValue === '1'
                );

                admin_flash('success', 'Настройките на плъгина са обновени.');
                admin_redirect('/admin.php?tab=plugins');
            }

            else {
                throw new RuntimeException('Непознато действие.');
            }
        }
    } catch (LengthException | RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        error_log('TarikCMS admin database error: ' . $exception->getMessage());
        $error = 'Грешка при работа с базата данни. Проверете настройките.';
    } catch (Throwable $exception) {
        error_log('TarikCMS admin error: ' . $exception->getMessage());
        $error = 'Възникна грешка при изпълнение на операцията.';
    }
}

/* Login screen */

if (!is_admin()):
?>
<!doctype html>
<html lang="bg">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#f2f0e9">
    <title>Вход — TarıkCMS</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<main class="login">
    <a class="brand" href="/">TARIKCMS</a>

    <p class="eyebrow">SECURE ACCESS / 001</p>
    <h1>Вход в системата<span class="text-accent">.</span></h1>
    <p class="muted">Административен панел за управление на сайта.</p>

    <?php if ($error !== ''): ?>
        <div class="error" role="alert"><?= admin_h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/admin.php">
        <input type="hidden" name="_csrf" value="<?= admin_h($csrfToken) ?>">
        <input type="hidden" name="action" value="login">

        <label>
            Потребителско име
            <input
                name="username"
                required
                maxlength="190"
                autocomplete="username"
                autofocus
            >
        </label>

        <label>
            Парола
            <input
                type="password"
                name="password"
                required
                autocomplete="current-password"
            >
        </label>

        <button type="submit">ВЛИЗАНЕ В СИСТЕМАТА →</button>
    </form>

    <p class="login-footer">
        TARIKCMS / ADMINISTRATION<br>
        <a href="/">← Към публичния сайт</a>
    </p>
</main>
</body>
</html>
<?php
exit;
endif;

/* Dashboard data */

$tab = (string) ($_GET['tab'] ?? 'dashboard');

$allowedTabs = ['dashboard', 'pages', 'plugins'];

if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'dashboard';
}

$pageCount = 0;
$publishedCount = 0;
$draftCount = 0;
$pages = [];
$plugins = [];
$editPage = null;
$pageError = '';

try {
    $pageCount = (int) $db->query(
        'SELECT COUNT(*) FROM pages'
    )->fetchColumn();

    $publishedCount = (int) $db->query(
        "SELECT COUNT(*) FROM pages WHERE status = 'published'"
    )->fetchColumn();

    $draftCount = (int) $db->query(
        "SELECT COUNT(*) FROM pages WHERE status = 'draft'"
    )->fetchColumn();

    if ($tab === 'pages') {
        $search = trim((string) ($_GET['q'] ?? ''));
        $statusFilter = (string) ($_GET['status'] ?? '');

        $pageId = filter_var(
            $_GET['edit'] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($pageId) {
            $stmt = $db->prepare(
                'SELECT id, title, slug, content, status
                 FROM pages WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$pageId]);
            $editPage = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if (!$editPage) {
                $pageError = 'Страницата за редактиране не е намерена.';
            }
        }

        $sql = 'SELECT id, title, slug, content, status
                FROM pages WHERE 1=1';
        $params = [];

        if ($search !== '') {
            $sql .= ' AND (title LIKE ? OR slug LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        if (in_array($statusFilter, ['draft', 'published'], true)) {
            $sql .= ' AND status = ?';
            $params[] = $statusFilter;
        }

        $sql .= ' ORDER BY id DESC LIMIT 200';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($tab === 'plugins') {
        $plugins = $pluginManager->discover();
    }
} catch (Throwable $exception) {
    error_log('TarikCMS admin load error: ' . $exception->getMessage());
    $pageError = 'Част от информацията не може да бъде заредена.';
}

$username = (string) ($_SESSION['username'] ?? 'Admin');
?>
<!doctype html>
<html lang="bg">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#111111">
    <title>Администрация — TarıkCMS</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>

<div class="admin-shell">

    <aside class="sidebar">
        <a class="brand" href="/admin.php">TARIKCMS</a>

        <p class="eyebrow">CONTROL PANEL / V2</p>

        <small class="muted-mono">CONTENT</small>

        <a
            href="/admin.php?tab=dashboard"
            <?= $tab === 'dashboard' ? 'aria-current="page"' : '' ?>
            class="<?= $tab === 'dashboard' ? 'active' : '' ?>"
        >01 / Табло</a>

        <a
            href="/admin.php?tab=pages"
            <?= $tab === 'pages' ? 'aria-current="page"' : '' ?>
            class="<?= $tab === 'pages' ? 'active' : '' ?>"
        >02 / Страници</a>

        <small class="muted-mono">SYSTEM</small>

        <a
            href="/admin.php?tab=plugins"
            <?= $tab === 'plugins' ? 'aria-current="page"' : '' ?>
            class="<?= $tab === 'plugins' ? 'active' : '' ?>"
        >03 / Плъгини</a>

        <a href="/" target="_blank" rel="noopener">↗ Преглед на сайта</a>

        <div style="margin-top:20px;padding:14px;border:1px solid #444">
            <small>LOGGED IN AS</small>
            <p style="margin:5px 0 0;font-weight:700;overflow-wrap:anywhere">
                <?= admin_h($username) ?>
            </p>
        </div>

        <form method="post" action="/admin.php">
            <input type="hidden" name="_csrf" value="<?= admin_h($csrfToken) ?>">
            <input type="hidden" name="action" value="logout">
            <button class="secondary" type="submit">ИЗХОД ↗</button>
        </form>
    </aside>

    <main class="admin-main">

        <header class="admin-topbar">
            <div>
                <p class="eyebrow">TARIKCMS / ADMINISTRATION</p>
                <p>Здравей, <strong><?= admin_h($username) ?></strong>.</p>
            </div>

            <span class="badge badge-success">ADMINISTRATOR</span>
        </header>

        <?php if ($notice !== ''): ?>
            <div class="notice" role="status"><?= admin_h($notice) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="error" role="alert"><?= admin_h($error) ?></div>
        <?php endif; ?>

        <?php if ($pageError !== ''): ?>
            <div class="error" role="alert"><?= admin_h($pageError) ?></div>
        <?php endif; ?>

        <?php if ($tab === 'dashboard'): ?>

            <p class="eyebrow">OVERVIEW / 001</p>
            <h1>Табло<span class="text-accent">.</span></h1>

            <p class="admin-description">
                Център за управление на съдържанието и разширенията на TarıkCMS.
            </p>

            <div class="stats-grid">
                <section class="stat-card">
                    <span class="stat-label">Всички страници</span>
                    <strong class="stat-value"><?= $pageCount ?></strong>
                    <span class="stat-note">Общ брой записи</span>
                </section>

                <section class="stat-card">
                    <span class="stat-label">Публикувани</span>
                    <strong class="stat-value"><?= $publishedCount ?></strong>
                    <span class="stat-note">Статус published</span>
                </section>

                <section class="stat-card">
                    <span class="stat-label">Чернови</span>
                    <strong class="stat-value"><?= $draftCount ?></strong>
                    <span class="stat-note">Статус draft</span>
                </section>

                <section class="stat-card">
                    <span class="stat-label">Версия на PHP</span>
                    <strong class="stat-value" style="font-size:1.5rem">
                        <?= admin_h(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?>
                    </strong>
                    <span class="stat-note">Runtime environment</span>
                </section>
            </div>

            <section class="panel">
                <div>
                    <p class="eyebrow">CONTENT MANAGEMENT</p>
                    <h2>Управление на страниците</h2>
                    <p>Създавай, редактирай, публикувай и търси съдържание.</p>
                </div>
                <a class="btn" href="/admin.php?tab=pages">ОТВОРИ →</a>
            </section>

            <section class="panel">
                <div>
                    <p class="eyebrow">EXTENSIONS</p>
                    <h2>Управление на плъгини</h2>
                    <p>Преглед и управление на откритите разширения.</p>
                </div>
                <a class="btn btn-secondary" href="/admin.php?tab=plugins">
                    ПЛЪГИНИ →
                </a>
            </section>

            <section class="panel">
                <div>
                    <p class="eyebrow">PUBLIC WEBSITE</p>
                    <h2>Публичен сайт</h2>
                    <p>Отвори сайта в нов прозорец.</p>
                </div>
                <a class="btn btn-dark" href="/" target="_blank" rel="noopener">
                    ПРЕГЛЕД ↗
                </a>
            </section>

        <?php elseif ($tab === 'plugins'): ?>

            <p class="eyebrow">EXTENSION MANAGER / 003</p>
            <h1>Плъгини<span class="text-accent">.</span></h1>

            <p class="admin-description">
                Управление на разширенията, открити от системата.
            </p>

            <form method="post" action="/admin.php?tab=plugins">
                <input type="hidden" name="_csrf" value="<?= admin_h($csrfToken) ?>">
                <input type="hidden" name="action" value="discover_plugins">
                <button type="submit">↻ ОТКРИЙ ПЛЪГИНИ</button>
            </form>

            <?php if (!$plugins): ?>
                <div class="empty-state">
                    <h2>Няма открити плъгини.</h2>
                    <p>
                        Провери директорията за плъгини и конфигурацията
                        на PluginManager.
                    </p>
                </div>
            <?php else: ?>

                <?php foreach ($plugins as $plugin): ?>
                    <?php
                    $pluginSlug = (string) ($plugin['slug'] ?? '');
                    $pluginActive = !empty($plugin['active']);
                    ?>

                    <section class="panel">
                        <div>
                            <span class="eyebrow">EXTENSION</span>
                            <h2>
                                <?= admin_h($plugin['name'] ?? $pluginSlug) ?>
                            </h2>

                            <p><?= admin_h($plugin['description'] ?? '') ?></p>

                            <small>
                                Версия <?= admin_h($plugin['version'] ?? '?') ?>
                                · <?= admin_h($pluginSlug) ?>
                            </small>

                            <p>
                                <span class="badge <?= $pluginActive
                                    ? 'badge-success'
                                    : 'badge-neutral' ?>">
                                    <?= $pluginActive ? 'АКТИВЕН' : 'НЕАКТИВЕН' ?>
                                </span>
                            </p>
                        </div>

                        <form method="post" action="/admin.php?tab=plugins">
                            <input type="hidden" name="_csrf"
                                   value="<?= admin_h($csrfToken) ?>">
                            <input type="hidden" name="action"
                                   value="toggle_plugin">
                            <input type="hidden" name="slug"
                                   value="<?= admin_h($pluginSlug) ?>">
                            <input type="hidden" name="active"
                                   value="<?= $pluginActive ? '0' : '1' ?>">

                            <button type="submit"
                                class="<?= $pluginActive ? 'secondary' : '' ?>">
                                <?= $pluginActive
                                    ? 'ДЕАКТИВИРАЙ'
                                    : 'АКТИВИРАЙ' ?>
                            </button>
                        </form>
                    </section>
                <?php endforeach; ?>

            <?php endif; ?>

            <div class="warning">
                <strong>Сигурност:</strong>
                PHP плъгините могат да изпълняват код със същите права като
                приложението. Инсталирай само проверени разширения.
            </div>

        <?php else: ?>

            <p class="eyebrow">CONTENT MANAGEMENT / 002</p>
            <h1>Страници<span class="text-accent">.</span></h1>

            <p class="admin-description">
                Създаване, редактиране, търсене и управление на публикации.
            </p>

            <section class="panel">
                <div>
                    <h2><?= $editPage ? 'Редактиране на страница' : 'Нова страница' ?></h2>
                    <p class="muted">Попълни полетата и запази промените.</p>
                </div>
            </section>

            <form method="post" action="/admin.php?tab=pages"
                  class="panel form-grid">

                <input type="hidden" name="_csrf"
                       value="<?= admin_h($csrfToken) ?>">
                <input type="hidden" name="action" value="save_page">
                <input type="hidden" name="id"
                       value="<?= (int) ($editPage['id'] ?? 0) ?>">

                <label>
                    Заглавие
                    <input
                        name="title"
                        required
                        maxlength="255"
                        value="<?= admin_h($editPage['title'] ?? '') ?>"
                        placeholder="Например: За нас"
                    >
                </label>

                <label>
                    URL slug
                    <input
                        name="slug"
                        required
                        maxlength="255"
                        pattern="[a-zA-Z0-9-]+"
                        value="<?= admin_h($editPage['slug'] ?? '') ?>"
                        placeholder="za-nas"
                    >
                    <small>Използвай латински букви, цифри и тирета.</small>
                </label>

                <label class="full">
                    Съдържание
                    <textarea
                        name="content"
                        rows="12"
                        maxlength="1000000"
                        placeholder="Въведи съдържанието на страницата..."
                    ><?= admin_h($editPage['content'] ?? '') ?></textarea>
                    <small>
                        Не поставяй непроверен HTML или JavaScript.
                    </small>
                </label>

                <label>
                    Статус
                    <select name="status">
                        <option value="draft"
                            <?= ($editPage['status'] ?? 'draft') === 'draft'
                                ? 'selected' : '' ?>>
                            Чернова
                        </option>
                        <option value="published"
                            <?= ($editPage['status'] ?? '') === 'published'
                                ? 'selected' : '' ?>>
                            Публикувана
                        </option>
                    </select>
                </label>

                <div class="form-actions">
                    <button type="submit">
                        <?= $editPage ? 'ЗАПАЗИ ПРОМЕНИТЕ' : 'СЪЗДАЙ СТРАНИЦА' ?>
                        →
                    </button>

                    <?php if ($editPage): ?>
                        <a class="btn btn-secondary"
                           href="/admin.php?tab=pages">
                            ОТКАЗ
                        </a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="toolbar">
                <div>
                    <h2>Съществуващи страници</h2>
                    <p class="muted">Показани са до 200 записа.</p>
                </div>
            </div>

            <form class="toolbar" method="get" action="/admin.php">
                <input type="hidden" name="tab" value="pages">

                <div class="search-form">
                    <input
                        type="search"
                        name="q"
                        maxlength="200"
                        value="<?= admin_h($_GET['q'] ?? '') ?>"
                        placeholder="Търси по заглавие или URL..."
                        aria-label="Търсене на страници"
                    >
                    <button type="submit">ТЪРСИ</button>
                </div>

                <div class="filters">
                    <select name="status" aria-label="Филтър по статус">
                        <option value="">Всички статуси</option>
                        <option value="published"
                            <?= ($_GET['status'] ?? '') === 'published'
                                ? 'selected' : '' ?>>
                            Публикувани
                        </option>
                        <option value="draft"
                            <?= ($_GET['status'] ?? '') === 'draft'
                                ? 'selected' : '' ?>>
                            Чернови
                        </option>
                    </select>

                    <button class="secondary" type="submit">ФИЛТРИРАЙ</button>
                </div>
            </form>

            <?php if (!$pages): ?>

                <div class="empty-state">
                    <h2>Няма намерени страници.</h2>
                    <p>Създай нова страница или промени критериите за търсене.</p>
                </div>

            <?php else: ?>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Заглавие</th>
                                <th>URL</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pages as $page): ?>
                            <tr>
                                <td><?= (int) $page['id'] ?></td>

                                <td>
                                    <strong><?= admin_h($page['title']) ?></strong>
                                </td>

                                <td>
                                    <code>/<?= admin_h($page['slug']) ?></code>
                                </td>

                                <td>
                                    <span class="badge <?= $page['status'] === 'published'
                                        ? 'badge-success'
                                        : 'badge-neutral' ?>">
                                        <?= $page['status'] === 'published'
                                            ? 'ПУБЛИКУВАНА'
                                            : 'ЧЕРНОВА' ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="table-actions">
                                        <a class="btn btn-secondary"
                                           href="/admin.php?tab=pages&amp;edit=<?= (int) $page['id'] ?>">
                                            РЕДАКТИРАЙ
                                        </a>

                                        <?php if ($page['status'] === 'published'): ?>
                                            <a class="btn btn-secondary"
                                               href="/?page=<?= rawurlencode((string) $page['slug']) ?>"
                                               target="_blank"
                                               rel="noopener">
                                                ПРЕГЛЕД ↗
                                            </a>
                                        <?php endif; ?>

                                        <form method="post"
                                              action="/admin.php?tab=pages"
                                              onsubmit="return confirm('Сигурен ли си, че искаш да изтриеш тази страница?');">

                                            <input type="hidden" name="_csrf"
                                                   value="<?= admin_h($csrfToken) ?>">
                                            <input type="hidden" name="action"
                                                   value="delete_page">
                                            <input type="hidden" name="id"
                                                   value="<?= (int) $page['id'] ?>">

                                            <button class="danger" type="submit">
                                                ИЗТРИЙ
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>

        <?php endif; ?>

        <footer style="margin-top:60px;padding-top:20px;border-top:2px solid">
            <small>
                TARIKCMS / ADMIN PANEL / PHP <?= admin_h(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?>
            </small>
        </footer>

    </main>
</div>

</body>
</html>