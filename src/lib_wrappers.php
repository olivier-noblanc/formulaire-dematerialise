<?php

declare(strict_types=1);

/**
 * Global wrapper functions — thin delegators to OOP services.
 *
 * Loaded by helpers.php. Provides backward-compatible global function names.
 * Functions with real logic live in their respective service classes.
 * Admin-only wrappers live in lib_wrappers_admin.php (required below).
 */

require_once __DIR__ . '/lib_wrappers_admin.php';

use App\Core\DateHelper;
use App\Core\SlugHelper;
use App\Core\TestModeService;
use App\Core\UuidHelper;
use App\Enum\SubmissionStatus;
use App\Render\JargonService;

// ── UUID ─────────────────────────────────────────────────────────
function generate_uuid(): string { return UuidHelper::generateUuid(); }
function generate_token(): string { return UuidHelper::generateToken(); }

// ── DATE ──────────────────────────────────────────────────────────
function parse_deadline_date(string $dateStr): ?int { return DateHelper::parseDeadlineDate($dateStr); }
function parse_date(string $date_str): ?DateTimeImmutable { return DateHelper::parseDate($date_str); }
/**
 * @return array{days_left: ?int, urgency: string, style: string}
 */
function calculate_deadline_urgency(string $deadlineVal, string $status = SubmissionStatus::EnCours->value): array
{
    return DateHelper::calculateDeadlineUrgency($deadlineVal, $status);
}

// ── SLUG / FIELD ──────────────────────────────────────────────────
function generate_field_name(string $label): string { return SlugHelper::generateFieldName($label); }
function generate_slug(string $label, ?string $exclude_form_id = null): string { return SlugHelper::generateSlug($label, $exclude_form_id); }
function parse_options_input(string $input): ?string { return SlugHelper::parseOptionsInput($input); }
/**
 * @return array{id: string, slug: string, label: string, description: string|null, actif: int, created_at: string, deadline_field: string}|null
 */
function get_form_by_uuid(string $uuid): ?array { return SlugHelper::getFormByUuid($uuid); }

// ── JARGON ────────────────────────────────────────────────────────
function t_jargon(string $text): string { return JargonService::translate($text); }

// ── TEST MODE ─────────────────────────────────────────────────────
/**
 * @return array<int, array{to: string, subject: string, body: string, time: string}>
 */
function get_test_mails(): array { return TestModeService::getTestMails(); }
function reset_test_mails(): void { TestModeService::resetTestMails(); }
/**
 * @param array<string, mixed> $data
 */
function test_json_response(array $data): void { TestModeService::testJsonResponse($data); }

// ── SETTINGS ──────────────────────────────────────────────────────
/**
 * @return list<string>
 */
function get_sensitive_setting_keys(): array { return \App\Settings\SettingsService::getSensitiveKeys(); }
function get_setting(string $key, string $default = ''): string { return \App\Core\App::settings()->get($key, $default); }
function set_setting(string $key, string $value, string $updated_by = ''): void { \App\Core\App::settings()->set($key, $value, $updated_by); }

// ── CACHE (App\Cache\CacheService) ────────────────────────────────
function cache_dir(): string { return \App\Core\App::cache()->getCacheDir(); }
function cache_get(string $key, int $ttl, callable $callback): mixed { return \App\Core\App::cache()->get($key, $ttl, $callback); }
function cache_set(string $key, mixed $value, int $ttl = 300): void { \App\Core\App::cache()->set($key, $value, $ttl); }
function cache_clear(string $key): void { \App\Core\App::cache()->clear($key); }
function get_latest_version(): string { return \App\Core\App::cache()->getLatestVersion(); }

// ── PERSONA ───────────────────────────────────────────────────────
function persona_create_token(string $admin_email, string $target_email): string { return \App\Persona\PersonaService::getService()->createToken($admin_email, $target_email); }
function persona_lookup(string $token): string { return \App\Persona\PersonaService::getService()->lookup($token); }
function persona_revoke(string $token): bool { return \App\Persona\PersonaService::getService()->revoke($token); }
function persona_cleanup(): int { return \App\Persona\PersonaService::getService()->cleanup(); }
function persona_current_token(): string { return \App\Persona\PersonaService::getService()->currentToken(); }
function persona_current_target(): string { return \App\Persona\PersonaService::getService()->currentTarget(); }
function persona_get_service(): \App\Persona\PersonaService { return \App\Persona\PersonaService::getService(); }

// ── CONDITIONS ────────────────────────────────────────────────────
/**
 * @param array<string, mixed> $data
 */
function evaluate_condition(?string $condition_json, array $data): bool { return \App\Core\App::conditions()->evaluate($condition_json, $data); }
/**
 * @param array{condition?: string} $step
 */
function evaluate_step_condition(array $step, string $submission_id): bool { return \App\Workflow\ConditionEvaluator::evaluateStepCondition($step, $submission_id); }
/**
 * @param array{condition?: string} $field
 * @param array<string, mixed> $form_data
 */
function evaluate_field_condition(array $field, array $form_data): bool { return \App\Workflow\ConditionEvaluator::evaluateFieldCondition($field, $form_data); }

// ── VALIDATION ────────────────────────────────────────────────────
function sanitize_input(string $input): string
{
    trigger_error('sanitize_input() is deprecated — use \App\Core\App::html()->escape() for HTML output and prepared statements for SQL', E_USER_DEPRECATED);
    return \App\Core\App::validation()->sanitize($input);
}
function validate_email(string $email): string { return \App\Core\App::validation()->validateEmail($email); }
/**
 * @param array{max_length?: int, allowed_values?: list<string>, min?: int, max?: int} $options
 */
function validate_input(mixed $value, string $rule, array $options = []): string|int { return \App\Core\App::validation()->validate($value, $rule, $options); }

// ── VIEW ──────────────────────────────────────────────────────────
function render_footer(): string
{
    return new \App\Render\NavigationRenderer()->footer();
}
