<?php
declare(strict_types=1);

/**
 * Service wrappers — procédural facade pour tous les services OOP.
 *
 * Ce fichier consolide les wrappers procéduraux qui délèguent aux services
 * OOP dans src/. Remplace les fichiers individuels :
 *  - attachments.php      → AttachmentService
 *  - audit_log.php        → AuditLogService
 *  - email_verify.php     → EmailVerificationService
 *  - export_csv.php       → ExportService
 *  - filled_by.php        → ValidatorDataService
 *  - lazy_cron.php        → CronService
 *  - mail.php             → MailerService
 *  - rgpd.php             → RgpdService
 *  - stats.php            → StatsService
 *  - tokens.php           → TokenService
 *  - webhook.php          → WebhookService
 *  - workflow.php         → WorkflowEngine
 *
 * @package lib
 */

use PHPMailer\PHPMailer\PHPMailer;

// ═══════════════════════════════════════════════════════════════
//  ATTACHMENTS (AttachmentService)
// ═══════════════════════════════════════════════════════════════

function get_allowed_mime_types(): array {
    return \App\Core\App::attachment()->getAllowedMimeTypes();
}

function get_allowed_extensions(): array {
    return \App\Core\App::attachment()->getAllowedExtensions();
}

function get_max_file_size(): int {
    return \App\Core\App::attachment()->getMaxFileSize();
}

function handle_file_upload(array $file, string $submission_id, string $field_name): array {
    return \App\Core\App::attachment()->handleFileUpload($file, $submission_id, $field_name);
}

function get_attachments(string $submission_id): array {
    return \App\Core\App::attachment()->getAttachments($submission_id);
}

function get_attachment_by_id(string $attachment_id): ?array {
    return \App\Core\App::attachment()->getAttachmentById($attachment_id);
}

// ═══════════════════════════════════════════════════════════════
//  AUDIT LOG (AuditLogService)
// ═══════════════════════════════════════════════════════════════

function app_log(string $action, string $target = '', string $detail = '', string $actor = ''): void {
    \App\Core\App::audit()->log($action, $target, $detail, $actor);
}

function security_log(string $event, string $detail = '', string $actor = ''): void {
    \App\Core\App::audit()->securityLog($event, $detail, $actor);
}

/** @return array<int, array<string, mixed>> */
function get_audit_logs(int $limit = 100, string $action_filter = ''): array {
    return \App\Core\App::audit()->getLogs($limit, $action_filter);
}

// ═══════════════════════════════════════════════════════════════
//  EMAIL VERIFICATION (EmailVerificationService)
// ═══════════════════════════════════════════════════════════════

function verify_email_ldap(string $email): array {
    return \App\Core\App::emailVerify()->verifyLdap($email);
}

function ldap_suggest(string $query = '', int $limit = 100): array {
    return \App\Core\App::emailVerify()->ldapSuggest($query, $limit);
}

function verify_email_smtp(string $email): array {
    return \App\Core\App::emailVerify()->verifySmtp($email);
}

function verify_email(string $email): array {
    return \App\Core\App::emailVerify()->verify($email);
}

function test_email_verification(string $email): array {
    return \App\Core\App::emailVerify()->testVerification($email);
}

// ═══════════════════════════════════════════════════════════════
//  EXPORT CSV (ExportService)
// ═══════════════════════════════════════════════════════════════

function export_csv(PDO $pdo, array $options = []): void {
    \App\Core\App::export()->exportCsv($options);
}

// ═══════════════════════════════════════════════════════════════
//  VALIDATOR DATA / FILLED_BY (ValidatorDataService)
// ═══════════════════════════════════════════════════════════════

function get_submission_validator_data(string $submission_id, ?string $step_id = null): array {
    return \App\Core\App::validatorData()->getSubmissionValidatorData($submission_id, $step_id);
}

function save_validator_data(
    string $submission_id,
    string $field_name,
    string $value,
    string $filled_by,
    ?string $step_id = null,
    ?string $step_label = null,
    ?string $filled_by_email = null,
    ?string $token_id = null
): void {
    \App\Core\App::validatorData()->saveValidatorData(
        $submission_id, $field_name, $value, $filled_by,
        $step_id, $step_label, $filled_by_email, $token_id
    );
}

function delete_validator_data(string $submission_id, string $field_name): void {
    \App\Core\App::validatorData()->deleteValidatorData($submission_id, $field_name);
}

function get_form_validator_fields(string $form_id, ?string $step_id = null): array {
    return \App\Core\App::validatorData()->getFormValidatorFields($form_id, $step_id);
}

function get_form_fields(string $form_id, ?string $filled_by = null): array {
    return \App\Core\App::validatorData()->getFormFields($form_id, $filled_by);
}

function get_validator_status_batch(PDO $pdo, array $submissions): array {
    return \App\Core\App::validatorData()->getValidatorStatusBatch($submissions);
}

// ═══════════════════════════════════════════════════════════════
//  CRON (CronService)
// ═══════════════════════════════════════════════════════════════

function run_lazy_cron(PDO $pdo): void {
    \App\Core\App::cron()->runLazyCron();
}

function parse_db_datetime(string $datetime): ?int {
    return \App\Cron\CronService::parseDbDatetime($datetime);
}

function handle_post(): ?string {
    return \App\Core\App::cron()->handlePost();
}

// ═══════════════════════════════════════════════════════════════
//  MAIL (MailerService)
// ═══════════════════════════════════════════════════════════════

