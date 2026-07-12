<?php

declare(strict_types=1);

/**
 * Global wrapper functions for lib/ utilities (absorbed into src/ services).
 *
 * Provides backward-compatible global function names that delegate to OOP classes.
 * Loaded by helpers.php instead of the individual lib/ files.
 */

use App\Core\DateHelper;
use App\Core\SlugHelper;
use App\Core\TestModeService;
use App\Core\UuidHelper;
use App\Render\AdminFormsRenderer;
use App\Render\AdminSettingsRenderer;
use App\Render\JargonService;

// ── UUID (lib/uuid.php → App\Core\UuidHelper) ─────────────────
function generate_uuid(): string
{
    return UuidHelper::generateUuid();
}
function generate_token(): string
{
    return UuidHelper::generateToken();
}

// ── DATE (lib/date.php → App\Core\DateHelper) ──────────────────
function parse_deadline_date(string $dateStr): ?int
{
    return DateHelper::parseDeadlineDate($dateStr);
}
function parse_date(string $date_str): ?DateTimeImmutable
{
    return DateHelper::parseDate($date_str);
}
/**
 * @return array<string, mixed>
 */
function calculate_deadline_urgency(string $deadlineVal, string $status = 'en_cours'): array
{
    return DateHelper::calculateDeadlineUrgency($deadlineVal, $status);
}

// ── SLUG/FIELD (lib/database.php → App\Core\SlugHelper) ────────
function generate_field_name(string $label): string
{
    return SlugHelper::generateFieldName($label);
}
function generate_slug(string $label, ?string $exclude_form_id = null): string
{
    return SlugHelper::generateSlug($label, $exclude_form_id);
}
function parse_options_input(string $input): ?string
{
    return SlugHelper::parseOptionsInput($input);
}
/**
 * @return array<string, mixed>|null
 */
function get_form_by_uuid(string $uuid): ?array
{
    return SlugHelper::getFormByUuid($uuid);
}

// ── DATABASE (lib/database.php → App\Core\App::db()) ────────────
function get_pdo(): PDO
{
    return \App\Core\App::db()->getPdo();
}
function release_pdo(): void
{
    \App\Core\App::db()->release();
}

// ── JARGON (lib/jargon.php → App\Render\JargonService) ─────────
function t_jargon(string $text): string
{
    return JargonService::translate($text);
}

// ── TEST MODE (lib/test_mode.php → App\Core\TestModeService) ────
/**
 * @return array<string, mixed>
 */
function get_test_mails(): array
{
    return TestModeService::getTestMails();
}
function reset_test_mails(): void
{
    TestModeService::resetTestMails();
}
/**
 * @param array<string, mixed> $data
 */
function test_json_response(array $data): void
{
    TestModeService::testJsonResponse($data);
}

// ── SETTINGS (lib/settings.php → App\Settings\SettingsService) ──
/**
 * @return array<int, string>
 */
function get_sensitive_setting_keys(): array
{
    return ['smtp_pass', 'ldap_bind_pass', 'app_test_secret'];
}

function encrypt_setting(string $value): string
{
    if ($value === '') {
        return '';
    }
    if (str_starts_with($value, 'enc:')) {
        return $value;
    }

    $key = getenv('APP_ENCRYPTION_KEY');
    if (empty($key) || strlen($key) < 32) {
        error_log('[SECURITY] APP_ENCRYPTION_KEY non définie ou trop courte — valeur stockée en clair');
        return $value;
    }
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    if ($iv_length === false) {
        error_log('[SECURITY] openssl_cipher_iv_length a échoué — valeur stockée en clair');
        return $value;
    }
    $iv = random_bytes(max(1, $iv_length));
    $encrypted = openssl_encrypt($value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) {
        error_log('[SECURITY] Échec de chiffrement — valeur stockée en clair');
        return $value;
    }
    return 'enc:' . base64_encode($iv . $encrypted);
}

function decrypt_setting(string $value): string
{
    if ($value === '' || !str_starts_with($value, 'enc:')) {
        return $value;
    }
    $key = getenv('APP_ENCRYPTION_KEY');
    if (empty($key)) {
        error_log('[SECURITY] APP_ENCRYPTION_KEY non définie — impossible de déchiffrer');
        return '[chiffré]';
    }
    $decoded = base64_decode(substr($value, 4), true);
    if ($decoded === false) {
        return '[chiffré]';
    }
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    if ($iv_length === false) {
        return '[chiffré]';
    }
    $iv = substr($decoded, 0, $iv_length);
    $ciphertext = substr($decoded, $iv_length);
    $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($decrypted === false) {
        error_log('[SECURITY] Échec de déchiffrement — clé probablement incorrecte');
        return '[chiffré]';
    }
    return $decrypted;
}

function get_setting(string $key, string $default = ''): string
{
    return \App\Core\App::settings()->get($key, $default);
}

