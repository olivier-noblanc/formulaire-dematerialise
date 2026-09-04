<?php
/**
 * test_bootstrap.php — Shared test framework utilities
 *
 * Deduplicates test framework code across all test files.
 * Provides: global counters, test/assert_test functions,
 * color output, environment setup, and test summary.
 *
 * Usage: require_once __DIR__ . '/test_bootstrap.php';
 *
 * After requiring, you may override $_SERVER values for your
 * specific test suite (e.g. $_SERVER['HTTP_X_TEST_USER']).
 */

// ═══════════════════════════════════════════════════════════════
// ERROR REPORTING — All errors must display, no silent failures
// ═══════════════════════════════════════════════════════════════
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('session.save_path', sys_get_temp_dir() . '/php-sessions');

// B-HARNESS : la sortie pré-bootstrap (annonce du reset déterministe) est
// bufferisée puis flushée après le chargement de l'application — sinon les
// headers seraient envoyés avant le session_start() de core_bootstrap
// (warning « headers already sent » à chaque run).
ob_start();

// ═══════════════════════════════════════════════════════════════
// ENVIRONMENT DEFAULTS FOR CLI TESTING
// Override these in your test file AFTER requiring this bootstrap.
// ═══════════════════════════════════════════════════════════════
if (!isset($_SERVER['HTTP_X_TEST_MODE'])) {
    $_SERVER['HTTP_X_TEST_MODE'] = '1';
}
if (!isset($_SERVER['HTTP_X_TEST_USER'])) {
    $_SERVER['HTTP_X_TEST_USER'] = 'testeur@e2e.test';
}
if (!isset($_SERVER['AUTH_USER'])) {
    $_SERVER['AUTH_USER'] = 'DREETS\testeur';
}
if (!isset($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost';
}
if (!isset($_SERVER['HTTPS'])) {
    $_SERVER['HTTPS'] = '';
}
if (!isset($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = '/';
}
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

// ═══════════════════════════════════════════════════════════════
// GLOBAL COUNTERS
// ═══════════════════════════════════════════════════════════════
$passed = 0;
$failed = 0;
$errors = [];

// ═══════════════════════════════════════════════════════════════
// B-HARNESS — FILET ANTI-MASQUAGE (Oracle, 2026-09-03)
// Un run interrompu (exit()/fatal depuis un require in-process, ex:
// index.php:108) ne doit JAMAIS masquer les échecs : le résumé est
// imprimé par ce filet et le code de sortie est forcé non nul, car un
// run incomplet est un run non fiable. Chemin nominal : print_test_summary()
// pose $GLOBALS['_test_summary_printed'] et le filet ne fait rien.
// NB : exit() dans une shutdown function redresse le code de sortie final
// (vérifié empiriquement : exit(0) principal → code final 1).
// RÉGRESSIONS : tests/test_harness_selftest.php (scénarios fail_exit0,
// fatal, nominal).
// ═══════════════════════════════════════════════════════════════
$GLOBALS['_test_summary_printed'] = false;
register_shutdown_function(static function (): void {
    if (!empty($GLOBALS['_test_summary_printed'])) {
        return; // chemin nominal — résumé et code de sortie déjà posés
    }
    $out = defined('STDOUT') ? STDOUT : fopen('php://output', 'wb');
    $passed = (int) ($GLOBALS['passed'] ?? 0);
    $failed = (int) ($GLOBALS['failed'] ?? 0);
    $errors = is_array($GLOBALS['errors'] ?? null) ? $GLOBALS['errors'] : [];
    // Le filet ne concerne que les runs qui ont ENGAGÉ les compteurs du
    // bootstrap (test_all, test_http, test_v4, test_e2e, scénarios selftest).
    // Les scripts standalone avec leurs propres compteurs et leur propre
    // résumé (test_mail_escaping, test_email_urls, test_routing,
    // test_phpmailer_warnings...) n'ont rien à masquer : s'ils meurent, leur
    // propre code de sortie (fatal → 255) s'exprime seul. Sans cette garde,
    // le filet forçait exit(1) après leur résumé nominal (run CI 33877387871,
    // job « Tests fonctionnels », étape test_mail_escaping.php).
    if ($passed === 0 && $failed === 0 && $errors === []) {
        return;
    }
    fwrite($out, "\n⚠️  TERMINAISON ANORMALE (exit()/fatal en cours de run) — RÉSULTATS partiels :\n");
    fwrite($out, "  $passed réussi(s) / $failed échoué(s) / " . ($passed + $failed) . " total\n");
    foreach ($errors as $e) {
        fwrite($out, '  • ' . $e . "\n");
    }
    fwrite($out, "\n  Run incomplet = run non fiable → code de sortie forcé à 1.\n\n");
    exit(1);
});

// ═══════════════════════════════════════════════════════════════
// COLOR OUTPUT UTILITIES (CLI)
// ═══════════════════════════════════════════════════════════════
function green(string $t): string  { return "\033[32m$t\033[0m"; }
function red(string $t): string    { return "\033[31m$t\033[0m"; }
function yellow(string $t): string { return "\033[33m$t\033[0m"; }
function cyan(string $t): string   { return "\033[36m$t\033[0m"; }
function bold(string $t): string   { return "\033[1m$t\033[0m"; }
function reset_color(): string     { return "\033[0m"; }

// ═══════════════════════════════════════════════════════════════
// TEST FUNCTIONS
// ═══════════════════════════════════════════════════════════════

/**
 * Run a test by executing a callable.
 * The callable must return true on success, or a string error message on failure.
 * Used by test_all.php and test_e2e.php.
 */
function test(string $name, callable $fn): void {
    global $passed, $failed, $errors;
    try {
        $result = $fn();
        if ($result === true) {
            echo "  ✅ $name\n";
            $passed++;
        } else {
            echo "  ❌ $name — $result\n";
            $failed++;
            $errors[] = "$name: $result";
        }
    } catch (Throwable $e) {
        echo "  💥 $name — " . $e->getMessage() . " (line " . $e->getLine() . ")\n";
        $failed++;
        $errors[] = "$name: " . $e->getMessage();
    }
}

/**
 * Assert a boolean condition with optional failure message.
 * Used by test_http.php and test_v4.php.
 */
function assert_test(string $name, bool $condition, string $fail_msg = ''): void {
    global $passed, $failed, $errors;
    if ($condition) {
        echo green("  ✓ $name") . "\n";
        $passed++;
    } else {
        echo red("  ✗ $name") . ($fail_msg ? " — $fail_msg" : '') . "\n";
        $failed++;
        $errors[] = "$name" . ($fail_msg ? ": $fail_msg" : '');
    }
}

/**
 * Capture output from a callable (ob_start wrapper).
 */
function capture_output(callable $fn): string {
    ob_start();
    $fn();
    return ob_get_clean();
}

// ═══════════════════════════════════════════════════════════════
// TEST SUMMARY
// ═══════════════════════════════════════════════════════════════

/**
 * Print the final test summary with colored output.
 * Returns exit code (0 = all passed, 1 = failures).
 */
function print_test_summary(string $title = 'RÉSULTATS'): int {
    global $passed, $failed, $errors;
    // B-HARNESS : marque le résumé comme imprimé → le filet anti-masquage
    // (shutdown) ne s'activera pas sur le chemin nominal.
    $GLOBALS['_test_summary_printed'] = true;

    echo bold("\n═══════════════════════════════════════════════════════════════\n");
    echo bold("  $title : ") . green("$passed réussi(s)") . " / " . red("$failed échoué(s)") . " / " . ($passed + $failed) . " total\n";
    echo bold("═══════════════════════════════════════════════════════════════\n");

    if (!empty($errors)) {
        echo red("\nTests échoués :\n");
        foreach ($errors as $e) {
            echo red("  • $e\n");
        }
    }

    return $failed > 0 ? 1 : 0;
}

// ═══════════════════════════════════════════════════════════════
// CROSS-PLATFORM HELPERS
// ═══════════════════════════════════════════════════════════════

/**
 * Répertoire temporaire système (remplace /tmp/ hardcodé).
 */
function test_temp_dir(): string {
    return sys_get_temp_dir();
}

/**
 * Tue les processus écoutant sur un port donné — cross-platform.
 * Sécurité : ne tue que les ports dans la plage de test (8760-8799).
 */
function kill_port(int $port): void {
    if ($port < 8760 || $port > 8799) {
        error_log("kill_port refusé : port $port hors plage de test (8760-8799)");
        return;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $output = shell_exec("netstat -ano | findstr :$port");
        if ($output) {
            preg_match_all('/\s+(\d+)\s*$/', $output, $matches);
            $pids = array_unique($matches[1] ?? []);
            foreach ($pids as $pid) {
                shell_exec("taskkill /F /PID $pid 2>NUL");
            }
        }
    } else {
        shell_exec("kill $(lsof -t -i:$port 2>/dev/null) 2>/dev/null");
    }
}

// ═══════════════════════════════════════════════════════════════
// SUBPROCESS RUNNER — B-HARNESS (Oracle, 2026-09-03)
// ═══════════════════════════════════════════════════════════════

/**
 * Exécute un script PHP en sous-processus et retourne sortie/erreurs/code.
 *
 * B-HARNESS : le code de sortie est le signal fiable de crash (fatal → 255),
 * indépendamment de display_errors (TEST_MODE le force à 0, donc le texte
 * « Fatal error » peut être absent de la sortie).
 *
 * @param string $scriptPath Chemin absolu du script à exécuter
 * @param string|null $cwd   Répertoire de travail (défaut : racine du dépôt)
 * @return array{out: string, err: string, code: int}
 */
function run_php_subprocess(string $scriptPath, ?string $cwd = null): array {
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([PHP_BINARY, $scriptPath], $descriptors, $pipes, $cwd ?? dirname(__DIR__));
    if (!is_resource($proc)) {
        return ['out' => '', 'err' => 'proc_open a échoué', 'code' => -1];
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) ?: '';
    $err = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return ['out' => $out, 'err' => $err, 'code' => $code];
}

// ═══════════════════════════════════════════════════════════════
// DETERMINISTIC TEST DB RESET — B-HARNESS (Oracle, 2026-09-03)
// ═══════════════════════════════════════════════════════════════

/**
 * Supprime db/workflow_test.db (+ -wal / -shm) AVANT le bootstrap applicatif.
 *
 * La connexion PDO suivante recrée schéma + seeds de façon déterministe
 * (db_migrate → apply_schema_initial → seed_default_forms →
 * apply_post_migration_fixes) : chaque run part d'un état identique, sans
 * résidus (WAL orphelin d'un run crashé, données de tests précédents...).
 *
 * Garde stricte :
 *  - opt-in : uniquement si TEST_ALL_DB_RESET === true, constante définie
 *    par le fichier appelant AVANT le require de test_bootstrap.php ;
 *  - CLI uniquement ;
 *  - chemin verrouillé sur db/workflow_test.db (basename exact) — la DB de
 *    production workflow.db ne peut jamais être touchée ;
 *  - échec de suppression → exit(1) : on refuse de tourner sur un état
 *    résiduel qu'on n'a pas pu nettoyer (règle 9 AGENTS.md — ne pas avaler
 *    une erreur sur un chemin critique).
 */
function reset_test_db_strict(): void {
    if (!defined('TEST_ALL_DB_RESET') || !TEST_ALL_DB_RESET) {
        return; // opt-in : les autres suites (test_http, test_v4...) ne sont pas affectées
    }
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "[test_bootstrap] reset_test_db_strict() refusé hors CLI\n");
        exit(1);
    }
    $testDbPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'workflow_test.db';
    if (basename($testDbPath) !== 'workflow_test.db') {
        fwrite(STDERR, "[test_bootstrap] Garde stricte : chemin DB test inattendu — reset annulé\n");
        exit(1);
    }
    $removed = [];
    foreach ([$testDbPath, $testDbPath . '-wal', $testDbPath . '-shm'] as $file) {
        if (is_file($file)) {
            if (!@unlink($file)) {
                fwrite(STDERR, "[test_bootstrap] FATAL : suppression impossible de $file (verrou SQLite ?) — run refusé plutôt qu'exécuté sur un état résiduel\n");
                exit(1);
            }
            $removed[] = basename($file);
        }
    }
    if ($removed !== []) {
        echo yellow('[harness] Reset déterministe DB test : ' . implode(', ', $removed) . " supprimé(s) — recréation au premier accès PDO\n");
    }
}

// Le reset DOIT précéder le chargement de l'application (helpers.php →
// core_bootstrap → première connexion PDO qui recrée la DB).
reset_test_db_strict();

// ═══════════════════════════════════════════════════════════════
// LOAD HELPERS — so all tests have access to app functions
// ═══════════════════════════════════════════════════════════════
require_once dirname(__DIR__) . '/helpers.php';

// B-HARNESS : flush de la sortie pré-bootstrap (voir ob_start en tête de
// fichier) — le session_start() de core_bootstrap a déjà eu lieu, les
// headers peuvent maintenant partir sans warning.
ob_end_flush();
