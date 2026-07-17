<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\App;
use App\Core\Database;
use App\Settings\SettingsService;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Service d'envoi d'emails extrait de lib/mail.php.
 *
 * Enveloppe les fonctions globales send_mail(), send_mail_detailed(),
 * log_mail_attempt(), get_recent_mail_logs(), build_mail_html(),
 * render_email_template() dans une classe injectable.
 */
final readonly class MailerService
{
    public function __construct(private Database $database, private SettingsService $settingsService)
    {
    }

    /**
     * Envoie un email via PHPMailer. Retourne true en cas de succès, false sinon.
     */
    public function send(string $to, string $subject, string $body): bool
    {
        $result = $this->sendDetailed($to, $subject, $body);
        return $result['success'];
    }

    /**
     * Variante détaillée de send() qui retourne un tableau avec :
     *  - 'success'  (bool)
     *  - 'error'    (string)
     *  - 'smtp_log' (string)
     *  - 'status'   (string) : 'sent' | 'error' | 'blocked' | 'dry_run' | 'cli_blocked' | 'rate_limited'
     *
     * @return array{success:bool,error:string,smtp_log:string,status:string}
     */
    public function sendDetailed(string $to, string $subject, string $body): array
    {
        $result = ['success' => false, 'error' => '', 'smtp_log' => '', 'status' => 'error'];
        $to = $to |> trim(...) |> strtolower(...);
        $actor = App::auth()->getUser();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';

        // Sécurité : valider l'adresse du destinataire
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $msg = "Adresse destinataire invalide : $to";
            error_log("send_mail() BLOQUÉ — $msg");
            $this->logAttempt($to, $subject, 'blocked', $msg, '', $actor, $ip);
            $result['error'] = $msg;
            $result['status'] = 'blocked';
            return $result;
        }

        // Mode test : intercepter les mails sans les envoyer
        /** @phpstan-ignore-next-line if.alwaysTrue */
        if (TEST_MODE) {
            $GLOBALS['_test_mails'][] = [
                'to'      => $to,
                'subject' => $subject,
                'body'    => $body,
                'time'    => gmdate('Y-m-d H:i:s'),
            ];
            $this->logAttempt($to, $subject, 'dry_run', 'Mode test (TEST_MODE)', '', $actor, $ip);
            return ['success' => true, 'error' => '', 'smtp_log' => '', 'status' => 'dry_run'];
        }

        // Mode dry-run : aucun email réel n'est envoyé, tout est journalisé
        /** @phpstan-ignore deadCode.unreachable */
        $dry_run = $this->settingsService->get('mail_dry_run', '0') === '1';
        if ($dry_run) {
            error_log("send_mail() DRY-RUN — destinataire: $to, sujet: $subject");
            App::audit()->log('mail_dry_run', 'mail:' . $to, "Email intercepté (dry-run) — Sujet : $subject", '');
            $this->logAttempt($to, $subject, 'dry_run', 'Mode dry-run activé (mail_dry_run=1)', '', $actor, $ip);
            return ['success' => true, 'error' => '', 'smtp_log' => '', 'status' => 'dry_run'];
        }

        // Vérification de l'adresse email avant envoi
        $verify_mode = $this->settingsService->get('email_verify_mode', 'none');
        if ($verify_mode !== 'none') {
            $verification = App::emailVerify()->verify($to);
            if (!$verification['ok']) {
                $msg = 'Email non vérifié : ' . $verification['detail'];
                error_log("send_mail() BLOQUÉ — $msg — destinataire: $to");
                App::audit()->log('mail_blocked', 'mail:' . $to, 'Email bloqué (vérification échouée) — ' . $verification['detail'] . " — Sujet : $subject", '');
                $this->logAttempt($to, $subject, 'blocked', $msg, '', $actor, $ip);
                $result['error'] = $msg;
                $result['status'] = 'blocked';
                return $result;
            }
        }

        // Sécurité CLI : ne jamais envoyer d'emails réels depuis un contexte CLI
        if (php_sapi_name() === 'cli' && !defined('CLI_MAIL_ALLOWED')) {
            $msg = 'Envoi bloqué en CLI sans CLI_MAIL_ALLOWED';
            error_log("send_mail() $msg (destinataire: $to)");
            $this->logAttempt($to, $subject, 'cli_blocked', $msg, '', $actor, $ip);
            $result['error'] = $msg;
            $result['status'] = 'cli_blocked';
            return $result;
        }

        // ── Configuration SMTP ──
        $smtp_host = $this->settingsService->get('smtp_host');
        $smtp_port = (int) $this->settingsService->get('smtp_port', '25');
        $smtp_auth = $this->settingsService->get('smtp_auth', '0') === '1';
        $smtp_user = $this->settingsService->get('smtp_user', '');
        $smtp_pass = $this->settingsService->get('smtp_pass', '');
        $smtp_secure = $this->settingsService->get('smtp_secure', '');
        $smtp_from = $this->settingsService->get('smtp_from');
        $smtp_from_name = $this->settingsService->get('smtp_from_name');

        if (empty($smtp_host)) {
            $msg = 'Aucun hôte SMTP configuré (smtp_host vide)';
            error_log("send_mail() BLOQUÉ — $msg");
            $this->logAttempt($to, $subject, 'blocked', $msg, '', $actor, $ip);
            $result['error'] = $msg;
            $result['status'] = 'blocked';
            return $result;
        }
        if (empty($smtp_from)) {
            $msg = 'Aucune adresse From configurée (smtp_from vide)';
            error_log("send_mail() BLOQUÉ — $msg");
            $this->logAttempt($to, $subject, 'blocked', $msg, '', $actor, $ip);
            $result['error'] = $msg;
            $result['status'] = 'blocked';
            return $result;
        }

        // ── Capture de la conversation SMTP pour debug ──
        $smtp_log_buf = [];
        $phpMailer = new PHPMailer(true);
        try {
            $phpMailer->isSMTP();
            $phpMailer->Host       = $smtp_host;
            $phpMailer->Port       = $smtp_port;
            $phpMailer->SMTPAuth   = $smtp_auth;
            if ($phpMailer->SMTPAuth) {
                $phpMailer->Username = $smtp_user;
                $phpMailer->Password = $smtp_pass;
            }
            if ($smtp_secure === 'tls') {
                $phpMailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($smtp_secure === 'ssl') {
                $phpMailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            }
            $phpMailer->SMTPAutoTLS = ($smtp_secure === 'tls' || $smtp_secure === 'ssl');

            $phpMailer->Timeout = 30;
            $phpMailer->getSMTPInstance()->Timelimit = 15;

            $phpMailer->SMTPDebug  = 3;
            $phpMailer->Debugoutput = function (string $str, int $level) use (&$smtp_log_buf): void {
                $smtp_log_buf[] = '[' . $level . '] ' . rtrim($str);
            };

            $phpMailer->CharSet  = 'UTF-8';
            $phpMailer->setFrom($smtp_from, $smtp_from_name);
            $phpMailer->addAddress($to);
            $phpMailer->isHTML(true);
            $phpMailer->Subject  = $subject;
            $phpMailer->Body     = $body;
            $phpMailer->send();

            $smtp_log = implode("\n", $smtp_log_buf);
            App::audit()->log('mail_sent', 'mail:' . $to, "Email envoyé — Sujet : $subject", '');
            $this->logAttempt($to, $subject, 'sent', '', $smtp_log, $actor, $ip);
            return ['success' => true, 'error' => '', 'smtp_log' => $smtp_log, 'status' => 'sent'];
        } catch (\Exception) {
            $smtp_log = implode("\n", $smtp_log_buf);
            $err = $phpMailer->ErrorInfo;
            error_log('Mail error: ' . $err);
            App::audit()->log('mail_error', 'mail:' . $to, 'Échec envoi — ' . $err . " — Sujet : $subject", '');
            $this->logAttempt($to, $subject, 'error', $err, $smtp_log, $actor, $ip);
            return ['success' => false, 'error' => $err, 'smtp_log' => $smtp_log, 'status' => 'error'];
        }
    }

    /**
     * Insère une ligne dans la table mail_log (si elle existe).
     */
    public function logAttempt(string $to, string $subject, string $status, string $error, string $smtp_log, string $actor, string $ip): void
    {
        static $table_exists = null;
        try {
            $pdo = $this->database->getPdo();
            if ($table_exists === null) {
                $stmt = $pdo->query(
                    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='mail_log'"
                );
                $cnt = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
                $table_exists = ($cnt > 0);
            }
            if (!$table_exists) {
                return;
            }

            if (strlen($smtp_log) > 32000) {
                $smtp_log = substr($smtp_log, 0, 32000) . "\n... (tronqué)";
            }
            $subject = mb_substr($subject, 0, 500, 'UTF-8');
            $error = mb_substr($error, 0, 2000, 'UTF-8');

            $pdo->prepare('INSERT INTO mail_log (id, created_at, recipient, subject, status, error_message, smtp_log, actor, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    generate_uuid(),
                    gmdate('Y-m-d H:i:s'),
                    $to,
                    $subject,
                    $status,
                    $error,
                    $smtp_log,
                    $actor,
                    $ip,
                ]);
        } catch (\Exception $e) {
            error_log('log_mail_attempt error: ' . $e->getMessage());
        }
    }

    /**
     * Récupère les N dernières entrées de mail_log.
     *
     * @return array<int, array{id: string, created_at: string, recipient: string, subject: string, status: string, error_message: string, smtp_log: string, actor: string, ip: string}>
     */
    public function getRecentLogs(int $limit = 30): array
    {
        static $table_exists = null;
        try {
            $pdo = $this->database->getPdo();
            if ($table_exists === null) {
                $stmt = $pdo->query(
                    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='mail_log'"
                );
                $cnt = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
                $table_exists = ($cnt > 0);
            }
            if (!$table_exists) {
                return [];
            }

            $stmt = $pdo->prepare('SELECT id, created_at, recipient, subject, status, error_message, smtp_log, actor, ip FROM mail_log ORDER BY created_at DESC LIMIT ?');
            $stmt->execute([$limit]);
            /** @var array<int, array{id: string, created_at: string, recipient: string, subject: string, status: string, error_message: string, smtp_log: string, actor: string, ip: string}> */
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log('get_recent_mail_logs error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Construit le HTML du mail de validation (token + bouton).
     *
     * @param array{data: string, form_label?: string} $submission
     */
    public function buildMailHtml(array $submission, string $step_label, string $token): string
    {
        $data         = json_decode($submission['data'], true);
        $form_label   = (string) ($submission['form_label'] ?? '');
        $validate_url = resolve_base_url() . '/index.php?p=validate&token=' . urlencode($token);

        $lignes = '';
        foreach ($data as $k => $v) {
            if (empty($v) || $k === 'validations') {
                continue;
            }
            if (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            $label  = \App\Core\App::html()->escape(ucfirst(str_replace('_', ' ', preg_replace('/^[a-z]+_/', '', (string) $k) ?? (string) $k)));
            $valeur = $v === '1' ? '✓' : \App\Core\App::html()->escape((string) $v);
            $lignes .= "<tr><td style='padding:5px 8px;font-weight:bold;color:#555;'>{$label}</td><td style='padding:5px 8px;'>{$valeur}</td></tr>";
        }

        $body_html = '<p style="color:#555;margin-bottom:16px;">Étape : <strong>' . \App\Core\App::html()->escape($step_label) . '</strong></p>'
            . '<table style="border-collapse:collapse;width:100%;margin-bottom:24px;">' . $lignes . '</table>'
            . '<a href="' . $validate_url . '" style="background:#003189;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;display:inline-block;">'
            . '✓ Marquer comme effectué</a>'
            . '<p style="font-size:12px;color:#999;margin-top:8px;">Lien à usage unique — ' . \App\Core\App::html()->escape($this->settingsService->get('smtp_from')) . '</p>';

        return $this->renderEmailTemplate($form_label . ' — Action requise', $body_html);
    }

    /**
     * Génère le HTML d'un email avec le template standard de l'application.
     */
    public function renderEmailTemplate(string $title, string $body_html): string
    {
        $app_name = \App\Render\NavigationRenderer::getAppName();
        return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;color:#222;">
  <h2 style="color:#003189;">' . \App\Core\App::html()->escape($title) . '</h2>
  ' . $body_html . '
  <p style="font-size:12px;color:#999;margin-top:24px;">' . \App\Core\App::html()->escape($app_name) . ' — Ne pas répondre</p>
</body></html>';
    }
}
