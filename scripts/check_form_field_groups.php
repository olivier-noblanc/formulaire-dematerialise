#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Garde CI — dérive de la liste des groupes `form_fields.card_group`.
 *
 * Contexte : les libellés `card_group` du seed (`seed_default_forms.php`) sont
 * écrits en clair, ligne par ligne, pour rester lisibles. La migration v47 doit
 * connaître exactement ces groupes pour reconnaître (ou refuser) la rotation
 * `card_group`/`hint` sans jamais convertir un texte isolé. La liste est donc
 * partagée dans `classes/migrations/form_field_groups.php`.
 *
 * Ce script rend la duplication NON silencieuse, dans les deux sens :
 *   1. seed_only   un groupe utilisé par le seed doit exister dans la liste
 *                  partagée (sinon v47 refuserait une rotation légitime) ;
 *   2. list_only   un groupe de la liste partagée doit être seedé, sauf
 *                  `Général` qui est la valeur par défaut (jamais seedée) ;
 *   3. consumer    `v47.php` doit consommer `default_form_field_card_groups()`
 *                  et ne pas réintroduire sa propre liste littérale.
 *
 * Aucune dépendance, aucune base de données, aucun bootstrap applicatif.
 *
 * Usage   : php scripts/check_form_field_groups.php [racine]
 * Sortie  : 0 = conforme, 1 = dérive détectée, 2 = usage/IO.
 */

const GROUPS_SOURCE = 'classes/migrations/form_field_groups.php';
const SEED_SOURCE = 'classes/migrations/seed_default_forms.php';
const V47_SOURCE = 'classes/migrations/v47.php';

/** Groupes légitimement absents du seed (valeur par défaut). */
const DEFAULT_ONLY_GROUPS = ['Général'];

/**
 * Décode une chaîne littérale PHP entre apostrophes (`\\` et `\'` uniquement).
 */
function unescape_php_single(string $value): string
{
    return str_replace(["\\\\", "\\'"], ["\\", "'"], $value);
}

/**
 * Extrait les `card_group` littéraux des tableaux `$*_fields` du seed.
 *
 * Les blocs sont délimités par `$xxx_fields = [` … `];` et chaque ligne de champ
 * commence par `['<card_group>',`. Le format du seed est volontairement simple :
 * si la forme change, les groupes ne sont plus extraits et le contrôle
 * bidirectionnel échoue bruyamment (jamais de dérive silencieuse).
 *
 * @return list<array{group:string, line:int}>
 */
function extract_seed_groups(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        fwrite(STDERR, "IO: impossible de lire {$path}\n");
        exit(2);
    }

    $groups = [];
    $inBlock = false;
    foreach ($lines as $index => $line) {
        $lineNo = $index + 1;

        if (!$inBlock) {
            if (preg_match('/^\s*\$[A-Za-z_][A-Za-z0-9_]*_fields\s*=\s*\[\s*$/', $line) === 1) {
                $inBlock = true;
            }
            continue;
        }

        if (preg_match('/^\s*\];\s*$/', $line) === 1) {
            $inBlock = false;
            continue;
        }

        if (preg_match("/^\s*\[\s*'((?:[^'\\\\]|\\\\.)*)'\s*,/", $line, $m) === 1) {
            $groups[] = ['group' => unescape_php_single($m[1]), 'line' => $lineNo];
        }
    }

    return $groups;
}

$root = $argv[1] ?? dirname(__DIR__);
if (!is_dir($root)) {
    fwrite(STDERR, "Usage: php scripts/check_form_field_groups.php [racine]\n");
    exit(2);
}
$root = rtrim($root, "/\\");

foreach ([GROUPS_SOURCE, SEED_SOURCE, V47_SOURCE] as $relative) {
    if (!is_file($root . '/' . $relative)) {
        fwrite(STDERR, "IO: fichier introuvable — {$relative}\n");
        exit(2);
    }
}

require_once $root . '/' . GROUPS_SOURCE;
if (!function_exists('default_form_field_card_groups')) {
    fwrite(STDERR, "ERREUR: default_form_field_card_groups() absente de " . GROUPS_SOURCE . "\n");
    exit(1);
}

$shared = default_form_field_card_groups();
$duplicates = array_keys(array_filter(array_count_values($shared), static fn(int $n): bool => $n > 1));
$seedGroups = extract_seed_groups($root . '/' . SEED_SOURCE);
$seedSet = [];
foreach ($seedGroups as $row) {
    $seedSet[$row['group']] = true;
}

$violations = [];

if ($duplicates !== []) {
    $violations[] = 'groups_duplicate   ' . GROUPS_SOURCE . ' : doublons ' . implode(', ', $duplicates);
}

if ($seedGroups === []) {
    $violations[] = 'seed_parse         ' . SEED_SOURCE
        . ' : aucun groupe extrait (format des blocs `$*_fields` modifié ?)';
}

$sharedSet = array_fill_keys($shared, true);
foreach ($seedGroups as $row) {
    if (!isset($sharedSet[$row['group']])) {
        $violations[] = 'seed_only          ' . SEED_SOURCE . ':' . $row['line']
            . ' groupe "' . $row['group'] . '" absent de ' . GROUPS_SOURCE;
    }
}

foreach ($shared as $group) {
    if (isset($seedSet[$group])) {
        continue;
    }
    if (in_array($group, DEFAULT_ONLY_GROUPS, true)) {
        continue;
    }
    $violations[] = 'list_only          ' . GROUPS_SOURCE . ' : groupe "' . $group
        . '" jamais seedé dans ' . SEED_SOURCE;
}

$v47Source = file_get_contents($root . '/' . V47_SOURCE);
if ($v47Source === false) {
    fwrite(STDERR, 'IO: impossible de lire ' . V47_SOURCE . "\n");
    exit(2);
}
if (!str_contains($v47Source, 'default_form_field_card_groups()')) {
    $violations[] = 'consumer_missing   ' . V47_SOURCE
        . ' : ne consomme pas default_form_field_card_groups()';
}
if (preg_match('/\$legacyGroups\s*=\s*\[/', $v47Source) === 1) {
    $violations[] = 'consumer_literal   ' . V47_SOURCE
        . ' : liste littérale `$legacyGroups = [` réintroduite (duplication)';
}

if ($violations !== []) {
    fwrite(STDERR, "Dérive des groupes card_group détectée (" . count($violations) . ") :\n");
    foreach ($violations as $violation) {
        fwrite(STDERR, '  - ' . $violation . "\n");
    }
    fwrite(STDERR, "\nCorriger " . GROUPS_SOURCE . " et/ou " . SEED_SOURCE
        . " pour qu'ils restent alignés.\n");
    exit(1);
}

printf(
    "OK — %d groupes partagés, %d littéraux de seed alignés (%s et %s cohérents).\n",
    count($shared),
    count($seedGroups),
    GROUPS_SOURCE,
    V47_SOURCE
);
exit(0);