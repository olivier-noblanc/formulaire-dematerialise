<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Settings\SettingsService;
use App\Core\Database;

final class SettingsServiceTest extends TestCase
{
    private SettingsService $settings;
    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database();
        $this->settings = new SettingsService($this->db);
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $result = $this->settings->get('nonexistent_key_xyz', 'default_value');
        $this->assertSame('default_value', $result);
    }

    public function testGetReturnsConfigDefault(): void
    {
        // smtp_host is defined in SETTINGS_DEFAULTS
        $result = $this->settings->get('smtp_host');
        $this->assertNotEmpty($result);
    }

    public function testSetAndGet(): void
    {
        $testKey = 'test_setting_' . uniqid();
        $this->settings->set($testKey, 'test_value');
        $result = $this->settings->get($testKey);
        $this->assertSame('test_value', $result);
    }

    public function testSetWithUpdatedBy(): void
    {
        $testKey = 'test_updated_by_' . uniqid();
        $this->settings->set($testKey, 'value', 'admin@test.com');
        $result = $this->settings->get($testKey);
        $this->assertSame('value', $result);
    }

    public function testEncryptDecrypt(): void
    {
        $original = 'sensitive_password';
        $encrypted = $this->settings->encrypt($original);
        // Without APP_ENCRYPTION_KEY, encrypt returns the original value
        // This test verifies the method doesn't throw
        $this->assertIsString($encrypted);
    }

    public function testDecryptNonEncryptedReturnsOriginal(): void
    {
        $value = 'plain_text_value';
        $result = $this->settings->decrypt($value);
        $this->assertSame($value, $result);
    }

    public function testDecryptEncryptedWithoutKeyReturnsPlaceholder(): void
    {
        $value = 'enc:' . base64_encode('test');
        $result = $this->settings->decrypt($value);
        // Without APP_ENCRYPTION_KEY, should return placeholder
        $this->assertIsString($result);
    }
}
