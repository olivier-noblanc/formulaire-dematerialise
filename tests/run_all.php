<?php
declare(strict_types=1);

/**
 * tests/run_all.php — Orchestrateur PHP-only des tests.
 *
 * Exécute, dans l'ordre, avec fail-fast :
 *   1. Lint PHP (php -l) sur tous les fichiers PHP du projet (hors vendor/tests)
 *   2. tests/test_all.php
 *   3. tests/test_form_render_html.php
 *   4. tests/StructuralHtmlTest.php        (si existe — sinon skip avec warning)
 *   5. tests/regression/run_all.php        (si existe — sinon skip)
 *
 * N'exécute PAS Node/Playwright (gardé pour scripts/gate.sh uniquement).
 *
 * Contraintes :
 *   - Couleurs ANSI (vert/rouge/jaune/cyan)
 *   - Capture les warnings/notices PHP émis sur stderr par les sous-processus
 *     et les compte. Un warning = avertissement mais pas échec, SAUF si > 10
 *     (auquel cas on considère que c'est une régression et on échoue).
 *   - Tableau récapitulatif final + exit code (0 = succès, 1 = échec, 2 = dépendance manquante).
 *
 * Usage : php tests/run_all.php
 */

// ─── Constantes ANSI ─────────────────────────────────────────────────────────
define('C_RESET', "\033[0m");
define('C_BOLD',  "\033[1m");
define('C_GREEN', "\033[32m");
define('C_RED',   "\033[31m");
define('C_YELLOW',"\033[33m");
define('C_CYAN',  "\033[36m");

// ─── Helpers d'affichage ─────────────────────────────────────────────────────
function info(string $msg): void  { echo C_CYAN . "[INFO] " . C_RESET . $msg . "\n"; }
function warn(string $msg): void  { echo C_YELLOW . "[WARN] " . C_RESET . $msg . "\n"; }
function err(string $msg): void   { fwrite(STDERR, C_RED . "[ERREUR] " . C_RESET . $msg . "\n"); }
function ok_msg(string $msg): void { echo C_GREEN . "[OK] " . C_RESET . $msg . "\n"; }

// ─── Se positionner à la racine du projet ────────────────────────────────────
$projectRoot = dirname(__DIR__);
chdir($projectRoot);

// ─── Vérifie que PHP est disponible (il l'est forcément, mais pour la forme) ─
// On utilise `php` depuis le PATH (et non PHP_BINARY) car PHP_BINARY peut pointer
// vers un binaire "nu" sans les extensions requises (mbstring, pdo_sqlite) si
// l'utilisateur a un wrapper `php` qui charge un php.ini spécifique via -c.
// En utilisant `php`, on hérite du même environnement que l'appelant.
$phpBin = 'php';
$smokeTest = shell_exec(escapeshellarg($phpBin) . ' -v 2>&1');
if ($smokeTest === null || strpos($smokeTest, 'PHP') === false) {
    // Fallback sur PHP_BINARY si `php` n'est pas dans le PATH
    $phpBin = PHP_BINARY;
}

// ─── Stockage des résultats ──────────────────────────────────────────────────
$results = []; // ['step' => string, 'duration' => float, 'status' => string, 'warnings' => int]

/**
 * Exécute une étape via proc_open et capture stdout + stderr.
 *
 * @param string $name  Nom affiché de l'étape
 * @param string $cmd   Commande à exécuter
 * @return array{status:string,duration:float,warnings:int,output:string}
 */
