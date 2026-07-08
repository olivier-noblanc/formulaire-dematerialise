<?php
declare(strict_types=1);

/**
 * Email verification (LDAP + SMTP).
 *
 * Fonctions globales — délèguent à \App\Email\EmailVerificationService.
 *
 * @package lib
 */

// ── VÉRIFICATION EMAIL ─────────────────────────────────────────

/**
 * @param string $email Adresse email à vérifier
 * @return array{ok: bool, method: string, detail: string}
 */
function verify_email_ldap(string $email): array {
    return \App\Core\App::emailVerify()->verifyLdap($email);
}

/**
 * @param string $query Terme de recherche (nom, prénom, ou partie d'email). Vide = tous.
 * @param int    $limit Nombre maximum de résultats (défaut 100, max 500).
 * @return array<int, array{email: string, cn: string}>
 */
function ldap_suggest(string $query = '', int $limit = 100): array {
    return \App\Core\App::emailVerify()->ldapSuggest($query, $limit);
}

/**
 * @param string $email Adresse email à vérifier
 * @return array{ok: bool, method: string, detail: string}
 */
function verify_email_smtp(string $email): array {
    return \App\Core\App::emailVerify()->verifySmtp($email);
}

/**
 * @param string $email Adresse email à vérifier
 * @return array{ok: bool, method: string, detail: string}
 */
function verify_email(string $email): array {
    return \App\Core\App::emailVerify()->verify($email);
}

/**
 * Teste la vérification email avec une adresse donnée (pour la page admin).
 * @return array<string, mixed>
 */
function test_email_verification(string $email): array {
    return \App\Core\App::emailVerify()->testVerification($email);
}
