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
// LOAD HELPERS — so all tests have access to app functions
// ═══════════════════════════════════════════════════════════════
require_once dirname(__DIR__) . '/helpers.php';
