<?php
declare(strict_types=1);

/**
 * Email verification (LDAP + SMTP).
 *
 * Vérification d'existence d'un email via :
 *   - LDAP / Active Directory (verify_email_ldap, ldap_suggest, cache_ldap_suggest)
 *   - SMTP RCPT TO (verify_email_smtp)
 *   - Combinaison des deux (verify_email)
 *
 * @package lib
 */

// ── VÉRIFICATION EMAIL ─────────────────────────────────────────

/**
 * Vérifie qu'une adresse email existe dans l'Active Directory via LDAP.
 *
 * Prérequis : extension PHP ldap activée, accès réseau vers le serveur AD.
 * Connexion en lecture seule (bind anonyme ou compte de service dédié).
 *
 * @param string $email Adresse email à vérifier
 * @return array<string, mixed> ['ok' => bool, 'method' => string, 'detail' => string]
 */
function verify_email_ldap(string $email): array {
    // Vérifier que l'extension LDAP est disponible
    if (!function_exists('ldap_connect')) {
        return ['ok' => false, 'method' => 'ldap', 'detail' => 'Extension PHP ldap non disponible'];
    }

    $host     = get_setting('ldap_host', '');
    $port     = (int)get_setting('ldap_port', '389');
    $base_dn  = get_setting('ldap_base_dn', '');
    $bind_dn  = get_setting('ldap_bind_dn', '');
    $bind_pass= get_setting('ldap_bind_pass', '');
    $filter   = get_setting('ldap_filter', '(mail={email})');

    if (empty($host) || empty($base_dn)) {
        return ['ok' => false, 'method' => 'ldap', 'detail' => 'Configuration LDAP incomplète (hôte ou base DN manquant)'];
    }

    // Connexion LDAP
    $ldap_uri = (strpos($host, '://') !== false) ? $host : 'ldap://' . $host;
    $conn = @ldap_connect($ldap_uri, $port);
    if (!$conn) {
        return ['ok' => false, 'method' => 'ldap', 'detail' => 'Impossible de se connecter au serveur LDAP ' . $host];
    }

    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);
    ldap_set_option($conn, LDAP_OPT_TIMELIMIT, 5);

    // Bind — anonyme si aucun bind_dn configuré, sinon avec le compte de service
    if (!empty($bind_dn)) {
        $bind = @ldap_bind($conn, $bind_dn, $bind_pass);
    } else {
        $bind = @ldap_bind($conn); // Bind anonyme
    }

    if (!$bind) {
        $errno = ldap_errno($conn);
        $error = ldap_err2str($errno);
        @ldap_close($conn);
        return ['ok' => false, 'method' => 'ldap', 'detail' => "Échec d'authentification LDAP (code $errno : $error)"];
    }

    // Recherche de l'email dans l'annuaire
    // Sécurité : échapper AVANT la substitution pour éviter l'injection LDAP
    // (l'ancien code substituait puis échappait, ce qui était incorrect si l'email
    // contenait des caractères présents dans le filtre template)
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

    $count = (int)($entries['count'] ?? 0);
    if ($count > 0) {
        $cn = $entries[0]['cn'][0] ?? '(nom inconnu)';
        return ['ok' => true, 'method' => 'ldap', 'detail' => "Trouvé dans l'AD : $cn"];
    }

    return ['ok' => false, 'method' => 'ldap', 'detail' => "Adresse $email introuvable dans l'annuaire Active Directory"];
}

/**
 * Recherche des adresses email dans l'annuaire LDAP pour l'autocomplétion.
 * Retourne un tableau d'entrées ['email' => '...', 'cn' => '...'].
 * Utilise un cache fichier de 30 minutes pour éviter de surcharger le serveur LDAP.
 *
 * @param string $query Terme de recherche (nom, prénom, ou partie d'email). Vide = tous.
 * @param int    $limit Nombre maximum de résultats (défaut 100, max 500).
 * @return array<string, mixed> Tableau d'entrées ['email' => string, 'cn' => string]
 */
function ldap_suggest(string $query = '', int $limit = 100): array {
    // Sécurité (S-16) : limiter le nombre de requêtes LDAP par IP
    if (!rate_limit_check('ldap_suggest', 20, 60)) {
        return [];
    }
    // Vérifier que l'extension LDAP est disponible
    if (!function_exists('ldap_connect')) {
        return [];
    }

    // Vérifier que la suggestion LDAP est activée
    if (get_setting('ldap_suggest_enabled', '0') !== '1') {
        return [];
    }

    $host    = get_setting('ldap_host', '');
    $base_dn = get_setting('ldap_base_dn', '');
    if (empty($host) || empty($base_dn)) {
        return [];
    }

    $limit = max(1, min(500, $limit));

    // A-11 : déléguer la mise en cache à cache_ldap_suggest() (TTL 5 min)
    return cache_ldap_suggest($query, $limit);
}

