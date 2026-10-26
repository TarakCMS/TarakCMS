<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';

/*
|--------------------------------------------------------------------------
| TARAKCMS Public Frontend
|--------------------------------------------------------------------------
| Dependencies:
| - core/bootstrap.php
| - PDO connection: $db
| - $config
| - apply_filters()
| - e()
|
| Existing routes:
| /                  -> latest published page (fallback homepage)
| /?page=za-nas      -> published page by slug
|--------------------------------------------------------------------------
*/

if (!function_exists('cms_h')) {
    function cms_h(mixed $value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

/**
 * Accept only valid HTTP/HTTPS URLs for external metadata.
 */
if (!function_exists('cms_valid_url')) {
    function cms_valid_url(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);

        if (
            $parts === false ||
            !isset($parts['scheme'], $parts['host']) ||
            !in_array(strtolower($parts['scheme']), ['http', 'https'], true) ||
            isset($parts['user']) ||
            isset($parts['pass'])
        ) {
            return '';
        }

        return $url;
    }
}

/**
 * Sanitize HTML using an allowlist.
 *
 * If DOMDocument is unavailable, escape the complete input rather than
 * rendering potentially unsafe HTML.
 *
 * This is a basic sanitizer. For production use, a maintained HTML
 * sanitizer such as HTML Purifier is preferable.
 */
if (!function_exists('cms_safe_html')) {
    function cms_safe_html(string $html): string
    {
        if (!class_exists(DOMDocument::class)) {
            return nl2br(cms_h($html), false);
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument('1.0', 'UTF-8');

            $document->loadHTML(
                '<?xml encoding="UTF-8"><div id="cms-content">' .
                $html .
                '</div>',
                LIBXML_HTML_NOIMPLIED |
                LIBXML_HTML_NODEFDTD
            );

            $root = $document->getElementById('cms-content');

            if (!$root) {
                return nl2br(cms_h($html), false);
            }

            $allowedTags = [
                'p', 'br', 'hr',
                'h2', 'h3', 'h4', 'h5', 'h6',
                'strong', 'b', 'em', 'i', 'u', 's',
                'ul', 'ol', 'li',
                'blockquote', 'pre', 'code',
                'a',
            ];

            $dangerousTags = [
                'script', 'style', 'iframe', 'object', 'embed',
                'form', 'input', 'button', 'textarea', 'select',
                'option', 'meta', 'link', 'base', 'svg', 'math',
                'template', 'noscript',
            ];

            $clean = static function (DOMNode $parent) use (
                &$clean,
                $allowedTags,
                $dangerousTags
            ): void {
                $children = [];

                foreach ($parent->childNodes as $child) {
                    $children[] = $child;
                }

                foreach ($children as $child) {
                    if ($child instanceof DOMComment) {
                        $parent->removeChild($child);
                        continue;
                    }

                    if ($child instanceof DOMText) {
                        continue;
                    }

                    if (!$child instanceof DOMElement) {
                        $parent->removeChild($child);
                        continue;
                    }

                    $tag = strtolower($child->tagName);

                    if (in_array($tag, $dangerousTags, true)) {
                        $parent->removeChild($child);
                        continue;
                    }

                    $clean($child);

                    if (!in_array($tag, $allowedTags, true)) {
                        $fragment = $child->ownerDocument->createDocumentFragment();

                        while ($child->firstChild) {
                            $fragment->appendChild($child->firstChild);
                        }

                        $parent->replaceChild($fragment, $child);
                        continue;
                    }

                    $attributes = [];

                    foreach ($child->attributes as $attribute) {
                        $attributes[] = $attribute->name;
                    }

                    foreach ($attributes as $attribute) {
                        if ($tag !== 'a' || $attribute !== 'href') {
                            $child->removeAttribute($attribute);
                        }
                    }

                    if ($tag === 'a') {
                        $href = trim($child->getAttribute('href'));

                        // Allow local relative links and HTTP(S) links only.
                        $parts = parse_url($href);
                        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

                        $safe = $href !== ''
                            && !preg_match('/[\\\\\x00-\x20]/', $href)
                            && !in_array($scheme, ['javascript', 'data', 'vbscript'], true)
                            && (
                                $scheme === ''
                                || in_array($scheme, ['http', 'https'], true)
                            );

                        if (!$safe || str_starts_with($href, '//')) {
                            $child->removeAttribute('href');
                        } else {
                            $child->setAttribute('rel', 'nofollow noopener noreferrer');
                        }
                    }
                }
            };

            $clean($root);

            $output = '';

            foreach ($root->childNodes as $child) {
                $output .= $document->saveHTML($child);
            }

            return $output;
        } catch (Throwable $exception) {
            error_log('TARAKCMS HTML sanitization error: ' . $exception->getMessage());

            return nl2br(cms_h($html), false);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}

/* Application settings */

$appName = trim((string) ($config['app_name'] ?? 'TARAKCMS'));

if ($appName === '') {
    $appName = 'TARAKCMS';
}

$siteUrl = cms_valid_url(
    (string) ($config['site_url'] ?? '')
);

$defaultDescription = (string) (
    $config['meta_description'] ??
    'Официален сайт, създаден с TARAKCMS.'
);

$slug = trim((string) ($_GET['page'] ?? ''), '/');

$slugValid = $slug === '' ||
    preg_match('/^[a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)*$/', $slug);

/* Load the requested page */

$page = null;
$pages = [];
$loadError = false;
$notFound = false;

try {
    if (!$slugValid) {
        $notFound = true;
    } elseif ($slug !== '') {
        $stmt = $db->prepare(
            "SELECT id, title, slug, content, status
             FROM pages
             WHERE slug = ? AND status = 'published'
             LIMIT 1"
        );

        $stmt->execute([$slug]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$page) {
            $notFound = true;
        }
    } else {
        /*
         * Backward-compatible homepage:
         * display the newest published page when no dedicated homepage
         * configuration is defined.
         */
        $homeSlug = trim(
            (string) ($config['home_slug'] ?? '')
        );

        if ($homeSlug !== '') {
            $stmt = $db->prepare(
                "SELECT id, title, slug, content, status
                 FROM pages
                 WHERE slug = ? AND status = 'published'
                 LIMIT 1"
            );

            $stmt->execute([$homeSlug]);
            $page = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if (!$page) {
            $stmt = $db->query(
                "SELECT id, title, slug, content, status
                 FROM pages
                 WHERE status = 'published'
                 ORDER BY id DESC
                 LIMIT 1"
            );

            $page = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

    // Navigation contains published pages only.
    $stmt = $db->query(
        "SELECT title, slug
         FROM pages
         WHERE status = 'published'
         ORDER BY id ASC
         LIMIT 50"
    );

    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    error_log('TARAKCMS frontend database error: ' . $exception->getMessage());

    $loadError = true;
    $page = null;
    $pages = [];
}

/* Page metadata */

$isHome = $slug === '' && !$notFound && !$loadError;

$title = $page
    ? trim((string) $page['title'])
    : ($notFound ? 'Страницата не е намерена' : $appName);

if ($title === '') {
    $title = $appName;
}

$description = trim((string) (
    $page['meta_description'] ?? ''
));

if ($description === '') {
    $description = $defaultDescription;
}

$description = preg_replace('/\s+/u', ' ', strip_tags($description)) ?? '';
$description = mb_substr($description, 0, 300, 'UTF-8');

$canonicalUrl = '';

if ($siteUrl !== '') {
    $canonicalUrl = $isHome
        ? $siteUrl . '/'
        : $siteUrl . '/?page=' . rawurlencode(
            (string) ($page['slug'] ?? '')
        );
}

/* Render page content through the CMS filter system */

if ($loadError) {
    http_response_code(503);

    $body = '<h2>Временно недостъпен сайт</h2>' .
        '<p>Моля, опитайте отново по-късно.</p>';
} elseif ($notFound) {
    http_response_code(404);

    $body = '<h2>404 / Не намерихме тази страница.</h2>' .
        '<p>Възможно е адресът да е променен или страницата да е премахната.</p>' .
        '<p><a href="/">Обратно към началото →</a></p>';
} elseif ($page) {
    $body = (string) $page['content'];
} else {
    $body = '<h2>Добре дошли в TARAKCMS.</h2>' .
        '<p>Влезте в администрацията, за да създадете първата си страница.</p>' .
        '<p><a href="/admin.php">Отвори администрацията →</a></p>';
}

/*
 * Sanitize AFTER filters: plugins may change the content.
 * This deliberately permits only the tags listed in cms_safe_html().
 */
try {
    $body = (string) apply_filters('page_content', $body, $page);
    $body = cms_safe_html($body);
} catch (Throwable $exception) {
    error_log('TARAKCMS page_content filter error: ' . $exception->getMessage());

    $body = '<p>Възникна грешка при показване на съдържанието.</p>';
}

/* Footer filters are sanitized as well. */

try {
    $footer = (string) apply_filters(
        'site_footer',
        '<p>Създадено с <a href="/">TARAKCMS</a></p>'
    );

    $footer = cms_safe_html($footer);
} catch (Throwable $exception) {
    error_log('TARAKCMS footer filter error: ' . $exception->getMessage());

    $footer = 'Създадено с TARAKCMS';
}

$year = date('Y');
?>
<!doctype html>
<html lang="bg">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <meta name="robots" content="<?= $notFound || $loadError
        ? 'noindex,nofollow'
        : 'index,follow' ?>">

    <meta name="theme-color" content="#111111">
    <meta name="description" content="<?= cms_h($description) ?>">

    <title><?= cms_h($title) ?><?= $title !== $appName
        ? ' — ' . cms_h($appName)
        : '' ?></title>

    <?php if ($canonicalUrl !== ''): ?>
        <link rel="canonical" href="<?= cms_h($canonicalUrl) ?>">
    <?php endif; ?>

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= cms_h($appName) ?>">
    <meta property="og:title" content="<?= cms_h($title) ?>">
    <meta property="og:description" content="<?= cms_h($description) ?>">

    <?php if ($canonicalUrl !== ''): ?>
        <meta property="og:url" content="<?= cms_h($canonicalUrl) ?>">
    <?php endif; ?>

    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>

<a class="skip-link" href="#main-content">Към съдържанието</a>

<header class="site-header">
    <a class="brand" href="/" aria-label="<?= cms_h($appName) ?> — начало">
        <?= cms_h($appName) ?><span class="brand-dot">.</span>
    </a>

    <nav class="site-nav" aria-label="Основна навигация">
        <a href="/" <?= $isHome ? 'aria-current="page"' : '' ?>>
            Начало
        </a>

        <?php foreach ($pages as $navPage): ?>
            <?php
            $navSlug = (string) $navPage['slug'];

            if ($navSlug === (string) ($page['slug'] ?? '') && !$isHome) {
                $current = true;
            } else {
                $current = false;
            }
            ?>

            <?php if ($navSlug !== ''): ?>
                <a
                    href="/?page=<?= rawurlencode($navSlug) ?>"
                    <?= $current ? 'aria-current="page"' : '' ?>
                >
                    <?= cms_h($navPage['title']) ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>

        <a class="nav-admin" href="/admin.php">АДМИН ↗</a>
    </nav>

    <button
        class="menu-toggle"
        type="button"
        aria-expanded="false"
        aria-controls="mobile-navigation"
    >
        МЕНЮ <span aria-hidden="true">+</span>
    </button>
</header>

<div class="mobile-nav" id="mobile-navigation" hidden>
    <a href="/">Начало</a>

    <?php foreach ($pages as $navPage): ?>
        <?php if ((string) $navPage['slug'] !== ''): ?>
            <a href="/?page=<?= rawurlencode((string) $navPage['slug']) ?>">
                <?= cms_h($navPage['title']) ?>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>

    <a href="/admin.php">Администрация ↗</a>
</div>

<main class="content" id="main-content">
    <div class="content-meta">
        <span class="eyebrow">
            TARAKCMS / <?= $isHome ? 'HOME' : ($notFound ? '404' : 'PAGE') ?>
        </span>

        <?php if ($page): ?>
            <span class="page-id">
                ID <?= (int) $page['id'] ?>
            </span>
        <?php endif; ?>
    </div>

    <h1><?= cms_h($title) ?><span class="title-dot">.</span></h1>

    <div class="content-rule"></div>

    <article class="page-body">
        <?= $body ?>
    </article>

    <?php if (!$notFound && !$loadError && !$page): ?>
        <a class="cta-link" href="/admin.php">
            СЪЗДАЙ ПЪРВАТА СТРАНИЦА <span>↗</span>
        </a>
    <?php endif; ?>

    <div class="content-bottom">
        <span>END OF PAGE / <?= $year ?></span>
        <a href="#main-content">НАГОРЕ ↑</a>
    </div>
</main>

<footer class="site-footer">
    <div class="footer-brand">
        <a class="brand" href="/"><?= cms_h($appName) ?><span class="brand-dot">.</span></a>
        <p>Леко ядро. Пълен контрол.</p>
    </div>

    <div class="footer-content">
        <?= $footer ?>
    </div>

    <div class="footer-bottom">
        <span>© <?= $year ?> <?= cms_h($appName) ?></span>
        <span>BUILT WITH PURPOSE.</span>
    </div>
</footer>

<script>
(() => {
    const button = document.querySelector('.menu-toggle');
    const menu = document.getElementById('mobile-navigation');

    if (!button || !menu) return;

    button.addEventListener('click', () => {
        const expanded = button.getAttribute('aria-expanded') === 'true';

        button.setAttribute('aria-expanded', String(!expanded));
        menu.hidden = expanded;
        button.querySelector('span').textContent = expanded ? '+' : '−';
    });
})();
</script>

</body>
</html>