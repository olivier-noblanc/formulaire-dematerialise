<?php

declare(strict_types=1);

namespace App\Contract;

interface CacheInterface
{
    public function get(string $key, int $ttl, callable $callback): mixed;
    public function set(string $key, mixed $value, int $ttl = 300): void;
    public function clear(string $key): void;
    public function getLatestVersion(): string;
    public function getCacheDir(): string;
}
