<?php

declare(strict_types=1);

/**
 * Global cache helpers.
 *
 * File-based cache wrappers.
 * Loaded by lib_wrappers.php (main loader).
 */

function cache_dir(): string
{
    $cache_dir = dirname(__DIR__, 1) . '/db/cache';
    if (!is_dir($cache_dir)) {
        @mkdir($cache_dir, 0o750, true);
        $web_config = $cache_dir . '/web.config';
        if (!file_exists($web_config)) {
            @file_put_contents($web_config, '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<configuration><system.webServer><authorization>'
                . '<deny users="*"/>'
                . '</authorization></system.webServer></configuration>' . "\n");
        }
    }
    return $cache_dir;
}

function cache_get(string $key, int $ttl, callable $callback): mixed
{
    $cache_file = cache_dir() . '/cache_' . md5($key) . '.json';
    if (is_readable($cache_file)) {
        $payload = @json_decode((string) file_get_contents($cache_file), true);
        if (is_array($payload) && array_key_exists('value', $payload)
            && isset($payload['created_at'])
            && (time() - (int) $payload['created_at']) < $ttl) {
            return $payload['value'];
        }
    }
    $value = $callback();
    cache_set($key, $value, $ttl);
    return $value;
}

function cache_set(string $key, mixed $value, int $ttl = 300): void
{
    $cache_file = cache_dir() . '/cache_' . md5($key) . '.json';
    $payload = [
        'value'      => $value,
        'ttl'        => $ttl,
        'created_at' => time(),
    ];
    @file_put_contents($cache_file, json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function cache_clear(string $key): void
{
    $cache_file = cache_dir() . '/cache_' . md5($key) . '.json';
    if (file_exists($cache_file)) {
        @unlink($cache_file);
    }
}

function get_latest_version(): string
{
    static $version = null;
    if ($version !== null) {
        return $version;
    }
    $changelog_path = dirname(__DIR__, 1) . '/CHANGELOG.md';
    if (file_exists($changelog_path)) {
        $content = file_get_contents($changelog_path);
        if ($content !== false && preg_match('/^##\s*\[(\d+\.\d+\.\d+)\]/m', $content, $m) === 1) {
            $version = $m[1];
            return $version;
        }
    }
    $version = '0.0.0';
    return $version;
}
