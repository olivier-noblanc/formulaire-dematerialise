<?php
/**
 * lib_validation.php — Validation et sanitisation centralisée des entrées (A-01).
 *
 * Module Phase 1 du découpage progressif de helpers.php (S3-CTO).
 * Chargé automatiquement par helpers.php via require_once — aucune inclusion
 * manuelle nécessaire. Les fonctions restent disponibles globalement
 * (pas de namespace, pas de classe) — compatibilité ascendante totale.
 *
 * Fonctions exposées :
 *  - sanitize_input()  : @deprecated — échappement legacy (stslashes + htmlspecialchars)
 *  - validate_email()  : valide et normalise un email (filter_var FILTER_VALIDATE_EMAIL)
 *  - validate_input()  : validation centralisée par règle
 *    (uuid|email|slug|action|status|alpha_num|int|date|token) — lève
 *    InvalidArgumentException en cas d'échec. Options : max_length, min, max, allowed_values.
 *
 * Aucune dépendance externe — utilise uniquement des fonctions natives PHP
 * (preg_match, filter_var, mb_substr, strtotime, htmlspecialchars, trim,
 * stripslashes, trigger_error, strtolower).
 *
 * Plan 3 phases (CTO, REUNION1-CTO §4) :
 *  - Phase 1 (S3, cette version) : fonctions autonomes peu couplées.
 *  - Phase 2 (S4) : fonctions medium-coupling (workflow, mail, LDAP, RGPD).
 *  - Phase 3 (S5+) : fonctions à couplage fort (DB, cache, settings).
 */

/**
 * @deprecated Ne pas utiliser. Utilisez h() pour le HTML et les requêtes préparées pour le SQL.
 * stripslashes() peut corrompre des données légitimes (chemins Windows).
 */
function sanitize_input(string $input): string {
    trigger_error('sanitize_input() is deprecated — use h() for HTML output and prepared statements for SQL', E_USER_DEPRECATED);
    return \App\Core\App::validation()->sanitize($input);
}

/**
 * Validate and sanitize email
 */
function validate_email(string $email): string {
    return \App\Core\App::validation()->validateEmail($email);
}

// ── CENTRALIZED INPUT VALIDATION (A-01) ────────────────────────

/**
 * Validation centralisée des entrées utilisateur (A-01).
 * Remplace les validations dispersées dans les pages individuelles.
 * Chaque règle retourne la valeur validée ou lance une exception.
 *
 * @param mixed  $value   Valeur à valider
 * @param string $rule    Règle de validation (uuid|email|slug|action|status|alpha_num|int|date)
 * @param array<string, mixed> $options Options supplémentaires [max_length, min, max, allowed_values]
 * @return string|int Valeur validée et sanitisée
 * @throws \InvalidArgumentException Si la validation échoue
 */
function validate_input(mixed $value, string $rule, array $options = []): string|int {
    return \App\Core\App::validation()->validate($value, $rule, $options);
}
