<?php
/**
 * tests/test_unit_wave4_security.php — Section 12.10-12.14 : Wave 4 — encrypt_setting, parse_date, security_log, security_headers, rate limiting
 *
 * Module thématique extrait de test_unit.php (refactor P-TESTS).
 * Dépendances : test_bootstrap.php (test), tests/test_unit_helpers.php (helpers shared).
 */

declare(strict_types=1);

/**
 * Section 12.10-12.14 : Wave 4 — encrypt_setting, parse_date, security_log, security_headers, rate limiting
 */
function run_tests_unit_wave4_security(): void {
// 12.10 — encrypt_setting() / decrypt_setting() round-trip
// Note : selon la disponibilité de l'extension openssl, encrypt_setting()
// chiffre (AES-256-CBC) ou fallback en clair avec warning error_log.
// Les tests s'adaptent aux deux comportements.
// ───────────────────────────────────────────────────────────────
test('encrypt_setting() chaîne vide retourne chaîne vide', function() {
    $result = encrypt_setting('');
    return $result === '' ? true : "Got: $result";
});

test('encrypt_setting() idempotente : ne rechiffre pas une valeur déjà chiffrée', function() {
    if (!function_exists('openssl_cipher_iv_length')) {
        // Sans openssl, encrypt_setting() crasherait si une clé est définie (32+ chars).
        // On neutralise la clé pour tester le fallback en clair, qui est trivialement idempotent.
        $saved_key = getenv('APP_ENCRYPTION_KEY');
        putenv('APP_ENCRYPTION_KEY');
        try {
            $result = encrypt_setting('test-value');
            $double = encrypt_setting($result);
            return $double === $result ? true : "Idempotence cassée (fallback): $double";
        } finally {
            if ($saved_key !== false) putenv('APP_ENCRYPTION_KEY=' . $saved_key);
        }
    }
    $encrypted = encrypt_setting('test-value');
    $double = encrypt_setting($encrypted);
    return $double === $encrypted ? true : "Double-chiffrement détecté";
});

test('encrypt_setting() sans clé retourne la valeur en clair', function() {
    $saved_key = getenv('APP_ENCRYPTION_KEY');
    putenv('APP_ENCRYPTION_KEY');
    try {
        $result = encrypt_setting('test-no-key');
        return $result === 'test-no-key' ? true : "Got: $result";
    } finally {
        if ($saved_key !== false) putenv('APP_ENCRYPTION_KEY=' . $saved_key);
    }
});

test('encrypt_setting() avec clé trop courte retourne la valeur en clair', function() {
    $saved_key = getenv('APP_ENCRYPTION_KEY');
    putenv('APP_ENCRYPTION_KEY=short');  // Clé trop courte (< 32 chars)
    try {
        $result = encrypt_setting('test-short-key');
        return $result === 'test-short-key' ? true : "Got: $result";
    } finally {
        if ($saved_key !== false) putenv('APP_ENCRYPTION_KEY=' . $saved_key);
    }
});

test('encrypt_setting() / decrypt_setting() round-trip restitue la valeur originale', function() {
    if (!function_exists('openssl_cipher_iv_length')) {
        // Sans openssl : encrypt_setting crasherait si une clé 32+ est définie.
        // On neutralise la clé pour tester le fallback (valeur en clair des deux côtés).
        $saved_key = getenv('APP_ENCRYPTION_KEY');
        putenv('APP_ENCRYPTION_KEY');
        try {
            $original = 'fallback-secret-value';
            $encrypted = encrypt_setting($original);
            $decrypted = decrypt_setting($encrypted);
            return $decrypted === $original ? true : "Fallback round-trip cassé: $decrypted";
        } finally {
            if ($saved_key !== false) putenv('APP_ENCRYPTION_KEY=' . $saved_key);
        }
    }
    $original = 'smtp-secret-password-123';
    $encrypted = encrypt_setting($original);
    $decrypted = decrypt_setting($encrypted);
    return $decrypted === $original ? true : "Got: $decrypted (attendu: $original)";
});

test('encrypt_setting() produit le préfixe enc: quand openssl est disponible', function() {
    if (!function_exists('openssl_cipher_iv_length')) {
        return true;  // N/A sans openssl — fallback en clair, pas de préfixe
    }
    $result = encrypt_setting('my-secret-value');
    return strpos($result, 'enc:') === 0 ? true : "Pas de préfixe enc: dans: $result";
});

test('encrypt_setting() / decrypt_setting() avec valeur longue', function() {
    if (!function_exists('openssl_cipher_iv_length')) {
        return true;  // N/A sans openssl
    }
    $original = str_repeat('A very long secret. ', 100);
    $encrypted = encrypt_setting($original);
    $decrypted = decrypt_setting($encrypted);
    return $decrypted === $original ? true : 'Round-trip échec sur valeur longue';
});

test('encrypt_setting() produit des ciphertexts différents (IV aléatoire)', function() {
    if (!function_exists('openssl_cipher_iv_length')) {
        return true;  // N/A sans openssl
    }
    $a = encrypt_setting('same-value');
    $b = encrypt_setting('same-value');
    return $a !== $b ? true : 'IV non aléatoire (ciphertexts identiques)';
});

test('decrypt_setting() chaîne non chiffrée retournée telle quelle', function() {
    $result = decrypt_setting('plaintext-value');
    return $result === 'plaintext-value' ? true : "Got: $result";
});

test('decrypt_setting() chaîne vide retournée telle quelle', function() {
    $result = decrypt_setting('');
    return $result === '' ? true : "Got: $result";
});

test('decrypt_setting() sans clé retourne [chiffré] pour une valeur chiffrée', function() {
    if (!function_exists('openssl_cipher_iv_length')) {
        return true;  // N/A sans openssl : encrypt_setting ne chiffre pas
    }
    $encrypted = encrypt_setting('test-value');
    $saved_key = getenv('APP_ENCRYPTION_KEY');
    putenv('APP_ENCRYPTION_KEY');
    try {
        $result = decrypt_setting($encrypted);
        return $result === '[chiffré]' ? true : "Got: $result";
    } finally {
        if ($saved_key !== false) putenv('APP_ENCRYPTION_KEY=' . $saved_key);
    }
});

test('decrypt_setting() avec mauvaise clé retourne [chiffré]', function() {
    if (!function_exists('openssl_cipher_iv_length')) {
        return true;  // N/A sans openssl
    }
    $encrypted = encrypt_setting('test-value');
    $saved_key = getenv('APP_ENCRYPTION_KEY');
    putenv('APP_ENCRYPTION_KEY=' . str_repeat('z', 32));  // Mauvaise clé
    try {
        $result = decrypt_setting($encrypted);
        return $result === '[chiffré]' ? true : "Got: $result";
    } finally {
        if ($saved_key !== false) putenv('APP_ENCRYPTION_KEY=' . $saved_key);
    }
});

test('get_sensitive_setting_keys() retourne les 4 clés attendues', function() {
    $keys = get_sensitive_setting_keys();
    $expected = ['smtp_pass', 'ldap_bind_pass', 'webhook_secret', 'app_test_secret'];
    sort($keys);
    sort($expected);
    return $keys === $expected ? true : 'Clés sensibles incorrectes: ' . implode(',', $keys);
});

// ───────────────────────────────────────────────────────────────
// 12.11 — parse_date() avec formats YYYY-MM-DD et DD/MM/YYYY
// ───────────────────────────────────────────────────────────────
test('parse_date() YYYY-MM-DD retourne DateTimeImmutable', function() {
    $result = parse_date('2026-12-31');
    return $result instanceof DateTimeImmutable ? true : 'Pas un DateTimeImmutable';
});

test('parse_date() YYYY-MM-DD donne le bon jour', function() {
    $result = parse_date('2026-12-31');
    return $result && $result->format('Y-m-d') === '2026-12-31' ? true : 'Mauvaise date';
});

test('parse_date() DD/MM/YYYY retourne DateTimeImmutable', function() {
    $result = parse_date('31/12/2026');
    return $result instanceof DateTimeImmutable ? true : 'Pas un DateTimeImmutable';
});

test('parse_date() DD/MM/YYYY convertit vers YYYY-MM-DD', function() {
    $result = parse_date('15/06/2026');
    return $result && $result->format('Y-m-d') === '2026-06-15' ? true : 'Mauvaise conversion: ' . ($result ? $result->format('Y-m-d') : 'null');
});

test('parse_date() format invalide retourne null', function() {
    $result = parse_date('not-a-date');
    return $result === null ? true : 'Devrait retourner null';
});

test('parse_date() chaîne vide retourne null', function() {
    $result = parse_date('');
    return $result === null ? true : 'Devrait retourner null';
});

test('parse_date() format YYYY/MM/DD retourne null (séparateur incorrect)', function() {
    $result = parse_date('2026/12/31');
    return $result === null ? true : 'Devrait retourner null';
});

test('parse_date() format DD-MM-YYYY retourne null (séparateur incorrect)', function() {
    $result = parse_date('31-12-2026');
    return $result === null ? true : 'Devrait retourner null';
});

test('parse_date() avec espaces autour trime et accepte', function() {
    $result = parse_date('  2026-12-31  ');
    return $result instanceof DateTimeImmutable ? true : 'Devrait accepter après trim';
});

test('parse_date() gère les années bissextiles (29/02/2024)', function() {
    $result = parse_date('2024-02-29');
    return $result && $result->format('Y-m-d') === '2024-02-29' ? true : 'Année bissextile non gérée';
});

// ───────────────────────────────────────────────────────────────
// 12.12 — security_log() écrit dans audit_log DB et error_log
// ───────────────────────────────────────────────────────────────
test('security_log() existe et peut être appelée', function() {
    if (!function_exists('security_log')) return 'security_log() n\'existe pas';
    security_log('test_event', 'test detail', 'test@unit.example');
    return true;
});

test('security_log() écrit une ligne dans audit_log (action = security_event)', function() {
    $pdo = \App\Core\App::db()->getPdo();
    // Compter avant
    $count_before = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'security_event'")->fetchColumn();
    // Écrire
    $event = 'test_event_' . bin2hex(random_bytes(4));
    $detail = 'Détail de test unitaire';
    $actor = 'unit_test@example.com';
    security_log($event, $detail, $actor);
    // Compter après
    $count_after = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'security_event'")->fetchColumn();
    return $count_after > $count_before ? true : "Pas d'insertion ($count_before → $count_after)";
});

test('security_log() stocke l\'event dans la colonne target', function() {
    $pdo = \App\Core\App::db()->getPdo();
    $event = 'unique_event_' . bin2hex(random_bytes(4));
    security_log($event, 'test detail', 'unit_test@example.com');
    $stmt = $pdo->prepare("SELECT target FROM audit_log WHERE action = 'security_event' AND target = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$event]);
    $found = $stmt->fetchColumn();
    return $found === $event ? true : "Event non trouvé dans target: $found";
});

test('security_log() stocke le detail dans la colonne detail', function() {
    $pdo = \App\Core\App::db()->getPdo();
    $marker = 'MARKER_' . bin2hex(random_bytes(4));
    security_log('test_event', $marker, 'unit_test@example.com');
    $stmt = $pdo->prepare("SELECT detail FROM audit_log WHERE action = 'security_event' AND detail LIKE ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute(["%$marker%"]);
    $found = $stmt->fetchColumn();
    return $found !== false && strpos($found, $marker) !== false ? true : "Detail non trouvé";
});

test('security_log() stocke l\'actor dans la colonne actor', function() {
    $pdo = \App\Core\App::db()->getPdo();
    $actor = 'actor_' . bin2hex(random_bytes(4)) . '@example.com';
    security_log('test_event', 'detail', $actor);
    $stmt = $pdo->prepare("SELECT actor FROM audit_log WHERE action = 'security_event' AND actor = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$actor]);
    $found = $stmt->fetchColumn();
    return $found === $actor ? true : "Actor non trouvé: $found";
});

test('security_log() appelle error_log (vérifié via ini_set log_errors)', function() {
    // S4-TESTS / Action 9 : on utilise _find_function_in_libs() plutôt que
    // file_get_contents(helpers.php) directement, pour rester robuste si
    // security_log() est un jour extraite vers lib_security.php (refactoring).
    $body = _find_function_in_libs('security_log');
    if ($body === '') return 'security_log() introuvable dans helpers.php + lib_*.php';
    $has_error_log_call = strpos($body, 'error_log("[SECURITY]') !== false;
    return $has_error_log_call ? true : 'security_log() n\'appelle pas error_log("[SECURITY]...")';
});

test('security_log() utilise l\'utilisateur connecté si actor est vide', function() {
    $pdo = \App\Core\App::db()->getPdo();
    $saved_user = $_SERVER['HTTP_X_TEST_USER'] ?? null;
    $_SERVER['HTTP_X_TEST_USER'] = 'auto_actor@example.com';
    security_log('auto_event', 'detail');  // Pas d'actor explicite
    $stmt = $pdo->prepare("SELECT actor FROM audit_log WHERE action = 'security_event' AND actor = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute(['auto_actor@example.com']);
    $found = $stmt->fetchColumn();
    if ($saved_user !== null) $_SERVER['HTTP_X_TEST_USER'] = $saved_user;
    return $found === 'auto_actor@example.com' ? true : "Actor auto non utilisé: $found";
});

// ───────────────────────────────────────────────────────────────
// 12.13 — send_security_headers() (vérification par inspection du code source)
// Note : headers_list() retourne un tableau vide en CLI SAPI, on inspecte donc
// le code source de la fonction pour vérifier que les bons headers sont émis.
// ───────────────────────────────────────────────────────────────
test('send_security_headers() existe', function() {
    return function_exists('send_security_headers') ? true : 'send_security_headers() n\'existe pas';
});

// Note : les helpers _extract_function_body() et _find_function_in_libs() sont
// définis dans tests/test_unit_helpers.php (chargé via require_once dans test_unit.php).
// On les utilise directement ici sans les redéclarer.

test('send_security_headers() définit Content-Security-Policy', function() {
    $body = _find_function_in_libs('send_security_headers');
    return strpos($body, 'Content-Security-Policy:') !== false ? true : 'CSP non trouvé dans le corps de la fonction';
});

test('send_security_headers() CSP autorise les scripts inline (appli interne, bypass sécu accepté)', function() {
    // S3-TESTER : le CSP script-src 'none' cassait validate.php (JS inline pour récap refus)
    // et form.php (indicateur progression). Corrigé en S3 vers 'self' 'unsafe-inline'.
    // Ce test garantit qu'on ne revient pas à 'none' qui casserait les UX Sprint 2.
    $body = _find_function_in_libs('send_security_headers');
    if (strpos($body, "script-src 'none'") !== false) {
        return 'Régression détectée : script-src none présent (casse validate.php et form.php)';
    }
    if (strpos($body, "script-src 'self' 'unsafe-inline'") === false) {
        return "script-src 'self' 'unsafe-inline' non trouvé dans le CSP";
    }
    return true;
});

test('send_security_headers() définit X-Content-Type-Options: nosniff', function() {
    $body = _find_function_in_libs('send_security_headers');
    return strpos($body, 'X-Content-Type-Options: nosniff') !== false ? true : 'nosniff non trouvé';
});

test('send_security_headers() définit X-Frame-Options: DENY', function() {
    $body = _find_function_in_libs('send_security_headers');
    return strpos($body, 'X-Frame-Options: DENY') !== false ? true : 'X-Frame-Options: DENY non trouvé';
});

test('send_security_headers() définit Referrer-Policy', function() {
    $body = _find_function_in_libs('send_security_headers');
    return strpos($body, 'Referrer-Policy: strict-origin-when-cross-origin') !== false ? true : 'Referrer-Policy non trouvé';
});

test('send_security_headers() définit Permissions-Policy', function() {
    $body = _find_function_in_libs('send_security_headers');
    return strpos($body, 'Permissions-Policy: camera=(), microphone=(), geolocation=()') !== false ? true : 'Permissions-Policy non trouvé';
});

test('send_security_headers() définit X-XSS-Protection: 0 (CSP gère)', function() {
    $body = _find_function_in_libs('send_security_headers');
    return strpos($body, 'X-XSS-Protection: 0') !== false ? true : 'X-XSS-Protection: 0 non trouvé';
});

test('send_security_headers() active HSTS conditionnellement sur HTTPS', function() {
    $body = _find_function_in_libs('send_security_headers');
    $has_hsts = strpos($body, 'Strict-Transport-Security: max-age=31536000; includeSubDomains; preload') !== false;
    $has_https_check = strpos($body, '$_SERVER[\'HTTPS\']') !== false || strpos($body, '$_SERVER["HTTPS"]') !== false;
    return $has_hsts && $has_https_check ? true : 'HSTS conditionnel non trouvé';
});

test('send_security_headers() court-circuite si headers déjà envoyés', function() {
    $body = _find_function_in_libs('send_security_headers');
    return strpos($body, 'headers_sent()') !== false ? true : 'Pas de garde headers_sent()';
});

test('send_security_headers() appelée automatiquement en mode non-CLI', function() {
    $code = file_get_contents(dirname(__DIR__) . '/helpers.php');
    // Vérifie la présence du hook d'auto-call
    $has_auto_call = strpos($code, "php_sapi_name() !== 'cli'") !== false
        && strpos($code, 'send_security_headers();') !== false;
    return $has_auto_call ? true : 'Auto-call en mode non-CLI non trouvé';
});

echo "\n";
}