function set_setting(string $key, string $value, string $updated_by = ''): void
{
    \App\Core\App::settings()->set($key, $value, $updated_by);
}

// ── CACHE (lib/cache.php → App\Cache\CacheService) ──────────────
function cache_dir(): string
{
    $cache_dir = dirname(__DIR__, 1) . '/db/cache';
    if (!is_dir($cache_dir)) {
        @mkdir($cache_dir, 0o750, true);
        $web_config = $cache_dir . '/web.config';
        if (!file_exists($web_config)) {
            @file_put_contents($web_config, '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<configuration><system.webServer><authorization>'
                . '<deny users="*"/>'
                . '</authorization></system.webServer></configuration>' . "\n");
        }
    }
    return $cache_dir;
}

function cache_get(string $key, int $ttl, callable $callback): mixed
{
    $cache_file = cache_dir() . '/cache_' . md5($key) . '.json';
    if (is_readable($cache_file)) {
        $payload = @json_decode((string) file_get_contents($cache_file), true);
        if (is_array($payload) && array_key_exists('value', $payload)
            && isset($payload['created_at'])
            && (time() - (int) $payload['created_at']) < $ttl) {
            return $payload['value'];
        }
    }
    $value = $callback();
    cache_set($key, $value, $ttl);
    return $value;
}

