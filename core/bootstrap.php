<?php
declare(strict_types=1);

/**
 * TarаkCMS - Core Bootstrap
 * PHP 8.2+
 *
 * Централна инициализация:
 * - конфигурация
 * - сесии
 * - база данни
 * - hooks и plugins
 * - CSRF защита
 * - административен достъп
 * - помощни функции
 */

if (!defined('TARAKCMS_BOOTSTRAPPED')) {
    define('TARAKCMS_BOOTSTRAPPED', true);
}

/*
|--------------------------------------------------------------------------
| Основни пътища
|--------------------------------------------------------------------------
*/

define('CMS_ROOT', dirname(__DIR__));
define('CMS_CORE', __DIR__);
define('CMS_CONFIG_FILE', CMS_ROOT . '/config/config.php');

/*
|--------------------------------------------------------------------------
| Конфигурация
|--------------------------------------------------------------------------
*/

if (!is_file(CMS_CONFIG_FILE)) {
    http_response_code(500);
    exit(
        'Липсва config/config.php. ' .
        'Копирайте config.example.php и настройте базата данни.'
    );
}

$config = require CMS_CONFIG_FILE;

if (!is_array($config)) {
    error_log('[TarikCMS] Конфигурацията трябва да връща PHP array.');
    http_response_code(500);
    exit('Грешка в конфигурацията на сайта.');
}

/*
|--------------------------------------------------------------------------
| Режим на работа
|--------------------------------------------------------------------------
|
| В config/config.php можеш да зададеш:
| 'environment' => 'production',
| 'debug' => false,
|
*/

$environment = (string) ($config['environment'] ?? 'production');
$debug = (bool) ($config['debug'] ?? false);

error_reporting(E_ALL);
ini_set('display_errors', ($debug && $environment !== 'production') ? '1' : '0');
ini_set('log_errors', '1');

/*
|--------------------------------------------------------------------------
| Часова зона
|--------------------------------------------------------------------------
*/

$timezone = (string) ($config['timezone'] ?? 'Europe/Sofia');

if (!in_array($timezone, timezone_identifiers_list(), true)) {
    $timezone = 'Europe/Sofia';
}

date_default_timezone_set($timezone);

/*
|--------------------------------------------------------------------------
| HTTPS проверка
|--------------------------------------------------------------------------
|
| Не се доверяваме автоматично на X-Forwarded-Proto,
| защото може да бъде подаден от клиент.
| Ако използваш reverse proxy, настрой го отделно и сигурно.
|
*/

$isHttps = (
    (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
);

/*
|--------------------------------------------------------------------------
| Сесии
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');

    session_name('TARIKCMSSESSID');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/*
|--------------------------------------------------------------------------
| Заглавки за сигурност
|--------------------------------------------------------------------------
|
| Не задаваме принудително HSTS, защото той трябва да се включва
| само след правилно конфигуриран HTTPS за целия домейн.
|
*/

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

/*
|--------------------------------------------------------------------------
| Зареждане на класовете
|--------------------------------------------------------------------------
*/

require_once CMS_CORE . '/Database.php';
require_once CMS_CORE . '/Hooks.php';
require_once CMS_CORE . '/PluginManager.php';

/*
|--------------------------------------------------------------------------
| База данни
|--------------------------------------------------------------------------
*/

try {
    $db = Database::connection($config);
} catch (Throwable $exception) {
    error_log('[TarikCMS][Database] ' . $exception->getMessage());

    http_response_code(500);
    exit(
        $debug && $environment !== 'production'
            ? 'Грешка при свързване с базата данни: ' . e($exception->getMessage())
            : 'Възникна техническа грешка. Опитайте отново по-късно.'
    );
}

/*
|--------------------------------------------------------------------------
| Hooks и плъгини
|--------------------------------------------------------------------------
*/

try {
    $pluginManager = new PluginManager($db, CMS_CORE . '/plugins');
    $pluginManager->loadActive();
} catch (Throwable $exception) {
    error_log('[TarikCMS][Plugins] ' . $exception->getMessage());

    // При проблем с плъгин не показваме вътрешните данни на посетителя.
    // Ако плъгинът е задължителен за сайта, премахни този catch
    // или промени поведението според нуждите си.
}

/*
|--------------------------------------------------------------------------
| HTML escaping
|--------------------------------------------------------------------------
*/

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Конфигурационен помощник
|--------------------------------------------------------------------------
*/

if (!function_exists('cms_config')) {
    function cms_config(string $key, mixed $default = null): mixed
    {
        global $config;

        return $config[$key] ?? $default;
    }
}

/*
|--------------------------------------------------------------------------
| Проверка на метода на заявката
|--------------------------------------------------------------------------
*/

if (!function_exists('cms_request_method')) {
    function cms_request_method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }
}

