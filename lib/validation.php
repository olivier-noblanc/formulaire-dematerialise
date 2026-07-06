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
    $input = trim($input);
    $input = stripslashes($input);
    $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    return $input;
}

/**
 * Validate and sanitize email
 */
function validate_email(string $email): string {
    $email = strtolower(trim($email));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
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
    $str_value = is_string($value) ? trim($value) : (string)$value;
    $max_length = $options['max_length'] ?? 0;

    switch ($rule) {
        case 'uuid':
            // UUID v4 : xxxxxxxx-xxxx-4xxx-[89ab]xxx-xxxxxxxxxxxx
            if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $str_value)) {
                throw new \InvalidArgumentException('Identifiant invalide');
            }
            return strtolower($str_value);

        case 'email':
            $str_value = strtolower($str_value);
            if ($max_length > 0) $str_value = mb_substr($str_value, 0, $max_length);
            if (!filter_var($str_value, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Adresse email invalide');
            }
            return $str_value;

        case 'slug':
            // Bug v5.27.0 (découvert par l'utilisateur en prod) :
            // la regex n'acceptait que [a-z0-9_] (underscore) mais les formulaires
            // créés par la migration par défaut utilisent des tirets (acces-si,
            // sortie-hors-plages, remboursement-avance-frais, materiel-prescription).
            // En prod, form.php?f=acces-si levait une erreur 400.
            // CORRECTION : accepter aussi les tirets dans les slugs.
            if (!preg_match('/^[a-z0-9_-]+$/i', $str_value)) {
                throw new \InvalidArgumentException('Slug invalide (caractères autorisés : a-z, 0-9, _, -)');
            }
            if ($max_length > 0) $str_value = mb_substr($str_value, 0, $max_length);
            return $str_value;

        case 'action':
            // Nom d'action POST : alphanumérique + underscore
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $str_value)) {
                throw new \InvalidArgumentException('Nom d\'action invalide');
            }
            if ($max_length > 0) $str_value = mb_substr($str_value, 0, $max_length);
            return $str_value;

        case 'status':
            $allowed = $options['allowed_values'] ?? ['en_cours', 'valide', 'refuse'];
            if (!in_array($str_value, $allowed, true)) {
                throw new \InvalidArgumentException('Statut invalide');
            }
            return $str_value;

        case 'alpha_num':
            // Alphanumérique + espaces + accents
            if (!preg_match('/^[\p{L}0-9\s._\-]+$/u', $str_value)) {
                throw new \InvalidArgumentException('Caractères non autorisés');
            }
            if ($max_length > 0) $str_value = mb_substr($str_value, 0, $max_length);
            return $str_value;

        case 'int':
            $int_value = filter_var($str_value, FILTER_VALIDATE_INT);
            if ($int_value === false) {
                throw new \InvalidArgumentException('Nombre entier invalide');
            }
            if (isset($options['min']) && $int_value < $options['min']) {
                throw new \InvalidArgumentException('Valeur trop petite');
            }
            if (isset($options['max']) && $int_value > $options['max']) {
                throw new \InvalidArgumentException('Valeur trop grande');
            }
            return $int_value;

        case 'date':
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $str_value)) {
                throw new \InvalidArgumentException('Format de date invalide (YYYY-MM-DD attendu)');
            }
            $ts = strtotime($str_value);
            if ($ts === false) {
                throw new \InvalidArgumentException('Date invalide');
            }
            return $str_value;

        case 'token':
            // Token de validation : 64 caractères hexadécimaux
            if (!preg_match('/^[a-f0-9]{64}$/', $str_value)) {
                throw new \InvalidArgumentException('Token invalide');
            }
            return $str_value;

        default:
            // Règle inconnue — sanitisser basiquement
            if ($max_length > 0) $str_value = mb_substr($str_value, 0, $max_length);
            return $str_value;
    }
}
