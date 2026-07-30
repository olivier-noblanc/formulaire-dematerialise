<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Cache\CacheService;

final class CacheServiceTest extends TestCase
{
    private CacheService $cache;
    private string $testCacheDir;

    protected function setUp(): void
    {
        $this->testCacheDir = sys_get_temp_dir() . '/phpunit_cache_test_' . uniqid();
        $this->cache = new CacheService($this->testCacheDir, 1024 * 1024); // 1MB limit
    }

    protected function tearDown(): void
    {
        // Clean up test cache directory
        if (is_dir($this->testCacheDir)) {
            $files = glob($this->testCacheDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->testCacheDir);
        }
    }

    public function testSetAndGet(): void
    {
        $this->cache->set('test_key', 'test_value', 60);
        $result = $this->cache->get('test_key', 60, fn() => 'default');
        self::assertSame('test_value', $result);
    }

    public function testGetReturnsCallbackOnMiss(): void
    {
        $result = $this->cache->get('nonexistent', 60, fn() => 'computed_value');
        self::assertSame('computed_value', $result);
    }

    public function testGetReturnsCachedValueOnHit(): void
    {
        $this->cache->set('key', 'cached', 60);
        $result = $this->cache->get('key', 60, fn() => 'new_value');
        self::assertSame('cached', $result);
    }

    public function testSetArrayValue(): void
    {
        $data = ['name' => 'John', 'age' => 30];
        $this->cache->set('array_key', $data, 60);
        $result = $this->cache->get('array_key', 60, fn() => []);
        self::assertSame($data, $result);
    }

    public function testSetNullValueTreatedAsMiss(): void
    {
        // CacheService treats null values as cache miss (isset check)
        $this->cache->set('null_key', null, 60);
        $result = $this->cache->get('null_key', 60, fn() => 'default');
        self::assertSame('default', $result);
    }

    public function testGetLatestVersion(): void
    {
        $version = $this->cache->getLatestVersion();
        self::assertIsString($version);
        // Should match semver pattern or be 0.0.0
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
    }

    public function testCacheDirCreatedAutomatically(): void
    {
        $newDir = sys_get_temp_dir() . '/phpunit_cache_new_' . uniqid();
        $cache = new CacheService($newDir);
        self::assertDirectoryExists($newDir);
        @rmdir($newDir);
    }
}
