<?php

declare(strict_types=1);

namespace App\Email;

use App\Cache\CacheService;

/**
 * Vérification d'adresse email via LDAP et suggestions LDAP.
 *
 * Extrait de EmailVerificationService (H-01, 2026-08-05).
 */
final readonly class LdapVerifier
{
    public function __construct(private CacheService $cacheService) {}

    /**
     * Vérifie qu'une adresse email existe dans l'Active Directory via LDAP.
     *
     * @return array{ok: bool, method: string, detail: string}
     */
    public function verify(string $email): array
    {
        if (!function_exists('ldap_connect')) {
            return ['ok' => false, 'method' => 'ldap', 'detail' => 'Extension PHP ldap non disponible'];
        }

        $host      = \App\Core\App::settings()->get('ldap_host', '');
        $port      = (int) \App\Core\App::settings()->get('ldap_port', '389');
        $base_dn   = \App\Core\App::settings()->get('ldap_base_dn', '');
        $bind_dn   = \App\Core\App::settings()->get('ldap_bind_dn', '');
        $bind_pass = \App\Core\App::settings()->get('ldap_bind_pass', '');
        $filter    = \App\Core\App::settings()->get('ldap_filter', '(mail={email})');

        if ($host === '' || $base_dn === '') {
            return ['ok' => false, 'method' => 'ldap', 'detail' => 'Configuration LDAP incomplète (hôte ou base DN manquant)'];
        }

        $ldap_uri = str_starts_with($host, '://') ? $host : 'ldap://' . $host;
        $conn = @ldap_connect("{$ldap_uri}:{$port}");
        if (!((bool)$conn)) {
            return ['ok' => false, 'method' => 'ldap', 'detail' => 'Impossible de se connecter au serveur LDAP ' . $host];
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);
        ldap_set_option($conn, LDAP_OPT_TIMELIMIT, 5);

        $bind = $bind_dn !== '' ? @ldap_bind($conn, $bind_dn, $bind_pass) : @ldap_bind($conn);

        if (!$bind) {
            $errno = ldap_errno($conn);
            $error = ldap_err2str($errno);
            @ldap_close($conn);
            return ['ok' => false, 'method' => 'ldap', 'detail' => "Échec d'authentification LDAP (code $errno : $error)"];
        }

        $escaped_email = ldap_escape($email, '', LDAP_ESCAPE_FILTER);
        $search_filter = str_replace('{email}', $escaped_email, $filter);

        $search = @ldap_search($conn, $base_dn, $search_filter, ['mail', 'cn', 'distinguishedName']);
        if (!$search instanceof \LDAP\Result) {
            $errno = ldap_errno($conn);
            @ldap_close($conn);
            return ['ok' => false, 'method' => 'ldap', 'detail' => "Erreur de recherche LDAP (code $errno)"];
        }

        $entries = ldap_get_entries($conn, $search);
        @ldap_close($conn);

        $count = (int) ($entries['count'] ?? 0);
        if ($count > 0) {
            $cn = $entries[0]['cn'][0] ?? '(nom inconnu)';
            return ['ok' => true, 'method' => 'ldap', 'detail' => "Trouvé dans l'AD : $cn"];
        }

        return ['ok' => false, 'method' => 'ldap', 'detail' => "Adresse $email introuvable dans l'annuaire Active Directory"];
    }

    /**
     * Recherche des adresses email dans l'annuaire LDAP pour l'autocomplétion.
     *
     * @return array<int, array{email: string, cn: string}>
     */
    public function suggest(string $query = '', int $limit = 100): array
    {
        if (!function_exists('ldap_connect')) {
            return [];
        }

        if (\App\Core\App::settings()->get('ldap_suggest_enabled', '0') !== '1') {
            return [];
        }

        $host    = \App\Core\App::settings()->get('ldap_host', '');
        $base_dn = \App\Core\App::settings()->get('ldap_base_dn', '');
        if ($host === '' || $base_dn === '') {
            return [];
        }

        $limit = max(1, min(500, $limit));

        return $this->cacheSuggest($query, $limit);
    }

    /**
     * Cache pour les suggestions LDAP (TTL 5 min).
     *
     * @return array<int, array{email: string, cn: string}>
     */
    private function cacheSuggest(string $query, int $limit): array
    {
        $host     = \App\Core\App::settings()->get('ldap_host', '');
        $base_dn = \App\Core\App::settings()->get('ldap_base_dn', '');
        $cache_key = 'ldap_suggest:' . $host . ':' . $base_dn . ':' . $query . ':' . $limit;

        return $this->cacheService->get($cache_key, 300, function () use ($query, $limit): array {
            $host          = \App\Core\App::settings()->get('ldap_host', '');
            $port          = (int) \App\Core\App::settings()->get('ldap_port', '389');
            $base_dn       = \App\Core\App::settings()->get('ldap_base_dn', '');
            $bind_dn       = \App\Core\App::settings()->get('ldap_bind_dn', '');
            $bind_pass     = \App\Core\App::settings()->get('ldap_bind_pass', '');
            $suggest_filter = \App\Core\App::settings()->get(
                'ldap_suggest_filter',
                '(|(cn=*{query}*)(mail=*{query}*)(sn=*{query}*)(givenName=*{query}*))'
            );

            $ldap_uri = str_starts_with($host, '://') ? $host : 'ldap://' . $host;
            $conn = @ldap_connect("{$ldap_uri}:{$port}");
            if (!((bool)$conn)) {
                return [];
            }

            ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);
            ldap_set_option($conn, LDAP_OPT_TIMELIMIT, 8);

            $bind = $bind_dn !== '' ? @ldap_bind($conn, $bind_dn, $bind_pass) : @ldap_bind($conn);
            if (!$bind) {
                @ldap_close($conn);
                return [];
            }

            $escaped_query = ldap_escape($query, '', LDAP_ESCAPE_FILTER);
            $search_filter = str_replace('{query}', $escaped_query, $suggest_filter);

            if ($query === '') {
                $search_filter = '(mail=*)';
            }

            $search = @ldap_search($conn, $base_dn, $search_filter, ['mail', 'cn', 'sn', 'givenName'], 0, $limit);
            if (!$search instanceof \LDAP\Result) {
                @ldap_close($conn);
                return [];
            }

            $entries = ldap_get_entries($conn, $search);
            @ldap_close($conn);

            if (!is_array($entries)) {
                return [];
            }

            $results = [];
            $count = (int) ($entries['count'] ?? 0);
            for ($i = 0; $i < $count; $i++) {
                $entry = $entries[$i];
                $mail = $entry['mail'][0] ?? '';
                if ($mail === '') {
                    continue;
                }
                if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $cn        = $entry['cn'][0] ?? '';
                $sn        = $entry['sn'][0] ?? '';
                $givenName = $entry['givenname'][0] ?? '';

                $display = $cn;
                if ($display === '') {
                    $display = trim($givenName . ' ' . $sn);
                }
                if ($display === '') {
                    $display = $mail;
                }

                $results[] = ['email' => strtolower(trim($mail)), 'cn' => $display];
            }

            usort($results, fn(array $a, array $b): int => strcasecmp($a['cn'], $b['cn']));

            return $results;
        });
    }
}
