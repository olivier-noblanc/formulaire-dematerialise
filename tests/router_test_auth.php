<?php
file_put_contents(sys_get_temp_dir() . '/router_debug.log', date('Y-m-d H:i:s') . " REQUEST_URI=" . $_SERVER['REQUEST_URI'] . " HTTP_AUTH_USER=" . ($_SERVER['HTTP_AUTH_USER'] ?? 'NOT SET') . "\n", FILE_APPEND);

if (!empty($_SERVER['HTTP_AUTH_USER']) && empty($_SERVER['AUTH_USER'])) {
    $_SERVER['AUTH_USER'] = $_SERVER['HTTP_AUTH_USER'];
}
if (!empty($_SERVER['HTTP_REMOTE_USER']) && empty($_SERVER['REMOTE_USER'])) {
    $_SERVER['REMOTE_USER'] = $_SERVER['HTTP_REMOTE_USER'];
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/../index.php';
    return true;
}
$file = __DIR__ . '/../' . ltrim($uri, '/');
if (preg_match('/\.(php)$/', $uri) && is_file($file)) {
    require $file;
    return true;
}
if (is_file($file)) {
    return false;
}
http_response_code(404);
echo '404 Not Found: ' . $uri;
return true;
