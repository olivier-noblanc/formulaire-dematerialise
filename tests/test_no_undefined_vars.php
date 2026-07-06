<?php
declare(strict_types=1);
/**
 * test_no_undefined_vars.php — Audit : aucune variable .= sans init préalable.
 *
 * Bug historique (v9.5.0) : render_dashboard.php:564 faisait
 *   $content .= "..." sans jamais initialiser $content
 * → PHP warning "Undefined variable $content" sur chaque page dashboard.
 *
 * Ce bug a échappé à :
 *   - php -l (check syntaxique uniquement, pas runtime)
 *   - test_all.php (rend les pages mais ne fail pas sur les warnings)
 *   - PHPStan niveau 6 (l'avait détecté mais était ignoré dans le baseline)
 *
 * Ce test parse le code source PHP et vérifie que pour chaque fonction,
 * toute variable utilisée avec .= a été initialisée avec = auparavant
 * (ou est un paramètre, ou est dans un use() de closure).
 *
 * Fichier : tests/test_no_undefined_vars.php
 */

$passed = 0;
$failed = 0;
$violations = [];

function check_var(string $name, bool $ok, array $details = []): void {
    global $passed, $failed, $violations;
    if ($ok) {
        echo "  ✅ $name\n";
        $passed++;
    } else {
        echo "  ❌ $name (" . count($details) . " violation(s))\n";
        foreach (array_slice($details, 0, 10) as $d) {
            echo "     • $d\n";
        }
        $failed++;
        $violations = array_merge($violations, $details);
    }
}

// ── Dossier à scanner ──
$scanDirs = [
    __DIR__ . '/../lib/',
    __DIR__ . '/../pages/',
    __DIR__ . '/../src/',
];

/**
 * Découpe le source en blocs de fonction.
 *
 * @return array<array{name: string, params: array<string>, body: string, body_offset: int}>
 */
function split_functions(string $src): array {
    $funcs = [];
    $i = 0;
    while (true) {
        $m = preg_match('/\bfunction\s+(\w+)\s*\(([^)]*)\)/', $src, $matches, PREG_OFFSET_CAPTURE, $i);
        if (!$m) break;
        $func_name = $matches[1][0];
        $params_str = $matches[2][0];
        $func_start = $matches[0][1];

        // Trouver l'accolade ouvrante
        $brace_start = strpos($src, '{', $matches[0][1] + strlen($matches[0][0]));
        if ($brace_start === false) break;

        // Compter les accolades en skipant les strings
        $depth = 1;
        $j = $brace_start + 1;
        $len = strlen($src);
        while ($j < $len && $depth > 0) {
            $c = $src[$j];
            if ($c === "'" || $c === '"') {
                $end_char = $c;
                $j++;
                while ($j < $len) {
                    if ($src[$j] === '\\') { $j += 2; continue; }
                    if ($src[$j] === $end_char) break;
                    $j++;
                }
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
            }
            $j++;
        }
        $body = substr($src, $brace_start + 1, $j - $brace_start - 2);
        $params = [];
        preg_match_all('/\$(\w+)/', $params_str, $pm);
        $params = $pm[1] ?? [];

        $funcs[] = [
            'name' => $func_name,
            'params' => $params,
            'body' => $body,
            'body_offset' => $brace_start + 1,
        ];
        $i = $j;
    }
    return $funcs;
}

/**
 * Audite une fonction pour détecter les .= sans init.
 *
 * @param array{name: string, params: array<string>, body: string, body_offset: int} $func
 * @param string $file_path Chemin relatif pour affichage
 * @return array<array{line: int, var: string, line_content: string}>
 */
