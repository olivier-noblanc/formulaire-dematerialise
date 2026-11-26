<?php

declare(strict_types=1);

/**
 * check_vendor_autoload.php — garde-fou « vendor versionné = no-dev ».
 *
 * Le projet est déployé sur un serveur Windows hors-ligne où `composer install`
 * n'est pas possible : `vendor/autoload.php` et `vendor/composer/autoload_*.php`
 * sont donc VERSIONNÉS et doivent rester cohérents avec un `composer
 * dump-autoload --no-dev`. Régénérés en mode dev (dépendances de require-dev
 * installées), ils référencent des paquets absents de l'export versionné
 * (amphp/*, phpstan/*…) et `vendor/autoload.php` meurt sur
 * `require vendor/composer/../amphp/amp/src/functions.php`.
 *
 * Deux contrôles :
 *   1. (toujours) aucun chemin d'autoload ne pointe vers un paquet présent
 *      uniquement dans `require-dev` (composer.lock → packages-dev) ;
 *   2. (--require-exists) chaque chemin référencé existe réellement sur le
 *      disque — à utiliser sur un export frais (fichiers versionnés seuls).
 *
 * À exécuter DEPUIS la racine du projet (ou de l'export) :
 *   php scripts/check_vendor_autoload.php                  # arbre de dev
 *   php scripts/check_vendor_autoload.php --require-exists # export frais (CI)
 *
 * Exit codes : 0 = cohérent, 1 = incohérence détectée, 2 = environnement.
 */

$root = getcwd();
if ($root === false) {
    fwrite(STDERR, "Impossible de déterminer le répertoire courant.\n");
    exit(2);
}

$requireExists = in_array('--require-exists', $argv ?? [], true);

if (!is_file($root . '/vendor/autoload.php') || !is_dir($root . '/vendor/composer')) {
    fwrite(STDERR, "vendor/autoload.php ou vendor/composer/ introuvable dans $root\n");
    exit(2);
}

/**
 * autoload_static.php construit ses chemins en `$vendorDir . '/composer/../pkg'` :
 * on résout lexicalement les segments `.` et `..` avant toute analyse
 * (impossible d'utiliser realpath(), les cibles peuvent être absentes).
 */
function normalizeAutoloadPath(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $drive = '';
    if (preg_match('#^([A-Za-z]):/#', $path, $matches) === 1) {
        $drive = $matches[1] . ':';
        $path = substr($path, 2);
    }
    $isAbsolute = str_starts_with($path, '/');
    $stack = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..' && $stack !== [] && end($stack) !== '..') {
            array_pop($stack);
            continue;
        }
        $stack[] = $segment;
    }

    return $drive . ($isAbsolute ? '/' : '') . implode('/', $stack);
}

/**
 * PHPMailer est versionné sous vendor/PHPMailer/src (chargé à la main par
 * lib/core_bootstrap.php) alors que l'install-path Composer reste
 * vendor/phpmailer/phpmailer/src. On normalise l'alias avant vérification
 * d'existence ; le paquet reste `phpmailer/phpmailer` (prod) côté analyse.
 */
function resolveOfflineAlias(string $normalizedPath): string
{
    return str_replace('/phpmailer/phpmailer/src', '/PHPMailer/src', $normalizedPath);
}

// ─── Paquets dev-only (composer.lock) ───────────────────────────────────────
$devOnly = [];
$lockPath = $root . '/composer.lock';
if (is_file($lockPath)) {
    /** @var array{packages?: list<array{name: string}>, packages-dev?: list<array{name: string}>}|null $lock */
    $lock = json_decode((string) file_get_contents($lockPath), true);
    if (!is_array($lock)) {
        fwrite(STDERR, "composer.lock illisible (JSON invalide).\n");
        exit(2);
    }
    $prod = array_column($lock['packages'] ?? [], 'name');
    $dev = array_column($lock['packages-dev'] ?? [], 'name');
    $devOnly = array_values(array_diff($dev, $prod));
}

// ─── Collecte des chemins référencés ────────────────────────────────────────
/** @var list<array{source: string, path: string}> $entries */
$entries = [];

