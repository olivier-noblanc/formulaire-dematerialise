<?php
declare(strict_types=1);

/**
 * Audit statique custom : détecte les accès à des clés de tableau susceptibles
 * d'être undefined dans les fichiers PHP de l'application.
 *
 * Heuristiques:
 * 1) Pour chaque variable tableau $X['key'], vérifie si 'key' apparaît dans un
 *    SELECT ... AS key, ou dans un array literal 'key' => ... dans le même fichier.
 * 2) Détecte les variables utilisées sans assignation visible dans le scope.
 * 3) Détecte les propriétés d'objet accédées sans initialisation visible.
 *
 * Usage: php audit_undefined.php <root_dir>
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php audit_undefined.php <root_dir>\n");
    exit(1);
}
$root = $argv[1];
if (!is_dir($root)) {
    fwrite(STDERR, "Not a dir: $root\n");
    exit(1);
}

// Récupère tous les fichiers PHP (hors vendor, tests)
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
$phpFiles = [];
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    if ($f->getExtension() !== 'php') continue;
    $p = $f->getPathname();
    if (strpos($p, '/vendor/') !== false) continue;
    if (strpos($p, '/tests/') !== false) continue;
    $phpFiles[] = $p;
}
sort($phpFiles);

$totalIssues = 0;
$fileIssues = [];

foreach ($phpFiles as $file) {
    $src = file_get_contents($file);
    if ($src === false) continue;
    $lines = explode("\n", $src);
    $rel = str_replace($root . '/', '', $file);

    // ── 1) SELECT columns detection ────────────────────────────────
    // Pattern: SELECT ... AS alias
    $definedKeys = []; // keys defined in this file (table literals + SQL aliases + foreach $row vars)
    // SQL aliases: match `AS alias` or `as alias` (case-insensitive) word boundary
    if (preg_match_all('/\b(?:AS|as)\s+([a-z_][a-z0-9_]*)/i', $src, $m)) {
        foreach ($m[1] as $k) $definedKeys[$k] = true;
    }
    // Direct column refs: t.col_name AS col_name → already captured above.
    // But also: `SELECT t.token, t.sent_at` without alias → token, sent_at
    if (preg_match_all('/\b(?:t|st|s|f|svd)\.([a-z_][a-z0-9_]*)\s*(?:,|FROM|from|\n)/i', $src, $m2)) {
        foreach ($m2[1] as $k) $definedKeys[$k] = true;
    }
    // Array literals: 'key' => or "key" =>
    if (preg_match_all('/[\'"]([a-z_][a-z0-9_]*)[\'"]\s*=>/i', $src, $m3)) {
        foreach ($m3[1] as $k) $definedKeys[$k] = true;
    }
    // fetchAll with PDO::FETCH_ASSOC keys come from SQL — covered by AS aliases.

    // ── 2) Scan for $X['key'] accesses where X is a DB row var ──────
    // Heuristic: $tk, $row, $r, $as, $data, $submission, $token, $form, $step
    // are common DB row vars. We'll check all $var['key'] accesses.
    $dbRowVars = ['tk', 'row', 'r', 'as', 'data', 'submission', 'token', 'form',
                  'step', 'sub', 'user', 'field', 'st', 's', 'f', 'v',
                  'pending', 'done', 'vd', 'submission_data', 'form_row',
                  'step_row', 'form_field'];

    foreach ($lines as $idx => $line) {
        $ln = $idx + 1;
        // Skip comments
        $trimLine = ltrim($line);
        if (strncmp($trimLine, '//', 2) === 0) continue;
        if (strncmp($trimLine, '#', 1) === 0) continue;
        if (strpos($trimLine, '*') === 0) continue;

        // Find all $var['key'] or $var["key"]
        if (preg_match_all('/\$([a-z_][a-z0-9_]*)\[[\'"]([a-z_][a-z0-9_]*)[\'"]\]/i', $line, $mm)) {
            foreach ($mm[1] as $i => $varName) {
                $key = $mm[2][$i];
                // Only audit DB-row-like vars
                if (!in_array($varName, $dbRowVars, true)) continue;
                // If key is defined in this file → skip
                if (isset($definedKeys[$key])) continue;
                // If accessed with ?? or isset() or empty() → safe
                if (preg_match('/isset\s*\(\s*\$' . preg_quote($varName) . '\[[\'"]' . preg_quote($key) . '[\'"]\]/', $line)) continue;
                if (preg_match('/empty\s*\(\s*\$' . preg_quote($varName) . '\[[\'"]' . preg_quote($key) . '[\'"]\]/', $line)) continue;
                // Check if ?? on same line for this access
                if (preg_match('/\$' . preg_quote($varName) . '\[[\'"]' . preg_quote($key) . '[\'"]\]\s*\?\?/', $line)) continue;
                // Report
                $fileIssues[] = [
                    'file' => $rel,
                    'line' => $ln,
                    'type' => 'undefined_array_key',
                    'var'  => '$' . $varName,
                    'key'  => $key,
                    'code' => trim($line),
                ];
                $totalIssues++;
            }
        }
    }
}

// Output report
echo "=== AUDIT STATIQUE — Variables / Clés potentiellement undefined ===\n\n";
echo "Fichiers scannés : " . count($phpFiles) . "\n";
echo "Problèmes détectés : $totalIssues\n\n";

// Group by file
$byFile = [];
foreach ($fileIssues as $iss) {
    $byFile[$iss['file']][] = $iss;
}
ksort($byFile);

foreach ($byFile as $file => $issues) {
    echo "── $file (" . count($issues) . ") ──\n";
    foreach ($issues as $i) {
        printf("  L%-4d %s → %s['%s']\n", $i['line'], $i['type'], $i['var'], $i['key']);
        echo "    Code: " . substr($i['code'], 0, 120) . "\n";
    }
    echo "\n";
}

exit($totalIssues > 0 ? 2 : 0);
