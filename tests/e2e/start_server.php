<?php
/**
 * Cross-platform wrapper to start PHP built-in server for E2E tests.
 *
 * Sets test env vars via putenv() and starts the server in background.
 * Writes PID to a file for the caller to manage.
 *
 * Usage: php start_server.php <port> <docroot> [pidfile]
 *
 * pidfile est optionnel : le seul appelant actuel (HttpRouteTest) gère le
 * process via le handle proc_open() retourné directement et ne le lit jamais.
 */
if ($argc < 3) {
    fwrite(STDERR, "Usage: php start_server.php <port> <docroot> [pidfile]\n");
    exit(1);
}

$port = (int) $argv[1];
$docRoot = $argv[2];
$pidFile = $argv[3] ?? null;
$routerPath = $docRoot . DIRECTORY_SEPARATOR . 'router.php';

// Set test environment variables
putenv('APP_TEST_MODE=1');
putenv('APP_TEST_SECRET=1');
putenv('AUTH_USER=admin@dreets.gouv.fr');

// Write PID file (only if the caller provided one)
if ($pidFile !== null) {
    file_put_contents($pidFile, (string) getmypid());
}

$phpBin = PHP_BINARY;
// expose_php=0 : évite la fuite de l'en-tête X-Powered-By, propre au serveur de
// dev PHP intégré (IIS/Apache en prod ne l'exposent pas nativement — voir
// testNoServerHeaderLeak dans HttpRouteTest.php).
$serverCmd = $phpBin . ' -d expose_php=0 -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($docRoot) . ' ' . escapeshellarg($routerPath);

if (PHP_OS_FAMILY === 'Windows') {
    // On Windows, use COM to start process in background
    $wsh = new COM('WScript.Shell');
    $wsh->Run('cmd /c ' . $serverCmd . ' > NUL 2>&1', 0, false);
    // Exit immediately
    exit(0);
} else {
    // On Linux, passthru blocks — proc_open manages the lifecycle
    passthru($serverCmd, $exitCode);
    if ($pidFile !== null) {
        @unlink($pidFile);
    }
    exit($exitCode);
}