foreach (['autoload_psr4.php', 'autoload_namespaces.php', 'autoload_files.php', 'autoload_classmap.php'] as $file) {
    $path = $root . '/vendor/composer/' . $file;
    if (!is_file($path)) {
        continue;
    }
    /** @var mixed $map */
    $map = require $path;
    if (!is_array($map)) {
        continue;
    }
    foreach ($map as $value) {
        foreach ((array) $value as $entry) {
            if (is_string($entry)) {
                $entries[] = ['source' => $file, 'path' => $entry];
            }
        }
    }
}

// autoload_static.php est le fichier réellement consommé par autoload_real.php :
// on le vérifie aussi, via réflexion, pour attraper une divergence/hand-edit.
$staticPath = $root . '/vendor/composer/autoload_static.php';
if (is_file($staticPath)) {
    $before = get_declared_classes();
    require $staticPath;
    $new = array_values(array_diff(get_declared_classes(), $before));
    foreach ($new as $class) {
        if (!str_starts_with($class, 'Composer\Autoload\ComposerStaticInit')) {
            continue;
        }
        $ref = new ReflectionClass($class);
        foreach (['prefixDirsPsr4', 'prefixesPsr0', 'classMap', 'files'] as $prop) {
            if (!$ref->hasProperty($prop)) {
                continue;
            }
            /** @var mixed $value */
            $value = $ref->getStaticPropertyValue($prop);
            foreach ((array) $value as $item) {
                foreach ((array) $item as $entry) {
                    if (is_string($entry)) {
                        $entries[] = ['source' => 'autoload_static.php::$' . $prop, 'path' => $entry];
                    }
                }
            }
        }
    }
}

// ─── Analyse ────────────────────────────────────────────────────────────────
$devRefs = [];
$missing = [];
$vendorPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/vendor/';

foreach ($entries as $entry) {
    $raw = $entry['path'];
    $normalized = normalizeAutoloadPath($raw);

    if ($requireExists && !file_exists(resolveOfflineAlias($normalized))) {
        $missing[] = $entry['source'] . ' -> ' . $raw;
    }

    if (!str_starts_with($normalized, $vendorPrefix)) {
        continue; // src/, phpstan-rules/, rector-rules/… : code du projet
    }
    $rest = substr($normalized, strlen($vendorPrefix));
    $parts = explode('/', $rest);
    if (count($parts) < 2) {
        continue; // vendor/autoload.php, vendor/composer/…
    }
    $package = $parts[0] . '/' . $parts[1];
    if (in_array($package, $devOnly, true)) {
        $devRefs[$package][$entry['source']] = true;
    }
}

$failed = false;

if ($devRefs !== []) {
    $failed = true;
    ksort($devRefs);
    fwrite(STDERR, "Autoload versionné généré en mode DEV : chemins vers des paquets require-dev.\n");
    fwrite(STDERR, "Corrige avec : composer dump-autoload --no-dev (puis commit de vendor/composer/)\n\n");
    $shown = 0;
    foreach ($devRefs as $package => $sources) {
        if ($shown >= 15) {
            fwrite(STDERR, sprintf("  … et %d autre(s) paquet(s) dev-only.\n", count($devRefs) - $shown));
            break;
        }
        fwrite(STDERR, '  - ' . $package . ' (' . implode(', ', array_keys($sources)) . ")\n");
        $shown++;
    }
    fwrite(STDERR, "\n");
}

if ($missing !== []) {
    $failed = true;
    fwrite(STDERR, "Chemins référencés par l'autoload mais absents du disque :\n\n");
    foreach (array_slice($missing, 0, 20) as $ref) {
        fwrite(STDERR, '  - ' . $ref . "\n");
    }
    if (count($missing) > 20) {
        fwrite(STDERR, sprintf("  … et %d autre(s) chemin(s) manquant(s).\n", count($missing) - 20));
    }
    fwrite(STDERR, "\n");
}

if ($failed) {
    exit(1);
}

printf(
    "Autoload cohérent : %d chemins référencés, 0 paquet dev-only%s.\n",
    count($entries),
    $requireExists ? ', 0 chemin manquant' : ''
);
exit(0);