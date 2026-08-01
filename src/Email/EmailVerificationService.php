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
 */
final readonly class EmailVerificationService
{
    public function __construct(private CacheService $cacheService) {}

    /**
     * Vérifie qu'une adresse email existe dans l'Active Directory via LDAP.
     *
     * @return array{ok: bool, method: string, detail: string}
     */
    public function verifyLdap(string $email): array
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
        if (!$conn) {
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
    public function ldapSuggest(string $query = '', int $limit = 100): array
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

        return $this->cacheLdapSuggest($query, $limit);
    }

    /**
     * Cache pour les suggestions LDAP (TTL 5 min).
     *
     * @return array<int, array{email: string, cn: string}>
     */
    private function cacheLdapSuggest(string $query, int $limit): array
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
            if (!$conn) {
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
                if ($mail === '' || !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
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

                $results[] = ['email' => $mail |> trim(...) |> strtolower(...), 'cn' => $display];
            }

            usort($results, fn(array $a, array $b) => strcasecmp($a['cn'], $b['cn']));

            return $results;
        });
    }

    /**
     * Vérifie qu'une adresse email existe via une probe SMTP (RCPT TO).
     *
     * @return array{ok: bool, method: string, detail: string}
     */
    public function verifySmtp(string $email): array
    {
        $smtp_host   = \App\Core\App::settings()->get('smtp_host');
        $smtp_port   = (int) \App\Core\App::settings()->get('smtp_port');
        $smtp_from   = \App\Core\App::settings()->get('smtp_from');
        $smtp_secure = \App\Core\App::settings()->get('smtp_secure', '');

        if ($smtp_host === '') {
            return ['ok' => false, 'method' => 'smtp', 'detail' => 'Aucun serveur SMTP configuré'];
        }

        if (!function_exists('fsockopen')) {
            return ['ok' => false, 'method' => 'smtp', 'detail' => 'Extension PHP sockets non disponible'];
        }

        $timeout = 10;
        $errno   = 0;
        $errstr  = '';

        $conn = @fsockopen($smtp_host, $smtp_port, $errno, $errstr, $timeout);
        if (!$conn) {
            return ['ok' => false, 'method' => 'smtp', 'detail' => "Impossible de se connecter à $smtp_host:$smtp_port ($errstr)"];
        }

        stream_set_timeout($conn, $timeout);

        $read_smtp = function () use ($conn): string {
            $response = '';
            while ($line = fgets($conn, 512)) {
                $response .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $response;
        };

        $send_smtp = function (string $cmd) use ($conn): void {
            fwrite($conn, $cmd . "\r\n");
        };

        $banner = $read_smtp();
        if (!str_starts_with($banner, '220')) {
            fclose($conn);
            return ['ok' => false, 'method' => 'smtp', 'detail' => 'Bannière SMTP invalide : ' . trim($banner)];
        }

        $helo_host = preg_replace('/[^a-zA-Z0-9.\-]/', '', (string) gethostname());
        if ($helo_host === '') {
            $helo_host = 'localhost';
        }
        $send_smtp('HELO ' . $helo_host);
        $resp = $read_smtp();
        if (!str_starts_with($resp, '250')) {
            fclose($conn);
            return ['ok' => false, 'method' => 'smtp', 'detail' => 'HELO rejeté : ' . trim($resp)];
        }

        if ($smtp_secure === 'tls') {
            $send_smtp('STARTTLS');
            $resp = $read_smtp();
            if (!str_starts_with($resp, '220')) {
                fclose($conn);
                return ['ok' => false, 'method' => 'smtp', 'detail' => 'STARTTLS rejeté : ' . trim($resp)];
            }
            if (!@stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($conn);
                return ['ok' => false, 'method' => 'smtp', 'detail' => 'Échec de la négociation TLS'];
            }
            $send_smtp('EHLO ' . $helo_host);
            $resp = $read_smtp();
            if (!str_starts_with($resp, '250')) {
                fclose($conn);
                return ['ok' => false, 'method' => 'smtp', 'detail' => 'EHLO après STARTTLS rejeté : ' . trim($resp)];
            }
        }

        $safe_smtp_from = str_replace(["\r", "\n", "\t"], '', $smtp_from);
        $send_smtp('MAIL FROM:<' . $safe_smtp_from . '>');
        $resp = $read_smtp();
        if (!str_starts_with($resp, '250')) {
            $send_smtp('QUIT');
            $read_smtp();
            fclose($conn);
            return ['ok' => false, 'method' => 'smtp', 'detail' => 'MAIL FROM rejeté : ' . trim($resp)];
        }

        $safe_email = str_replace(["\r", "\n", "\t", '<', '>'], '', $email);
        $send_smtp('RCPT TO:<' . $safe_email . '>');
        $resp = $read_smtp();

        $send_smtp('QUIT');
        $read_smtp();
        fclose($conn);

        $code = substr($resp, 0, 3);
        if ($code === '250') {
            return ['ok' => true, 'method' => 'smtp', 'detail' => 'Adresse acceptée par le serveur SMTP'];
        }

        if ($code === '251') {
            return ['ok' => true, 'method' => 'smtp', 'detail' => 'Adresse acceptée (transfert) par le serveur SMTP'];
        }

        return ['ok' => false, 'method' => 'smtp', 'detail' => 'Adresse rejetée par le serveur SMTP : ' . trim($resp)];
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
            return $this->verifyLdap($email);
        }

        if ($mode === 'smtp') {
            return $this->verifySmtp($email);
        }

        return ['ok' => true, 'method' => 'none', 'detail' => 'Mode de vérification inconnu : ' . $mode];
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
            $results['ldap'] = $this->verifyLdap($email);
        } elseif ($mode === 'smtp') {
            $results['smtp'] = $this->verifySmtp($email);
        } elseif ($mode === 'both') {
            $ldap_result = $this->verifyLdap($email);
            $results['ldap'] = $ldap_result;
            if (!$ldap_result['ok']) {
                $results['smtp'] = $this->verifySmtp($email);
            }
        }

        $results['verify'] = $this->verify($email);

        return $results;
    }
}
