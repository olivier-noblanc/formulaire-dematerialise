<?php
/**
 * tests/test_unit_helpers.php — Helpers partagés pour test_unit.php
 *
 * Fournit les fonctions utilitaires utilisées par les sections Wave 4-9 :
 *   - _extract_function_body()  : extrait le corps d'une fonction depuis helpers.php
 *   - _find_function_in_libs()  : parcourt helpers.php + lib_*.php pour trouver
 *                                  le corps d'une fonction (robuste au refactoring)
 *   - _run_http_subprocess()    : exécute une page PHP via subprocess CLI pour
 *                                  vérifier son rendu HTTP réel (smoke test runtime)
 *
 * Ces helpers sont chargés une seule fois par test_unit.php (require_once) puis
 * utilisés par les modules tests/test_unit_wave*.php.
 *
 * Note : les chemins __DIR__ pointent vers le dossier du projet (parent de tests/)
 * car les fichiers inspectés (helpers.php, lib_*.php, pages PHP) sont à la racine.
 */

declare(strict_types=1);

/**
 * Helper local : extrait le corps d'une fonction depuis helpers.php.
 */
function _extract_function_body(string $func_name): string {
    $code = file_get_contents(dirname(__DIR__) . '/helpers.php');
    $needle = "function {$func_name}(";
    $start = strpos($code, $needle);
    if ($start === false) return '';
    // Trouver l'accolade ouvrante
    $brace_start = strpos($code, '{', $start);
    // Trouver l'accolade fermante correspondante
    $depth = 0;
    $i = $brace_start;
    while ($i < strlen($code)) {
        if ($code[$i] === '{') $depth++;
        elseif ($code[$i] === '}') {
            $depth--;
            if ($depth === 0) return substr($code, $brace_start, $i - $brace_start + 1);
        }
        $i++;
    }
    return '';
}

/**
 * Helper S4-TESTS / Action 9 : parcourt helpers.php + lib_*.php pour trouver
 * le corps d'une fonction. Robuste au refactoring (extraction vers lib_*.php).
 *
 * Les tests 12.12 (security_log), 12.13 (send_security_headers) et 15.3
 * (get_delegations) inspectaient le code source de helpers.php directement.
 * Quand on extrait des fonctions vers lib_*.php, ces tests cassaient. Ce
 * helper parcourt tous les fichiers PHP candidats et retourne le corps de
 * la fonction où qu'elle soit définie.
 *
 * @param string $function_name Nom exact de la fonction (sans les parenthèses)
 * @return string Corps de la fonction (incluant les accolades {}), ou '' si introuvable
 */
function _find_function_in_libs(string $function_name): string {
    // Liste des fichiers à parcourir : helpers.php + tous les lib_*.php du dépôt.
    // helpers.php en premier car c'est le fichier principal (cas nominal), lib_*.php
    // en complément pour le cas où la fonction aurait été extraite.
    $files = array_merge([dirname(__DIR__) . '/helpers.php'], glob(dirname(__DIR__) . '/lib_*.php'));
    $needle = "function {$function_name}(";
    foreach ($files as $file) {
        if (!is_file($file)) continue;
        $code = @file_get_contents($file);
        if ($code === false) continue;
        $start = strpos($code, $needle);
        if ($start === false) continue;
        // Trouver l'accolade ouvrante de la fonction
        $brace_start = strpos($code, '{', $start);
        if ($brace_start === false) continue;
        // Trouver l'accolade fermante correspondante (comptage de profondeur)
        $depth = 0;
        $len = strlen($code);
        for ($i = $brace_start; $i < $len; $i++) {
            if ($code[$i] === '{') $depth++;
            elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) return substr($code, $brace_start, $i - $brace_start + 1);
            }
        }
    }
    return '';
}

