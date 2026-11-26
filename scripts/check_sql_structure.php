#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Garde CI — erreurs SQL structurelles (statique, sans base de données).
 *
 * Trois règles textuelles KISS, aucune dépendance, aucun bootstrap applicatif :
 *   1. select_star        `SELECT *` interdit dans src/ et classes/migrations/.
 *   2. insert_no_columns  `INSERT ... VALUES|SELECT|DEFAULT` sans liste explicite
 *                         de colonnes interdit dans le code applicatif
 *                         (tout sauf classes/, qui contient schémas/seeds/migrations).
 *   3. direct_write       INSERT/REPLACE/UPDATE/DELETE sur form_fields, steps ou
 *                         step_recipients interdit hors src/Repository/ et
 *                         classes/migrations/ (seeds incluses).
 *
 * Les commentaires PHP sont masqués avant l'analyse (token_get_all) pour ne pas
 * signaler un exemple SQL documenté. Les chaînes de caractères sont conservées.
 *
 * Allowlist : uniquement les exceptions structurelles justifiées, ancrées sur
 * fichier + ligne + extrait attendu. Une entrée qui ne correspond plus à une
 * violation réelle fait échouer la garde (évite une allowlist qui pourrit).
 *
 * Usage : php scripts/check_sql_structure.php [racine]
 * Sortie : 0 = conforme, 1 = violations ou allowlist obsolète, 2 = usage/IO.
 */

const TARGET_TABLES = ['form_fields', 'steps', 'step_recipients'];

/** Écritures autorisées : couche Repository et migrations/seeds historiques. */
const WRITE_ALLOWED_PREFIXES = [
    'src/Repository/',
    'classes/migrations/',
];

/**
 * Exceptions `SELECT *` : aucune. v43/v44 recopiaient auparavant la table
 * entière via `SELECT *` ; elles utilisent désormais une liste de colonnes
 * explicite des deux côtés (mapping par nom, pas par position physique).
 * Une exception ajoutée ici devra être justifiée par un extrait attendu.
 */
const SELECT_STAR_ALLOWLIST = [];

/**
 * Remplace le contenu des commentaires par des espaces (longueur et retours à
 * la ligne préservés) afin de garder des offsets fiables pour les lignes.
 */
function maskComments(string $source): string
{
    $masked = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            [$id, $text] = $token;
            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                $masked .= preg_replace('/[^\r\n]/', ' ', $text) ?? '';
                continue;
            }
            $masked .= $text;
            continue;
        }
        $masked .= $token;
    }

    return $masked;
}

