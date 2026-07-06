<?php
declare(strict_types=1);
/**
 * test_no_deprecated_session.php — Audit : aucune directive de session
 * deprecated en PHP 8.4+ dans le code source.
 *
 * Bug historique (v9.6.0) : lib/core_bootstrap.php:51 utilisait
 *   session_start(['use_cookies' => false, 'use_only_cookies' => false])
 * Le paramètre 'use_only_cookies' => false est DEPRECATED depuis PHP 8.4
 * → warning PHP sur tous les scripts CLI (alert_check.php, remind.php, tests)
 *
 * Ce test scanne le code source PHP et vérifie :
 *   1. Aucun 'use_only_cookies' => false (deprecated PHP 8.4)
 *   2. Aucun @ini_set('session.use_only_cookies', '0') (deprecated PHP 8.4)
 *   3. tests/php_test.ini ne contient pas session.use_only_cookies = 0
 *
 * Fichier : tests/test_no_deprecated_session.php
 */

$passed = 0;
$failed = 0;
$violations = [];

function check_session(string $name, bool $ok, array $details = []): void {
    global $passed, $failed, $violations;
    if ($ok) {
        echo "  ✅ $name\n";
        $passed++;
    } else {
        echo "  ❌ $name (" . count($details) . " violation(s))\n";
        foreach ($details as $d) {
            echo "     • $d\n";
        }
        $failed++;
        $violations = array_merge($violations, $details);
    }
}

// ── Dossiers à scanner ──
$scanDirs = [
    __DIR__ . '/../lib/',
    __DIR__ . '/../pages/',
    __DIR__ . '/../src/',
];
$scanFiles = [
    __DIR__ . '/../helpers.php',
    __DIR__ . '/../config.php',
    __DIR__ . '/../alert_check.php',
    __DIR__ . '/../remind.php',
];

echo "── Audit : aucune directive de session dépréciée (PHP 8.4+) ──\n";

// ── Test 1 : 'use_only_cookies' => false dans code PHP ──
echo "\n── Test 1 : 'use_only_cookies' => false dans code PHP ──\n";
$patterns1 = [
    "/['\"]use_only_cookies['\"]\s*=>\s*false/",
    "/['\"]use_only_cookies['\"]\s*,\s*['\"]?0['\"]?/",
];
$allFiles = $scanFiles;
foreach ($scanDirs as $dir) {
    if (!is_dir($dir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $f) {
        if ($f->getExtension() === 'php') $allFiles[] = $f->getPathname();
    }
}
$r1 = [];
foreach ($allFiles as $filepath) {
    if (!file_exists($filepath)) continue;
    $lines = file($filepath, FILE_IGNORE_NEW_LINES);
    $rel = str_replace(dirname(__DIR__, 2) . '/', '', $filepath);
    foreach ($lines as $i => $line) {
        // Skip commentaires
        $t = ltrim($line);
        if (strncmp($t, '//', 2) === 0 || strncmp($t, '#', 1) === 0 || strncmp($t, '*', 1) === 0) continue;
        foreach ($patterns1 as $p) {
            if (preg_match($p, $line)) {
                $r1[] = "$rel:" . ($i + 1) . " → " . trim($line);
            }
        }
    }
}
check_session("Aucun 'use_only_cookies' => false dans code PHP", empty($r1), $r1);

// ── Test 2 : @ini_set('session.use_only_cookies', '0') ──
echo "\n── Test 2 : @ini_set('session.use_only_cookies', '0') ──\n";
$patterns2 = [
    "/ini_set\s*\(\s*['\"]session\.use_only_cookies['\"]\s*,\s*['\"]?0['\"]?\s*\)/",
];
$r2 = [];
foreach ($allFiles as $filepath) {
    if (!file_exists($filepath)) continue;
    $lines = file($filepath, FILE_IGNORE_NEW_LINES);
    $rel = str_replace(dirname(__DIR__, 2) . '/', '', $filepath);
    foreach ($lines as $i => $line) {
        $t = ltrim($line);
        if (strncmp($t, '//', 2) === 0 || strncmp($t, '#', 1) === 0 || strncmp($t, '*', 1) === 0) continue;
        foreach ($patterns2 as $p) {
            if (preg_match($p, $line)) {
                $r2[] = "$rel:" . ($i + 1) . " → " . trim($line);
            }
        }
    }
}
check_session("Aucun ini_set('session.use_only_cookies', '0')", empty($r2), $r2);

// ── Test 3 : tests/php_test.ini ne contient pas session.use_only_cookies = 0 ──
echo "\n── Test 3 : tests/php_test.ini sans session.use_only_cookies = 0 ──\n";
$r3 = [];
$iniFile = __DIR__ . '/php_test.ini';
if (file_exists($iniFile)) {
    $lines = file($iniFile, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*session\.use_only_cookies\s*=\s*0\s*$/', $line)) {
            $r3[] = "tests/php_test.ini:" . ($i + 1) . " → " . trim($line);
        }
    }
}
check_session("tests/php_test.ini sans session.use_only_cookies = 0", empty($r3), $r3);

// ── Test 4 : tests/php_test.ini contient session.use_only_cookies = 1 (recommandé) ──
echo "\n── Test 4 : tests/php_test.ini avec session.use_only_cookies = 1 (recommandé) ──\n";
$r4 = [];
if (file_exists($iniFile)) {
    $content = file_get_contents($iniFile);
    if (!preg_match('/^\s*session\.use_only_cookies\s*=\s*1\s*$/m', $content)) {
        $r4[] = "tests/php_test.ini — session.use_only_cookies = 1 manquant";
    }
}
check_session("tests/php_test.ini avec session.use_only_cookies = 1", empty($r4), $r4);

// ── Résumé ──
echo "\n═══════════════════════════════════════════════════\n";
echo "  AUDIT SESSION DÉPRÉCIÉE — " . (empty($violations) ? "✅ AUCUNE VIOLATION" : "❌ " . count($violations) . " violation(s)") . "\n";
echo "  $passed test(s) réussi(s) / $failed échoué(s) / " . ($passed + $failed) . " total\n";
echo "═══════════════════════════════════════════════════\n";
exit($failed > 0 ? 1 : 0);
