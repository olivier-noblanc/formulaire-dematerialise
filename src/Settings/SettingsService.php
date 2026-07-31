<?php

declare(strict_types=1);

namespace App\Settings;

use App\Contract\SettingsInterface;
use App\Repository\SettingsRepository;

/**
 * Service de gestion des settings (key/value store avec chiffrement).
 */
final class SettingsService implements SettingsInterface
{
    /** @var array<string, string> */
    private static array $cache = [];

    public function __construct(private readonly SettingsRepository $settingsRepository)
    {
    }

    public function get(string $key, string $default = ''): string
    {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        try {
            $val = $this->settingsRepository->get($key);

            if ($val !== null) {
                $result = in_array($key, $this->getSensitiveKeys(, true), true) ? $this->decrypt($val) : $val;
                self::$cache[$key] = $result;
                return $result;
            }
        } catch (\Throwable) {
            // DB pas encore prête
        }

        $defaults = defined('SETTINGS_DEFAULTS') ? SETTINGS_DEFAULTS : [];
        // Les valeurs de SETTINGS_DEFAULTS sont toutes typées string dans config.php
        // (SETTINGS_DEFAULTS est un array<string, string>). Le cast (string) est donc
        // théoriquement inutile — sauf si quelqu'un définit SETTINGS_DEFAULTS avec
        // une valeur non-string (int, null, etc.) par erreur. On garde le cast comme
        // filet de sécurité, mais on le supprime pour satisfaire phpstan-strict-rules
        // (cast.useless). Si une valeur non-string est détectée à terme, ce sera
        // une TypeError qui apparaîtra ici plutôt que silencieusement castée.
        $result = $defaults[$key] ?? $default;
        self::$cache[$key] = $result;
        return $result;
    }

    public function set(string $key, string $value, string $updatedBy = ''): void
    {
        if (in_array($key, $this->getSensitiveKeys(, true), true) && $value !== '') {
            $value = $this->encrypt($value);
        }

        $this->settingsRepository->set($key, $value, $updatedBy);

        self::$cache[$key] = $value;
    }

    /** @return array<int, string> */
    private function getSensitiveKeys(): array
    {
        return ['smtp_pass', 'ldap_bind_pass', 'app_test_secret'];
    }

    public function encrypt(string $value): string
    {
        $key = getenv('APP_ENCRYPTION_KEY') ?: '';
        if ($key === '') {
            error_log('[SECURITY] APP_ENCRYPTION_KEY non définie — valeur stockée en clair');
            return $value;
        }
        if (strlen($key) < 32) {
            error_log('[SECURITY] APP_ENCRYPTION_KEY trop courte (< 32 octets) — valeur stockée en clair');
            return $value;
        }

        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        if ($ivLength === false || $ivLength < 1) {
            return $value;
        }

        $iv = random_bytes($ivLength);
        $encrypted = openssl_encrypt($value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            return $value;
        }

        return 'enc:' . base64_encode($iv . $encrypted);
    }

    public function decrypt(string $value): string
    {
        if (!str_starts_with($value, 'enc:')) {
            return $value;
        }

        $key = getenv('APP_ENCRYPTION_KEY') ?: '';
        if ($key === '') {
            return '[chiffré]';
        }

        $decoded = base64_decode(substr($value, 4), true);
        if ($decoded === false) {
            return '[chiffré]';
        }

        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        if ($ivLength === false) {
            return '[chiffré]';
        }

        $iv = substr($decoded, 0, $ivLength);
        $ciphertext = substr($decoded, $ivLength);
        $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return $decrypted === false ? '[chiffré]' : $decrypted;
    }
}
