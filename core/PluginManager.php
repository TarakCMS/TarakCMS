<?php

declare(strict_types=1);

/**
 * TarаkCMS Plugin Manager
 *
 * Features:
 * - Plugin discovery and manifest validation
 * - Safe plugin path resolution
 * - Database synchronization
 * - Activation and deactivation
 * - Dependency validation and loading order
 * - Circular dependency detection
 * - PHP version requirements
 * - Plugin diagnostics
 * - Error logging
 *
 * PHP 8.2+
 */

final class PluginManager
{
    private PDO $db;
    private string $pluginDir;

    /** @var array<string, array> */
    private array $discovered = [];

    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<string, bool> */
    private array $loaded = [];

    /** @var array<string, bool> */
    private array $loading = [];

    public function __construct(PDO $db, string $pluginDir)
    {
        $this->db = $db;

        $directory = realpath($pluginDir);

        if ($directory === false || !is_dir($directory)) {
            throw new RuntimeException(
                'Plugin directory does not exist: ' . $pluginDir
            );
        }

        $this->pluginDir = rtrim($directory, DIRECTORY_SEPARATOR);
    }

    /*
    |--------------------------------------------------------------------------
    | Plugin discovery
    |--------------------------------------------------------------------------
    */

    public function discover(): array
    {
        $this->errors = [];
        $this->discovered = [];

        $stmt = $this->db->query(
            'SELECT slug, active FROM plugins'
        );

        $databasePlugins = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $slug = (string) ($row['slug'] ?? '');

            if ($this->isValidSlug($slug)) {
                $databasePlugins[$slug] = (bool) $row['active'];
            }
        }

        $manifests = glob(
            $this->pluginDir . DIRECTORY_SEPARATOR . '*' .
            DIRECTORY_SEPARATOR . 'plugin.json'
        ) ?: [];

        sort($manifests, SORT_STRING);

        foreach ($manifests as $manifestPath) {
            try {
                $plugin = $this->readManifest($manifestPath);

                $slug = $plugin['slug'];

                $plugin['active'] = $databasePlugins[$slug] ?? false;
                $plugin['_path'] = dirname($manifestPath);

                $this->discovered[$slug] = $plugin;
            } catch (Throwable $exception) {
                $name = basename(dirname($manifestPath));

                $this->recordError(
                    'discover:' . $name,
                    $exception->getMessage()
                );
            }
        }

