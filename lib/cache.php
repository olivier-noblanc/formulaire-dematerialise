<?php
declare(strict_types=1);

/**
 * File-based cache utilities (A-11).
 *
 * Cache simple par fichiers dans /cache/ — utilisé pour LDAP suggestions,
 * latest version check, etc. TTL en secondes.
 *
 * @package lib
 */

// ── CACHE (A-11) ─────────────────────────────────────────────

/**
 * Répertoire de cache fichier (A-11).
 * Créé à la demande. Protégé contre l'accès web via web.config (IIS).
 */
function cache_dir(): string {
    $cache_dir = __DIR__ . '/../db/cache';
    if (!is_dir($cache_dir)) {
        @mkdir($cache_dir, 0750, true);
        // Sécurité : protéger le répertoire de cache contre l'accès web
        // IIS : créer un web.config pour interdire l'accès (pas de .htaccess sur IIS)
        $web_config = $cache_dir . '/web.config';
        if (!file_exists($web_config)) {
            @file_put_contents($web_config, '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<configuration><system.webServer><authorization>'
                . '<deny users="*"/>'
                . '</authorization></system.webServer></configuration>' . "\n");
        }
    }
    return $cache_dir;
}

/**
 * Cache fichier générique (A-11).
 * Récupère une valeur depuis le cache, ou appelle le callback et la met en cache.
 *
 * @param string   $key      Clé de cache (hashée pour le nom de fichier)
 * @param int      $ttl      Durée de vie en secondes
 * @param callable $callback Fonction à appeler si le cache est vide ou expiré
 * @return mixed La valeur cachée ou la valeur retournée par le callback
 */
function cache_get(string $key, int $ttl, callable $callback): mixed {
    $cache_file = cache_dir() . '/cache_' . md5($key) . '.json';
    if (is_readable($cache_file)) {
        $payload = @json_decode((string)file_get_contents($cache_file), true);
        if (is_array($payload) && array_key_exists('value', $payload)
            && isset($payload['created_at'])
            && (time() - (int)$payload['created_at']) < $ttl) {
            return $payload['value'];
        }
    }
    $value = $callback();
    cache_set($key, $value, $ttl);
    return $value;
}

/**
 * Stocke une valeur dans le cache fichier (A-11).
 *
 * @param string $key   Clé de cache
 * @param mixed  $value Valeur à cacher (doit être sérialisable en JSON)
 * @param int    $ttl   Durée de vie en secondes (informationnelle — cf. cache_get)
 */
function cache_set(string $key, mixed $value, int $ttl = 300): void {
    $cache_file = cache_dir() . '/cache_' . md5($key) . '.json';
    $payload = [
        'value'      => $value,
        'ttl'        => $ttl,
        'created_at' => time(),
    ];
    @file_put_contents($cache_file, json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * Invalide une entrée de cache (A-11).
 *
 * @param string $key Clé de cache à supprimer
 */
function cache_clear(string $key): void {
    $cache_file = cache_dir() . '/cache_' . md5($key) . '.json';
    if (file_exists($cache_file)) {
        @unlink($cache_file);
    }
}

/**
 * Retourne la version la plus récente lue depuis CHANGELOG.md.
 * Remplace l'ancienne constante APP_VERSION.
 */
function get_latest_version(): string {
    static $version = null;
    if ($version !== null) {
        return $version;
    }
    $changelog_path = __DIR__ . '/../CHANGELOG.md';
    if (file_exists($changelog_path)) {
        $content = file_get_contents($changelog_path);
        if ($content !== false && preg_match('/^##\s*\[(\d+\.\d+\.\d+)\]/m', $content, $m)) {
            $version = $m[1];
            return $version;
        }
    }
    $version = '0.0.0';
    return $version;
}