function cache_set(string $key, mixed $value, int $ttl = 300): void
{
    $cache_file = cache_dir() . '/cache_' . md5($key) . '.json';
    $payload = [
        'value'      => $value,
        'ttl'        => $ttl,
        'created_at' => time(),
    ];
    @file_put_contents($cache_file, json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function cache_clear(string $key): void
{
    $cache_file = cache_dir() . '/cache_' . md5($key) . '.json';
    if (file_exists($cache_file)) {
        @unlink($cache_file);
    }
}

function get_latest_version(): string
{
    static $version = null;
    if ($version !== null) {
        return $version;
    }
    $changelog_path = dirname(__DIR__, 1) . '/CHANGELOG.md';
    if (file_exists($changelog_path)) {
        $content = file_get_contents($changelog_path);
        if ($content !== false && preg_match('/^##\s*\[(\d+\.\d+\.\d+)\]/m', $content, $m)) {
            $version = $m[1];
            return $version;
        }
    }
    $version = '0.0.0';
    return $version;
}

// ── PERSONA (lib/persona.php → App\Persona\PersonaService) ──────
function persona_create_token(string $admin_email, string $target_email): string
{
    $personaService = persona_get_service();
    return $personaService->createToken($admin_email, $target_email);
}

function persona_lookup(string $token): string
{
    $personaService = persona_get_service();
    return $personaService->lookup($token);
}

function persona_revoke(string $token): bool
{
    $personaService = persona_get_service();
    return $personaService->revoke($token);
}

function persona_cleanup(): int
{
    $personaService = persona_get_service();
    return $personaService->cleanup();
}

function persona_current_token(): string
{
    $personaService = persona_get_service();
    return $personaService->currentToken();
}

function persona_current_target(): string
{
    $personaService = persona_get_service();
    return $personaService->currentTarget();
}

function persona_get_service(): \App\Persona\PersonaService
{
    if (\App\Core\App::getInstance()->has(\App\Persona\PersonaService::class)) {
        return \App\Core\App::getInstance()->get(\App\Persona\PersonaService::class);
    }
    return new \App\Persona\PersonaService(new \App\Core\Database());
}

// ── CONDITIONS (lib/conditions.php → App\Workflow\ConditionEvaluator) ──
/**
 * @param array<string, mixed> $data
 */
function evaluate_condition(?string $condition_json, array $data): bool
{
    return \App\Core\App::conditions()->evaluate($condition_json, $data);
}

/**
 * @param array<string, mixed> $step
 */
function evaluate_step_condition(array $step, string $submission_id): bool
{
    $condition_json = $step['condition'] ?? '';
    if (empty($condition_json)) {
        return true;
    }

    $validator_data = \App\Core\App::validatorData()->getSubmissionValidatorData($submission_id);
    $data = [];
    foreach ($validator_data as $vd) {
        $data[$vd['field_name'] ?? ''] = $vd['value'] ?? '';
    }

    return evaluate_condition($condition_json, $data);
}

/**
 * @param array<string, mixed> $field
 * @param array<string, mixed> $form_data
 */
function evaluate_field_condition(array $field, array $form_data): bool
{
    $condition_json = $field['condition'] ?? '';
    return evaluate_condition($condition_json, $form_data);
}

// ── VALIDATION (lib/validation.php → App\Validation\ValidationService) ──
function sanitize_input(string $input): string
{
    trigger_error('sanitize_input() is deprecated — use \App\Core\App::html()->escape() for HTML output and prepared statements for SQL', E_USER_DEPRECATED);
    return \App\Core\App::validation()->sanitize($input);
}

function validate_email(string $email): string
{
    return \App\Core\App::validation()->validateEmail($email);
}

/**
 * @param array<string, mixed> $options
 */
function validate_input(mixed $value, string $rule, array $options = []): string|int
{
    return \App\Core\App::validation()->validate($value, $rule, $options);
}

// ── FORM JSON VALIDATION (lib/admin_forms_json.php → App\Forms\FormJsonValidator) ──
/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function validate_form_json(array $data): array
{
    return \App\Forms\FormJsonValidator::validate($data);
}

/**
 * @param array<string, mixed> $result
 */
function format_validation_results(array $result): string
{
    return \App\Forms\FormJsonValidator::formatResults($result);
}

// ── SAMPLE FORMS (lib/admin_forms_samples.php → App\Forms\SampleFormsService) ──
function populate_sample_forms(\PDO $pdo): string
{
    $service = new \App\Forms\SampleFormsService(\App\Core\App::db());
    return $service->populate();
}

// ── ADMIN FORMS HANDLERS (lib/admin_forms_handlers.php → App\Controller\AdminFormsHandlers) ──
/**
 * @return array<string, mixed>|null
 */
function handle_admin_action(\PDO $pdo, string $action, string $get_form_id = ''): ?array
{
    return \App\Controller\AdminFormsHandlers::dispatch($action, $get_form_id);
}

// ── ADMIN SETTINGS HANDLERS (lib/admin_settings_handlers.php → App\Controller\AdminSettingsHandlers) ──
/**
 * @return array<string, mixed>
 */
function handle_admin_settings_post(): array
{
    return \App\Controller\AdminSettingsHandlers::handlePost();
}

// ── ADMIN FORMS RENDER (lib/admin_forms_render*.php → App\Render\AdminFormsRenderer) ──
function get_admin_forms_page_css(): string
{
    return AdminFormsRenderer::getInstance()->getPageCss();
}

/**
 * @return array<string, string>
 */
function get_admin_forms_field_types(): array
{
    return AdminFormsRenderer::getInstance()->getFormFieldTypes();
}

function field_type_icon(string $type): string
{
    return AdminFormsRenderer::getInstance()->fieldTypeIcon($type);
}

function field_type_label(string $type): string
{
    return AdminFormsRenderer::getInstance()->fieldTypeLabel($type);
}

function options_to_lines(?string $json): string
{
    return AdminFormsRenderer::getInstance()->optionsToLines($json);
}

/**
 * @param array<string, mixed> $ctx
 */
function render_form_selector_panel(array $ctx): string
{
    return AdminFormsRenderer::getInstance()->renderSelectorPanel($ctx);
}

/**
 * @param array<string, mixed> $ctx
 */
function render_import_json_panel(array $ctx): string
{
    return AdminFormsRenderer::getInstance()->renderImportJsonPanel($ctx);
}

/**
 * @param array<string, mixed> $ctx
 */
function render_prompt_ia_panel(array $ctx): string
{
    return AdminFormsRenderer::getInstance()->renderPromptIaPanel($ctx);
}

/**
 * @param array<string, mixed> $ctx
 */
function render_new_form_panel(array $ctx): string
{
    return AdminFormsRenderer::getInstance()->renderNewFormPanel($ctx);
}

/**
 * @param array<string, mixed> $ctx
 */
function render_top_action_bar(array $ctx): string
{
    return AdminFormsRenderer::getInstance()->renderTopActionBar($ctx);
}

/**
 * @param array<string, mixed> $ctx
 */
function render_form_info_section(array $ctx): string
{
    return AdminFormsRenderer::getInstance()->renderFormInfoSection($ctx);
}

/**
 * @param array<string, mixed> $ctx
 */
function render_owners_section(array $ctx): string
{
    return AdminFormsRenderer::getInstance()->renderOwnersSection($ctx);
}

/**
 * @param array<string, mixed> $ctx
 */
function render_workflow_diagram_section(array $ctx): string
{
    return AdminFormsRenderer::getInstance()->renderWorkflowDiagramSection($ctx);
}

/**
 * @param array<string, mixed> $ctx
 */
function render_form_fields_section(array $ctx): string
{
    return AdminFormsRenderer::getInstance()->renderFormFieldsSection($ctx);
}

/**
 * @param array<string, mixed> $ctx
 */
function render_admin_forms_page(array $ctx): void
{
    AdminFormsRenderer::getInstance()->renderPage($ctx);
}

// ── ADMIN SETTINGS RENDER (lib/render_admin_settings.php → App\Render\AdminSettingsRenderer) ──
function admin_settings_page_css(): string
{
    return AdminSettingsRenderer::getInstance()->getPageCss();
}

/**
 * @param array<string, mixed> $state
 */
function render_admin_settings_content(array $state): string
{
    return AdminSettingsRenderer::getInstance()->renderContent($state);
}

function render_admin_settings_after_main(): string
{
    return AdminSettingsRenderer::getInstance()->renderAfterMain();
}
