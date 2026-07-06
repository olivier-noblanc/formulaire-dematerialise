<?php
/**
 * lib_security.php — Helpers de sécurité CSRF (génération, vérification, garde).
 *
 * Module Phase 1 du découpage progressif de helpers.php (S3-CTO).
 * Chargé automatiquement par helpers.php via require_once — aucune inclusion
 * manuelle nécessaire. Les fonctions restent disponibles globalement
 * (pas de namespace, pas de classe) — compatibilité ascendante totale.
 *
 * Fonctions exposées :
 *  - generate_csrf_token() : génère/retourne le jeton CSRF session-scoped (256 bits)
 *  - csrf_field()          : génère un <input type="hidden"> prêt à insérer dans un form
 *  - verify_csrf()         : vérifie le jeton POST contre le jeton session (rotation post-check)
 *  - require_csrf()        : vérifie le jeton et affiche une 403 si invalide
 *
 * Note Phase 1 — périmètre réduit vs spec initiale :
 *  La spec listait aussi send_security_headers() et security_log() dans ce module.
 *  Elles RESTENT dans helpers.php car test_unit.php (sections 12.12 et 12.13,
 *  11 tests au total) inspecte le code source de helpers.php via file_get_contents
 *  + strpos pour vérifier la présence de la définition de security_log et le
 *  corps de la définition de send_security_headers. Les déplacer en Phase 1
 *  aurait cassé ces tests — violation de la contrainte "0 breaking change /
 *  ne pas modifier les tests". Leur extraction est donc reportée à Phase 2,
 *  après refactoring des tests d'inspection source pour qu'ils parcourent
 *  l'ensemble des lib_*.php.
 *
 * Dépendances : aucune externe. csrf_field() appelle h() (lib_html.php) — résolu
 * au moment de l'appel (fonctions globales procédurales). require_csrf() appelle
 * render_error_page() (helpers.php) — idem, résolu à l'appel.
 *
 * Plan 3 phases (CTO, REUNION1-CTO §4) :
 *  - Phase 1 (S3, cette version) : fonctions autonomes peu couplées.
 *  - Phase 2 (S4) : fonctions medium-coupling (workflow, mail, LDAP, RGPD)
 *    + extraction de send_security_headers() et security_log() (après refactor tests).
 *  - Phase 3 (S5+) : fonctions à couplage fort (DB, cache, settings).
 */

// ── CSRF ─────────────────────────────────────────────────────
function generate_csrf_token(): string {
    return \App\Core\App::security()->generateCsrfToken();
}

function csrf_field(): string {
    return \App\Core\App::security()->csrfField();
}

function verify_csrf(): bool {
    return \App\Core\App::security()->verifyCsrf();
}

/**
 * Vérifie le jeton CSRF et affiche une page d'erreur 403 si invalide.
 * Remplace le pattern répétitif : if (!verify_csrf()) { render_error_page(403, ...); }
 */
function require_csrf(): void {
    \App\Core\App::security()->requireCsrf();
}
function send_security_headers(): void {
    \App\Core\App::security()->sendSecurityHeaders();
}

// ── SECURITY HARDENING ──────────────────────────────────────

/**
 * Rate limiting par IP et par action
 * Retourne true si l'action est autorisée, false si le rate limit est atteint
 */
function rate_limit_check(string $action = 'default', int $max_attempts = 10, int $window_seconds = 60): bool {
    return \App\Core\App::security()->rateLimitCheck($action, $max_attempts, $window_seconds);
}
