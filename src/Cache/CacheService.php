<?php
declare(strict_types=1);

namespace App\Cache;

/**
 * Service de cache fichier simple (TTL en secondes).
 */
final class CacheService
{
    private string $cacheDir;

    public function __construct()
    {
        $this->cacheDir = dirname(__DIR__, 2) . '/db/cache';
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
            // Sécurité IIS : deny access
            @file_put_contents($this->cacheDir . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><staticContent><clear /></staticContent></system.webServer></configuration>');
        }
    }

    public function get(string $key, int $ttl, callable $callback): mixed
    {
        $file = $this->cacheDir . '/' . md5($key) . '.json';
        if (file_exists($file) && (time() - filemtime($file)) < $ttl) {
            $data = json_decode((string) file_get_contents($file), true);
            if ($data !== null) return $data['value'] ?? null;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function set(string $key, mixed $value, int $ttl = 300): void
    {
        $file = $this->cacheDir . '/' . md5($key) . '.json';
        $payload = json_encode(['value' => $value, 'ttl' => $ttl, 'created_at' => time()], JSON_UNESCAPED_UNICODE);
        file_put_contents($file, $payload, LOCK_EX);
    }

    public function clear(string $key): void
    {
        $file = $this->cacheDir . '/' . md5($key) . '.json';
        if (file_exists($file)) @unlink($file);
    }

    public function getLatestVersion(): string
    {
        static $version = null;
        if ($version !== null) return $version;

        $changelog = dirname(__DIR__, 2) . '/CHANGELOG.md';
        if (!file_exists($changelog)) return '0.0.0';

        $content = file_get_contents($changelog);
        if ($content === false) return '0.0.0';

        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $t = trim($line);
            if (str_starts_with($t, '## [')) {
                $open = strpos($t, '[') + 1;
                $close = strpos($t, ']');
                if ($open > 0 && $close > $open) {
                    $v = trim(substr($t, $open, $close - $open));
                    if (preg_match('/^\d+\.\d+\.\d+$/', $v)) {
                        $version = $v;
                        return $v;
                    }
                }
            }
        }
        $version = '0.0.0';
        return $version;
    }
}