/** @return list<string> chemins absolus normalisés en slashes */
function collectFiles(string $root): array
{
    $files = [];
    foreach (['src', 'classes', 'lib'] as $dir) {
        $base = $root . '/' . $dir;
        if (!is_dir($base)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
    }
    foreach (glob($root . '/*.php') ?: [] as $file) {
        if (is_file($file)) {
            $files[] = str_replace('\\', '/', $file);
        }
    }

    $files = array_values(array_unique($files));
    sort($files);

    return $files;
}

function relativePath(string $root, string $file): string
{
    $prefix = $root . '/';
    if (str_starts_with($file, $prefix)) {
        return substr($file, strlen($prefix));
    }

    return basename($file);
}

function lineAt(string $masked, int $offset): int
{
    return substr_count(substr($masked, 0, $offset), "\n") + 1;
}

function tableName(string $matched): string
{
    $matched = trim($matched, "`\"[]");
    $dot = strrpos($matched, '.');
    if ($dot !== false) {
        $matched = substr($matched, $dot + 1);
    }

    return strtolower(trim($matched, "`\"[]"));
}

if (($argv[1] ?? '') === '--help' || ($argv[1] ?? '') === '-h') {
    fwrite(STDOUT, "Usage : php scripts/check_sql_structure.php [racine]\n");
    fwrite(STDOUT, "Vérifie SELECT *, INSERT sans colonnes et écritures directes form_fields/steps/step_recipients.\n");
    exit(0);
}

$root = rtrim(str_replace('\\', '/', $argv[1] ?? dirname(__DIR__)), '/');
if (!is_dir($root)) {
    fwrite(STDERR, "ERREUR: répertoire introuvable: {$root}\n");
    exit(2);
}

$files = collectFiles($root);
if ($files === []) {
    fwrite(STDERR, "ERREUR: aucun fichier PHP trouvé sous {$root} (src/, classes/, lib/, *.php).\n");
    exit(2);
}

/** @var list<array{rule:string,file:string,line:int,text:string}> $violations */
$violations = [];
/** @var array<string, array<int, true>> $usedAllowlist */
$usedAllowlist = [];
$ioErrors = 0;

foreach ($files as $file) {
    $source = file_get_contents($file);
    if ($source === false) {
        fwrite(STDERR, "ERREUR: lecture impossible: {$file}\n");
        $ioErrors++;
        continue;
    }

    try {
        $masked = maskComments($source);
    } catch (ParseError $e) {
        fwrite(STDERR, "ERREUR: parse error {$file}: {$e->getMessage()}\n");
        $ioErrors++;
        continue;
    }

    $rel = relativePath($root, $file);
    $lines = preg_split('/\r\n|\n|\r/', $source) ?: [];
    $isSrc = str_starts_with($rel, 'src/');
    $isClasses = str_starts_with($rel, 'classes/');
    $isMigrations = str_starts_with($rel, 'classes/migrations/');

    // ── Règle 1 : SELECT * (src/ et classes/migrations/) ─────────────
    if ($isSrc || $isMigrations) {
        if (preg_match_all('/\bselect\s+(?:distinct\s+)?\*/i', $masked, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$text, $offset]) {
                $line = lineAt($masked, $offset);
                $lineText = $lines[$line - 1] ?? '';
                $expected = SELECT_STAR_ALLOWLIST[$rel][$line] ?? null;
                if (is_string($expected) && str_contains($lineText, $expected)) {
                    $usedAllowlist[$rel][$line] = true;
                    continue;
                }
                $violations[] = [
                    'rule' => 'select_star',
                    'file' => $rel,
                    'line' => $line,
                    'text' => trim($lineText),
                ];
            }
        }
    }

    // ── Règle 2 : INSERT applicatif sans liste de colonnes ───────────
    // classes/ exclu : schémas, seeds et migrations reconstruisent les tables.
    if (!$isClasses) {
        $insertPattern = '~\binsert\s+(?:or\s+\w+\s+)?into\s+[`"\[]?[\w.]+[`"\]]?'
            . "(?:\s*['\"]\s*\.\s*['\"])?\s*(\(|values\b|select\b|default\b)~i";
        if (preg_match_all($insertPattern, $masked, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $index => [$keyword]) {
                if (strtolower($keyword) === '(') {
                    continue;
                }
                $offset = $matches[0][$index][1];
                $line = lineAt($masked, $offset);
                $violations[] = [
                    'rule' => 'insert_no_columns',
                    'file' => $rel,
                    'line' => $line,
                    'text' => trim($lines[$line - 1] ?? ''),
                ];
            }
        }
    }
    $writeAllowed = array_any(WRITE_ALLOWED_PREFIXES, fn(string $prefix): bool => str_starts_with($rel, $prefix));
    if (!$writeAllowed) {
        $writePattern = '~\b(?:insert\s+(?:or\s+\w+\s+)?into|replace\s+into'
            . '|update(?:\s+or\s+\w+)?|delete\s+from)\s+([`"\[]?)([\w.]+)\1~i';
        if (preg_match_all($writePattern, $masked, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[2] as $index => [$rawTable]) {
                if (!in_array(tableName($rawTable), TARGET_TABLES, true)) {
                    continue;
                }
                $offset = $matches[0][$index][1];
                $line = lineAt($masked, $offset);
                $violations[] = [
                    'rule' => 'direct_write',
                    'file' => $rel,
                    'line' => $line,
                    'text' => trim($lines[$line - 1] ?? ''),
                ];
            }
        }
    }
}

$stale = [];
foreach (SELECT_STAR_ALLOWLIST as $rel => $entries) {
    foreach ($entries as $line => $expected) {
        if (!isset($usedAllowlist[$rel][$line])) {
            $stale[] = "{$rel}:{$line} (allowlist obsolète, extrait attendu: {$expected})";
        }
    }
}

$messages = [
    'select_star' => 'SELECT * interdit : lister explicitement les colonnes (src/, classes/migrations/).',
    'insert_no_columns' => 'INSERT sans liste explicite de colonnes : écrire INSERT INTO table (col1, col2, ...) VALUES (...).',
    'direct_write' => 'Écriture SQL directe vers ' . implode('/', TARGET_TABLES)
        . ' hors src/Repository/ et classes/migrations/ : passer par le Repository.',
];

foreach ($violations as $violation) {
    $text = mb_substr($violation['text'], 0, 160);
    fwrite(STDOUT, sprintf(
        "%s:%d [%s] %s\n    %s\n",
        $violation['file'],
        $violation['line'],
        $violation['rule'],
        $messages[$violation['rule']],
        $text
    ));
}

foreach ($stale as $entry) {
    fwrite(STDOUT, "ALLOWLIST [stale_allowlist] {$entry}\n");
}

if ($ioErrors > 0) {
    fwrite(STDERR, "ERREUR: {$ioErrors} fichier(s) illisible(s)/invalide(s).\n");
    exit(2);
}

if ($violations !== [] || $stale !== []) {
    fwrite(STDERR, sprintf(
        "ÉCHEC garde SQL structurelle : %d violation(s), %d entrée(s) d'allowlist obsolète(s) (%d fichiers analysés).\n",
        count($violations),
        count($stale),
        count($files)
    ));
    exit(1);
}

fwrite(STDOUT, sprintf("OK garde SQL structurelle : 0 violation (%d fichiers analysés).\n", count($files)));
exit(0);