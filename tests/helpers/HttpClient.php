<?php
declare(strict_types=1);

/**
 * tests/helpers/HttpClient.php — Helper d'invocation de routes en sous-processus.
 *
 * Le mode TEST_MODE de l'application (header HTTP_X_TEST_MODE) court-circuite
 * le rendu HTML et renvoie du JSON. Pour tester le HTML rendu, on doit :
 *   1. Désactiver TEST_MODE (neutraliser APP_TEST_MODE env + header)
 *   2. Exécuter le contrôleur dans un sous-processus PHP (capture ob_start)
 *   3. Capturer stdout (HTML) et stderr (warnings PHP) séparément
 *
 * Ce helper fournit une API simple :
 *   HttpClient::renderRoute('GET', '/form.php?f=onboarding')
 *     → ['html' => '...', 'stderr' => '...', 'exit_code' => 0]
 *   HttpClient::getCsrfToken($html)
 *     → 'abcdef0123...'
 *   HttpClient::assertResponseStatus($html, 200)
 *
 * Pour les routes admin, passer `isAdmin: true` → le sous-processus injecte
 * `$_SERVER['AUTH_USER'] = 'DREETS\admin.local'` (admin en DB de test).
 *
 * Usage :
 *   require_once __DIR__ . '/HttpClient.php';
 *   $r = HttpClient::renderRoute('GET', '/index.php?p=admin_settings', [], true);
 *   $doc = DomAssertions::fromHtml($r['html']);
 *   DomAssertions::assertTitleNonEmpty($doc);
 */

/**
 * Helper statique d'invocation de routes en sous-processus.
 */
final class HttpClient
{
    /**
     * Utilisateur admin de référence dans la DB de test.
     * (présent dans la table `admins` de workflow.db ET workflow_test.db)
     */
    private const ADMIN_AUTH_USER = 'DREETS\admin.local';

    /**
     * Utilisateur non-admin de référence pour les routes publiques.
     */
    private const REGULAR_AUTH_USER = 'DREETS\testeur';

    /**
     * Invoque une route dans un sous-processus PHP, SANS TEST_MODE, et capture
     * le HTML rendu + stderr (warnings PHP).
     *
     * @param string $method 'GET' ou 'POST'
     * @param string $path   Chemin absolu depuis la racine web, ex: '/form.php?f=onboarding'
     *                       (la query string est extraite automatiquement)
     * @param array<string,string> $post Données POST (pour method='POST')
     * @param bool $isAdmin  Si true → injecte AUTH_USER admin (DREETS\admin.local)
     * @return array{html:string, stderr:string, exit_code:int}
     */
    public static function renderRoute(
        string $method,
        string $path,
        array $post = [],
        bool $isAdmin = false
    ): array {
        $projectRoot = dirname(__DIR__, 2); // tests/helpers/ → project root

        // Séparer le path de la query string
        $pathOnly = $path;
        $queryString = '';
        $qPos = strpos($path, '?');
        if ($qPos !== false) {
            $pathOnly = substr($path, 0, $qPos);
            $queryString = substr($path, $qPos + 1);
        }

        // Vérifier que le script existe (sinon message d'erreur clair)
        $scriptFile = ltrim($pathOnly, '/');
        $scriptPath = $projectRoot . '/' . $scriptFile;
        if (!is_file($scriptPath)) {
            return [
                'html' => '',
                'stderr' => "ERREUR HttpClient: fichier introuvable: {$scriptPath}\n",
                'exit_code' => 1,
            ];
        }

        // Construire le script subprocess
        $authUser = $isAdmin ? self::ADMIN_AUTH_USER : self::REGULAR_AUTH_USER;
        $methodUpper = strtoupper($method);

        // Données POST encodées en query string (seront injectées via stdin)
        $postBody = '';
        if ($methodUpper === 'POST' && !empty($post)) {
            $postBody = http_build_query($post);
        }

        // Script subprocess — écrit dans un fichier temporaire puis exécuté
        $script = self::buildSubprocessScript();

        $tmp = tempnam(sys_get_temp_dir(), 'httpclient_') . '.php';
        file_put_contents($tmp, $script);

        // Variables à injecter (encodées en base64 pour passer en CLI safety)
        $injected = base64_encode(serialize([
            'REQUEST_METHOD' => $methodUpper,
            'REQUEST_URI' => $path,
            'QUERY_STRING' => $queryString,
            'CONTENT_TYPE' => $methodUpper === 'POST' ? 'application/x-www-form-urlencoded' : '',
            'CONTENT_LENGTH' => $methodUpper === 'POST' ? (string) strlen($postBody) : '0',
        ]));

        // Commande : on passe (1) vars injectées, (2) project root, (3) AUTH_USER,
        // (4) script à inclure. Les données POST passent par stdin (pas la CLI).
        // Rappel : dans le sous-processus, $argv[0] = chemin du script temporaire,
        // donc nos arguments utiles commencent à $argv[1].
        // v10.0.1 — utilisation de PHP_BINARY avec -c si PHP_TEST_INI est défini
        // (permet aux tests de fonctionner avec extensions pdo_sqlite/mbstring
        // même si le php par défaut du PATH ne les a pas)
        $phpBin = getenv('PHP_TEST_BIN') ?: 'php';
        $phpIni = getenv('PHP_TEST_INI') ?: '';
        $phpCmd = $phpBin . ($phpIni !== '' ? ' -c ' . escapeshellarg($phpIni) : '');
        $cmd = sprintf(
            '%s %s %s %s %s %s',
            $phpCmd,
            escapeshellarg($tmp),          // $argv[0]
            escapeshellarg($injected),     // $argv[1] = vars $_SERVER
            escapeshellarg($projectRoot),  // $argv[2] = project root
            escapeshellarg($authUser),     // $argv[3] = AUTH_USER
            escapeshellarg($scriptFile)    // $argv[4] = script à inclure
        );

        // Descripteurs : stdin toujours ouvert (pour POST body ou fermé si GET)
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            @unlink($tmp);
            return [
                'html' => '',
                'stderr' => "ERREUR HttpClient: impossible de lancer le sous-processus.\n",
                'exit_code' => 1,
            ];
        }

