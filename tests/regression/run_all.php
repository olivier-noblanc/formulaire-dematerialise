<?php
declare(strict_types=1);
/**
 * tests/regression/run_all.php — Orchestrateur des 13 tests de non-régression.
 *
 * Inclut chaque fichier BugNN_<Name>Test.php et exécute sa fonction
 * `run_bugNN_test()`. Affiche un résumé `[PASS/FAIL] BugNN_Name — message`
 * et sort avec un code ≠ 0 si au moins un test échoue.
 *
 * Ces tests sont IMMORTELS : ils documentent les pièges historiques et
 * préviennent les régressions. Ne JAMAIS les supprimer.
 *
 * Usage : php tests/regression/run_all.php
 *
 * @package tests\regression
 */

// ── Constantes ANSI pour couleurs CLI ─────────────────────────────────────────
define('C_RESET', "\033[0m");
define('C_BOLD',  "\033[1m");
define('C_GREEN', "\033[32m");
define('C_RED',   "\033[31m");
define('C_CYAN',  "\033[36m");
define('C_YELLOW',"\033[33m");

// ── Liste ordonnée des 13 tests de non-régression ────────────────────────────
// Chaque entrée : [id, nom court, fichier, fonction]
$tests = [
    ['01', 'EndifFormController',  'Bug01_EndifFormControllerTest.php',  'run_bug01_test'],
    ['02', 'UploadFailure',        'Bug02_UploadFailureTest.php',        'run_bug02_test'],
    ['03', 'NestedForms',          'Bug03_NestedFormsTest.php',          'run_bug03_test'],
    ['04', 'ValidateExtraBrace',   'Bug04_ValidateExtraBraceTest.php',   'run_bug04_test'],
    ['05', 'StickyRgpd',           'Bug05_StickyRgpdTest.php',           'run_bug05_test'],
    ['06', 'StickyValidate',       'Bug06_StickyValidateTest.php',       'run_bug06_test'],
    ['07', 'FalseRefusedBadge',    'Bug07_FalseRefusedBadgeTest.php',    'run_bug07_test'],
    ['08', 'NoIsoDates',           'Bug08_NoIsoDatesTest.php',           'run_bug08_test'],
    ['09', 'TopbarLink',           'Bug09_TopbarLinkTest.php',           'run_bug09_test'],
    ['10', 'DuplicateLabelsHints', 'Bug10_DuplicateLabelsHintsTest.php', 'run_bug10_test'],
    ['11', 'NoTopbarBreadcrumb',   'Bug11_NoTopbarBreadcrumbTest.php',   'run_bug11_test'],
    ['12', 'RemindTimezoneOffset', 'Bug12_RemindTimezoneOffsetTest.php', 'run_bug12_test'],
    ['13', 'MailServiceDelegation', 'Bug13_MailServiceDelegationTest.php', 'run_bug13_test'],
];

echo C_BOLD . "\n═════════════════════════════════════════════════════════════════\n" . C_RESET;
echo C_BOLD . "  Tests de non-régression (13 bugs historiques) — tests/regression\n" . C_RESET;
echo C_BOLD . "═════════════════════════════════════════════════════════════════\n" . C_RESET;

$pass_count = 0;
$fail_count = 0;
$failures = [];

foreach ($tests as [$id, $name, $file, $fn]) {
    $path = __DIR__ . '/' . $file;
    if (!is_file($path)) {
        echo C_RED . "[FAIL] Bug{$id}_{$name} — Fichier manquant : {$file}" . C_RESET . "\n";
        $fail_count++;
        $failures[] = "Bug{$id}_{$name} : fichier manquant";
        continue;
    }
    // Inclure le fichier de test (définit la fonction run_bugNN_test)
    require_once $path;
    if (!function_exists($fn)) {
        echo C_RED . "[FAIL] Bug{$id}_{$name} — Fonction {$fn}() non définie" . C_RESET . "\n";
        $fail_count++;
        $failures[] = "Bug{$id}_{$name} : fonction manquante";
        continue;
    }

    // Capturer la sortie (echo) du test pour l'afficher indentée
    ob_start();
    try {
        $ok = $fn();
        $output = ob_get_clean();
    } catch (\Throwable $e) {
        $output = ob_get_clean() . "\n  💥 Exception : " . $e->getMessage() . "\n  " . $e->getTraceAsString();
        $ok = false;
    }

    // Afficher la sortie indentée du test (la dernière ligne contient
    // ✅ ou ❌ qui résume le résultat)
    if ($output !== '') {
        // Indenter chaque ligne pour la lisibilité
        echo preg_replace('/^/m', '  ', $output);
        if (!str_ends_with($output, "\n")) echo "\n";
    }

    if ($ok) {
        echo C_GREEN . "[PASS] Bug{$id}_{$name}" . C_RESET . "\n";
        $pass_count++;
    } else {
        echo C_RED . "[FAIL] Bug{$id}_{$name}" . C_RESET . "\n";
        $fail_count++;
        $failures[] = "Bug{$id}_{$name}";
    }
}

// ── Résumé final ─────────────────────────────────────────────────────────────
echo C_BOLD . "\n═════════════════════════════════════════════════════════════════\n" . C_RESET;
echo C_BOLD . "  RÉSUMÉ : " . C_GREEN . "{$pass_count} réussi(s)" . C_RESET . " / " . C_RED . "{$fail_count} échoué(s)" . C_RESET . " / " . ($pass_count + $fail_count) . " total\n";
echo C_BOLD . "═════════════════════════════════════════════════════════════════\n" . C_RESET;

if ($fail_count > 0) {
    echo C_RED . "  Tests échoués : " . implode(', ', $failures) . C_RESET . "\n";
    exit(1);
}

echo C_GREEN . C_BOLD . "  ✅ Tous les tests de non-régression passent — les 13 bugs historiques sont couverts." . C_RESET . "\n";
exit(0);
