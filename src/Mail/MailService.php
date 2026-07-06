<?php
declare(strict_types=1);

namespace App\Mail;

use App\Core\Database;
use App\Settings\SettingsService;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Service d'envoi d'emails via PHPMailer.
 */
final class MailService
{
    private Database $db;
    private SettingsService $settings;

    public function __construct(Database $db, SettingsService $settings)
    {
        $this->db = $db;
        $this->settings = $settings;
    }

    public function send(string $to, string $subject, string $body): bool
    {
        if (defined('TEST_MODE') && TEST_MODE) {
            $GLOBALS['_test_mails'][] = [
                'to' => $to,
                'subject' => $subject,
                'body' => $body,
                'time' => gmdate('Y-m-d H:i:s'),
            ];
            return true;
        }

        $dryRun = $this->settings->get('mail_dry_run', '0') === '1';
        if ($dryRun) {
            error_log("send_mail() DRY-RUN — destinataire: $to, sujet: $subject");
            return true;
        }

        $smtpHost = $this->settings->get('smtp_host');
        $smtpPort = (int) $this->settings->get('smtp_port', '25');
        $smtpFrom = $this->settings->get('smtp_from');
        $smtpFromName = $this->settings->get('smtp_from_name', 'CircuitDémat');

        if (empty($smtpHost)) {
            error_log('send_mail: aucun serveur SMTP configuré');
            return false;
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->Port = $smtpPort;
            $mail->setFrom($smtpFrom, $smtpFromName);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->isHTML(true);
            $mail->send();
            return true;
        } catch (\Throwable $e) {
            error_log('send_mail error: ' . $e->getMessage());
            return false;
        }
    }

    public function buildValidationEmail(array $submission, string $stepLabel, string $token): string
    {
        $appName = $this->settings->get('app_name', 'CircuitDémat');
        $baseUrl = function_exists('resolve_base_url') ? resolve_base_url() : (defined('BASE_URL') ? BASE_URL : '');
        $validateUrl = $baseUrl . '/index.php?p=validate&token=' . $token;

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>
            <h2>' . h($appName) . ' — Action requise</h2>
            <p>Une demande vous attend pour validation à l\'étape <strong>' . h($stepLabel) . '</strong>.</p>
            <p><a href="' . h($validateUrl) . '" style="background:#000091;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block;">Voir la demande</a></p>
            <p style="color:#666;font-size:.85rem;">Si le bouton ne fonctionne pas, copiez ce lien : ' . h($validateUrl) . '</p>
            </body></html>';
    }

    public function renderEmailTemplate(string $title, string $bodyHtml): string
    {
        $appName = $this->settings->get('app_name', 'CircuitDémat');
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>
            <div style="max-width:600px;margin:0 auto;font-family:sans-serif;">
            <h1 style="color:#000091;">' . h($title) . '</h1>
            ' . $bodyHtml . '
            <hr style="border:none;border-top:1px solid #ddd;margin:2rem 0;">
            <p style="color:#999;font-size:.75rem;">' . h($appName) . ' — CircuitDémat</p>
            </div></body></html>';
    }
}
