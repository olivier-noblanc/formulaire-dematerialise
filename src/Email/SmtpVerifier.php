<?php

declare(strict_types=1);

namespace App\Email;

/**
 * Vérification d'adresse email via probe SMTP (RCPT TO).
 *
 * Extrait de EmailVerificationService (H-01, 2026-08-05).
 */
final readonly class SmtpVerifier
{
    /**
     * Vérifie qu'une adresse email existe via une probe SMTP (RCPT TO).
     *
     * @return array{ok: bool, method: string, detail: string}
     */
    public function verify(string $email): array
    {
        $smtp_host   = \App\Core\App::settings()->get('smtp_host');
        $smtp_port   = (int) \App\Core\App::settings()->get('smtp_port');
        $smtp_from   = \App\Core\App::settings()->get('smtp_from');
        $smtp_secure = \App\Core\App::settings()->get('smtp_secure', '');

        if ($smtp_host === '') {
            return ['ok' => false, 'method' => 'smtp', 'detail' => 'Aucun serveur SMTP configuré'];
        }

        if (!function_exists('fsockopen')) {
            return ['ok' => false, 'method' => 'smtp', 'detail' => 'Extension PHP sockets non disponible'];
        }

        $timeout = 10;
        $errno   = 0;
        $errstr  = '';

        $conn = @fsockopen($smtp_host, $smtp_port, $errno, $errstr, $timeout);
        if (!((bool)$conn)) {
            return ['ok' => false, 'method' => 'smtp', 'detail' => "Impossible de se connecter à $smtp_host:$smtp_port ($errstr)"];
        }

        stream_set_timeout($conn, $timeout);

        $read_smtp = function () use ($conn): string {
            $response = '';
            while ($line = fgets($conn, 512)) {
                $response .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $response;
        };

        $send_smtp = function (string $cmd) use ($conn): void {
            fwrite($conn, $cmd . "\r\n");
        };

        $banner = $read_smtp();
        if (!str_starts_with($banner, '220')) {
            fclose($conn);
            return ['ok' => false, 'method' => 'smtp', 'detail' => 'Bannière SMTP invalide : ' . trim($banner)];
        }

        $helo_host = preg_replace('/[^a-zA-Z0-9.\-]/', '', (string) gethostname());
        if ($helo_host === '') {
            $helo_host = 'localhost';
        }
        $send_smtp('HELO ' . $helo_host);
        $resp = $read_smtp();
        if (!str_starts_with($resp, '250')) {
            fclose($conn);
            return ['ok' => false, 'method' => 'smtp', 'detail' => 'HELO rejeté : ' . trim($resp)];
        }

        if ($smtp_secure === 'tls') {
            $send_smtp('STARTTLS');
            $resp = $read_smtp();
            if (!str_starts_with($resp, '220')) {
                fclose($conn);
                return ['ok' => false, 'method' => 'smtp', 'detail' => 'STARTTLS rejeté : ' . trim($resp)];
            }
            if (!@stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($conn);
                return ['ok' => false, 'method' => 'smtp', 'detail' => 'Échec de la négociation TLS'];
            }
            $send_smtp('EHLO ' . $helo_host);
            $resp = $read_smtp();
            if (!str_starts_with($resp, '250')) {
                fclose($conn);
                return ['ok' => false, 'method' => 'smtp', 'detail' => 'EHLO après STARTTLS rejeté : ' . trim($resp)];
            }
        }

        $safe_smtp_from = str_replace(["\r", "\n", "\t"], '', $smtp_from);
        $send_smtp('MAIL FROM:<' . $safe_smtp_from . '>');
        $resp = $read_smtp();
        if (!str_starts_with($resp, '250')) {
            $send_smtp('QUIT');
            $read_smtp();
            fclose($conn);
            return ['ok' => false, 'method' => 'smtp', 'detail' => 'MAIL FROM rejeté : ' . trim($resp)];
        }

        $safe_email = str_replace(["\r", "\n", "\t", '<', '>'], '', $email);
        $send_smtp('RCPT TO:<' . $safe_email . '>');
        $resp = $read_smtp();

        $send_smtp('QUIT');
        $read_smtp();
        fclose($conn);

        $code = substr($resp, 0, 3);
        if ($code === '250') {
            return ['ok' => true, 'method' => 'smtp', 'detail' => 'Adresse acceptée par le serveur SMTP'];
        }

        if ($code === '251') {
            return ['ok' => true, 'method' => 'smtp', 'detail' => 'Adresse acceptée (transfert) par le serveur SMTP'];
        }

        return ['ok' => false, 'method' => 'smtp', 'detail' => 'Adresse rejetée par le serveur SMTP : ' . trim($resp)];
    }
}
