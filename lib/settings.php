<?php
declare(strict_types=1);

/**
 * Application settings (key/value store, with encrypted values for secrets).
 *
 * Les settings sont stockés en DB (table settings). Les valeurs sensibles
 * (smtp_pass, ldap_bind_pass, webhook_secret, app_test_secret) sont chiffrées
 * au repos via AES-256-CBC (openssl) avec la clé APP_ENCRYPTION_KEY (env), et
 * préfixées de 'enc:' pour distinguer clair/chiffré (A-12/S-04). Un cache par
 * requête ($GLOBALS['_settings_cache']) évite les requêtes SQL répétées (A-11).
 *
 * @package lib
 */

// ── SETTINGS ─────────────────────────────────────────────────

/**
 * Liste des clés de settings dont la valeur doit être chiffrée au repos (A-12/S-04).
 * Ces valeurs sont chiffrées avec AES-256-CBC via la clé APP_ENCRYPTION_KEY (env).
 * Si APP_ENCRYPTION_KEY n'est pas définie, les valeurs restent en clair avec un warning.
 * @return list<string>
 */
function get_sensitive_setting_keys(): array {
    return ['smtp_pass', 'ldap_bind_pass', 'webhook_secret', 'app_test_secret'];
}

/**
 * Chiffre une valeur sensible avec AES-256-CBC (A-12/S-04).
 * La clé de chiffrement est lue depuis la variable d'environnement APP_ENCRYPTION_KEY.
 * Retourne la valeur préfixée de 'enc:' pour distinguer clair/chiffré.
 * Si la clé de chiffrement n'est pas disponible, retourne la valeur en clair.
 */
function encrypt_setting(string $value): string {
    if ($value === '') return '';
    // Ne pas rechiffrer une valeur déjà chiffrée
    if (str_starts_with($value, 'enc:')) return $value;

    $key = getenv('APP_ENCRYPTION_KEY');
    if (empty($key) || strlen($key) < 32) {
        error_log('[SECURITY] APP_ENCRYPTION_KEY non définie ou trop courte — valeur stockée en clair');
        return $value;
    }
    // Sécurité : openssl_cipher_iv_length() peut retourner false (cipher inconnu).
    // On l'évite pour ne pas crasher random_bytes(false) qui fatalerait.
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    if ($iv_length === false) {
        error_log('[SECURITY] openssl_cipher_iv_length a échoué — valeur stockée en clair');
        return $value;
    }
    $iv = random_bytes($iv_length);
    $encrypted = openssl_encrypt($value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) {
        error_log('[SECURITY] Échec de chiffrement — valeur stockée en clair');
        return $value;
    }
    return 'enc:' . base64_encode($iv . $encrypted);
}

/**
 * Déchiffre une valeur sensible (A-12/S-04).
 * Si la valeur n'est pas préfixée 'enc:', elle est retournée telle quelle (rétrocompatibilité).
 */
function decrypt_setting(string $value): string {
    if ($value === '' || !str_starts_with($value, 'enc:')) {
        return $value;
    }
    $key = getenv('APP_ENCRYPTION_KEY');
    if (empty($key)) {
        error_log('[SECURITY] APP_ENCRYPTION_KEY non définie — impossible de déchiffrer');
        return '[chiffré]';
    }
    $decoded = base64_decode(substr($value, 4), true);
    if ($decoded === false) return '[chiffré]';
    // Sécurité : openssl_cipher_iv_length() peut retourner false (cipher inconnu).
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    if ($iv_length === false) return '[chiffré]';
    $iv = substr($decoded, 0, $iv_length);
    $ciphertext = substr($decoded, $iv_length);
    $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($decrypted === false) {
        error_log('[SECURITY] Échec de déchiffrement — clé probablement incorrecte');
        return '[chiffré]';
    }
    return $decrypted;
}

function get_setting(string $key, string $default = ''): string {
    return \App\Core\App::settings()->get($key, $default);
}

function set_setting(string $key, string $value, string $updated_by = ''): void {
    \App\Core\App::settings()->set($key, $value, $updated_by);
}
