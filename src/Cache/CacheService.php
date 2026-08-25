<?php

declare(strict_types=1);

namespace App\Cache;

use App\Contract\CacheInterface;

/**
 * Service de cache fichier simple (TTL en secondes).
 *
 * Features:
 * - TTL-based expiration via filemtime()
 * - Thundering herd protection via LOCK_EX + lock file pattern
 * - Max cache size enforcement (default 50 MB)
 * - Corrupted file graceful handling
 */
final readonly class CacheService implements CacheInterface
{
    private string $cacheDir;

    /** @var int Default max cache size: 50 MB */
    private const DEFAULT_MAX_SIZE = 50 * 1024 * 1024;

    public function __construct(?string $cacheDir = null, private int $maxSizeBytes = self::DEFAULT_MAX_SIZE)
    {
        $this->cacheDir = $cacheDir ?? dirname(__DIR__, 2) . '/db/cache';
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0o775, true);
            @file_put_contents($this->cacheDir . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><staticContent><clear /></staticContent></system.webServer></configuration>');
        }
    }

    /**
     * Read-through cache with thundering herd protection.
     *
     * Uses a lock file per key to prevent multiple concurrent callbacks
     * from executing the same expensive computation.
     */
    public function get(string $key, int $ttl, callable $callback): mixed
    {
        $file = $this->cacheDir . '/' . md5($key) . '.json';
        $lockFile = $file . '.lock';

        // Fast path: cache hit
        $fileMtime = file_exists($file) ? @filemtime($file) : false;
        if ($fileMtime !== false && (time() - $fileMtime) < $ttl) {
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $data = json_decode($raw, true);
                if ($data !== null && isset($data['value'])) {
                    return $data['value'];
                }
            }
            // Corrupted file — treat as miss, continue to callback
        }

        // Thundering herd protection: acquire exclusive lock
        $lock = @fopen($lockFile, 'c');
        if ($lock !== false && flock($lock, LOCK_EX)) {
            // Double-check after acquiring lock (another process may have populated)
            $fileMtime2 = file_exists($file) ? @filemtime($file) : false;
            if ($fileMtime2 !== false && (time() - $fileMtime2) < $ttl) {
                $raw = @file_get_contents($file);
                if ($raw !== false) {
                    $data = json_decode($raw, true);
                    if ($data !== null && isset($data['value'])) {
                        flock($lock, LOCK_UN);
                        fclose($lock);
                        @unlink($lockFile);
                        return $data['value'];
                    }
                }
            }

            $value = $callback();
            $this->set($key, $value, $ttl);

            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockFile);
            return $value;
        }

        // Fallback: no lock available (shouldn't happen), just run callback
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function set(string $key, mixed $value, int $ttl = 300): void
    {
        $this->evictIfNeeded();

        $file = $this->cacheDir . '/' . md5($key) . '.json';
        $payload = json_encode(['value' => $value, 'ttl' => $ttl, 'created_at' => time()], JSON_UNESCAPED_UNICODE);
        file_put_contents($file, $payload, LOCK_EX);
    }

    public function clear(string $key): void
    {
        $file = $this->cacheDir . '/' . md5($key) . '.json';
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    public function getCacheDir(): string
    {
        return $this->cacheDir;
    }

    public function getLatestVersion(): string
    {
        static $version = null;
        if ($version !== null) {
            return $version;
        }

        $changelog = dirname(__DIR__, 2) . '/CHANGELOG.md';
        if (!file_exists($changelog)) {
            return '0.0.0';
        }

        $content = file_get_contents($changelog);
        if ($content === false) {
            return '0.0.0';
        }

        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $t = trim($line);
            if (str_starts_with($t, '## [')) {
                $open = strpos($t, '[');
                if ($open === false) {
                    continue;
                }
                ++$open;
                $close = strpos($t, ']', $open);
                if ($close === false) {
                    continue;
                }
                $v = trim(substr($t, $open, $close - $open));
                if (preg_match('/^\d+\.\d+\.\d+$/', $v) === 1) {
                    $version = $v;
                    return $v;
                }
            }
        }
        $version = '0.0.0';
        return $version;
    }

    /**
     * Evict oldest cache files when total size exceeds max.
     */
    private function evictIfNeeded(): void
    {
        $totalSize = 0;
        $files = [];
        $dir = $this->cacheDir;

        $jsonFiles = glob($dir . '/*.json');
        if ($jsonFiles === false) {
            return;
        }
        foreach ($jsonFiles as $jsonFile) {
            $size = @filesize($jsonFile);
            if ($size === false) {
                continue;
            }
            $mtime = @filemtime($jsonFile);
            if ($mtime === false) {
                continue;
            }
            $totalSize += $size;
            $files[] = ['path' => $jsonFile, 'size' => $size, 'mtime' => $mtime];
        }

        if ($totalSize <= $this->maxSizeBytes || $files === []) {
            return;
        }

        // Sort by mtime ascending (oldest first)
        usort($files, fn(array $a, array $b): int => $a['mtime'] <=> $b['mtime']);

        foreach ($files as $file) {
            if ($totalSize <= $this->maxSizeBytes * 0.8) {
                break; // Evict to 80% to avoid constant evictions
            }
            @unlink($file['path']);
            $totalSize -= $file['size'];
        }
    }
}