function run_step_proc(string $name, string $cmd): array {
    $desc = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
    ];
    $start = microtime(true);
    $proc = @proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) {
        return ['status' => 'ÉCHEC', 'duration' => 0.0, 'warnings' => 0,
                'output' => "Impossible de lancer la commande: $cmd"];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $rc = proc_close($proc);
    $duration = microtime(true) - $start;

    // Affiche stdout (le test produit lui-même son propre output)
    if ($stdout !== '' && $stdout !== null) {
        echo $stdout;
        if (!str_ends_with($stdout, "\n")) echo "\n";
    }
    // Compte les warnings/notices PHP dans stdout ET stderr.
    // En CLI avec display_errors=On, les warnings vont sur STDOUT.
    // En mode fallback (PHP_BINARY nu), ils vont sur STDERR.
    // On scanne les deux pour être robuste.
    $warnings = 0;
    $pattern = '/^(?:PHP )?(Warning|Notice|Deprecated):/m';
    if ($stdout !== '' && $stdout !== null) {
        $warnings += preg_match_all($pattern, $stdout) ?: 0;
    }
    if ($stderr !== '' && $stderr !== null) {
        $warnings += preg_match_all($pattern, $stderr) ?: 0;
        // Affiche stderr (en jaune) — utile pour le debug
        if (trim($stderr) !== '') {
            fwrite(STDERR, C_YELLOW . "[stderr] " . C_RESET . $stderr);
            if (!str_ends_with($stderr, "\n")) fwrite(STDERR, "\n");
        }
    }
    return [
        'status'   => $rc === 0 ? 'OK' : 'ÉCHEC',
        'duration' => $duration,
        'warnings' => $warnings,
        'output'   => '',
    ];
}

/**
 * Compte les warnings dans le retour d'une étape.
 */
function add_result(string $name, array $res): void {
    global $results;
    $results[] = ['step' => $name] + $res;
}

/**
 * Affiche le récapitulatif final et retourne le code de sortie.
 */
function print_summary(): int {
    global $results;
    echo "\n" . C_BOLD . str_repeat('=', 78) . C_RESET . "\n";
    echo C_BOLD . "  RÉCAPITULATIF — run_all.php" . C_RESET . "\n";
    echo C_BOLD . str_repeat('=', 78) . C_RESET . "\n";
    printf("  %-50s | %-9s | %-8s | %s\n", "ÉTAPE", "DURÉE", "STATUT", "WARN");
    echo "  " . str_repeat('-', 50) . "+-----------+----------+------\n";

    $any_fail = false;
    $total_warnings = 0;
    foreach ($results as $r) {
        $color = C_GREEN;
        if ($r['status'] === 'ÉCHEC') { $color = C_RED; $any_fail = true; }
        elseif ($r['status'] === 'SKIP') { $color = C_YELLOW; }
        $dur = sprintf('%.1fs', $r['duration']);
        printf("  %-50s | %-9s | %s%-8s%s | %d\n",
            mb_substr($r['step'], 0, 50),
            $dur,
            $color, $r['status'], C_RESET,
            $r['warnings']);
        $total_warnings += $r['warnings'];
    }
    echo "  " . str_repeat('-', 50) . "+-----------+----------+------\n";
    echo "  Total warnings/notices PHP capturés : $total_warnings\n";

    if ($any_fail) {
        echo C_BOLD . C_RED . "  ORCHESTRATEUR : ÉCHEC" . C_RESET . "\n";
        return 1;
    }
    if ($total_warnings > 10) {
        echo C_BOLD . C_RED . "  ORCHESTRATEUR : ÉCHEC ($total_warnings warnings > seuil de 10)" . C_RESET . "\n";
        return 1;
    }
    if ($total_warnings > 0) {
        echo C_BOLD . C_YELLOW . "  ORCHESTRATEUR : SUCCÈS avec $total_warnings warning(s) (sous le seuil de 10)" . C_RESET . "\n";
    } else {
        echo C_BOLD . C_GREEN . "  ORCHESTRATEUR : SUCCÈS" . C_RESET . "\n";
    }
    echo C_BOLD . str_repeat('=', 78) . C_RESET . "\n";
    return 0;
}

// ═══════════════════════════════════════════════════════════════════════════
// ÉTAPE 1 — Lint PHP sur tous les fichiers
// ═══════════════════════════════════════════════════════════════════════════
echo "\n" . C_BOLD . "--- 1. Lint PHP (php -l sur tous les fichiers) ---" . C_RESET . "\n";

$phpFiles = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    $p = $f->getPathname();
    // Exclusions cohérentes avec scripts/audit_undefined.php :
    //  - /vendor/    : dépendances Composer (PHPMailer)
    //  - /node_modules/ : dépendances JS
    //  - /tests/     : fichiers de test (validés par les étapes 2-5, pas par le lint)
    if (preg_match('#/vendor/#', $p) || preg_match('#\\\\vendor\\\\#', $p)) continue;
    if (preg_match('#/node_modules/#', $p)) continue;
    if (preg_match('#/tests/#', $p) || preg_match('#\\\\tests\\\\#', $p)) continue;
    $phpFiles[] = $p;
}
sort($phpFiles);

