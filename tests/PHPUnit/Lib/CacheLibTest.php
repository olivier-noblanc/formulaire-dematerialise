<?php
declare(strict_types=1);

namespace App\Tests\Lib;

use PHPUnit\Framework\TestCase;

final class CacheLibTest extends TestCase
{
    public function testCacheSetAndGetRoundtrip(): void
    {
        $key = 'lib_cache_test_' . uniqid();
        cache_set($key, 'cached_value', 300);
        $hit = cache_get($key, 300, fn () => 'miss');
        self::assertSame('cached_value', $hit);
    }

    public function testCacheGetReturnsCallbackOnMiss(): void
    {
        $key = 'lib_cache_miss_' . uniqid();
        $result = cache_get($key, 300, fn () => 'computed');
        self::assertSame('computed', $result);
    }

    public function testCacheClearRemovesKey(): void
    {
        $key = 'lib_cache_clear_' . uniqid();
        cache_set($key, 'to_be_cleared', 300);
        cache_clear($key);
        $result = cache_get($key, 300, fn () => 'after_clear');
        self::assertSame('after_clear', $result);
    }

    public function testCacheClearNonexistentKeyDoesNotThrow(): void
    {
        cache_clear('nonexistent_key_' . uniqid());
        self::assertTrue(true);
    }

    public function testCacheSetArrayValue(): void
    {
        $key = 'lib_cache_array_' . uniqid();
        $data = ['a' => 1, 'b' => 'two'];
        cache_set($key, $data, 300);
        $result = cache_get($key, 300, fn () => []);
        self::assertSame($data, $result);
    }

    public function testCacheDirIsCreatedAutomatically(): void
    {
        $dir = cache_dir();
        self::assertDirectoryExists($dir);
    }

    public function testGetLatestVersionReturnsSemver(): void
    {
        $version = get_latest_version();
        self::assertIsString($version);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
    }

    public function testCacheGetReturnsCachedValueNotCallback(): void
    {
        $key = 'lib_cache_hit_' . uniqid();
        cache_set($key, 'original', 300);
        $result = cache_get($key, 300, fn () => 'new_value');
        self::assertSame('original', $result);
    }
}