        // Écrire les données POST sur stdin (ou fermer stdin immédiatement si GET)
        if ($methodUpper === 'POST' && $postBody !== '') {
            fwrite($pipes[0], $postBody);
        }
        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        @unlink($tmp);

        return [
            'html' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $exitCode,
        ];
    }

    /**
     * Extrait le token CSRF d'un HTML rendu.
     *
     * @param string $html HTML contenant potentiellement <input name="csrf_token" value="...">
     * @return string Le token (64 hex) ou '' si absent.
     */
    public static function getCsrfToken(string $html): string
    {
        // Approche 2 passes : d'abord on isole le tag <input name="csrf_token" ...>
        // puis on extrait l'attribut value, quel que soit l'ordre des attributs.
        if (!preg_match('/<input\b[^>]*\bname="csrf_token"[^>]*>/i', $html, $tagMatch)) {
            // Variante : value avant name (rare mais possible)
            if (!preg_match('/<input\b[^>]*\bvalue="([a-f0-9]+)"[^>]*\bname="csrf_token"[^>]*>/i', $html, $tagMatch)) {
                return '';
            }
            return $tagMatch[1];
        }
        $tag = $tagMatch[0];
        if (preg_match('/\bvalue="([a-f0-9]+)"/i', $tag, $valMatch)) {
            return $valMatch[1];
        }
        return '';
    }

    /**
     * Vérifie que la réponse HTTP implicite correspond au code attendu.
     *
     * En CLI, il n'y a pas de vrai code HTTP, mais on peut détecter :
     *   - 200 : HTML normal (pas d'erreur visible, pas de "404", pas de "403")
     *   - 403 : page "Accès refusé" (require_admin a échoué)
     *   - 404 : page "404 Not Found"
     *   - 500 : page d'erreur interne ou exception
     *
     * @param string $html HTML rendu
     * @param int $expectedCode Code attendu (défaut: 200)
     * @throws AssertionError Si le code détecté ne correspond pas.
     */
    public static function assertResponseStatus(string $html, int $expectedCode = 200): void
    {
        $detected = self::detectStatusFromHtml($html);
        if ($detected !== $expectedCode) {
            throw new AssertionError(sprintf(
                "Status HTTP attendu: %d, détecté: %d (HTML: %s)",
                $expectedCode,
                $detected,
                mb_substr($html, 0, 200)
            ));
        }
    }

    /**
     * Détecte le code de statut HTTP à partir du HTML rendu.
     *
     * Heuristique simple : recherche de patterns textuels.
     *
     * @param string $html
     * @return int
     */
    private static function detectStatusFromHtml(string $html): int
    {
        if ($html === '') {
            return 0; // Réponse vide (probablement un redirect)
        }
        // 500 : page d'erreur interne
        if (preg_match('/<h1[^>]*>\s*(Erreur interne|500)/i', $html)) {
            return 500;
        }
        if (str_contains($html, '__EXCEPTION__:')) {
            return 500;
        }
        // 403 : accès refusé
        if (preg_match('/<h1[^>]*>\s*(Accès refusé|403)/i', $html)) {
            return 403;
        }
        // 404 : non trouvé
        if (preg_match('/<h1[^>]*>\s*(404|Not Found)/i', $html)) {
            return 404;
        }
        // Défaut : 200 OK
        return 200;
    }

    /**
     * Construit le script PHP exécuté en sous-processus.
     *
     * Le script :
     *   1. Neutralise TEST_MODE (env + header)
     *   2. Définit $_SERVER minimal (AUTH_USER, HTTP_HOST, REMOTE_ADDR, etc.)
     *   3. Restaure les variables injectées (REQUEST_METHOD, REQUEST_URI, ...)
     *   4. Peuple $_GET depuis QUERY_STRING
     *   5. Charge helpers.php (qui charge core_bootstrap + services)
     *   6. Lit $_POST depuis stdin si method=POST
     *   7. Capture le rendu via ob_start
     *   8. Inclut le script de la route
     *
     * @return string Code PHP du sous-processus.
     */
    private static function buildSubprocessScript(): string
    {
        return <<<'PHP'
<?php
// ═══════════════════════════════════════════════════════════════
// SOUS-PROCESSUS — rendu HTML d'une route SANS TEST_MODE
// ═══════════════════════════════════════════════════════════════

// 1. Neutraliser TEST_MODE
putenv('APP_TEST_MODE=');
unset($_SERVER['HTTP_X_TEST_MODE']);
unset($_SERVER['HTTP_X_TEST_USER']);

// 2. $_SERVER minimal
$_SERVER['HTTP_HOST']     = 'localhost';
$_SERVER['HTTPS']         = '';
$_SERVER['REMOTE_ADDR']   = '127.0.0.1';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['SERVER_NAME']   = 'localhost';
$_SERVER['SERVER_PORT']   = '80';
$_SERVER['SERVER_SOFTWARE'] = 'PHP/'.PHP_VERSION.' HttpClient (test)';
$_SERVER['GATEWAY_INTERFACE'] = 'CGI/1.1';
$_SERVER['SCRIPT_NAME']   = '/' . $argv[4];
$_SERVER['PHP_SELF']      = '/' . $argv[4];
$_SERVER['SCRIPT_FILENAME'] = $argv[2] . '/' . $argv[4];

// 3. Restaurer les variables injectées (REQUEST_METHOD, REQUEST_URI, ...)
$injected = unserialize(base64_decode($argv[1]));
if (is_array($injected)) {
    foreach ($injected as $k => $v) {
        $_SERVER[$k] = $v;
    }
}

// 4. AUTH_USER injecté par HttpClient (admin ou regular)
$_SERVER['AUTH_USER'] = $argv[3];

// 5. Peupler $_GET depuis QUERY_STRING
if (!empty($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $_GET);
}

// 6. Lire $_POST depuis stdin si method=POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $post_body = stream_get_contents(STDIN);
    if ($post_body !== '' && $post_body !== false) {
        parse_str($post_body, $_POST);
    }
}