if (!function_exists('cms_require_method')) {
    function cms_require_method(string ...$methods): void
    {
        $allowed = array_map('strtoupper', $methods);

        if (!in_array(cms_request_method(), $allowed, true)) {
            header('Allow: ' . implode(', ', $allowed));
            http_response_code(405);
            exit('Методът на заявката не е разрешен.');
        }
    }
}

/*
|--------------------------------------------------------------------------
| CSRF защита
|--------------------------------------------------------------------------
|
| Използвай токена във всяка форма, която променя данни.
|
*/

if (!function_exists('cms_csrf_token')) {
    function cms_csrf_token(): string
    {
        if (
            !isset($_SESSION['_cms_csrf'])
            || !is_string($_SESSION['_cms_csrf'])
            || strlen($_SESSION['_cms_csrf']) !== 64
        ) {
            $_SESSION['_cms_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_cms_csrf'];
    }
}

if (!function_exists('cms_csrf_field')) {
    function cms_csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' .
            e(cms_csrf_token()) .
            '">';
    }
}

if (!function_exists('cms_verify_csrf')) {
    function cms_verify_csrf(): bool
    {
        $sessionToken = $_SESSION['_cms_csrf'] ?? null;
        $requestToken = $_POST['_csrf'] ?? null;

        return is_string($sessionToken)
            && is_string($requestToken)
            && $sessionToken !== ''
            && hash_equals($sessionToken, $requestToken);
    }
}

if (!function_exists('cms_require_csrf')) {
    function cms_require_csrf(): void
    {
        if (!cms_verify_csrf()) {
            http_response_code(403);
            exit('Заявката е невалидна или е изтекла. Презаредете страницата и опитайте отново.');
        }
    }
}

/*
|--------------------------------------------------------------------------
| Административен достъп
|--------------------------------------------------------------------------
*/

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return isset($_SESSION['user_id'])
            && (int) $_SESSION['user_id'] > 0
            && ($_SESSION['role'] ?? '') === 'admin';
    }
}

if (!function_exists('require_admin')) {
    function require_admin(): void
    {
        if (is_admin()) {
            return;
        }

        // При API заявки връщаме статус, вместо HTML пренасочване.
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));

        if (
            str_contains($accept, 'application/json')
            || str_starts_with(
                strtolower((string) ($_SERVER['REQUEST_URI'] ?? '')),
                '/api/'
            )
        ) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');

            echo json_encode(
                ['error' => 'authentication_required'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            exit;
        }

        header('Location: /admin.php', true, 302);
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Проверка на административна POST заявка
|--------------------------------------------------------------------------
*/

if (!function_exists('require_admin_post')) {
    function require_admin_post(): void
    {
        require_admin();
        cms_require_method('POST');
        cms_require_csrf();
    }
}

/*
|--------------------------------------------------------------------------
| Безопасно локално пренасочване
|--------------------------------------------------------------------------
|
| Не позволява redirect към произволен външен домейн.
|
*/

if (!function_exists('cms_redirect')) {
    function cms_redirect(string $path, int $status = 302): never
    {
        if (
            $path === ''
            || $path[0] !== '/'
            || str_starts_with($path, '//')
            || preg_match('/[\r\n]/', $path)
        ) {
            $path = '/';
        }

        header('Location: ' . $path, true, $status);
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Flash съобщения
|--------------------------------------------------------------------------
*/

if (!function_exists('cms_flash_set')) {
    function cms_flash_set(string $type, string $message): void
    {
        $allowedTypes = ['success', 'error', 'warning', 'info'];

        if (!in_array($type, $allowedTypes, true)) {
            $type = 'info';
        }

        $_SESSION['_cms_flash'][] = [
            'type' => $type,
            'message' => $message,
        ];
    }
}

if (!function_exists('cms_flash_get')) {
    function cms_flash_get(): array
    {
        $messages = $_SESSION['_cms_flash'] ?? [];
        unset($_SESSION['_cms_flash']);

        return is_array($messages) ? $messages : [];
    }
}

/*
|--------------------------------------------------------------------------
| Ограничена проверка за валиден slug
|--------------------------------------------------------------------------
*/

if (!function_exists('cms_valid_slug')) {
    function cms_valid_slug(string $slug): bool
    {
        return (bool) preg_match(
            '/^[a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)*$/',
            $slug
        );
    }
}

/*
|--------------------------------------------------------------------------
| Идентификатор на текущата заявка
|--------------------------------------------------------------------------
*/

if (!function_exists('cms_request_id')) {
    function cms_request_id(): string
    {
        if (!isset($GLOBALS['_cms_request_id'])) {
            $GLOBALS['_cms_request_id'] = bin2hex(random_bytes(8));
        }

        return $GLOBALS['_cms_request_id'];
    }
}
