<?php
// phpstan_inst_stubs.php — Stub file for PHPStan bootstrap
// Defines install.php functions and constants without executing session code

const INST_DEFAULT_SMTP_HOST      = 'smtp.social.gouv.fr';
const INST_DEFAULT_SMTP_FROM      = 'workflow@dreets.gouv.fr';
const INST_DEFAULT_SMTP_FROM_NAME = 'CircuitDémat';
const INST_DEFAULT_APP_NAME       = 'CircuitDémat';
const INST_DEFAULT_EMAIL_DOMAIN   = 'dreets.gouv.fr';
const INST_DEFAULT_DELAI_RELANCE  = 48;

if (!defined('DEFAULT_DB_PATH')) {
    define('DEFAULT_DB_PATH', __DIR__ . '/db/workflow.db');
}
if (!defined('DB_PATH')) {
    define('DB_PATH', DEFAULT_DB_PATH);
}

function inst_h(string $val): string {
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

function inst_generate_csrf(): string {
    return '';
}

function inst_csrf_field(): string {
    return '';
}

function inst_check_csrf(): bool {
    return true;
}

// ── Stubs for legacy procedural functions still called by alert_check.php, remind.php, etc. ──

/** @deprecated Use App::mail()->send() */
function send_mail(string $to, string $subject, string $body): bool {
    return \App\Core\App::mail()->send($to, $subject, $body);
}

/** @deprecated Use App::mail()->buildMailHtml() */
function build_mail_html(array $submission, string $step_label, string $token): string {
    return \App\Core\App::mail()->buildMailHtml($submission, $step_label, $token);
}

/** @deprecated Use App::mail()->renderEmailTemplate() */
function render_email_template(string $title, string $body_html): string {
    return \App\Core\App::mail()->renderEmailTemplate($title, $body_html);
}

/** @deprecated Use App::html()->formatFileSize() */
function format_bytes(int $bytes): string {
    return \App\Core\App::html()->formatFileSize($bytes);
}

/** @deprecated Defined locally in MySubmissionsController — stub for PHPStan */
function simplify_form_label(string $label): string {
    return $label;
}