info("Lint PHP : " . count($phpFiles) . " fichier(s) à vérifier…");

$lintErrors = 0;
$lintStart = microtime(true);
$lintStderr = '';
foreach ($phpFiles as $f) {
    $out = shell_exec(escapeshellarg($phpBin) . ' -l ' . escapeshellarg($f) . ' 2>&1');
    if ($out === null || strpos($out, 'No syntax errors') === false) {
        err("Lint échoué sur : $f");
        fwrite(STDERR, $out . "\n");
        $lintErrors++;
    }
}
$lintDuration = microtime(true) - $lintStart;

if ($lintErrors > 0) {
    err("Lint PHP : $lintErrors erreur(s) de syntaxe détectée(s).");
    add_result('1. Lint PHP (php -l)', [
        'status' => 'ÉCHEC', 'duration' => $lintDuration, 'warnings' => 0, 'output' => ''
    ]);
    // Fail-fast
    $rc = print_summary();
    err("Orchestrateur interrompu par fail-fast à l'étape : Lint PHP");
    exit($rc);
}
ok_msg("Lint PHP : tous les " . count($phpFiles) . " fichiers sont syntaxiquement valides (" . sprintf('%.1f', $lintDuration) . "s)");
add_result('1. Lint PHP (php -l)', [
    'status' => 'OK', 'duration' => $lintDuration, 'warnings' => 0, 'output' => ''
]);

// ═══════════════════════════════════════════════════════════════════════════
// Helper pour exécuter une étape de test
// ═══════════════════════════════════════════════════════════════════════════
function run_test_step(string $name, string $relativePath): void {
    global $phpBin;
    echo "\n" . C_BOLD . "--- $name ---" . C_RESET . "\n";

    if (!file_exists($relativePath)) {
        warn("Fichier introuvable : $relativePath — étape skippée.");
        add_result($name, [
            'status' => 'SKIP', 'duration' => 0.0, 'warnings' => 0, 'output' => ''
        ]);
        return;
    }

    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($relativePath);
    $res = run_step_proc($name, $cmd);

    if ($res['status'] === 'OK') {
        ok_msg(sprintf("%s réussi en %.1fs (%d warning(s) PHP)", $name, $res['duration'], $res['warnings']));
    } else {
        err(sprintf("%s échoué en %.1fs (code != 0)", $name, $res['duration']));
    }
    add_result($name, $res);

    if ($res['status'] === 'ÉCHEC') {
        // Fail-fast
        $rc = print_summary();
        err("Orchestrateur interrompu par fail-fast à l'étape : $name");
        exit($rc);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// ÉTAPE 2 — Tests PHP existants (tests/test_all.php)
// ═══════════════════════════════════════════════════════════════════════════
run_test_step('2. Tests PHP existants (tests/test_all.php)', 'tests/test_all.php');

// ═══════════════════════════════════════════════════════════════════════════
// ÉTAPE 3 — Tests de rendu HTML (tests/test_form_render_html.php)
// ═══════════════════════════════════════════════════════════════════════════
run_test_step('3. Tests de rendu HTML (tests/test_form_render_html.php)', 'tests/test_form_render_html.php');

// ═══════════════════════════════════════════════════════════════════════════
// ÉTAPE 4 — Tests structurels HTML (tests/StructuralHtmlTest.php)
// ═══════════════════════════════════════════════════════════════════════════
run_test_step('4. Tests structurels HTML (tests/StructuralHtmlTest.php)', 'tests/StructuralHtmlTest.php');

// ═══════════════════════════════════════════════════════════════════════════
// ÉTAPE 5 — Tests de non-régression (tests/regression/run_all.php)
// ═══════════════════════════════════════════════════════════════════════════
run_test_step('5. Tests de non-régression (tests/regression/run_all.php)', 'tests/regression/run_all.php');

// ─── Récapitulatif final ─────────────────────────────────────────────────────
$rc = print_summary();
exit($rc);
