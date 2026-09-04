<?php
/**
 * test_harness_selftest.php — B-HARNESS (Oracle) : régressions du harnais de test.
 *
 * Vérifie que tests/test_bootstrap.php garantit, même en terminaison anormale :
 *   1. fail_exit0 : le résumé (RÉSULTATS) est imprimé malgré un exit(0) prématuré
 *      (ex: require in-process d'une page qui appelle exit(), cf. index.php:108)
 *      ET le code de sortie est non nul (un run incomplet = run non fiable) ;
 *   2. fatal      : idem sur erreur fatale (fonction inexistante) ;
 *   3. nominal    : le chemin nominal (print_test_summary + exit) n'est pas
 *      perturbé par le filet (pas de marqueur « TERMINAISON ANORMALE »).
 *
 * Chaque scénario tourne dans un sous-processus PHP qui requiert le VRAI
 * tests/test_bootstrap.php. Aucun reset de DB n'est déclenché (le flag
 * TEST_ALL_DB_RESET n'est pas défini ici) et aucune donnée n'est modifiée.
 *
 * Usage : php tests/test_harness_selftest.php
 *         (aussi requis par tests/test_all.php — section 7)
 */

declare(strict_types=1);

/**
 * Exécute un script PHP en sous-processus.
 * @return array{out: string, err: string, code: int}
 */
function harness_selftest_run(string $scriptPath): array {
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([PHP_BINARY, $scriptPath], $descriptors, $pipes, dirname(__DIR__));
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

/**
 * Écrit un scénario dans le répertoire temporaire système (portable Windows/Unix).
 */
function harness_selftest_scenario_script(string $kind, string $root): string {
    $bootstrap = var_export($root . '/tests/test_bootstrap.php', true);
    $code = "<?php\n";
    $code .= "error_reporting(E_ALL);\n";
    $code .= "ini_set('display_errors', '1');\n";
    $code .= "require {$bootstrap};\n";

    switch ($kind) {
        case 'fail_exit0':
            // Simule l'exit in-process d'une page requise (cf. index.php:108)
            // après au moins un échec : le résumé doit être imprimé par le
            // filet et le code de sortie forcé non nul.
            $code .= "test('échec volontaire (ne doit pas être masqué)', function(): string|true { return 'échec volontaire'; });\n";
            $code .= "exit(0);\n";
            break;
        case 'fatal':
            $code .= "test('échec avant fatal', function(): string|true { return 'échec avant fatal'; });\n";
            $code .= "harness_selftest_undefined_function_onPurpose();\n";
            break;
        case 'nominal':
            $code .= "test('ok nominal', function(): bool { return true; });\n";
            $code .= "exit(print_test_summary('RÉSULTATS'));\n";
            break;
    }

    $path = sys_get_temp_dir() . '/harness_selftest_' . $kind . '_' . getmypid() . '.php';
    if (file_put_contents($path, $code) === false) {
        throw new RuntimeException("Impossible d'écrire le scénario $kind");
    }
    return $path;
}

/**
 * Exécute les 3 scénarios et retourne le nombre d'échecs.
 * Imprime le détail ✅/❌ quand $echo vaut true.
 */
function harness_selftest_run_all(bool $echo = false): int {
    global $argv;
    $root = dirname(__DIR__);
    $isStandalone = PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
    if ($echo || $isStandalone) {
        echo "── Auto-vérification du harnais (test_bootstrap.php) ──\n";
    }

    $failures = 0;
    $expectations = [
        // [code != 0, contient RÉSULTATS, contient marqueur échec, PAS de terminaison anormale]
        'fail_exit0' => [true, true, 'échec volontaire', false],
        'fatal'      => [true, true, 'échec avant fatal', false],
        'nominal'    => [false, true, null, true],
    ];

    foreach ($expectations as $kind => [$nonZero, $hasSummary, $marker, $cleanExit]) {
        $script = harness_selftest_scenario_script($kind, $root);
        try {
            $r = harness_selftest_run($script);
        } finally {
            @unlink($script);
        }

        $checks = [
            'code de sortie ' . ($nonZero ? 'non nul' : 'nul') . " (got {$r['code']})" => $nonZero ? $r['code'] !== 0 : $r['code'] === 0,
            'résumé RÉSULTATS imprimé' => str_contains($r['out'], 'RÉSULTATS'),
        ];
        if ($marker !== null) {
            $checks["marqueur « $marker » présent"] = str_contains($r['out'], $marker);
        }
        $checks['terminaison anormale ' . ($cleanExit ? 'absente' : 'annoncée')] = $cleanExit ? !str_contains($r['out'], 'TERMINAISON ANORMALE') : str_contains($r['out'], 'TERMINAISON ANORMALE');

        $scenarioFailures = 0;
        $details = [];
        foreach ($checks as $label => $ok) {
            if (!$ok) {
                $scenarioFailures++;
                $details[] = $label;
            }
        }

        if ($echo || $isStandalone) {
            echo $scenarioFailures === 0
                ? "  ✅ scénario $kind\n"
                : "  ❌ scénario $kind — " . implode(' ; ', $details) . "\n";
            if ($scenarioFailures > 0) {
                echo "     stdout: " . substr(str_replace("\n", ' | ', trim($r['out'])), 0, 220) . "\n";
                echo "     stderr: " . substr(str_replace("\n", ' | ', trim($r['err'])), 0, 220) . "\n";
            }
        }
        $failures += $scenarioFailures;
    }

    if ($echo || $isStandalone) {
        echo $failures === 0
            ? "  Harnais : 3/3 scénarios OK\n"
            : "  Harnais : $failures vérification(s) en échec\n";
    }
    return $failures;
}

// ── Exécution standalone (php tests/test_harness_selftest.php) ──
// Quand le fichier est requis par test_all.php, $argv[0] pointe vers
// test_all.php → la branche ne s'active pas.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $failures = harness_selftest_run_all(true);
    exit($failures > 0 ? 1 : 0);
}
