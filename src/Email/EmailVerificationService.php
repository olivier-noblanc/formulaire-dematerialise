<?php

declare(strict_types=1);

namespace App\Email;

use App\Cache\CacheService;

/**
 * Service de vérification email (LDAP + SMTP).
 *
 * Extrait de lib/email_verify.php — vérification, autocomplétion LDAP,
 * probe SMTP, et orchestration selon la configuration.
 * Les fonctions globales dans lib/email_verify.php délèguent maintenant ici.
 *
 * Orchestration déléguée à LdapVerifier et SmtpVerifier (H-01, 2026-08-05).
 */
final readonly class EmailVerificationService
{
    private LdapVerifier $ldapVerifier;
    private SmtpVerifier $smtpVerifier;

    public function __construct(private CacheService $cacheService)
    {
        $this->ldapVerifier = new LdapVerifier($this->cacheService);
        $this->smtpVerifier = new SmtpVerifier();
    }

    /**
     * Vérifie une adresse email selon le mode configuré (LDAP, SMTP ou aucun).
     *
     * @return array{ok: bool, method: string, detail: string}
     */
    public function verify(string $email): array
    {
        $mode = \App\Core\App::settings()->get('email_verify_mode', 'none');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'method' => 'format', 'detail' => 'Format d\'email invalide : ' . $email];
        }

        if ($mode === 'none') {
            return ['ok' => true, 'method' => 'none', 'detail' => 'Aucune vérification configurée'];
        }

        if ($mode === 'ldap') {
            return $this->ldapVerifier->verify($email);
        }

        if ($mode === 'smtp') {
            return $this->smtpVerifier->verify($email);
        }

        return ['ok' => true, 'method' => 'none', 'detail' => 'Mode de vérification inconnu : ' . $mode];
    }

    /**
     * Vérifie qu'une adresse email existe dans l'Active Directory via LDAP.
     *
     * @return array{ok: bool, method: string, detail: string}
     */
    public function verifyLdap(string $email): array
    {
        return $this->ldapVerifier->verify($email);
    }

    /**
     * Vérifie qu'une adresse email existe via une probe SMTP (RCPT TO).
     *
     * @return array{ok: bool, method: string, detail: string}
     */
    public function verifySmtp(string $email): array
    {
        return $this->smtpVerifier->verify($email);
    }

    /**
     * Recherche des adresses email dans l'annuaire LDAP pour l'autocomplétion.
     *
     * @return array<int, array{email: string, cn: string}>
     */
    public function ldapSuggest(string $query = '', int $limit = 100): array
    {
        return $this->ldapVerifier->suggest($query, $limit);
    }

    /**
     * Teste la vérification email avec une adresse donnée (pour la page admin).
     *
     * @return array{email: string, mode: string, format_valid: bool, ldap?: array{ok: bool, method: string, detail: string}, smtp?: array{ok: bool, method: string, detail: string}, verify: array{ok: bool, method: string, detail: string}}
     */
    public function testVerification(string $email): array
    {
        $mode = \App\Core\App::settings()->get('email_verify_mode', 'none');

        $results = [
            'email'         => $email,
            'mode'          => $mode,
            'format_valid'  => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        ];

        if ($mode === 'ldap') {
            $results['ldap'] = $this->ldapVerifier->verify($email);
        } elseif ($mode === 'smtp') {
            $results['smtp'] = $this->smtpVerifier->verify($email);
        } elseif ($mode === 'both') {
            $ldap_result = $this->ldapVerifier->verify($email);
            $results['ldap'] = $ldap_result;
            if (!$ldap_result['ok']) {
                $results['smtp'] = $this->smtpVerifier->verify($email);
            }
        }

        $results['verify'] = $this->verify($email);

        return $results;
    }
}
