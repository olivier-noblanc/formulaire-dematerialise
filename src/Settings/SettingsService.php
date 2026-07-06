<?php
declare(strict_types=1);

namespace App\Settings;

use App\Contract\SettingsInterface;
use App\Core\Database;

/**
 * Service de gestion des settings (key/value store avec chiffrement).
 */
final class SettingsService implements SettingsInterface
{
    private Database $db;
    private static array $cache = [];

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function get(string $key, string $default = ''): string
    {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        try {
            $pdo = $this->db->getPdo();
            $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ?");
            $stmt->execute([$key]);
            $val = $stmt->fetchColumn();

            if ($val !== false) {
                if (in_array($key, $this->getSensitiveKeys(), true)) {
                    $result = $this->decrypt((string) $val);
                } else {
                    $result = (string) $val;
                }
                self::$cache[$key] = $result;
                return $result;
            }
        } catch (\Throwable $e) {
            // DB pas encore prête
        }

        $defaults = defined('SETTINGS_DEFAULTS') ? SETTINGS_DEFAULTS : [];
        $result = $defaults[$key] ?? $default;
        self::$cache[$key] = $result;
        return $result;
    }

    public function set(string $key, string $value, string $updatedBy = ''): void
    {
        if (in_array($key, $this->getSensitiveKeys(), true) && $value !== '') {
            $value = $this->encrypt($value);
        }

        $pdo = $this->db->getPdo();
        $pdo->prepare("INSERT OR REPLACE INTO settings (key, value, updated_at, updated_by) VALUES (?, ?, datetime('now'), ?)")
            ->execute([$key, $value, $updatedBy]);

        self::$cache[$key] = $value;
    }

    /** @return array<int, string> */
    private function getSensitiveKeys(): array
    {
        return ['smtp_pass', 'ldap_bind_pass', 'webhook_secret', 'app_test_secret'];
    }

    public function encrypt(string $value): string
    {
        $key = getenv('APP_ENCRYPTION_KEY') ?: '';
        if ($key === '') {
            error_log('[SECURITY] APP_ENCRYPTION_KEY non définie — valeur stockée en clair');
            return $value;
        }

        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        if ($ivLength === false) return $value;

        $iv = random_bytes($ivLength);
        $encrypted = openssl_encrypt($value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) return $value;

        return 'enc:' . base64_encode($iv . $encrypted);
    }

    public function decrypt(string $value): string
    {
        if (!str_starts_with($value, 'enc:')) return $value;

        $key = getenv('APP_ENCRYPTION_KEY') ?: '';
        if ($key === '') return '[chiffré]';

        $decoded = base64_decode(substr($value, 4), true);
        if ($decoded === false) return '[chiffré]';

        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        if ($ivLength === false) return '[chiffré]';

        $iv = substr($decoded, 0, $ivLength);
        $ciphertext = substr($decoded, $ivLength);
        $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return $decrypted === false ? '[chiffré]' : $decrypted;
    }
}
