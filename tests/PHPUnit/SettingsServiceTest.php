<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Settings\SettingsService;
use App\Core\Database;
use App\Repository\SettingsRepository;

final class SettingsServiceTest extends TestCase
{
    private SettingsService $settings;
    private Database $db;

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(\App\Core\Database::class);
        $this->settings = new SettingsService(new SettingsRepository($this->db));

        // Reset static cache to avoid cross-test pollution
        $reflection = new \ReflectionClass(SettingsService::class);
        $cacheProp = $reflection->getProperty('cache');
        $cacheProp->setValue(null, []);

        // Clean up test keys from previous runs
        $pdo = $this->db->getPdo();
        $pdo->exec("DELETE FROM settings WHERE key LIKE 'test_setting_%' OR key LIKE 'test_updated_by_%' OR key LIKE 'test_overwrite_%' OR key = 'smtp_host'");
    }

    protected function tearDown(): void
    {
        // Clean up any test keys created during the test
        $pdo = $this->db->getPdo();
        $pdo->exec("DELETE FROM settings WHERE key LIKE 'test_%'");

        // Reset static cache
        $reflection = new \ReflectionClass(SettingsService::class);
        $cacheProp = $reflection->getProperty('cache');
        $cacheProp->setValue(null, []);
    }

    // ── get ────────────────────────────────────────────────────

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $result = $this->settings->get('nonexistent_key_xyz', 'default_value');
        self::assertSame('default_value', $result);
    }

    public function testGetReturnsEmptyStringAsDefault(): void
    {
        $result = $this->settings->get('nonexistent_key_xyz');
        self::assertSame('', $result);
    }

    public function testGetReturnsConfigDefault(): void
    {
        // smtp_host is defined in SETTINGS_DEFAULTS
        $result = $this->settings->get('smtp_host');
        self::assertNotEmpty($result);
    }

    public function testGetReturnsCachedValueOnSecondCall(): void
    {
        $key = 'test_cache_hit_' . uniqid();
        $this->settings->set($key, 'cached_value');

        // First call populates cache
        $first = $this->settings->get($key);
        self::assertSame('cached_value', $first);

        // Update DB value directly (bypassing service) to prove cache is used
        $pdo = $this->db->getPdo();
        $pdo->prepare("UPDATE settings SET value = 'db_changed_value' WHERE key = ?")->execute([$key]);

        // Second call should still return cached value
        $second = $this->settings->get($key);
        self::assertSame('cached_value', $second);
    }

    // ── set ────────────────────────────────────────────────────

    public function testSetAndGet(): void
    {
        $testKey = 'test_setting_' . uniqid();
        $this->settings->set($testKey, 'test_value');
        $result = $this->settings->get($testKey);
        self::assertSame('test_value', $result);
    }

    public function testSetWithUpdatedBy(): void
    {
        $testKey = 'test_updated_by_' . uniqid();
        $this->settings->set($testKey, 'value', 'admin@test.com');
        $result = $this->settings->get($testKey);
        self::assertSame('value', $result);

        // Verify updated_by is stored in DB
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT updated_by FROM settings WHERE key = ?");
        $stmt->execute([$testKey]);
        self::assertSame('admin@test.com', $stmt->fetchColumn());
    }

    public function testSetOverwritesExistingValue(): void
    {
        $testKey = 'test_overwrite_' . uniqid();
        $this->settings->set($testKey, 'first_value');
        $this->settings->set($testKey, 'second_value');
        $result = $this->settings->get($testKey);
        self::assertSame('second_value', $result);
    }

    public function testSetStoresTimestamp(): void
    {
        $testKey = 'test_setting_' . uniqid();
        $this->settings->set($testKey, 'with_timestamp');

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT updated_at FROM settings WHERE key = ?");
        $stmt->execute([$testKey]);
        $updatedAt = $stmt->fetchColumn();
        self::assertNotEmpty($updatedAt);
    }

}
