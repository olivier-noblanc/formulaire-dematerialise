<?php
/**
 * lib_uuid.php — Génération d'identifiants uniques (UUID v4, tokens de validation).
 *
 * Module Phase 1 du découpage progressif de helpers.php (S3-CTO).
 * Chargé automatiquement par helpers.php via require_once — aucune inclusion
 * manuelle nécessaire. Les fonctions restent disponibles globalement
 * (pas de namespace, pas de classe) — compatibilité ascendante totale.
 *
 * Fonctions exposées :
 *  - generate_uuid() : UUID v4 RFC 4122 pour identifiants de lignes DB
 *  - generate_token() : token hex 64 caractères pour URLs de validation et CSRF
 *
 * Aucune dépendance externe — utilise uniquement des fonctions natives PHP
 * (random_bytes, bin2hex, chr, ord, vsprintf, str_split).
 *
 * Plan 3 phases (CTO, REUNION1-CTO §4) :
 *  - Phase 1 (S3, cette version) : fonctions autonomes peu couplées.
 *  - Phase 2 (S4) : fonctions medium-coupling (workflow, mail, LDAP, RGPD).
 *  - Phase 3 (S5+) : fonctions à couplage fort (DB, cache, settings).
 */

/**
 * Generate a UUID v4 for database row identifiers (RFC 4122 compliant).
 * Uses random_bytes() for cryptographic security.
 * Used for: form IDs, submission IDs, token IDs, etc.
 * NOT for: validation URLs or CSRF (use generate_token() instead).
 */
function generate_uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Generate a 64-character hex token for validation URLs and CSRF.
 * Used for: email validation tokens, CSRF tokens.
 * NOT for: database row IDs (use generate_uuid() instead).
 */
function generate_token(): string {
    return bin2hex(random_bytes(32));
}
