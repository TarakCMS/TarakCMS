<?php

declare(strict_types=1);

/**
 * TarаkCMS Hook System
 *
 * Lightweight actions and filters.
 *
 * Features:
 * - Actions and filters
 * - Priority-based execution
 * - Stable callback order
 * - Remove callbacks
 * - One-time callbacks
 * - Hook inspection
 * - Recursion protection
 * - Exception propagation
 * - Backward-compatible procedural API
 */

final class Hooks
{
    /**
     * Registered actions and filters.
     *
     * Structure:
     * $registry[$name][$priority][] = [
     *     'id'       => string,
     *     'callback' => callable,
     *     'once'     => bool,
     *     'sequence' => int,
     * ];
     */
    private static array $actions = [];
    private static array $filters = [];

    private static int $sequence = 0;

    private static array $actionDepth = [];
    private static array $filterDepth = [];

    private const MAX_RECURSION_DEPTH = 100;

    /*
    |--------------------------------------------------------------------------
    | Callback identity
    |--------------------------------------------------------------------------
    */

    private static function callbackId(callable $callback): string
    {
        if (is_string($callback)) {
            return 'function:' . strtolower(ltrim($callback, '\\'));
        }

        if ($callback instanceof Closure) {
            return 'closure:' . spl_object_id($callback);
        }

        if (is_array($callback) && count($callback) === 2) {
            [$target, $method] = $callback;

            if (is_object($target)) {
                return 'object:' . spl_object_id($target)
                    . '::' . strtolower((string) $method);
            }

            return 'static:' . strtolower(ltrim((string) $target, '\\'))
                . '::' . strtolower((string) $method);
        }

        if (is_object($callback)) {
            return 'invokable:' . spl_object_id($callback);
        }

        throw new InvalidArgumentException('Unsupported callback type.');
    }

    private static function register(
        array &$registry,
        string $name,
        callable $callback,
        int $priority,
        bool $once
    ): void {
        self::validateName($name);

        $id = self::callbackId($callback);

        $registry[$name][$priority][] = [
            'id' => $id,
            'callback' => $callback,
            'once' => $once,
            'sequence' => self::$sequence++,
        ];
    }

