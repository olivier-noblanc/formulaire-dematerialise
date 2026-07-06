<?php
declare(strict_types=1);

/**
 * Autoloader PSR-4 manuel (sans Composer).
 * Namespace App\ → dossier src/
 * Compatible PHP 7.4+ — déployé manuellement (serveur offline)
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $prefixLen = strlen($prefix);

    // Normaliser le chemin de base (compatible Windows + Linux)
    $basePath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'src';
    if (!is_dir($basePath)) return;
    $base_dir = realpath($basePath);
    if ($base_dir === false) return;
    $base_dir .= DIRECTORY_SEPARATOR;

    if (substr($class, 0, $prefixLen) !== $prefix) return;

    $relative_class = substr($class, $prefixLen);
    $file = $base_dir . str_replace('\\', DIRECTORY_SEPARATOR, $relative_class) . '.php';

    if (file_exists($file)) require $file;
});
