<?php
declare(strict_types=1);

final class PluginManager
{
    public function __construct(private PDO $db, private string $pluginDir) {}

    public function discover(): array
    {
        $found = [];
        foreach (glob($this->pluginDir . '/*/plugin.json') ?: [] as $manifest) {
            $data = json_decode((string) file_get_contents($manifest), true);
            if (!is_array($data) || empty($data['slug'])) {
                continue;
            }
            $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $data['slug']));
            if ($slug === '') continue;
            $stmt = $this->db->prepare('SELECT active FROM plugins WHERE slug = ?');
            $stmt->execute([$slug]);
            $row = $stmt->fetch();
            $data['slug'] = $slug;
            $data['active'] = (bool) ($row['active'] ?? false);
            $data['_path'] = dirname($manifest);
            $found[$slug] = $data;
        }
        return $found;
    }

    public function loadActive(): void
    {
        $stmt = $this->db->query('SELECT slug FROM plugins WHERE active = 1');
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $slug) {
            $slug = preg_replace('/[^a-z0-9_-]/', '', (string) $slug);
            $file = $this->pluginDir . '/' . $slug . '/plugin.php';
            $manifest = $this->pluginDir . '/' . $slug . '/plugin.json';
            if (is_file($file) && is_file($manifest)) {
                require_once $file;
            }
        }
    }

    public function syncDiscovered(): void
    {
        foreach ($this->discover() as $slug => $plugin) {
            $stmt = $this->db->prepare(
                'INSERT INTO plugins (slug, name, version, description, active)
                 VALUES (?, ?, ?, ?, 0)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), version = VALUES(version), description = VALUES(description)'
            );
            $stmt->execute([
                $slug,
                substr((string)($plugin['name'] ?? $slug), 0, 190),
                substr((string)($plugin['version'] ?? '0.0.0'), 0, 30),
                substr((string)($plugin['description'] ?? ''), 0, 500),
            ]);
        }
    }

    public function setActive(string $slug, bool $active): void
    {
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
        $stmt = $this->db->prepare('UPDATE plugins SET active = ? WHERE slug = ?');
        $stmt->execute([$active ? 1 : 0, $slug]);
    }
}
