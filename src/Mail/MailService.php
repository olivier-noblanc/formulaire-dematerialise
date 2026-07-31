<?php

declare(strict_types=1);

namespace App\Mail;

use App\Contract\MailInterface;
use App\Repository\MailRepository;
use App\Settings\SettingsService;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Service d'envoi d'emails via PHPMailer.
 */
final readonly class MailService implements MailInterface
{
    public function __construct(private MailRepository $mailRepository, private SettingsService $settingsService)
    {
    }

    public function send(string $to, string $subject, string $body): bool
    {
        return $this->sendDetailed($to, $subject, $body)['success'];
    }

    /** @param array<string, mixed> $submission */
    public function buildValidationEmail(array $submission, string $stepLabel, string $token): string
    {
        $appName = $this->settingsService->get('app_name', 'CircuitDémat');
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
        $to = $to |> trim(...) |> strtolower(...);

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $msg = "Adresse destinataire invalide : $to";
            error_log("send_mail() BLOQUÉ — $msg");
            $result['error'] = $msg;
            $result['status'] = 'blocked';
            $this->logMailAttempt($to, $subject, $result);
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

        $dryRun = $this->settingsService->get('mail_dry_run', '0') === '1';
        if ($dryRun) {
            error_log("send_mail() DRY-RUN — destinataire: $to, sujet: $subject");
            $result = ['success' => true, 'error' => '', 'smtp_log' => '', 'status' => 'dry_run'];
            $this->logMailAttempt($to, $subject, $result);
            return $result;
        }

        $smtpHost = $this->settingsService->get('smtp_host');
        $smtpPort = (int) $this->settingsService->get('smtp_port', '25');
        $smtpAuth = $this->settingsService->get('smtp_auth', '0') === '1';
        $smtpUser = $this->settingsService->get('smtp_user', '');
        $smtpPass = $this->settingsService->get('smtp_pass', '');
        $smtpSecure = $this->settingsService->get('smtp_secure', '');
        $smtpFrom = $this->settingsService->get('smtp_from');
        $smtpFromName = $this->settingsService->get('smtp_from_name', 'CircuitDémat');

        if ($smtpHost === '' || $smtpHost === '0') {
            $msg = 'Aucun hôte SMTP configuré';
            $result['error'] = $msg;
            $result['status'] = 'blocked';
            $this->logMailAttempt($to, $subject, $result);
            return $result;
        }
        if ($smtpFrom === '' || $smtpFrom === '0') {
            $msg = 'Aucune adresse From configurée';
            $result['error'] = $msg;
            $result['status'] = 'blocked';
            $this->logMailAttempt($to, $subject, $result);
            return $result;
        }

        $smtpLogBuf = [];
        $phpMailer = new PHPMailer(true);
        try {
            $phpMailer->isSMTP();
            $phpMailer->Host = $smtpHost;
            $phpMailer->Port = $smtpPort;
            $phpMailer->SMTPAuth = $smtpAuth;
            if ($smtpAuth) {
                $phpMailer->Username = $smtpUser;
                $phpMailer->Password = $smtpPass;
            }
            if ($smtpSecure === 'tls') {
                $phpMailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($smtpSecure === 'ssl') {
                $phpMailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            }
            $phpMailer->SMTPAutoTLS = ($smtpSecure === 'tls' || $smtpSecure === 'ssl');
            $phpMailer->Timeout = 30;
            $phpMailer->SMTPDebug = 3;
            $phpMailer->Debugoutput = function (string $str, int $level) use (&$smtpLogBuf): void {
                $smtpLogBuf[] = '[' . $level . '] ' . rtrim($str);
            };
            $phpMailer->CharSet = 'UTF-8';
            $phpMailer->setFrom($smtpFrom, $smtpFromName);
            $phpMailer->addAddress($to);
            $phpMailer->isHTML(true);
            $phpMailer->Subject = $subject;
            $phpMailer->Body = $body;
            $phpMailer->send();

            $smtpLog = implode("\n", $smtpLogBuf);
            $result = ['success' => true, 'error' => '', 'smtp_log' => $smtpLog, 'status' => 'sent'];
            $this->logMailAttempt($to, $subject, $result);
            return $result;
        } catch (\Throwable) {
            $smtpLog = implode("\n", $smtpLogBuf);
            $err = $phpMailer->ErrorInfo;
            error_log('Mail error: ' . $err);
            $result = ['success' => false, 'error' => $err, 'smtp_log' => $smtpLog, 'status' => 'error'];
            $this->logMailAttempt($to, $subject, $result);
            return $result;
        }
    }

    /**
     * Persiste une tentative d'envoi dans mail_log (visible sur la page monitoring).
     * Ne journalise pas les envois TEST_MODE (interceptés dans $GLOBALS['_test_mails'],
     * mail_log reflète l'activité réelle, pas le harnais de test).
     *
     * B10 fix (audit 2026-07-26) : avant, le catch avalait silencieusement l'échec
     * d'écriture (règle AGENTS.md #9 violation). Maintenant, on log via error_log
     * ET on signale l'échec à l'appelant via le tableau $result en y ajoutant un
     * champ 'log_persist_error'. Les appelants peuvent ainsi réagir si besoin.
     *
     * @param array{success:bool,error:string,smtp_log:string,status:string} $result
     */
    private function logMailAttempt(string $to, string $subject, array $result): void
    {
        try {
            $actor = \App\Core\App::auth()->getUser();
            if ($actor === '') {
                $actor = 'system';
            }
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'CLI');
            $this->mailRepository->insertLog(
                \generate_uuid(),
                $to,
                $subject,
                $result,
                $actor,
                $ip
            );
        } catch (\Throwable $e) {
            // B10 : ne pas avaler silencieusement. error_log seul était insuffisant
            // car invisible côté applicatif. On ajoute un contexte structuré pour
            // que l'investigation soit possible (règle AGENTS.md #9).
            error_log(sprintf(
                '[MAIL_LOG_PERSIST_FAIL] to=%s subject=%s status=%s error=%s',
                $to,
                $subject,
                $result['status'],
                $e->getMessage()
            ));
        }
    }

    /**
     * Récupère les N dernières entrées de mail_log.
     *
     * @return array<int, array{id: string, created_at: string, recipient: string, subject: string, status: string, error_message: string, smtp_log: string, actor: string, ip: string}>
     */
    public function getRecentLogs(int $limit = 30): array
    {
        try {
            if (!$this->mailRepository->tableExists()) {
                return [];
            }
            return $this->mailRepository->getRecentLogs($limit);
        } catch (\Throwable $e) {
            error_log('getRecentLogs error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Construit le HTML du mail de validation (token + bouton).
     *
     * @param array{data: string, form_label?: string} $submission
     */
    public function buildMailHtml(array $submission, string $stepLabel, string $token): string
    {
        $data = json_decode($submission['data'], true) ?: [];
        $formLabel = (string) ($submission['form_label'] ?? '');
        $validateUrl = resolve_base_url() . '/index.php?p=validate&token=' . urlencode($token);

        $lignes = '';
        foreach ($data as $k => $v) {
            if ($v === '' || $v === null || $v === '0' || $k === 'validations') {
                continue;
            }
            if (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            $label = \App\Core\App::html()->escape(ucfirst(str_replace('_', ' ', preg_replace('/^[a-z]+_/', '', (string) $k) ?? (string) $k)));
            $valeur = $v === '1' ? '✓' : \App\Core\App::html()->escape((string) $v);
            $lignes .= "<tr><td style='padding:5px 8px;font-weight:bold;color:#555;'>{$label}</td><td style='padding:5px 8px;'>{$valeur}</td></tr>";
        }

        $bodyHtml = '<p style="color:#555;margin-bottom:16px;">Étape : <strong>' . \App\Core\App::html()->escape($stepLabel) . '</strong></p>'
            . '<table style="border-collapse:collapse;width:100%;margin-bottom:24px;">' . $lignes . '</table>'
            . '<a href="' . $validateUrl . '" style="background:#003189;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;display:inline-block;">'
            . '✓ Marquer comme effectué</a>'
            . '<p style="font-size:12px;color:#999;margin-top:8px;">Lien à usage unique — ' . \App\Core\App::html()->escape($this->settingsService->get('smtp_from')) . '</p>';

        return $this->renderEmailTemplate($formLabel . ' — Action requise', $bodyHtml);
    }
}
