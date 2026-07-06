<?php
declare(strict_types=1);

/**
 * Autoloader PSR-4 manuel (sans Composer).
 * Namespace App\ → dossier src/
 */
spl_autoload_register(function (string $class): void {
    // Ne charger que les classes App\
    if (!str_starts_with($class, 'App\\')) return;

    // Convertir App\Core\Config → Core/Config
    $relative = str_replace(['App\\', '\\'], ['', '/'], $class);
    
    // Construire le chemin: lib/../src/Core/Config.php
    $file = dirname(__DIR__) . '/src/' . $relative . '.php';
    
    // Sur Windows, normaliser les slashes
    $file = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);
    
    if (is_file($file)) {
        require $file;
    }
});
