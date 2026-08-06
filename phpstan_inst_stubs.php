<?php
// phpstan_inst_stubs.php — Stub file for PHPStan bootstrap
// Defines install.php functions and constants without executing session code

const INST_DEFAULT_SMTP_HOST      = 'smtp.social.gouv.fr';
const INST_DEFAULT_SMTP_FROM      = 'workflow@exemple.invalid';
const INST_DEFAULT_SMTP_FROM_NAME = 'CircuitDémat';
const INST_DEFAULT_APP_NAME       = 'CircuitDémat';
const INST_DEFAULT_EMAIL_DOMAIN   = 'exemple.invalid';
const INST_DEFAULT_DELAI_RELANCE  = 48;

// Stub for PHPStan — config.php est ignoré par git, PHPStan infère le shape
// depuis le define() réel qui manque admin_email/email_domain.
// @phpstan-type SettingsDefaultsShape array{smtp_host: string, smtp_port: int, smtp_from: string, smtp_from_name: string, delai_relance_h: int, app_name: string, mail_dry_run: int, admin_email: string, email_domain: string}
if (!defined('SETTINGS_DEFAULTS')) {
    define('SETTINGS_DEFAULTS', [
        'smtp_host' => '',
        'smtp_port' => '',
        'smtp_from' => '',
        'smtp_from_name' => '',
        'delai_relance_h' => '',
        'app_name' => '',
        'mail_dry_run' => '',
        'admin_email' => '',
        'email_domain' => '',
    ]);
}

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
// send_mail(), build_mail_html(), render_email_template(), format_bytes() sont désormais de
// vraies fonctions (src/mail_wrappers.php, chargé par helpers.php) — stubs retirés d'ici pour
// éviter une redéclaration fatale (ce fichier est chargé APRÈS helpers.php par phpstan.neon).

/** @deprecated Defined locally in MySubmissionsController — stub for PHPStan */
function simplify_form_label(string $label): string {
    return $label;
}
