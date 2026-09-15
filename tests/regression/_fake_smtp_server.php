<?php
declare(strict_types=1);

/**
 * Faux serveur SMTP minimal (tests) — parle juste assez SMTP pour qu'un envoi
 * PHPMailer sans auth ni TLS réussisse. Sert à prouver le cycle `sent` de
 * l'outbox write-ahead sans dépendre d'un vrai relais.
 *
 * Usage : php _fake_smtp_server.php <port> <markerFile> [maxMessages]
 *   - écrit <markerFile> dès que le socket écoute (le parent attend ce marker
 *     au lieu de se connecter, pour ne pas consommer un message) ;
 *   - répond aux commandes SMTP usuelles, se termine après maxMessages (déf. 1).
 *
 * Fichier : tests/regression/_fake_smtp_server.php
 */

$port   = (int) ($argv[1] ?? 0);
$marker = $argv[2] ?? '';
$max    = (int) ($argv[3] ?? 1);

$errno = 0;
$errstr = '';
$server = @stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "fake_smtp: bind 127.0.0.1:$port impossible: $errstr ($errno)\n");
    exit(2);
}

if ($marker !== '') {
    @file_put_contents($marker, 'ready');
}

$handled = 0;
$deadline = time() + 60;
while ($handled < $max && time() < $deadline) {
    $conn = @stream_socket_accept($server, 5);
    if ($conn === false) {
        continue;
    }
    stream_set_timeout($conn, 5);
    fwrite($conn, "220 fake-smtp ready\r\n");

    $inData = false;
    while (($line = fgets($conn, 2048)) !== false) {
        $trimmed = rtrim($line, "\r\n");
        if ($inData) {
            if ($trimmed === '.') {
                fwrite($conn, "250 OK queued\r\n");
                $inData = false;
            }
            continue;
        }
        $upper = strtoupper($trimmed);
        if (str_starts_with($upper, 'EHLO') || str_starts_with($upper, 'HELO')) {
            fwrite($conn, "250-fake-smtp\r\n250 SIZE 10485760\r\n");
        } elseif (str_starts_with($upper, 'DATA')) {
            fwrite($conn, "354 End data with <CR><LF>.<CR><LF>\r\n");
            $inData = true;
        } elseif (str_starts_with($upper, 'QUIT')) {
            fwrite($conn, "221 Bye\r\n");
            break;
        } else {
            // MAIL FROM / RCPT TO / RSET / NOOP / AUTH...
            fwrite($conn, "250 OK\r\n");
        }
    }
    fclose($conn);
    $handled++;
}

fclose($server);
exit(0);