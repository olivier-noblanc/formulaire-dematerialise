<?php
/**
 * Cross-platform wrapper to start PHP built-in server for E2E tests.
 *
 * Sets test env vars via putenv() and starts the server in background.
 * Writes PID to a file for the caller to manage.
 *
 * Usage: php start_server.php <port> <docroot> <pidfile>
 */
if ($argc < 4) {
    fwrite(STDERR, "Usage: php start_server.php <port> <docroot> <pidfile>\n");
    exit(1);
}

$port = (int) $argv[1];
$docRoot = $argv[2];
$pidFile = $argv[3];
$routerPath = $docRoot . DIRECTORY_SEPARATOR . 'router.php';

// Set test environment variables
putenv('APP_TEST_MODE=1');
putenv('APP_TEST_SECRET=1');
putenv('AUTH_USER=admin@exemple.invalid');

// Write PID file
file_put_contents($pidFile, (string) getmypid());

$phpBin = PHP_BINARY;
$serverCmd = $phpBin . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($docRoot) . ' ' . escapeshellarg($routerPath);

if (PHP_OS_FAMILY === 'Windows') {
    // On Windows, use COM to start process in background
    $wsh = new COM('WScript.Shell');
    $wsh->Run('cmd /c ' . $serverCmd . ' > NUL 2>&1', 0, false);
    // Exit immediately
    exit(0);
} else {
    // On Linux, passthru blocks — proc_open manages the lifecycle
    passthru($serverCmd, $exitCode);
    @unlink($pidFile);
    exit($exitCode);
}
