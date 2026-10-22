<?php
declare(strict_types=1);

/** Lightweight WordPress-style actions and filters. */
final class Hooks
{
    private static array $actions = [];
    private static array $filters = [];

    public static function addAction(string $name, callable $callback, int $priority = 10): void
    {
        self::$actions[$name][$priority][] = $callback;
    }

    public static function doAction(string $name, mixed ...$args): void
    {
        $groups = self::$actions[$name] ?? [];
        ksort($groups);
        foreach ($groups as $callbacks) {
            foreach ($callbacks as $callback) {
                $callback(...$args);
            }
        }
    }

    public static function addFilter(string $name, callable $callback, int $priority = 10): void
    {
        self::$filters[$name][$priority][] = $callback;
    }

    public static function applyFilters(string $name, mixed $value, mixed ...$args): mixed
    {
        $groups = self::$filters[$name] ?? [];
        ksort($groups);
        foreach ($groups as $callbacks) {
            foreach ($callbacks as $callback) {
                $value = $callback($value, ...$args);
            }
        }
        return $value;
    }
}

function add_action(string $name, callable $callback, int $priority = 10): void
{
    Hooks::addAction($name, $callback, $priority);
}
function do_action(string $name, mixed ...$args): void
{
    Hooks::doAction($name, ...$args);
}
function add_filter(string $name, callable $callback, int $priority = 10): void
{
    Hooks::addFilter($name, $callback, $priority);
}
function apply_filters(string $name, mixed $value, mixed ...$args): mixed
{
    return Hooks::applyFilters($name, $value, ...$args);
}