function _mail_service(): \App\Mail\MailerService {
    static $service = null;
    if ($service === null && class_exists(\App\Core\App::class)) {
        $app = \App\Core\App::getInstance();
        if ($app->has(\App\Mail\MailerService::class)) {
            $service = $app->get(\App\Mail\MailerService::class);
        }
    }
    if ($service === null) {
        $db = new \App\Core\Database();
        $settings = new \App\Settings\SettingsService(new \App\Repository\SettingsRepository($db));
        $service = new \App\Mail\MailerService($db, $settings);
    }
    return $service;
}

function send_mail(string $to, string $subject, string $body): bool {
    return _mail_service()->send($to, $subject, $body);
}

function send_mail_detailed(string $to, string $subject, string $body): array {
    return _mail_service()->sendDetailed($to, $subject, $body);
}

function log_mail_attempt(string $to, string $subject, string $status, string $error, string $smtp_log, string $actor, string $ip): void {
    _mail_service()->logAttempt($to, $subject, $status, $error, $smtp_log, $actor, $ip);
}

function get_recent_mail_logs(int $limit = 30): array {
    return _mail_service()->getRecentLogs($limit);
}

function build_mail_html(array $submission, string $step_label, string $token): string {
    return _mail_service()->buildMailHtml($submission, $step_label, $token);
}

function render_email_template(string $title, string $body_html): string {
    return _mail_service()->renderEmailTemplate($title, $body_html);
}

// ═══════════════════════════════════════════════════════════════
//  RGPD (RgpdService)
// ═══════════════════════════════════════════════════════════════

function rgpd_export_user_data(string $email): array {
    return \App\Core\App::getInstance()->get(\App\Rgpd\RgpdService::class)->exportUserData($email);
}

function rgpd_delete_user_data(string $email): bool {
    return \App\Core\App::getInstance()->get(\App\Rgpd\RgpdService::class)->deleteUserData($email);
}

function rgpd_auto_purge(int $months = 24): int {
    return \App\Core\App::getInstance()->get(\App\Rgpd\RgpdService::class)->autoPurge($months);
}

// ═══════════════════════════════════════════════════════════════
//  STATS (StatsService)
// ═══════════════════════════════════════════════════════════════

function search_submissions(string $query, array $filters = []): array {
    return \App\Core\App::getInstance()->get(\App\Stats\StatsService::class)->searchSubmissions($query, $filters);
}

function get_stats_by_period(string $period = 'month', int $limit = 12): array {
    return \App\Core\App::getInstance()->get(\App\Stats\StatsService::class)->getStatsByPeriod($period, $limit);
}

function get_global_stats(): array {
    return \App\Core\App::getInstance()->get(\App\Stats\StatsService::class)->getGlobalStats();
}

// ═══════════════════════════════════════════════════════════════
//  TOKENS (TokenService)
// ═══════════════════════════════════════════════════════════════

function regenerate_token(string $old_token_id): array {
    return \App\Core\App::token()->regenerate($old_token_id);
}

function cancel_submission(string $submission_id, string $cancelled_by = ''): array {
    return \App\Core\App::token()->cancel($submission_id, $cancelled_by);
}

function remind_one(string $token_id): array {
    return \App\Core\App::token()->remind($token_id);
}

function get_tokens_for_submission(string $submission_id, array $extra_fields = []): array {
    return \App\Core\App::token()->getForSubmission($submission_id, $extra_fields);
}

function delegate_token(string $token_id, string $to_email, string $reason = ''): array {
    return \App\Core\App::token()->delegate($token_id, $to_email, $reason);
}

function get_delegations(string $submission_id): array {
    return \App\Core\App::token()->getDelegations($submission_id);
}

// ═══════════════════════════════════════════════════════════════
//  WEBHOOK (WebhookService)
// ═══════════════════════════════════════════════════════════════

function send_webhook(string $event, array $data): void {
    \App\Core\App::webhook()->send($event, $data);
}

function get_db_size(): int {
    return \App\Core\App::webhook()->getDbSize();
}

// ═══════════════════════════════════════════════════════════════
//  WORKFLOW (WorkflowEngine)
// ═══════════════════════════════════════════════════════════════

function get_token_with_context(string $token_value): ?array {
    return \App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)
        ->getTokenWithContext($token_value);
}

function get_token_by_id_with_context(string $token_id): ?array {
    return \App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)
        ->getTokenByIdWithContext($token_id);
}

function get_workflow_steps(string $form_id): array {
    return \App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)
        ->getWorkflowSteps($form_id);
}

function get_submission_with_form_label(string $submission_id): ?array {
    return \App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)
        ->getSubmissionWithFormLabel($submission_id);
}

function resolve_dynamic_recipient(string $recipient, array $form_data, ?string $submission_id = null): string {
    return \App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)
        ->resolveDynamicRecipient($recipient, $form_data, $submission_id);
}

function advance_workflow(string $submission_id): void {
    \App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)
        ->advanceWorkflow($submission_id);
}

function validate_token(string $token, string $action = 'valider', string $comment = '', string $done_by = ''): array {
    return \App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)
        ->validateToken($token, $action, $comment, $done_by);
}

function has_active_submissions(string $form_id): int {
    return \App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)
        ->hasActiveSubmissions($form_id);
}

function has_active_step_submissions(string $step_id): int {
    return \App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)
        ->hasActiveStepSubmissions($step_id);
}