        return $this->discovered;
    }

    /*
    |--------------------------------------------------------------------------
    | Manifest validation
    |--------------------------------------------------------------------------
    */

    private function readManifest(string $manifestPath): array
    {
        $realManifest = realpath($manifestPath);

        if (
            $realManifest === false
            || !$this->isInsidePluginDirectory($realManifest)
            || basename($realManifest) !== 'plugin.json'
        ) {
            throw new RuntimeException('Invalid manifest path.');
        }

        $directory = realpath(dirname($realManifest));

        if (
            $directory === false
            || dirname($directory) !== $this->pluginDir
        ) {
            throw new RuntimeException(
                'Plugins must be stored in direct subdirectories of the plugin directory.'
            );
        }

        $directorySlug = basename($directory);

        if (!$this->isValidSlug($directorySlug)) {
            throw new RuntimeException(
                'Invalid plugin directory name: ' . $directorySlug
            );
        }

        $contents = file_get_contents($realManifest);

        if ($contents === false || trim($contents) === '') {
            throw new RuntimeException('Plugin manifest is empty or unreadable.');
        }

        try {
            $data = json_decode(
                $contents,
                true,
                32,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Invalid plugin.json: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        if (!is_array($data)) {
            throw new RuntimeException(
                'Plugin manifest must contain a JSON object.'
            );
        }

        $slug = strtolower(trim((string) ($data['slug'] ?? '')));

        if (!$this->isValidSlug($slug)) {
            throw new RuntimeException('Invalid plugin slug.');
        }

        if ($slug !== $directorySlug) {
            throw new RuntimeException(
                'Plugin slug must match its directory name.'
            );
        }

        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '' || strlen($name) > 190) {
            throw new RuntimeException(
                'Plugin name must contain 1 to 190 bytes.'
            );
        }

        $version = trim((string) ($data['version'] ?? '0.0.0'));

        if (
            $version === ''
            || strlen($version) > 30
            || !preg_match(
                '/^[0-9A-Za-z][0-9A-Za-z.+_-]*$/',
                $version
            )
        ) {
            throw new RuntimeException('Invalid plugin version.');
        }

        $description = (string) ($data['description'] ?? '');

        if (strlen($description) > 500) {
            throw new RuntimeException(
                'Plugin description cannot exceed 500 bytes.'
            );
        }

        $minPhp = (string) ($data['requires_php'] ?? '8.2');

        if (
            !preg_match(
                '/^[0-9]+(?:\.[0-9]+){1,2}$/',
                $minPhp
            )
        ) {
            throw new RuntimeException(
                'Invalid requires_php value.'
            );
        }

        if (version_compare(PHP_VERSION, $minPhp, '<')) {
            throw new RuntimeException(
                'This plugin requires PHP ' . $minPhp .
                ' or newer. Current version: ' . PHP_VERSION
            );
        }

        $dependencies = $data['requires_plugins'] ?? [];

        if (!is_array($dependencies)) {
            throw new RuntimeException(
                'requires_plugins must be an array of plugin slugs.'
            );
        }

        $normalizedDependencies = [];

        foreach ($dependencies as $dependency) {
            if (!is_string($dependency)) {
                throw new RuntimeException(
                    'Plugin dependency names must be strings.'
                );
            }

            $dependency = strtolower(trim($dependency));

            if (!$this->isValidSlug($dependency)) {
                throw new RuntimeException(
                    'Invalid plugin dependency slug.'
                );
            }

            if ($dependency === $slug) {
                throw new RuntimeException(
                    'A plugin cannot depend on itself.'
                );
            }

            if (!in_array($dependency, $normalizedDependencies, true)) {
                $normalizedDependencies[] = $dependency;
            }
        }

        $pluginFile = $directory . DIRECTORY_SEPARATOR . 'plugin.php';

        if (!is_file($pluginFile) || !is_readable($pluginFile)) {
            throw new RuntimeException(
                'Plugin entry file plugin.php is missing or unreadable.'
            );
        }

        $realPluginFile = realpath($pluginFile);

        if (
            $realPluginFile === false
            || dirname($realPluginFile) !== $directory
        ) {
            throw new RuntimeException(
                'Invalid plugin entry file path.'
            );
        }

        $data['slug'] = $slug;
        $data['name'] = $name;
        $data['version'] = $version;
        $data['description'] = $description;
        $data['requires_php'] = $minPhp;
        $data['requires_plugins'] = $normalizedDependencies;

        return $data;
    }

    private function isValidSlug(string $slug): bool
    {
        return (bool) preg_match(
            '/^[a-z0-9][a-z0-9_-]{0,99}$/',
            $slug
        );
    }

    private function isInsidePluginDirectory(string $path): bool
    {
        $prefix = $this->pluginDir . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $prefix);
    }

    /*
    |--------------------------------------------------------------------------
    | Synchronize plugin manifests with the database
    |--------------------------------------------------------------------------
    */

    public function syncDiscovered(): void
    {
        $plugins = $this->discover();

        $sql = '
            INSERT INTO plugins
                (slug, name, version, description, active)
            VALUES
                (:slug, :name, :version, :description, 0)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                version = VALUES(version),
                description = VALUES(description)
        ';

        $stmt = $this->db->prepare($sql);

        $manageTransaction = !$this->db->inTransaction();

        if ($manageTransaction) {
            $this->db->beginTransaction();
        }

        try {
            foreach ($plugins as $slug => $plugin) {
                $stmt->execute([
                    ':slug' => $slug,
                    ':name' => substr($plugin['name'], 0, 190),
                    ':version' => substr($plugin['version'], 0, 30),
                    ':description' => substr(
                        $plugin['description'],
                        0,
                        500
                    ),
                ]);
            }

            if ($manageTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($manageTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Activate / deactivate
    |--------------------------------------------------------------------------
    */

    public function setActive(string $slug, bool $active): void
    {
        $slug = strtolower(trim($slug));

        if (!$this->isValidSlug($slug)) {
            throw new InvalidArgumentException(
                'Invalid plugin slug.'
            );
        }

        $plugins = $this->discover();

        if (!isset($plugins[$slug])) {
            throw new RuntimeException(
                'Plugin was not found or its manifest is invalid: ' . $slug
            );
        }

        if ($active) {
            $this->assertDependenciesActive($slug, $plugins);
        } else {
            $this->assertNoActiveDependents($slug, $plugins);
        }

        $check = $this->db->prepare(
            'SELECT slug FROM plugins WHERE slug = ? LIMIT 1'
        );
        $check->execute([$slug]);

        if (!$check->fetchColumn()) {
            throw new RuntimeException(
                'Plugin is not registered in the database. Run syncDiscovered() first.'
            );
        }

        $stmt = $this->db->prepare(
            'UPDATE plugins SET active = ? WHERE slug = ?'
        );

        $stmt->execute([$active ? 1 : 0, $slug]);

        // Reset in-memory discovery state so subsequent calls refresh it.
        $this->discovered = [];
    }

    private function assertDependenciesActive(
        string $slug,
        array $plugins
    ): void {
        foreach ($plugins[$slug]['requires_plugins'] as $dependency) {
            if (!isset($plugins[$dependency])) {
                throw new RuntimeException(
                    "Required plugin '{$dependency}' is missing or invalid."
                );
            }

            if (!$plugins[$dependency]['active']) {
                throw new RuntimeException(
                    "Required plugin '{$dependency}' must be activated first."
                );
            }
        }
    }

    private function assertNoActiveDependents(
        string $slug,
        array $plugins
    ): void {
        foreach ($plugins as $otherSlug => $plugin) {
            if (
                $otherSlug !== $slug
                && $plugin['active']
                && in_array(
                    $slug,
                    $plugin['requires_plugins'],
                    true
                )
            ) {
                throw new RuntimeException(
                    "Cannot deactivate '{$slug}': active plugin '{$otherSlug}' depends on it."
                );
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Load active plugins
    |--------------------------------------------------------------------------
    */

    public function loadActive(): void
    {
        $plugins = $this->discover();

        $stmt = $this->db->query(
            'SELECT slug FROM plugins WHERE active = 1 ORDER BY slug'
        );

        $activeSlugs = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($activeSlugs as $activeSlug) {
            $slug = (string) $activeSlug;

            if (!isset($plugins[$slug])) {
                $this->recordError(
                    'load:' . $slug,
                    'Active plugin is missing or has an invalid manifest.'
                );
                continue;
            }

            try {
                $this->loadPlugin($slug, $plugins);
            } catch (Throwable $exception) {
                $this->recordError(
                    'load:' . $slug,
                    $exception->getMessage()
                );
            }
        }
    }

    private function loadPlugin(string $slug, array $plugins): void
    {
        if (isset($this->loaded[$slug])) {
            return;
        }

        if (isset($this->loading[$slug])) {
            throw new RuntimeException(
                'Circular plugin dependency detected at: ' . $slug
            );
        }

        if (!isset($plugins[$slug])) {
            throw new RuntimeException(
                'Required plugin not found: ' . $slug
            );
        }

        if (!$plugins[$slug]['active']) {
            throw new RuntimeException(
                'Required plugin is not active: ' . $slug
            );
        }

        $this->loading[$slug] = true;

        try {
            foreach ($plugins[$slug]['requires_plugins'] as $dependency) {
                if (!isset($plugins[$dependency])) {
                    throw new RuntimeException(
                        "Missing dependency '{$dependency}' for '{$slug}'."
                    );
                }

                $this->loadPlugin($dependency, $plugins);
            }

            $directory = $plugins[$slug]['_path'];
            $entry = realpath(
                $directory . DIRECTORY_SEPARATOR . 'plugin.php'
            );

            if (
                $entry === false
                || dirname($entry) !== $directory
                || !is_readable($entry)
            ) {
                throw new RuntimeException(
                    'Plugin entry file is invalid or unreadable.'
                );
            }

            require_once $entry;

            $this->loaded[$slug] = true;
        } finally {
            unset($this->loading[$slug]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Status and diagnostics
    |--------------------------------------------------------------------------
    */

    public function status(): array
    {
        $plugins = $this->discover();

        $result = [];

        foreach ($plugins as $slug => $plugin) {
            $result[$slug] = [
                'name' => $plugin['name'],
                'version' => $plugin['version'],
                'active' => $plugin['active'],
                'loaded' => isset($this->loaded[$slug]),
                'dependencies' => $plugin['requires_plugins'],
                'error' => $this->errors['load:' . $slug]
                    ?? $this->errors['discover:' . $slug]
                    ?? null,
            ];
        }

        return $result;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function isLoaded(string $slug): bool
    {
        return isset($this->loaded[$slug]);
    }

    private function recordError(string $key, string $message): void
    {
        $this->errors[$key] = $message;

        error_log(
            '[TarаkCMS][PluginManager][' . $key . '] ' . $message
        );
    }
}