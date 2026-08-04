<?php

declare(strict_types=1);

/**
 * Global settings helpers.
 *
 * Delegates to App\Settings\SettingsService.
 * Loaded by lib_wrappers.php (main loader).
 */

/**
 * @return list<string>
 */
function get_sensitive_setting_keys(): array
{
    return ['smtp_pass', 'ldap_bind_pass', 'app_test_secret'];
}

function encrypt_setting(string $value): string
{
    if ($value === '') {
        return '';
    }
    if (str_starts_with($value, 'enc:')) {
        return $value;
    }

    $key = getenv('APP_ENCRYPTION_KEY');
    if ($key === '' || $key === null || $key === '0' || strlen($key) < 32) {
        throw new \RuntimeException('APP_ENCRYPTION_KEY manquante ou trop courte (< 32 chars) — impossible de chiffrer les secrets');
    }
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    if ($iv_length === false) {
        throw new \RuntimeException('openssl_cipher_iv_length a échoué — chiffrement indisponible');
    }
    $iv = random_bytes(max(1, $iv_length));
    $encrypted = openssl_encrypt($value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) {
        throw new \RuntimeException('Échec de chiffrement OpenSSL');
    }
    return 'enc:' . base64_encode($iv . $encrypted);
}

function decrypt_setting(string $value): string
{
    if ($value === '' || !str_starts_with($value, 'enc:')) {
        return $value;
    }
    $key = getenv('APP_ENCRYPTION_KEY');
    if ($key === '' || $key === null || $key === '0') {
        error_log('[SECURITY] APP_ENCRYPTION_KEY non définie — impossible de déchiffrer');
        return '[chiffré]';
    }
    $decoded = base64_decode(substr($value, 4), true);
    if ($decoded === false) {
        return '[chiffré]';
    }
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    if ($iv_length === false) {
        return '[chiffré]';
    }
    $iv = substr($decoded, 0, $iv_length);
    $ciphertext = substr($decoded, $iv_length);
    $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($decrypted === false) {
        error_log('[SECURITY] Échec de déchiffrement — clé probablement incorrecte');
        return '[chiffré]';
    }
    return $decrypted;
}

function get_setting(string $key, string $default = ''): string
{
    return \App\Core\App::settings()->get($key, $default);
}

function set_setting(string $key, string $value, string $updated_by = ''): void
{
    \App\Core\App::settings()->set($key, $value, $updated_by);
}
