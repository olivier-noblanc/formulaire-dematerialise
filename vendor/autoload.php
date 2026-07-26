<?php

// Minimal autoload.php for local PHPUnit testing.
$baseDir = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($baseDir): void {
    if (str_starts_with($class, 'App\\Tests\\')) {
        $relative = substr($class, 10);
        $file = $baseDir . '/tests/PHPUnit/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
        return;
    }
    if (str_starts_with($class, 'App\\')) {
        $relative = substr($class, 4);
        $file = $baseDir . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
        return;
    }
    if (str_starts_with($class, 'PHPMailer\\PHPMailer\\')) {
        $relative = substr($class, 18);
        $file = $baseDir . '/vendor/PHPMailer/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';