/**
 * Cache pour les suggestions LDAP (A-11).
 * TTL: 5 minutes (300 secondes) — équilibre entre fraîcheur des données
 * et charge du serveur LDAP. Utilise le cache fichier générique cache_get().
 *
 * @param string $query Terme de recherche (nom, prénom, ou partie d'email). Vide = tous.
 * @param int    $limit Nombre maximum de résultats
 * @return array<string, mixed> Tableau d'entrées ['email' => string, 'cn' => string]
 */
function cache_ldap_suggest(string $query, int $limit): array {
    $host    = get_setting('ldap_host', '');
    $base_dn = get_setting('ldap_base_dn', '');
    $cache_key = 'ldap_suggest:' . $host . ':' . $base_dn . ':' . $query . ':' . $limit;

    return cache_get($cache_key, 300, function() use ($query, $limit) {
        $host     = get_setting('ldap_host', '');
        $port     = (int)get_setting('ldap_port', '389');
        $base_dn  = get_setting('ldap_base_dn', '');
        $bind_dn  = get_setting('ldap_bind_dn', '');
        $bind_pass= get_setting('ldap_bind_pass', '');
        $suggest_filter = get_setting('ldap_suggest_filter', '(|(cn=*{query}*)(mail=*{query}*)(sn=*{query}*)(givenName=*{query}*))');

        // ── Connexion LDAP ──────────────────────────────────────────
        $ldap_uri = (strpos($host, '://') !== false) ? $host : 'ldap://' . $host;
        $conn = @ldap_connect($ldap_uri, $port);
        if (!$conn) {
            return [];
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);
        ldap_set_option($conn, LDAP_OPT_TIMELIMIT, 8);

        // Bind
        if (!empty($bind_dn)) {
            $bind = @ldap_bind($conn, $bind_dn, $bind_pass);
        } else {
            $bind = @ldap_bind($conn);
        }
        if (!$bind) {
            @ldap_close($conn);
            return [];
        }

        // ── Recherche ───────────────────────────────────────────────
        $escaped_query = ldap_escape($query, '', LDAP_ESCAPE_FILTER);
        $search_filter = str_replace('{query}', $escaped_query, $suggest_filter);

        // Si la requête est vide, chercher tous les utilisateurs avec un email
        if (empty($query)) {
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

        // ── Mise en forme des résultats ─────────────────────────────
        $results = [];
        $count = (int)($entries['count'] ?? 0);
        for ($i = 0; $i < $count; $i++) {
            $entry = $entries[$i];
            $mail = $entry['mail'][0] ?? '';
            if (empty($mail) || !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $cn = $entry['cn'][0] ?? '';
            $sn = $entry['sn'][0] ?? '';
            $givenName = $entry['givenname'][0] ?? '';

            // Construire un nom affichable
            $display = $cn;
            if (empty($display)) {
                $display = trim($givenName . ' ' . $sn);
            }
            if (empty($display)) {
                $display = $mail;
            }

            $results[] = ['email' => strtolower(trim($mail)), 'cn' => $display];
        }

        // Trier par nom
        usort($results, function($a, $b) {
            return strcasecmp($a['cn'], $b['cn']);
        });

        return $results;
    });
}

/**
 * Vérifie qu'une adresse email existe via une probe SMTP (RCPT TO).
 *
 * Ouvre une connexion SMTP au serveur configuré, envoie HELO/MAIL FROM/RCPT TO
 * et vérifie si le serveur accepte le destinataire. Se déconnecte proprement
 * sans envoyer de mail (QUIT avant DATA).
 *
 * ⚠️ Attention : certains serveurs SMTP acceptent tous les RCPT TO (catch-all).
 *    Cette méthode est un indicateur, pas une garantie absolue.
 *
 * @param string $email Adresse email à vérifier
 * @return array<string, mixed> ['ok' => bool, 'method' => string, 'detail' => string]
 */
function verify_email_smtp(string $email): array {
    $smtp_host = get_setting('smtp_host');
    $smtp_port = (int)get_setting('smtp_port');
    $smtp_from = get_setting('smtp_from');
    $smtp_secure = get_setting('smtp_secure', '');

    if (empty($smtp_host)) {
        return ['ok' => false, 'method' => 'smtp', 'detail' => 'Aucun serveur SMTP configuré'];
    }

    // Vérifier que l'extension sockets est disponible
    if (!function_exists('fsockopen')) {
        return ['ok' => false, 'method' => 'smtp', 'detail' => 'Extension PHP sockets non disponible'];
    }

    // Connexion SMTP avec timeout
    $timeout = 10;
    $errno = 0;
    $errstr = '';

    // Pour TLS, on se connecte d'abord en clair puis on fait STARTTLS
    $conn = @fsockopen($smtp_host, $smtp_port, $errno, $errstr, $timeout);
    if (!$conn) {
        return ['ok' => false, 'method' => 'smtp', 'detail' => "Impossible de se connecter à $smtp_host:$smtp_port ($errstr)"];
    }

    stream_set_timeout($conn, $timeout);

    // Fonction utilitaire pour lire une réponse SMTP
    $read_smtp = function() use ($conn): string {
        $response = '';
        while ($line = fgets($conn, 512)) {
            $response .= $line;
            // Les réponses multilignes ont un '-' après le code, la dernière ligne a un espace
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $response;
    };

    // Fonction utilitaire pour envoyer une commande SMTP
    $send_smtp = function(string $cmd) use ($conn): void {
        fwrite($conn, $cmd . "\r\n");
    };

    // Bannière de bienvenue
    $banner = $read_smtp();
    if (!str_starts_with($banner, '220')) {
        fclose($conn);
        return ['ok' => false, 'method' => 'smtp', 'detail' => 'Bannière SMTP invalide : ' . trim($banner)];
    }

    // HELO — Sécurité : assainir le hostname pour prévenir l'injection CRLF SMTP
    $helo_host = preg_replace('/[^a-zA-Z0-9.\-]/', '', (string)gethostname());
    if (empty($helo_host)) $helo_host = 'localhost';
    $send_smtp('HELO ' . $helo_host);
    $resp = $read_smtp();
    if (!str_starts_with($resp, '250')) {
        fclose($conn);
        return ['ok' => false, 'method' => 'smtp', 'detail' => 'HELO rejeté : ' . trim($resp)];
    }

    // STARTTLS si configuré
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
        // Retour au HELO après STARTTLS — Sécurité : assainir comme pour HELO
        $send_smtp('EHLO ' . $helo_host);
        $resp = $read_smtp();
        if (!str_starts_with($resp, '250')) {
            fclose($conn);
            return ['ok' => false, 'method' => 'smtp', 'detail' => 'EHLO après STARTTLS rejeté : ' . trim($resp)];
        }
    }

    // MAIL FROM — Sécurité : assainir pour prévenir l'injection CRLF SMTP
    $safe_smtp_from = str_replace(["\r", "\n", "\t"], '', $smtp_from);
    $send_smtp('MAIL FROM:<' . $safe_smtp_from . '>');
    $resp = $read_smtp();
    if (!str_starts_with($resp, '250')) {
        $send_smtp('QUIT');
        $read_smtp();
        fclose($conn);
        return ['ok' => false, 'method' => 'smtp', 'detail' => 'MAIL FROM rejeté : ' . trim($resp)];
    }

    // RCPT TO — la vérification clé
    // Sécurité : s'assurer que l'email ne contient pas de CR/LF (injection SMTP)
    $safe_email = str_replace(["\r", "\n", "\t"], '', $email);
    $send_smtp('RCPT TO:<' . $safe_email . '>');
    $resp = $read_smtp();

    // QUIT proprement
    $send_smtp('QUIT');
    $read_smtp();
    fclose($conn);

    $code = substr($resp, 0, 3);
    if ($code === '250') {
        return ['ok' => true, 'method' => 'smtp', 'detail' => 'Adresse acceptée par le serveur SMTP'];
    }

    if ($code === '251') {
        // 251 = User not local; will forward to <forward-path>
        return ['ok' => true, 'method' => 'smtp', 'detail' => 'Adresse acceptée (transfert) par le serveur SMTP'];
    }

    return ['ok' => false, 'method' => 'smtp', 'detail' => 'Adresse rejetée par le serveur SMTP : ' . trim($resp)];
}

/**
 * Vérifie une adresse email selon le mode configuré (LDAP, SMTP ou aucun).
 *
 * @param string $email Adresse email à vérifier
 * @return array<string, mixed> ['ok' => bool, 'method' => string, 'detail' => string]
 */
function verify_email(string $email): array {
    $mode = get_setting('email_verify_mode', 'none');

    // Validation basique du format email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'method' => 'format', 'detail' => 'Format d\'email invalide : ' . $email];
    }

    if ($mode === 'none') {
        return ['ok' => true, 'method' => 'none', 'detail' => 'Aucune vérification configurée'];
    }

    if ($mode === 'ldap') {
        return verify_email_ldap($email);
    }

    if ($mode === 'smtp') {
        return verify_email_smtp($email);
    }

    // Mode inconnu = pas de vérification
    return ['ok' => true, 'method' => 'none', 'detail' => 'Mode de vérification inconnu : ' . $mode];
}

/**
 * Teste la vérification email avec une adresse donnée (pour la page admin).
 * Retourne le résultat détaillé pour affichage.
 * @return array<string, mixed>
 */
function test_email_verification(string $email): array {
    $mode = get_setting('email_verify_mode', 'none');

    $results = [
        'email' => $email,
        'mode'  => $mode,
        'format_valid' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
    ];

    if ($mode === 'ldap') {
        $results['ldap'] = verify_email_ldap($email);
    } elseif ($mode === 'smtp') {
        $results['smtp'] = verify_email_smtp($email);
    } elseif ($mode === 'both') {
        // Mode both : LDAP en priorité, SMTP en fallback
        $ldap_result = verify_email_ldap($email);
        $results['ldap'] = $ldap_result;
        if (!$ldap_result['ok']) {
            $results['smtp'] = verify_email_smtp($email);
        }
    }

    $results['verify'] = verify_email($email);
    return $results;
}
