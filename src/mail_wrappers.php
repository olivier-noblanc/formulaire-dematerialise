<?php

declare(strict_types=1);

/**
 * Global wrapper functions for legacy procedural mail/formatting calls.
 *
 * Ces fonctions n'existaient QUE comme stubs dans phpstan_inst_stubs.php
 * (chargé uniquement par PHPStan, jamais à l'exécution réelle) — tout appel
 * réel depuis remind.php, alert_check.php ou SubmissionViewController
 * provoquait un "Call to undefined function" fatal. Voir CHANGELOG.
 *
 * Loaded by helpers.php, juste après src/lib_wrappers.php.
 */

use App\Core\App;

// ── MAIL (App\Mail\MailService) ──────────────────────────────────
function send_mail(string $to, string $subject, string $body): bool
{
    return App::mail()->send($to, $subject, $body);
}

/**
 * @param array{data: string, form_label?: string} $submission
 */
function build_mail_html(array $submission, string $step_label, string $token): string
{
    return App::mail()->buildMailHtml($submission, $step_label, $token);
}

function render_email_template(string $title, string $body_html): string
{
    return App::mail()->renderEmailTemplate($title, $body_html);
}

// ── FORMAT (App\Render\HtmlService) ──────────────────────────────
function format_bytes(int $bytes): string
{
    return App::html()->formatFileSize($bytes);
}
