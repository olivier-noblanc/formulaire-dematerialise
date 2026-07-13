<?php
// Simple router for PHP built-in server
// NOTE: This file is ONLY used by `php -S` (dev server). IIS uses index.php directly.
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Simulate IIS AUTH_USER for dev server only
// Reads from DEV_AUTH_USER env var — NEVER hardcode a real email here
if (php_sapi_name() === 'cli-server' && empty($_SERVER['AUTH_USER'])) {
    $devUser = getenv('DEV_AUTH_USER') ?: '';
    if ($devUser !== '') {
        $_SERVER['AUTH_USER'] = $devUser;
    }
}

// Serve static files if they exist
$publicPath = __DIR__ . $uri;
if (php_sapi_name() === 'cli-server' && is_file($publicPath) && preg_match('/\.(css|js|png|jpg|gif|ico|svg)$/', (string)$uri)) {
    return false; // Let the server handle static files
}

// Route to the actual PHP file
$script = __DIR__ . $uri;
if (is_file($script) && preg_match('/\.php$/', (string)$uri)) {
    require $script;
    return true;
}

// Default: try index.php
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/index.php';
    return true;
}

// 404
require_once __DIR__ . '/helpers.php';
(new \App\Render\ErrorRenderer())->errorPage(404, 'Page introuvable',
    'La page que vous cherchez n\'existe pas sur ce serveur.',
    'Vérifiez l\'adresse dans votre navigateur. Si vous avez suivi un lien, il est peut-être obsolète.');
