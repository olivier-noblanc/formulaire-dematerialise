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

    public function __construct(private readonly SettingsRepository $settingsRepository) {}

    public function get(string $key, string $default = ''): string
    {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        try {
            $val = $this->settingsRepository->get($key);

            if ($val !== null) {
                $result = in_array($key, $this->getSensitiveKeys(), true) ? $this->decrypt($val) : $val;
                self::$cache[$key] = $result;
                return $result;
            }
        } catch (\Throwable) {
            // DB pas encore prête
        }

        $defaults = defined('SETTINGS_DEFAULTS') ? SETTINGS_DEFAULTS : [];
        $result = (string) ($defaults[$key] ?? $default);
        self::$cache[$key] = $result;
        return $result;
    }

    public function set(string $key, string $value, string $updatedBy = ''): void
    {
        if (in_array($key, $this->getSensitiveKeys(), true) && $value !== '') {
            $value = $this->encrypt($value);
        }

        $this->settingsRepository->set($key, $value, $updatedBy);

        self::$cache[$key] = $value;
    }

    /** @return list<string> */
    private function getSensitiveKeys(): array
    {
        return ['smtp_pass', 'ldap_bind_pass', 'app_test_secret'];
    }

    public function encrypt(string $value): string
    {
        $key = getenv('APP_ENCRYPTION_KEY') !== false ? getenv('APP_ENCRYPTION_KEY') : '';
        // Fail-fast : refuser de stocker un secret en clair si la clé est absente
        // ou trop courte. Avant, la valeur était retournée en clair silencieusement
        // (audit CTO C-01 2026-08-01). Maintenant on throw pour forcer la config.
        if ($key === '') {
            throw new \RuntimeException(
                'APP_ENCRYPTION_KEY non définie — refus de stocker une valeur sensible en clair. '
                . 'Définissez APP_ENCRYPTION_KEY (32+ octets) dans l\'environnement.'
            );
        }
        if (strlen((string) $key) < 32) {
            throw new \RuntimeException(
                'APP_ENCRYPTION_KEY trop courte (' . strlen((string) $key) . ' octets, minimum 32 requis) — '
                . 'refus de stocker une valeur sensible en clair.'
            );
        }

        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        if ($ivLength === false || $ivLength < 1) {
            throw new \RuntimeException('openssl_cipher_iv_length a échoué — extension OpenSSL défaillante');
        }

        $iv = random_bytes($ivLength);
        $encrypted = openssl_encrypt($value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            throw new \RuntimeException('openssl_encrypt a échoué — vérifiez la clé et l\'IV');
        }

        return 'enc:' . base64_encode($iv . $encrypted);
    }

    public function decrypt(string $value): string
    {
        if (!str_starts_with($value, 'enc:')) {
            return $value;
        }

        $key = getenv('APP_ENCRYPTION_KEY') !== false ? getenv('APP_ENCRYPTION_KEY') : '';
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
