<?php
declare(strict_types=1);

/**
 * Sonde exécutée en sous-processus HORS TEST_MODE (voir Bug13Test.php).
 * PHPUnit tourne toujours avec TEST_MODE=1, qui court-circuite
 * MailService::sendDetailed() avant d'atteindre la logique réelle —
 * impossible de tester ce chemin depuis un TestCase classique.
 *
 * Usage : php _mail_send_probe.php <scenario>
 *   scenario = 'dry_run' | 'blocked'
 */
$scratchDb = $argv[2] ?? (sys_get_temp_dir() . '/mail_probe_' . getmypid() . '.db');
define('DEFAULT_DB_PATH', $scratchDb);
define('DB_PATH', $scratchDb);
define('BASE_URL', 'http://localhost');
define('SETTINGS_DEFAULTS', [
    'smtp_host' => '', 'smtp_port' => '25',
    'smtp_from' => 'test@localhost', 'smtp_from_name' => 'CircuitDemat',
    'app_name' => 'CircuitDémat',
]);

require __DIR__ . '/../../helpers.php';

$scenario = $argv[1] ?? 'dry_run';

\App\Core\App::settings()->set('mail_dry_run', '1', 'probe');

$mail = \App\Core\App::mail();

if ($scenario === 'blocked') {
    // Adresse invalide : doit être bloquée par sendDetailed() ET send().
    $sendResult = $mail->send('adresse-invalide-sans-arobase', 'Sujet sonde', '<p>Corps</p>');
    $detailedResult = $mail->sendDetailed('adresse-invalide-sans-arobase', 'Sujet sonde 2', '<p>Corps</p>');
} else {
    $sendResult = $mail->send('probe-dry-run@test.local', 'Sujet sonde', '<p>Corps</p>');
    $detailedResult = $mail->sendDetailed('probe-dry-run@test.local', 'Sujet sonde 2', '<p>Corps</p>');
}

echo json_encode([
    'send_bool' => $sendResult,
    'send_detailed' => $detailedResult,
]) . "\n";
