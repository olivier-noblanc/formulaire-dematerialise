<?php
declare(strict_types=1);

namespace App\Mail;

use App\Contract\MailInterface;
use App\Core\Database;
use App\Settings\SettingsService;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Service d'envoi d'emails via PHPMailer.
 */
final class MailService implements MailInterface
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
            <h2>' . \App\Core\App::html()->escape($appName) . ' — Action requise</h2>
            <p>Une demande vous attend pour validation à l\'étape <strong>' . \App\Core\App::html()->escape($stepLabel) . '</strong>.</p>
            <p><a href="' . \App\Core\App::html()->escape($validateUrl) . '" style="background:#000091;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block;">Voir la demande</a></p>
            <p style="color:#666;font-size:.85rem;">Si le bouton ne fonctionne pas, copiez ce lien : ' . \App\Core\App::html()->escape($validateUrl) . '</p>
            </body></html>';
    }

    public function renderEmailTemplate(string $title, string $bodyHtml): string
    {
        $app_name = \App\Render\NavigationRenderer::getAppName();
        return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;color:#222;">
  <h2 style="color:#003189;">' . \App\Core\App::html()->escape($title) . '</h2>
  ' . $bodyHtml . '
  <p style="font-size:12px;color:#999;margin-top:24px;">' . \App\Core\App::html()->escape($app_name) . ' — Ne pas répondre</p>
</body></html>';
    }

    /**
     * Variante détaillée de send() retournant un tableau de diagnostic.
     *
     * @return array{success:bool,error:string,smtp_log:string,status:string}
     */
    public function sendDetailed(string $to, string $subject, string $body): array
    {
        $result = ['success' => false, 'error' => '', 'smtp_log' => '', 'status' => 'error'];
        $to = strtolower(trim($to));

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $msg = "Adresse destinataire invalide : $to";
            error_log("send_mail() BLOQUÉ — $msg");
            $result['error'] = $msg;
            $result['status'] = 'blocked';
            return $result;
        }

        /** @phpstan-ignore-next-line if.alwaysTrue */
        if (defined('TEST_MODE') && TEST_MODE) {
            $GLOBALS['_test_mails'][] = [
                'to' => $to,
                'subject' => $subject,
                'body' => $body,
                'time' => gmdate('Y-m-d H:i:s'),
            ];
            return ['success' => true, 'error' => '', 'smtp_log' => '', 'status' => 'dry_run'];
        }

        $dryRun = $this->settings->get('mail_dry_run', '0') === '1';
        if ($dryRun) {
            error_log("send_mail() DRY-RUN — destinataire: $to, sujet: $subject");
            return ['success' => true, 'error' => '', 'smtp_log' => '', 'status' => 'dry_run'];
        }

        $smtpHost = $this->settings->get('smtp_host');
        $smtpPort = (int) $this->settings->get('smtp_port', '25');
        $smtpAuth = $this->settings->get('smtp_auth', '0') === '1';
        $smtpUser = $this->settings->get('smtp_user', '');
        $smtpPass = $this->settings->get('smtp_pass', '');
        $smtpSecure = $this->settings->get('smtp_secure', '');
        $smtpFrom = $this->settings->get('smtp_from');
        $smtpFromName = $this->settings->get('smtp_from_name', 'CircuitDémat');

        if (empty($smtpHost)) {
            $msg = "Aucun hôte SMTP configuré";
            $result['error'] = $msg;
            $result['status'] = 'blocked';
            return $result;
        }
        if (empty($smtpFrom)) {
            $msg = "Aucune adresse From configurée";
            $result['error'] = $msg;
            $result['status'] = 'blocked';
            return $result;
        }

        $smtpLogBuf = [];
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->Port = $smtpPort;
            $mail->SMTPAuth = $smtpAuth;
            if ($smtpAuth) {
                $mail->Username = $smtpUser;
                $mail->Password = $smtpPass;
            }
            if ($smtpSecure === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($smtpSecure === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            }
            $mail->SMTPAutoTLS = ($smtpSecure === 'tls' || $smtpSecure === 'ssl');
            $mail->Timeout = 30;
            $mail->SMTPDebug = 3;
            $mail->Debugoutput = function (string $str, int $level) use (&$smtpLogBuf): void {
                $smtpLogBuf[] = '[' . $level . '] ' . rtrim($str);
            };
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($smtpFrom, $smtpFromName);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->send();

            $smtpLog = implode("\n", $smtpLogBuf);
            return ['success' => true, 'error' => '', 'smtp_log' => $smtpLog, 'status' => 'sent'];
        } catch (\Throwable $e) {
            $smtpLog = implode("\n", $smtpLogBuf);
            $err = $mail->ErrorInfo;
            error_log('Mail error: ' . $err);
            return ['success' => false, 'error' => $err, 'smtp_log' => $smtpLog, 'status' => 'error'];
        }
    }

    /**
     * Récupère les N dernières entrées de mail_log.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecentLogs(int $limit = 30): array
    {
        static $tableExists = null;
        try {
            $pdo = $this->db->getPdo();
            if ($tableExists === null) {
                $stmt = $pdo->query(
                    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='mail_log'"
                );
                $cnt = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
                $tableExists = ($cnt > 0);
            }
            if (!$tableExists) return [];

            $stmt = $pdo->prepare("SELECT * FROM mail_log ORDER BY created_at DESC LIMIT ?");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('getRecentLogs error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Construit le HTML du mail de validation (token + bouton).
     *
     * @param array<string, mixed> $submission
     */
    public function buildMailHtml(array $submission, string $stepLabel, string $token): string
    {
        $data = json_decode($submission['data'], true) ?: [];
        $formLabel = (string) ($submission['form_label'] ?? '');
        $validateUrl = resolve_base_url() . '/index.php?p=validate&token=' . urlencode($token);

        $lignes = '';
        foreach ($data as $k => $v) {
            if (empty($v) || $k === 'validations') continue;
            if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            $label = \App\Core\App::html()->escape(ucfirst(str_replace('_', ' ', preg_replace('/^[a-z]+_/', '', (string) $k) ?? (string) $k)));
            $valeur = $v === '1' ? '✓' : \App\Core\App::html()->escape((string) $v);
            $lignes .= "<tr><td style='padding:5px 8px;font-weight:bold;color:#555;'>{$label}</td><td style='padding:5px 8px;'>{$valeur}</td></tr>";
        }

        $bodyHtml = '<p style="color:#555;margin-bottom:16px;">Étape : <strong>' . \App\Core\App::html()->escape($stepLabel) . '</strong></p>'
            . '<table style="border-collapse:collapse;width:100%;margin-bottom:24px;">' . $lignes . '</table>'
            . '<a href="' . $validateUrl . '" style="background:#003189;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;display:inline-block;">'
            . '✓ Marquer comme effectué</a>'
            . '<p style="font-size:12px;color:#999;margin-top:8px;">Lien à usage unique — ' . \App\Core\App::html()->escape($this->settings->get('smtp_from')) . '</p>';

        return $this->renderEmailTemplate($formLabel . ' — Action requise', $bodyHtml);
    }
}