// 7. Charger helpers.php (provoque le chargement de core_bootstrap + services)
require_once $argv[2] . '/helpers.php';

// 8. Capturer le rendu de la route
$script_path = $argv[2] . '/' . $argv[4];
if (!is_file($script_path)) {
    fwrite(STDERR, "ERREUR HttpClient: fichier introuvable: {$script_path}\n");
    exit(1);
}

ob_start();
try {
    require $script_path;
} catch (\Throwable $e) {
    // Capture les exceptions non gérées par le set_exception_handler
    $buf = ob_get_clean();
    echo $buf;
    fwrite(STDERR, "UNCAUGHT EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
$out = ob_get_clean();
echo $out;
PHP;
    }
}

// ─── Mode autonome : si on lance ce fichier directement, smoke test ─
if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    fwrite(STDOUT, "HttpClient smoke test — OK (classe chargée).\n");

    // Mini self-test : getCsrfToken
    $html = '<form><input type="hidden" name="csrf_token" value="abc123def456"></form>';
    $token = HttpClient::getCsrfToken($html);
    if ($token !== 'abc123def456') {
        fwrite(STDERR, "Self-test getCsrfToken échec : obtenu '{$token}'\n");
        exit(1);
    }
    fwrite(STDOUT, "Self-test getCsrfToken : OK (token='{$token}')\n");

    // Self-test : getCsrfToken avec ordre inversé des attributs
    // Note : on utilise un vrai hex token ([a-f0-9] uniquement) car la regex
    // ne capture que les caractères hexadécimaux.
    $html2 = '<form><input value="0123abcd" type="hidden" name="csrf_token"></form>';
    $token2 = HttpClient::getCsrfToken($html2);
    if ($token2 !== '0123abcd') {
        fwrite(STDERR, "Self-test getCsrfToken (ordre inversé) échec : obtenu '{$token2}'\n");
        exit(1);
    }
    fwrite(STDOUT, "Self-test getCsrfToken (ordre inversé) : OK (token='{$token2}')\n");

    // Self-test : assertResponseStatus
    try {
        HttpClient::assertResponseStatus('<html><body>OK</body></html>', 200);
        fwrite(STDOUT, "Self-test assertResponseStatus(200) : OK\n");
    } catch (AssertionError $e) {
        fwrite(STDERR, "Self-test assertResponseStatus(200) échec : " . $e->getMessage() . "\n");
        exit(1);
    }

    fwrite(STDOUT, "Tous les self-tests HttpClient sont OK.\n");
    exit(0);
}
