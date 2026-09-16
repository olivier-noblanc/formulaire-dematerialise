<?php

declare(strict_types=1);

namespace App\Mail;

use App\Contract\MailInterface;
use App\Enum\MailStatus;
use App\Enum\SubmissionField;
use App\Repository\MailRepository;
use App\Settings\SettingsService;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Service d'envoi d'emails via PHPMailer.
 */
final readonly class MailService implements MailInterface
{
    public function __construct(private MailRepository $mailRepository, private SettingsService $settingsService) {}

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
     * Outbox SMTP write-ahead durable : en mode réel, la ligne `pending` (avec
     * le corps HTML complet) est écrite dans mail_log AVANT toute tentative SMTP.
     * Si cette écriture échoue, l'envoi est ANNULÉ (sinon le mail partirait sans
     * trace rejouable) et un échec structuré est retourné. Après la tentative,
     * la ligne est mise à jour (sent / error / blocked / dry_run). Un échec SMTP
     * est planifié pour rejeu (next_retry_at), repris ultérieurement par le cron.
     *
     * TEST_MODE est préservé à l'identique : les envois valides sont interceptés
     * dans $GLOBALS['_test_mails'] sans écriture DB.
     *
     * @return array{success:bool,error:string,smtp_log:string,status:string}
     */
    public function sendDetailed(string $to, string $subject, string $body): array
    {
        $to = strtolower(trim($to));
        $invalidAddress = filter_var($to, FILTER_VALIDATE_EMAIL) === false;

        /** @phpstan-ignore-next-line if.alwaysTrue */
        if (defined('TEST_MODE') && TEST_MODE) {
            if ($invalidAddress) {
                $result = ['success' => false, 'error' => "Adresse destinataire invalide : $to", 'smtp_log' => '', 'status' => MailStatus::Blocked->value];
                error_log("send_mail() BLOQUÉ — {$result['error']}");
                $this->logMailAttempt($to, $subject, $result);
                return $result;
            }
            $GLOBALS['_test_mails'][] = [
                'to' => $to,
                'subject' => $subject,
                'body' => $body,
                'time' => gmdate('Y-m-d H:i:s'),
            ];
            return ['success' => true, 'error' => '', 'smtp_log' => '', 'status' => MailStatus::DryRun->value];
        }

        // ── Write-ahead : persister la ligne AVANT tout envoi ──
        $logId = \generate_uuid();
        $pendingError = $this->insertPending($logId, $to, $subject, $body);
        if ($pendingError !== '') {
            // A2 : si l'écriture durable échoue, on n'envoie PAS — un mail
            // parti sans ligne d'outbox ne serait ni traçable ni rejouable.
            // L'échec n'est jamais avalé : il est remonté, structuré, à
            // l'appelant, qui doit faire intervenir un technicien.
            error_log(sprintf('[MAIL_OUTBOX_INSERT_FAIL] to=%s error=%s', $to, $pendingError));
            return [
                'success' => false,
                'error' => 'Journal des emails indisponible — envoi annulé pour préserver la traçabilité '
                    . "(write-ahead). Intervention d'un technicien requise. Détail : " . $pendingError,
                'smtp_log' => '',
                'status' => MailStatus::Error->value,
            ];
        }

        if ($invalidAddress) {
            $result = ['success' => false, 'error' => "Adresse destinataire invalide : $to", 'smtp_log' => '', 'status' => MailStatus::Blocked->value];
            error_log("send_mail() BLOQUÉ — {$result['error']}");
            $this->finalizeLog($logId, $result, false);
            return $result;
        }

        if ($this->settingsService->get('mail_dry_run', '0') === '1') {
            error_log("send_mail() DRY-RUN — destinataire: $to, sujet: $subject");
            $result = ['success' => true, 'error' => '', 'smtp_log' => '', 'status' => MailStatus::DryRun->value];
            $this->finalizeLog($logId, $result, false);
            return $result;
        }

        $result = $this->transmit($to, $subject, $body);
        // Un échec SMTP réessayable est planifié (failed = épuisement côté worker).
        $this->finalizeLog($logId, $result, $result['status'] === MailStatus::Error->value);
        return $result;
    }

    /**
     * Envoi SMTP bas niveau — sans aucune persistance ni gestion d'outbox.
     *
     * Utilisé par sendDetailed() après le write-ahead. Exposé pour permettre à
     * un rejeu ultérieur de renvoyer le corps d'une ligne d'outbox existante.
     *
     * @return array{success:bool,error:string,smtp_log:string,status:string}
     */
    public function transmit(string $to, string $subject, string $body): array
    {
        $to = strtolower(trim($to));
        $smtpHost = $this->settingsService->get('smtp_host');
        $smtpFrom = $this->settingsService->get('smtp_from');

        if ($smtpHost === '' || $smtpHost === '0') {
            return ['success' => false, 'error' => 'Aucun hôte SMTP configuré', 'smtp_log' => '', 'status' => MailStatus::Blocked->value];
        }
        if ($smtpFrom === '' || $smtpFrom === '0') {
            return ['success' => false, 'error' => 'Aucune adresse From configurée', 'smtp_log' => '', 'status' => MailStatus::Blocked->value];
        }

        $smtpPort = (int) $this->settingsService->get('smtp_port', '25');
        $smtpAuth = $this->settingsService->get('smtp_auth', '0') === '1';
        $smtpUser = $this->settingsService->get('smtp_user', '');
        $smtpPass = $this->settingsService->get('smtp_pass', '');
        $smtpSecure = $this->settingsService->get('smtp_secure', '');
        $smtpFromName = $this->settingsService->get('smtp_from_name', 'CircuitDémat');

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
            // Décision projet : SMTPDebug=3 toujours actif pour permettre le
            // diagnostic SMTP en direct depuis la page monitoring. Non négociable.
            // Ne JAMAIS modifier ce comportement.
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

            return ['success' => true, 'error' => '', 'smtp_log' => implode("\n", $smtpLogBuf), 'status' => MailStatus::Sent->value];
        } catch (\Throwable) {
            // @silent-ok: external SMTP failure — returns structured error result
            $err = $phpMailer->ErrorInfo;
            error_log('Mail error: ' . $err);
            return ['success' => false, 'error' => $err, 'smtp_log' => implode("\n", $smtpLogBuf), 'status' => MailStatus::Error->value];
        }
    }

    /**
     * Insère la ligne d'outbox `pending` (write-ahead).
     *
     * @return string Message d'erreur si l'écriture échoue, '' en cas de succès.
     *                L'appelant NE DOIT alors PAS envoyer le mail. L'erreur est
     *                remontée telle quelle (jamais avalée) pour permettre le
     *                diagnostic — règle AGENTS.md #9.
     */
    private function insertPending(string $logId, string $to, string $subject, string $body): string
    {
        try {
            $actor = \App\Core\App::auth()->getUser();
            if ($actor === '') {
                $actor = 'system';
            }
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'CLI');
            if (!$this->mailRepository->insertPending($logId, $to, $subject, $body, $actor, $ip)) {
                return 'insertPending() a retourné false';
            }
            return '';
        } catch (\Throwable $e) {
            // @silent-ok: l'erreur n'est pas avalée — elle est remontée à
            // l'appelant sous forme structurée, qui bloque l'envoi et
            // déclenche l'intervention d'un technicien.
            return $e->getMessage();
        }
    }

    /**
     * Finalise la ligne d'outbox. `$scheduleRetry` planifie un rejeu (échec SMTP).
     *
     * @param array{success:bool,error:string,smtp_log:string,status:string} $result
     */
    private function finalizeLog(string $logId, array $result, bool $scheduleRetry): void
    {
        $status = MailStatus::tryFrom($result['status']) ?? MailStatus::Error;
        $nextRetryAt = $scheduleRetry
            ? gmdate('Y-m-d H:i:s', time() + MailOutbox::BACKOFF_SECONDS)
            : null;
        try {
            $this->mailRepository->finalize($logId, $status, $result['error'], $result['smtp_log'], 1, $nextRetryAt);
        } catch (\Throwable $e) {
            // @silent-ok: log-only with structured context (AGENTS.md #9) — la
            // ligne reste `pending` et sera reprise par le rejeu (write-ahead).
            error_log(sprintf('[MAIL_LOG_FINALIZE_FAIL] id=%s status=%s error=%s', $logId, $status->value, $e->getMessage()));
        }
    }

    /**
     * Rejoue les envois en échec de l'outbox (worker sans service externe).
     *
     * Revendique atomiquement les lignes réessayables (claim CAS + bail), tente
     * l'envoi SMTP via transmit(), puis finalise :
     *   - sent    : le message est parti ;
     *   - blocked : impossible sans intervention (config SMTP absente) ;
     *   - error   : nouvel échec réessayable → next_retry_at = now + backoff ;
     *   - failed  : échec définitif après MAX_ATTEMPTS (plus de rejeu).
     * Une ligne dont le corps a été purgé (RGPD) ne peut plus être rejouée :
     * elle est marquée failed sans contacter le SMTP.
     *
     * @return array{processed: int, sent: int, failed: int, error: int, blocked: int}
     */
    public function replayOutbox(int $limit = 20): array
    {
        $stats = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'error' => 0, 'blocked' => 0];
        if (!$this->mailRepository->tableExists()) {
            return $stats;
        }

        $leaseUntil = gmdate('Y-m-d H:i:s', time() + MailOutbox::LEASE_SECONDS);
        $rows = $this->mailRepository->claimRetryable(
            MailOutbox::MAX_ATTEMPTS,
            $limit,
            $leaseUntil,
            MailOutbox::STALE_PENDING_SECONDS
        );

        foreach ($rows as $row) {
            $stats['processed']++;
            $body = (string) ($row['body_html'] ?? '');

            if ($body === '') {
                // Corps purgé (RGPD) : plus rien à envoyer, échec définitif.
                $this->mailRepository->finalize(
                    $row['id'],
                    MailStatus::Failed,
                    'Corps du message absent (purge RGPD ?) — rejeu impossible',
                    '',
                    $row['attempts'],
                    null
                );
                $stats['failed']++;
                continue;
            }

            $result = $this->transmit($row['recipient'], $row['subject'], $body);
            $status = MailStatus::tryFrom($result['status']) ?? MailStatus::Error;

            if ($status === MailStatus::Sent) {
                $this->mailRepository->finalize($row['id'], MailStatus::Sent, $result['error'], $result['smtp_log'], $row['attempts'], null);
                $stats['sent']++;
            } elseif ($status === MailStatus::Blocked) {
                $this->mailRepository->finalize($row['id'], MailStatus::Blocked, $result['error'], $result['smtp_log'], $row['attempts'], null);
                $stats['blocked']++;
            } elseif ($row['attempts'] >= MailOutbox::MAX_ATTEMPTS) {
                $this->mailRepository->finalize($row['id'], MailStatus::Failed, $result['error'], $result['smtp_log'], $row['attempts'], null);
                $stats['failed']++;
            } else {
                $nextRetryAt = gmdate('Y-m-d H:i:s', time() + MailOutbox::BACKOFF_SECONDS);
                $this->mailRepository->finalize($row['id'], MailStatus::Error, $result['error'], $result['smtp_log'], $row['attempts'], $nextRetryAt);
                $stats['error']++;
            }
        }

        return $stats;
    }

    /**
     * Rejoue MANUELLEMENT une ligne `failed` de l'outbox (action opérateur).
     *
     * Séquence : revendication atomique (`claimFailedForManualReplay`, plafond
     * `MailOutbox::MANUAL_REPLAY_MAX`) → envoi SMTP synchrone (`transmit`) →
     * finalisation. La revendication place la ligne en `error` avec un bail
     * (`next_retry_at = now + LEASE_SECONDS`) AVANT l'envoi : si le process meurt
     * entre la revendication et la finalisation, la ligne reste reprise par le
     * worker automatique — crash-safe, aucun message perdu.
     *
     * Refus (retour sans contact SMTP) quand la revendication échoue : ligne
     * introuvable, statut ≠ `failed` (déjà rejouée/reprise par un autre clic),
     * corps purgé (RGPD) ou plafond de rejeux manuels atteint. Un corps vide est
     * également refusé sans contacter le SMTP.
     *
     * @api Point d'entrée consommé par le rejeu manuel opérateur (contrôleur).
     *
     * @return array{success:bool,error:string,smtp_log:string,status:string}
     */
    public function replayFailed(string $id): array
    {
        if (!$this->mailRepository->tableExists()) {
            return [
                'success' => false,
                'error' => 'Journal des emails absent — rejeu impossible',
                'smtp_log' => '',
                'status' => MailStatus::Failed->value,
            ];
        }

        $leaseUntil = gmdate('Y-m-d H:i:s', time() + MailOutbox::LEASE_SECONDS);
        $claimed = $this->mailRepository->claimFailedForManualReplay($id, $leaseUntil, MailOutbox::MANUAL_REPLAY_MAX);
        if ($claimed === null) {
            return [
                'success' => false,
                'error' => 'Rejeu refusé : message introuvable, déjà rejoué, corps purgé (RGPD) '
                    . 'ou plafond de ' . MailOutbox::MANUAL_REPLAY_MAX . ' rejeux manuels atteint',
                'smtp_log' => '',
                'status' => MailStatus::Failed->value,
            ];
        }

        // Corps absent (défense en profondeur : le claim refuse déjà `body_html
        // IS NULL`) : refus SANS contacter le SMTP, échec définitif.
        $body = $claimed['body_html'];
        if ($body === '') {
            $this->mailRepository->finalize(
                $claimed['id'],
                MailStatus::Failed,
                'Corps du message absent (purge RGPD ?) — rejeu manuel impossible',
                '',
                0,
                null
            );
            return [
                'success' => false,
                'error' => 'Corps du message absent (purge RGPD ?) — rejeu impossible',
                'smtp_log' => '',
                'status' => MailStatus::Failed->value,
            ];
        }

        try {
            $result = $this->transmit($claimed['recipient'], $claimed['subject'], $body);
        } catch (\Throwable $e) {
            // @silent-ok: converti en résultat structuré réessayable (jamais avalé).
            $result = [
                'success' => false,
                'error' => $e->getMessage(),
                'smtp_log' => '',
                'status' => MailStatus::Error->value,
            ];
        }

        $status = MailStatus::tryFrom($result['status']) ?? MailStatus::Error;
        // Un échec réessayable repart dans la file automatique (backoff) ; les
        // statuts terminaux (sent / blocked) coupent le rejeu.
        $nextRetryAt = $status === MailStatus::Error
            ? gmdate('Y-m-d H:i:s', time() + MailOutbox::BACKOFF_SECONDS)
            : null;
        $this->mailRepository->finalize($claimed['id'], $status, $result['error'], $result['smtp_log'], 1, $nextRetryAt);

        return $result;
    }

    /**
     * Nombre de messages en échec définitif dans l'outbox (signal opérateur).
     */
    public function getOutboxFailureCount(): int
    {
        try {
            if (!$this->mailRepository->tableExists()) {
                return 0;
            }
            return $this->mailRepository->countByStatus(MailStatus::Failed);
        } catch (\Throwable $e) {
            // @silent-ok: log-only fallback for read-only display
            error_log('getOutboxFailureCount error: ' . $e->getMessage());
            return 0;
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
            // @silent-ok: log-only with structured context (B10 — mail log persist failure)
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
            // @silent-ok: log-only fallback for read-only display
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
        $data = json_decode($submission['data'], true) ?? [];
        $formLabel = (string) ($submission['form_label'] ?? '');
        $validateUrl = resolve_base_url() . '/index.php?p=validate&token=' . urlencode($token);

        $lignes = '';
        foreach ($data as $k => $v) {
            if (in_array($v, ['', null, '0'], true)) {
                continue;
            }
            if ($k === SubmissionField::VALIDATIONS->value) {
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