function audit_function(array $func, string $file_path): array {
    $bugs = [];
    $initialized = array_flip($func['params']);
    // Variables super-globales PHP (toujours définies)
    $superglobals = ['_GET', '_POST', '_SERVER', '_SESSION', '_FILES', '_COOKIE', '_ENV', 'GLOBALS', 'argv', 'argc'];
    foreach ($superglobals as $g) $initialized[$g] = true;

    $lines = explode("\n", $func['body']);
    foreach ($lines as $i => $line) {
        $line_num = $i + 1;
        $stripped = ltrim($line);
        // Skip commentaires
        if (strncmp($stripped, '//', 2) === 0 || strncmp($stripped, '#', 1) === 0 || strncmp($stripped, '*', 1) === 0) {
            continue;
        }

        // Détecter $var = (initialisation, mais PAS .=, ==, =>, =>)
        if (preg_match('/^\s*\$(\w+)\s*=\s*(?![=.>])/', $line, $m_init)) {
            $initialized[$m_init[1]] = true;
        }

        // Détecter foreach ($items as $key => $val) qui init $key et $val
        if (preg_match('/foreach\s*\([^$]*\$\w+\s+as\s+(?:\$(\w+)\s*=>\s*)?\$(\w+)\)/', $line, $m_foreach)) {
            if (!empty($m_foreach[1])) $initialized[$m_foreach[1]] = true;
            if (!empty($m_foreach[2])) $initialized[$m_foreach[2]] = true;
        }

        // Détecter global $var
        if (preg_match('/^\s*global\s+([^;]+);/', $line, $m_global)) {
            preg_match_all('/\$(\w+)/', $m_global[1], $gm);
            foreach ($gm[1] as $v) $initialized[$v] = true;
        }

        // Détecter use ($var1, $var2) dans les closures
        if (preg_match('/\buse\s*\(([^)]*)\)/', $line, $m_use)) {
            preg_match_all('/\$(\w+)/', $m_use[1], $um);
            foreach ($um[1] as $v) $initialized[$v] = true;
        }

        // Détecter $var .= (concaténation)
        if (preg_match('/^\s*\$(\w+)\s*\.=\s*/', $line, $m_concat)) {
            $var = $m_concat[1];
            if (!isset($initialized[$var])) {
                // Calculer le numéro de ligne absolu
                $abs_line = substr_count(substr(file_get_contents($file_path), 0, $func['body_offset']), "\n") + $line_num;
                $bugs[] = [
                    'line' => $abs_line,
                    'var' => $var,
                    'line_content' => rtrim($line),
                ];
            }
        }
    }
    return $bugs;
}

// ── Scan ──
echo "── Audit : aucune variable .= sans init préalable ──\n";

$allFiles = [];
foreach ($scanDirs as $dir) {
    if (!is_dir($dir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $f) {
        if ($f->getExtension() === 'php') $allFiles[] = $f->getPathname();
    }
}

$allViolations = [];
$filesScanned = 0;
$functionsScanned = 0;
foreach ($allFiles as $filepath) {
    $filesScanned++;
    $rel = str_replace(dirname(__DIR__, 2) . '/', '', $filepath);
    $src = file_get_contents($filepath);
    if ($src === false) continue;
    $funcs = split_functions($src);
    foreach ($funcs as $func) {
        $functionsScanned++;
        $bugs = audit_function($func, $filepath);
        foreach ($bugs as $b) {
            $allViolations[] = "$rel:{$b['line']} — \${$b['var']} .= sans init dans {$func['name']}() | " . trim($b['line_content']);
        }
    }
}

check_var("$filesScanned fichier(s) scanné(s), $functionsScanned fonction(s) — 0 variable .= sans init", empty($allViolations), $allViolations);

echo "\n═══════════════════════════════════════════════════\n";
echo "  AUDIT VARIABLES — " . (empty($violations) ? "✅ AUCUNE VIOLATION" : "❌ " . count($violations) . " violation(s)") . "\n";
echo "  $passed test(s) réussi(s) / $failed échoué(s) / " . ($passed + $failed) . " total\n";
echo "═══════════════════════════════════════════════════\n";
exit($failed > 0 ? 1 : 0);
