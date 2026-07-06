<?php
declare(strict_types=1);
/**
 * _subprocess_helper.php — Helper partagé pour les tests de non-régression
 * qui nécessitent d'invoquer les contrôleurs en sous-processus PHP avec
 * TEST_MODE désactivé (pour tester le HTML rendu, pas le JSON court-circuité).
 *
 * Pourquoi un sous-processus ? TEST_MODE est défini une fois pour toutes dans
 * lib/core_bootstrap.php via `define('TEST_MODE', ...)`. Une fois défini, on
 * ne peut plus le changer dans le même processus. On lance donc un processus
 * PHP séparé qui neutralise les déclencheurs de TEST_MODE avant tout require.
 *
 * Usage depuis un test de non-régression :
 *
 *   require_once __DIR__ . '/_subprocess_helper.php';
 *   $result = run_regression_subprocess(function(string $projectRoot) {
 *       // Corps du script exécuté dans le sous-processus.
 *       // $projectRoot est passé en argument.
 *       require_once $projectRoot . '/helpers.php';
 *       $_SERVER['REQUEST_METHOD'] = 'GET';
 *       $_SERVER['QUERY_STRING']   = 'f=onboarding';
 *       parse_str($_SERVER['QUERY_STRING'], $_GET);
 *       $controller = new App\Controller\FormController();
 *       $controller->handle();
 *   });
 *   // $result['stdout'] contient le HTML rendu
 *   // $result['stderr'] contient les warnings/notices PHP
 *   // $result['exit_code'] est le code de sortie
 *
 * @package tests\regression
 */

if (!function_exists('run_regression_subprocess')) {
    /**
     * Lance un sous-processus PHP qui exécute le callable fourni avec
     * TEST_MODE désactivé.
     *
     * Le callable reçoit le chemin absolu de la racine du projet en paramètre.
     * Il doit effectuer les require_once nécessaires et produire sa sortie
     * (echo/ob_end) sur stdout.
     *
     * @param callable(string):void $fn   Corps du script exécuté en sous-processus
     * @param array<string,mixed>   $env  Variables $_SERVER additionnelles à injecter
     *                                    (ex: ['AUTH_USER' => 'DREETS\admin'])
     * @return array{stdout:string, stderr:string, exit_code:int}
     */
    function run_regression_subprocess(callable $fn, array $env = []): array {
        // Récupère le code source du callable via la réflexion — on ne peut
        // pas vraiment sérialiser un closure, donc on extrait son corps via
        // un fichier temporaire qui l'appelle.
        //
        // En pratique, on sérialise le callable en l'écrivant dans un fichier
        // temporaire. Pour rester simple, on exige que $fn soit une closure
        // dont le corps est un script PHP valide qu'on extrait via Reflection.
        //
        // APPROCHE PLUS SIMPLE : on exige que le callable soit défini comme
        // une closure qui écrit son propre script. On fournit donc plutôt
        // une fonction qui prend une chaîne (corps de script) en paramètre.
        throw new \LogicException('Use run_regression_script() instead — closures cannot be serialized across processes.');
    }

    /**
     * Lance un sous-processus PHP qui exécute le script fourni avec
     * TEST_MODE désactivé.
     *
     * Le script reçoit en $argv[1] le chemin absolu de la racine du projet.
     * Il doit effectuer les require_once nécessaires et produire sa sortie
     * sur stdout. Les warnings PHP vont sur stderr.
     *
     * @param string              $scriptBody  Corps du script PHP SANS la balise `<?php` ouvrante
     * @param array<string,mixed> $serverVars  Variables $_SERVER additionnelles
     *                                         (ex: ['AUTH_USER' => 'DREETS\admin'])
     * @param string              $stdin       Données à envoyer sur stdin (POST body, etc.)
     * @return array{stdout:string, stderr:string, exit_code:int}
     */
    function run_regression_script(string $scriptBody, array $serverVars = [], string $stdin = ''): array {
        $project_root = dirname(__DIR__, 2);

        // Construire le script complet : neutralise TEST_MODE puis appelle le
        // corps fourni par l'appelant.
        $env_assignments = '';
        foreach ($serverVars as $k => $v) {
            $env_assignments .= sprintf(
                "\$_SERVER[%s] = %s;\n",
                var_export($k, true),
                var_export((string)$v, true)
            );
        }

        $script = '<?php
// ═══ Test de non-régression : sous-processus avec TEST_MODE désactivé ═══
// Neutraliser tous les déclencheurs de TEST_MODE AVANT tout require.
putenv("APP_TEST_MODE=");            // pas de variable d\'env
unset($_SERVER["HTTP_X_TEST_MODE"]); // pas de header HTTP
unset($_SERVER["HTTP_X_TEST_USER"]); // pas d\'utilisateur test
unset($_SERVER["APP_TEST_SECRET"]);

// Variables minimales pour simulateur IIS/Kerberos + CLI PHP
$_SERVER["HTTP_HOST"]   = "localhost";
$_SERVER["HTTPS"]       = "";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";

// Variables spécifiques injectées par l\'appelant
' . $env_assignments . '

// Peupler $_GET depuis QUERY_STRING (en CLI, PHP ne le fait pas automatiquement)
if (!empty($_SERVER["QUERY_STRING"])) {
    parse_str($_SERVER["QUERY_STRING"], $_GET);
}

// Peupler $_POST depuis stdin si CONTENT_TYPE est form-urlencoded
if (($_SERVER["REQUEST_METHOD"] ?? "") === "POST"
    && (($_SERVER["CONTENT_TYPE"] ?? "") === "application/x-www-form-urlencoded")
) {
    $raw = stream_get_contents(STDIN);
    parse_str($raw, $_POST);
}

// Chemin racine du projet passé en argv
$project_root = $argv[1] ?? ' . var_export($project_root, true) . ';

// ═══ Corps du script fourni par le test de non-régression ═══
' . $scriptBody . '
';

        // Écrire le script dans un fichier temporaire
        $tmp = tempnam(sys_get_temp_dir(), 'regression_') . '.php';
        file_put_contents($tmp, $script);

        // Lancer le sous-processus en capturant stdout ET stderr séparément
        $cmd = 'php ' . escapeshellarg($tmp) . ' ' . escapeshellarg($project_root);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            @unlink($tmp);
            return ['stdout' => '', 'stderr' => 'proc_open failed', 'exit_code' => -1];
        }
        // Envoyer stdin
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit_code = proc_close($proc);
        @unlink($tmp);

        return [
            'stdout'     => $stdout,
            'stderr'     => $stderr,
            'exit_code'  => $exit_code,
        ];
    }
}