/**
 * Helper S4-TESTS / Action 7 : exécute une page PHP via subprocess CLI pour
 * vérifier son rendu HTTP réel (smoke test runtime). Retourne un tableau
 * de marqueurs KEY=VALUE (OUTPUT_LEN, HAS_*, HTTP_RESPONSE_CODE, etc.).
 *
 * @param string $page  Chemin absolu de la page PHP à exécuter
 * @param string $user  Email utilisateur pour X-Test-User
 * @param array  $get   Tableau clé/valeur pour $_GET
 * @return array Marqueurs parsés depuis la sortie du subprocess
 */
function _run_http_subprocess(string $page, string $user, array $get = []): array {
    $session_dir = sys_get_temp_dir() . '/php-sessions';
    @mkdir($session_dir, 0777, true);
    $ini = php_ini_loaded_file();
    $php_cmd = PHP_BINARY
        . ($ini ? ' -c ' . escapeshellarg($ini) : '')
        . ' -d session.save_path=' . escapeshellarg($session_dir);
    $target_path = escapeshellarg($page);
    $script = sys_get_temp_dir() . '/test_rt_s4_' . uniqid() . '.php';
    // Construire les lignes $_GET
    $get_lines = '';
    foreach ($get as $k => $v) {
        $esc_v = addcslashes((string)$v, "'\\");
        $get_lines .= "\$_GET['{$k}'] = '{$esc_v}';\n";
    }
    $request_uri = '/' . basename($page);
    $code = <<<PHP
<?php
\$_SERVER['HTTP_X_TEST_MODE'] = '1';
\$_SERVER['HTTP_X_TEST_USER'] = '{$user}';
\$_SERVER['HTTP_HOST'] = 'localhost';
\$_SERVER['HTTPS'] = '';
\$_SERVER['REQUEST_URI'] = '{$request_uri}';
\$_SERVER['REQUEST_METHOD'] = 'GET';
\$_SERVER['SCRIPT_NAME'] = 'health.php';
\$_SERVER['SCRIPT_FILENAME'] = 'health.php';
{$get_lines}
error_reporting(E_ALL & ~E_WARNING);
ini_set('display_errors', 1);
ob_start();
register_shutdown_function(function() {
    \$out = ob_get_clean();
    echo 'OUTPUT_LEN=' . strlen(\$out) . "\n";
    echo 'HAS_DOCTYPE=' . (strpos(\$out, 'DOCTYPE') !== false ? '1' : '0') . "\n";
    echo 'HAS_FATAL=' . (strpos(\$out, 'Fatal error') !== false ? '1' : '0') . "\n";
    echo 'HAS_PARSE_ERROR=' . (strpos(\$out, 'Parse error') !== false ? '1' : '0') . "\n";
    echo 'HAS_PDOEXCEPTION=' . (strpos(\$out, 'PDOException') !== false ? '1' : '0') . "\n";
    echo 'HAS_CE_SCRIPT=' . (strpos(\$out, 'Ce script ne peut') !== false ? '1' : '0') . "\n";
    echo 'HAS_NO_SUCH_TABLE=' . (strpos(\$out, 'no such table') !== false ? '1' : '0') . "\n";
    echo 'HAS_NO_SUCH_COLUMN=' . (strpos(\$out, 'no such column') !== false ? '1' : '0') . "\n";
    echo 'HTTP_RESPONSE_CODE=' . http_response_code() . "\n";
    // Marqueur brut pour que le test puisse chercher des chaînes spécifiques dans la sortie
    echo 'OUTPUT_BASE64=' . base64_encode(\$out) . "\n";
});
try {
    require {$target_path};
} catch (\Throwable \$e) {
    echo 'EXCEPTION=' . str_replace(["\n", "\r"], ' ', \$e->getMessage()) . "\n";
}
PHP;
    file_put_contents($script, $code);
    $env = 'APP_TEST_MODE=1 APP_TEST_SECRET=test';
    $output = shell_exec("env $env $php_cmd " . escapeshellarg($script) . " 2>&1");
    @unlink($script);
    $markers = [];
    foreach (explode("\n", $output ?? '') as $line) {
        if (strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $markers[$k] = $v;
    }
    return $markers;
}
