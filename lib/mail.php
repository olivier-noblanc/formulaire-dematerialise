<?php
declare(strict_types=1);

/**
 * Email sending via PHPMailer.
 *
 * send_mail()          — envoi via PHPMailer (SMTP configuré via settings).
 *                        Retourne bool (compatibilité descendante).
 *                        Tente aussi d'écrire dans la table mail_log (v23+).
 *
 * send_mail_detailed() — variante qui retourne un tableau détaillé :
 *                        ['success' => bool, 'error' => string, 'smtp_log' => string, 'status' => string]
 *                        Utilisée par le test SMTP dans monitoring.php et
 *                        admin_settings.php pour permettre le diagnostic.
 *
 * build_mail_html()         — corps HTML du mail de validation (token + bouton)
 * render_email_template()   — template HTML wrapper (header/footer)
 * get_recent_mail_logs()    — récupère les N dernières entrées de mail_log
 * log_mail_attempt()        — insère une ligne dans mail_log (helper interne)
 *
 * @package lib
 */

use PHPMailer\PHPMailer\PHPMailer;

// ── MAIL ─────────────────────────────────────────────────────

/**
 * Résout une instance de MailerService via le container DI.
 *
 * @return \App\Mail\MailerService
 */
function _mail_service(): \App\Mail\MailerService {
    static $service = null;
    if ($service === null && class_exists(\App\Core\App::class)) {
        $app = \App\Core\App::getInstance();
        if ($app->has(\App\Mail\MailerService::class)) {
            $service = $app->get(\App\Mail\MailerService::class);
        }
    }
    // Fallback : instancier directement (hors bootstrap)
    if ($service === null) {
        $db = new \App\Core\Database();
        $settings = new \App\Settings\SettingsService($db, new \App\Repository\SettingsRepository($db));
        $service = new \App\Mail\MailerService($db, $settings);
    }
    return $service;
}

/**
 * Envoie un email via PHPMailer. Retourne true en cas de succès, false sinon.
 *
 * Compatibilité descendante : la signature reste (string, string, string) -> bool.
 * Délègue à MailerService::send().
 */
function send_mail(string $to, string $subject, string $body): bool {
    return _mail_service()->send($to, $subject, $body);
}

/**
 * Variante détaillée de send_mail(). Délègue à MailerService::sendDetailed().
 *
 * @return array{success:bool,error:string,smtp_log:string,status:string}
 */
function send_mail_detailed(string $to, string $subject, string $body): array {
    return _mail_service()->sendDetailed($to, $subject, $body);
}

/**
 * Insère une ligne dans la table mail_log (si elle existe).
 * Délègue à MailerService::logAttempt().
 */
function log_mail_attempt(string $to, string $subject, string $status, string $error, string $smtp_log, string $actor, string $ip): void {
    _mail_service()->logAttempt($to, $subject, $status, $error, $smtp_log, $actor, $ip);
}

/**
 * Récupère les N dernières entrées de mail_log pour affichage dans l'UI.
 * Délègue à MailerService::getRecentLogs().
 *
 * @return array<int, array<string, mixed>>
 */
function get_recent_mail_logs(int $limit = 30): array {
    return _mail_service()->getRecentLogs($limit);
}

/** @param array<string, mixed> $submission */
function build_mail_html(array $submission, string $step_label, string $token): string {
    return _mail_service()->buildMailHtml($submission, $step_label, $token);
}

/**
 * Génère le HTML d'un email avec le template standard de l'application.
 * Délègue à MailerService::renderEmailTemplate().
 */
function render_email_template(string $title, string $body_html): string {
    return _mail_service()->renderEmailTemplate($title, $body_html);
}
