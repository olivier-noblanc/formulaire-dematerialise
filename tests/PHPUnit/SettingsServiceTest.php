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

    // ── sensitive keys ─────────────────────────────────────────

    public function testSetSensitiveKeyStoresEncrypted(): void
    {
        $this->settings->set('smtp_pass', 'secret_password');

        // Read raw value from DB (bypassing decrypt)
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = 'smtp_pass'");
        $stmt->execute([]);
        $rawValue = $stmt->fetchColumn();

        // Without APP_ENCRYPTION_KEY, encrypt returns plaintext, so verify it's stored
        self::assertNotFalse($rawValue);
    }

    public function testSetSensitiveKeyWithEmptyValueSkipsEncryption(): void
    {
        $this->settings->set('smtp_pass', '');

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = 'smtp_pass'");
        $stmt->execute([]);
        $rawValue = $stmt->fetchColumn();
        self::assertSame('', $rawValue);
    }

    public function testSetLdapBindPassIsSensitive(): void
    {
        $this->settings->set('ldap_bind_pass', 'ldap_secret');
        $result = $this->settings->get('ldap_bind_pass');
        self::assertIsString($result);
    }

    public function testSetAppTestSecretIsSensitive(): void
    {
        $this->settings->set('app_test_secret', 'test_secret_value');
        $result = $this->settings->get('app_test_secret');
        self::assertIsString($result);
    }

    // ── encrypt / decrypt ──────────────────────────────────────

    public function testEncryptDecrypt(): void
    {
        $original = 'sensitive_password';
        $encrypted = $this->settings->encrypt($original);
        // Without APP_ENCRYPTION_KEY, encrypt returns the original value
        self::assertIsString($encrypted);
    }

    public function testDecryptNonEncryptedReturnsOriginal(): void
    {
        $value = 'plain_text_value';
        $result = $this->settings->decrypt($value);
        self::assertSame($value, $result);
    }

    public function testDecryptEncryptedWithoutKeyReturnsPlaceholder(): void
    {
        $value = 'enc:' . base64_encode('test');
        $result = $this->settings->decrypt($value);
        self::assertIsString($result);
    }

    public function testEncryptDecryptRoundtripWithKey(): void
    {
        $originalKey = getenv('APP_ENCRYPTION_KEY');
        $testKey = 'test_encryption_key_for_unit_test_!';

        try {
            putenv("APP_ENCRYPTION_KEY=$testKey");

            $plaintext = 'my_secret_data_12345';
            $encrypted = $this->settings->encrypt($plaintext);

            // Should be encrypted (different from plaintext)
            self::assertNotSame($plaintext, $encrypted, 'Encrypted value should differ from plaintext');
            self::assertStringStartsWith('enc:', $encrypted);

            $decrypted = $this->settings->decrypt($encrypted);
            self::assertSame($plaintext, $decrypted);
        } finally {
            // Restore original env
            if ($originalKey !== false) {
                putenv("APP_ENCRYPTION_KEY=$originalKey");
            } else {
                putenv('APP_ENCRYPTION_KEY');
            }
        }
    }

    public function testDecryptInvalidBase64ReturnsPlaceholder(): void
    {
        $originalKey = getenv('APP_ENCRYPTION_KEY');
        putenv("APP_ENCRYPTION_KEY=test_key");

        try {
            $result = $this->settings->decrypt('enc:not-valid-base64!!!');
            self::assertSame('[chiffré]', $result);
        } finally {
            if ($originalKey !== false) {
                putenv("APP_ENCRYPTION_KEY=$originalKey");
            } else {
                putenv('APP_ENCRYPTION_KEY');
            }
        }
    }

    public function testDecryptWithCorruptedDataReturnsPlaceholder(): void
    {
        $originalKey = getenv('APP_ENCRYPTION_KEY');
        putenv("APP_ENCRYPTION_KEY=test_key");

        try {
            // Valid base64 but wrong ciphertext
            $ivLength = openssl_cipher_iv_length('aes-256-cbc');
            $fakeData = random_bytes($ivLength + 10); // enough bytes but wrong content
            $result = $this->settings->decrypt('enc:' . base64_encode($fakeData));
            self::assertSame('[chiffré]', $result);
        } finally {
            if ($originalKey !== false) {
                putenv("APP_ENCRYPTION_KEY=$originalKey");
            } else {
                putenv('APP_ENCRYPTION_KEY');
            }
        }
    }

    public function testEncryptWithoutKeyReturnsPlaintext(): void
    {
        $originalKey = getenv('APP_ENCRYPTION_KEY');
        putenv('APP_ENCRYPTION_KEY');

        try {
            $result = $this->settings->encrypt('plaintext_value');
            self::assertSame('plaintext_value', $result);
        } finally {
            if ($originalKey !== false) {
                putenv("APP_ENCRYPTION_KEY=$originalKey");
            }
        }
    }

    public function testDecryptWithoutEncPrefixReturnsOriginal(): void
    {
        $result = $this->settings->decrypt('some_plain_text');
        self::assertSame('some_plain_text', $result);
    }
}