    private static function validateName(string $name): void
    {
        if (
            $name === ''
            || strlen($name) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $name)
        ) {
            throw new InvalidArgumentException(
                'Hook name must be non-empty and contain no control characters.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    */

    public static function addAction(
        string $name,
        callable $callback,
        int $priority = 10
    ): void {
        self::register(
            self::$actions,
            $name,
            $callback,
            $priority,
            false
        );
    }

    public static function addActionOnce(
        string $name,
        callable $callback,
        int $priority = 10
    ): void {
        self::register(
            self::$actions,
            $name,
            $callback,
            $priority,
            true
        );
    }

    public static function doAction(
        string $name,
        mixed ...$args
    ): void {
        self::validateName($name);

        self::enterHook(self::$actionDepth, $name);

        try {
            $entries = self::snapshot(self::$actions, $name);

            foreach ($entries as $entry) {
                if ($entry['once']) {
                    self::removeOne(
                        self::$actions,
                        $name,
                        $entry['id'],
                        self::findPriority(
                            self::$actions,
                            $name,
                            $entry['sequence']
                        ),
                        $entry['sequence']
                    );
                }

                ($entry['callback'])(...$args);
            }
        } finally {
            self::leaveHook(self::$actionDepth, $name);
        }
    }

    public static function removeAction(
        string $name,
        callable $callback,
        ?int $priority = null
    ): bool {
        return self::removeCallback(
            self::$actions,
            $name,
            self::callbackId($callback),
            $priority
        );
    }

    public static function hasAction(
        string $name,
        ?callable $callback = null
    ): int|bool {
        return self::hasCallback(
            self::$actions,
            $name,
            $callback
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    public static function addFilter(
        string $name,
        callable $callback,
        int $priority = 10
    ): void {
        self::register(
            self::$filters,
            $name,
            $callback,
            $priority,
            false
        );
    }

    public static function addFilterOnce(
        string $name,
        callable $callback,
        int $priority = 10
    ): void {
        self::register(
            self::$filters,
            $name,
            $callback,
            $priority,
            true
        );
    }

    public static function applyFilters(
        string $name,
        mixed $value,
        mixed ...$args
    ): mixed {
        self::validateName($name);

        self::enterHook(self::$filterDepth, $name);

        try {
            $entries = self::snapshot(self::$filters, $name);

            foreach ($entries as $entry) {
                if ($entry['once']) {
                    self::removeOne(
                        self::$filters,
                        $name,
                        $entry['id'],
                        self::findPriority(
                            self::$filters,
                            $name,
                            $entry['sequence']
                        ),
                        $entry['sequence']
                    );
                }

                $value = ($entry['callback'])($value, ...$args);
            }

            return $value;
        } finally {
            self::leaveHook(self::$filterDepth, $name);
        }
    }

    public static function removeFilter(
        string $name,
        callable $callback,
        ?int $priority = null
    ): bool {
        return self::removeCallback(
            self::$filters,
            $name,
            self::callbackId($callback),
            $priority
        );
    }

    public static function hasFilter(
        string $name,
        ?callable $callback = null
    ): int|bool {
        return self::hasCallback(
            self::$filters,
            $name,
            $callback
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Callback removal
    |--------------------------------------------------------------------------
    */

    private static function removeCallback(
        array &$registry,
        string $name,
        string $id,
        ?int $priority
    ): bool {
        if (!isset($registry[$name])) {
            return false;
        }

        $priorities = $priority === null
            ? array_keys($registry[$name])
            : [$priority];

        $removed = false;

        foreach ($priorities as $currentPriority) {
            if (!isset($registry[$name][$currentPriority])) {
                continue;
            }

            foreach ($registry[$name][$currentPriority] as $index => $entry) {
                if ($entry['id'] === $id) {
                    unset($registry[$name][$currentPriority][$index]);
                    $removed = true;
                    break;
                }
            }

            if (empty($registry[$name][$currentPriority])) {
                unset($registry[$name][$currentPriority]);
            }

            if ($removed && $priority !== null) {
                break;
            }
        }

        if (empty($registry[$name])) {
            unset($registry[$name]);
        }

        return $removed;
    }

    private static function removeOne(
        array &$registry,
        string $name,
        string $id,
        ?int $priority,
        int $sequence
    ): bool {
        if (
            $priority === null
            || !isset($registry[$name][$priority])
        ) {
            return false;
        }

        foreach ($registry[$name][$priority] as $index => $entry) {
            if (
                $entry['id'] === $id
                && $entry['sequence'] === $sequence
            ) {
                unset($registry[$name][$priority][$index]);

                if (empty($registry[$name][$priority])) {
                    unset($registry[$name][$priority]);
                }

                if (empty($registry[$name])) {
                    unset($registry[$name]);
                }

                return true;
            }
        }

        return false;
    }

    private static function findPriority(
        array $registry,
        string $name,
        int $sequence
    ): ?int {
        foreach ($registry[$name] ?? [] as $priority => $entries) {
            foreach ($entries as $entry) {
                if ($entry['sequence'] === $sequence) {
                    return (int) $priority;
                }
            }
        }

        return null;
    }

    private static function hasCallback(
        array $registry,
        string $name,
        ?callable $callback
    ): int|bool {
        if (empty($registry[$name])) {
            return false;
        }

        $wantedId = $callback !== null
            ? self::callbackId($callback)
            : null;

        foreach ($registry[$name] as $priority => $entries) {
            foreach ($entries as $entry) {
                if (
                    $wantedId === null
                    || $entry['id'] === $wantedId
                ) {
                    return (int) $priority;
                }
            }
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Dispatch snapshots and recursion protection
    |--------------------------------------------------------------------------
    */

    private static function snapshot(
        array $registry,
        string $name
    ): array {
        $entries = [];

        foreach ($registry[$name] ?? [] as $priorityEntries) {
            foreach ($priorityEntries as $entry) {
                $entries[] = $entry;
            }
        }

        usort(
            $entries,
            static function (array $a, array $b): int {
                return ($a['sequence'] <=> $b['sequence'])
                    ?: 0;
            }
        );

        /*
         * Sequence alone is not enough: priority must be primary.
         */
        usort(
            $entries,
            static function (array $a, array $b) use (
                $registry,
                $name
            ): int {
                $priorityA = self::sequencePriority(
                    $registry,
                    $name,
                    $a['sequence']
                );

                $priorityB = self::sequencePriority(
                    $registry,
                    $name,
                    $b['sequence']
                );

                return ($priorityA <=> $priorityB)
                    ?: ($a['sequence'] <=> $b['sequence']);
            }
        );

        return $entries;
    }

    private static function sequencePriority(
        array $registry,
        string $name,
        int $sequence
    ): int {
        foreach ($registry[$name] ?? [] as $priority => $entries) {
            foreach ($entries as $entry) {
                if ($entry['sequence'] === $sequence) {
                    return (int) $priority;
                }
            }
        }

        return PHP_INT_MAX;
    }

    private static function enterHook(
        array &$depths,
        string $name
    ): void {
        $depths[$name] = ($depths[$name] ?? 0) + 1;

        if ($depths[$name] > self::MAX_RECURSION_DEPTH) {
            $depths[$name]--;

            throw new LogicException(
                "Maximum hook recursion depth exceeded: {$name}"
            );
        }
    }

    private static function leaveHook(
        array &$depths,
        string $name
    ): void {
        if (!isset($depths[$name])) {
            return;
        }

        $depths[$name]--;

        if ($depths[$name] <= 0) {
            unset($depths[$name]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Diagnostics
    |--------------------------------------------------------------------------
    */

    public static function registeredHooks(): array
    {
        return [
            'actions' => self::describe(self::$actions),
            'filters' => self::describe(self::$filters),
        ];
    }

    private static function describe(array $registry): array
    {
        $result = [];

        foreach ($registry as $name => $priorities) {
            ksort($priorities);

            $result[$name] = [];

            foreach ($priorities as $priority => $entries) {
                $result[$name][(string) $priority] = [
                    'count' => count($entries),
                    'callbacks' => array_map(
                        static fn (array $entry): array => [
                            'id' => $entry['id'],
                            'once' => $entry['once'],
                            'sequence' => $entry['sequence'],
                        ],
                        array_values($entries)
                    ),
                ];
            }
        }

        return $result;
    }

    /**
     * Clear all hooks. Intended for tests and controlled resets.
     */
    public static function reset(): void
    {
        self::$actions = [];
        self::$filters = [];
        self::$actionDepth = [];
        self::$filterDepth = [];
        self::$sequence = 0;
    }
}

/*
|--------------------------------------------------------------------------
| Backward-compatible procedural API
|--------------------------------------------------------------------------
*/

if (!function_exists('add_action')) {
    function add_action(
        string $name,
        callable $callback,
        int $priority = 10
    ): void {
        Hooks::addAction($name, $callback, $priority);
    }
}

if (!function_exists('add_action_once')) {
    function add_action_once(
        string $name,
        callable $callback,
        int $priority = 10
    ): void {
        Hooks::addActionOnce($name, $callback, $priority);
    }
}

if (!function_exists('do_action')) {
    function do_action(string $name, mixed ...$args): void
    {
        Hooks::doAction($name, ...$args);
    }
}

if (!function_exists('remove_action')) {
    function remove_action(
        string $name,
        callable $callback,
        ?int $priority = null
    ): bool {
        return Hooks::removeAction($name, $callback, $priority);
    }
}

if (!function_exists('has_action')) {
    function has_action(
        string $name,
        ?callable $callback = null
    ): int|bool {
        return Hooks::hasAction($name, $callback);
    }
}

if (!function_exists('add_filter')) {
    function add_filter(
        string $name,
        callable $callback,
        int $priority = 10
    ): void {
        Hooks::addFilter($name, $callback, $priority);
    }
}

if (!function_exists('add_filter_once')) {
    function add_filter_once(
        string $name,
        callable $callback,
        int $priority = 10
    ): void {
        Hooks::addFilterOnce($name, $callback, $priority);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(
        string $name,
        mixed $value,
        mixed ...$args
    ): mixed {
        return Hooks::applyFilters($name, $value, ...$args);
    }
}

if (!function_exists('remove_filter')) {
    function remove_filter(
        string $name,
        callable $callback,
        ?int $priority = null
    ): bool {
        return Hooks::removeFilter($name, $callback, $priority);
    }
}

if (!function_exists('has_filter')) {
    function has_filter(
        string $name,
        ?callable $callback = null
    ): int|bool {
        return Hooks::hasFilter($name, $callback);
    }
}

if (!function_exists('cms_registered_hooks')) {
    function cms_registered_hooks(): array
    {
        return Hooks::registeredHooks();
    }
}