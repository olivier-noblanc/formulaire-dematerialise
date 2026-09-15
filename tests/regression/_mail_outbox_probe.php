<?php
declare(strict_types=1);

/**
 * Sonde outbox SMTP — exécutée en sous-processus HORS TEST_MODE (voir
 * tests/PHPUnit/MailOutboxWriteAheadTest.php). En TEST_MODE,
 * MailService::sendDetailed() court-circuite la logique réelle : le write-ahead
 * n'est pas exerçable depuis un TestCase PHPUnit classique.
 *
 * Usage : php _mail_outbox_probe.php <scenario> <scratchDb>
 *   scenario = 'sent' | 'smtp_down' | 'insert_fail'
 *
 * Sortie : une ligne JSON sur stdout = { scenario, result, row }.
 *   - sent        : envoi réel contre un faux serveur SMTP local → sent
 *   - smtp_down   : SMTP injoignable (port fermé) → error + corps conservé
 *   - insert_fail : mail_log en lecture seule → write-ahead impossible,
 *                   l'envoi est annulé SANS contacter le SMTP
 *
 * Fichier : tests/regression/_mail_outbox_probe.php
 */

$scenario  = $argv[1] ?? 'smtp_down';
$scratchDb = $argv[2] ?? (sys_get_temp_dir() . '/mail_outbox_probe_' . getmypid() . '.db');
foreach ([$scratchDb, $scratchDb . '-wal', $scratchDb . '-shm'] as $f) {
    @unlink($f);
}

define('DEFAULT_DB_PATH', $scratchDb);
define('DB_PATH', $scratchDb);
define('BASE_URL', 'http://localhost');
define('SETTINGS_DEFAULTS', [
    'smtp_host' => '', 'smtp_port' => '25',
    'smtp_from' => 'noreply@example.com', 'smtp_from_name' => 'CircuitDemat',
    'admin_email' => 'admin@localhost', 'email_domain' => 'localhost',
    'app_name' => 'CircuitDémat',
]);

require __DIR__ . '/../../helpers.php';

$pdo = \App\Core\App::getInstance()->get(\App\Core\Database::class)->getPdo();

\App\Core\App::settings()->set('mail_dry_run', '0', 'probe');
\App\Core\App::settings()->set('smtp_from', 'noreply@example.com', 'probe');
\App\Core\App::settings()->set('smtp_from_name', 'CircuitDemat', 'probe');

$serverProc = null;
$markerFile = '';
$serverStderr = '';

try {
    if ($scenario === 'sent') {
        // Port libre : on lie puis on relâche, puis on sert ce port.
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $e, $s);
        if ($probe === false) {
            throw new \RuntimeException('impossible de trouver un port libre: ' . $s);
        }
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $markerFile = $scratchDb . '.smtp.ready';
        @unlink($markerFile);
        $serverCmd = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(__DIR__ . '/_fake_smtp_server.php') . ' '
            . escapeshellarg((string) $port) . ' '
            . escapeshellarg($markerFile) . ' 1';
        $serverProc = proc_open(
            $serverCmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $serverPipes,
            __DIR__
        );
        if (!is_resource($serverProc)) {
            throw new \RuntimeException('proc_open() du faux serveur SMTP a échoué');
        }
        fclose($serverPipes[0]);
        // Attendre le marker (le serveur écoute) sans consommer de message.
        $deadline = microtime(true) + 5.0;
        while (!is_file($markerFile) && microtime(true) < $deadline) {
            usleep(50000);
        }
        if (!is_file($markerFile)) {
            $serverStderr = (string) stream_get_contents($serverPipes[2]);
            throw new \RuntimeException('faux serveur SMTP non prêt: ' . $serverStderr);
        }

        \App\Core\App::settings()->set('smtp_host', '127.0.0.1', 'probe');
        \App\Core\App::settings()->set('smtp_port', (string) $port, 'probe');
    } elseif ($scenario === 'smtp_down') {
        // 127.0.0.1:1 → connexion refusée immédiatement.
        \App\Core\App::settings()->set('smtp_host', '127.0.0.1', 'probe');
        \App\Core\App::settings()->set('smtp_port', '1', 'probe');
    } elseif ($scenario === 'insert_fail') {
        // smtp_host vide : si transmit() était atteint, le statut serait
        // 'blocked'. La base en lecture seule force l'échec du write-ahead.
        \App\Core\App::settings()->set('smtp_host', '', 'probe');
        \App\Core\App::settings()->set('smtp_port', '1', 'probe');
        $pdo->exec('PRAGMA query_only = ON');
    } else {
        throw new \RuntimeException('scénario inconnu: ' . $scenario);
    }

    $result = \App\Core\App::mail()->sendDetailed('probe-outbox@test.local', 'Sujet sonde', '<p>Corps sonde outbox</p>');

    // SELECT reste autorisé même sous PRAGMA query_only.
    $stmt = $pdo->query(
        "SELECT status, body_html, attempts, next_retry_at FROM mail_log
         WHERE recipient = 'probe-outbox@test.local' ORDER BY created_at DESC LIMIT 1"
    );
    $row = $stmt !== false ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
    $stmt = null;

    echo json_encode([
        'scenario' => $scenario,
        'result' => $result,
        'row' => $row === false ? null : $row,
    ]) . "\n";
} catch (\Throwable $e) {
    echo json_encode([
        'scenario' => $scenario,
        'probe_error' => $e->getMessage(),
    ]) . "\n";
    exit(3);
} finally {
    if (is_resource($serverProc)) {
        // Draine les pipes pour éviter tout blocage, puis arrête le serveur.
        foreach ([1, 2] as $i) {
            if (isset($serverPipes[$i]) && is_resource($serverPipes[$i])) {
                stream_set_blocking($serverPipes[$i], false);
                stream_get_contents($serverPipes[$i]);
                fclose($serverPipes[$i]);
            }
        }
        @proc_terminate($serverProc);
        @proc_close($serverProc);
    }
    if ($markerFile !== '') {
        @unlink($markerFile);
    }
}