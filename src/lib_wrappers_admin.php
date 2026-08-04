<?php

declare(strict_types=1);

/**
 * Global admin wrappers (forms + settings).
 *
 * Delegates to App\Render\AdminFormsRenderer, App\Render\AdminSettingsRenderer,
 * App\Controller\AdminFormsHandlers, App\Controller\AdminSettingsHandlers,
 * App\Forms\FormJsonValidator, App\Forms\SampleFormsService.
 * Loaded by lib_wrappers.php (main loader).
 */

use App\Render\AdminFormsRenderer;
use App\Render\AdminSettingsRenderer;

// ── FORM JSON VALIDATION ──────────────────────────────────────────

/**
 * @param array<string, mixed> $data
 * @return array{valid: bool, errors: string[], warnings: string[]}
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

// ── SAMPLE FORMS ─────────────────────────────────────────────────

function populate_sample_forms(\PDO $pdo): string
{
    $service = new \App\Forms\SampleFormsService(\App\Core\App::db());
    return $service->populate();
}

// ── ADMIN FORMS HANDLERS ─────────────────────────────────────────

/**
 * @return array{error?: string, form_id?: string, redirect?: string, validation_html?: string, preserved_json?: string, filename?: string, json_output?: string}|null
 */
function handle_admin_action(string $action, string $get_form_id = ''): ?array
{
    return \App\Controller\AdminFormsHandlers::dispatch($action, $get_form_id);
}

// ── ADMIN SETTINGS HANDLERS ──────────────────────────────────────

/**
 * @return array{success: string, error: string, test: string, verify_result: mixed}
 */
function handle_admin_settings_post(): array
{
    return \App\Controller\AdminSettingsHandlers::handlePost();
}

// ── ADMIN FORMS RENDER ────────────────────────────────────────────

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
 * Build AdminFormsContext from legacy array.
 *
 * @param array<string, mixed> $ctx
 */
function _build_admin_forms_context(array $ctx): \App\Render\AdminFormsContext
{
    return new \App\Render\AdminFormsContext(
        form_id: (string) ($ctx['form_id'] ?? ''),
        form: $ctx['form'] ?? null,
        forms: $ctx['forms'] ?? [],
        error_msg: (string) ($ctx['error_msg'] ?? ''),
        success_msg: (string) ($ctx['success_msg'] ?? ''),
        preserved_json: (string) ($ctx['preserved_json'] ?? ''),
        validation_html: (string) ($ctx['validation_html'] ?? ''),
        owners: $ctx['owners'] ?? [],
        steps: $ctx['steps'] ?? [],
        steps_by_ordre: $ctx['steps_by_ordre'] ?? [],
        edit_step_id: (string) ($ctx['edit_step_id'] ?? ''),
        form_fields: $ctx['form_fields'] ?? [],
        edit_field_id: (string) ($ctx['edit_field_id'] ?? ''),
        existing_groups: $ctx['existing_groups'] ?? [],
    );
}

/**
 * Build SubmissionViewContext from legacy array.
 *
 * @param array<string, mixed> $ctx
 */
function _build_submission_view_context(array $ctx): \App\Render\SubmissionViewContext
{
    return new \App\Render\SubmissionViewContext(
        sub_id: (string) ($ctx['sub_id'] ?? ''),
        sub: $ctx['sub'] ?? [],
        data: $ctx['data'] ?? [],
        status: (string) ($ctx['status'] ?? \App\Enum\SubmissionStatus::EnCours->value),
        status_label: (string) ($ctx['status_label'] ?? ''),
        status_cls: (string) ($ctx['status_cls'] ?? ''),
        user: (string) ($ctx['user'] ?? ''),
        is_admin: (bool) ($ctx['is_admin'] ?? false),
        is_form_owner: (bool) ($ctx['is_form_owner'] ?? false),
        nom_agent: (string) ($ctx['nom_agent'] ?? ''),
        workflow_steps: $ctx['workflow_steps'] ?? [],
        all_tokens: $ctx['all_tokens'] ?? [],
        total_steps: (int) ($ctx['total_steps'] ?? 0),
        done_steps: (int) ($ctx['done_steps'] ?? 0),
        progress_pct: (int) ($ctx['progress_pct'] ?? 0),
        dl_info: $ctx['dl_info'] ?? [],
        deadline_ts: $ctx['deadline_ts'] ?? null,
        days_remaining: (int) ($ctx['days_remaining'] ?? 0),
        action_msg: (string) ($ctx['action_msg'] ?? ''),
        field_info: $ctx['field_info'] ?? [],
        validator_data_rows: $ctx['validator_data_rows'] ?? [],
        submission_reminds: $ctx['submission_reminds'] ?? [],
        total_relances: (int) ($ctx['total_relances'] ?? 0),
        pending_with_relance: $ctx['pending_with_relance'] ?? [],
        attachments: $ctx['attachments'] ?? [],
        delegations: $ctx['delegations'] ?? [],
        admin_comment: (string) ($ctx['admin_comment'] ?? ''),
    );
}

/**
 * Build MonitoringContext from legacy array.
 *
 * @param array<string, mixed> $ctx
 */
function _build_monitoring_context(array $ctx): \App\Render\MonitoringContext
{
    return new \App\Render\MonitoringContext(
        total_sub: (int) ($ctx['total_sub'] ?? 0),
        valide_sub: (int) ($ctx['valide_sub'] ?? 0),
        en_cours_sub: (int) ($ctx['en_cours_sub'] ?? 0),
        refuse_sub: (int) ($ctx['refuse_sub'] ?? 0),
        taux_validation: (float) ($ctx['taux_validation'] ?? 0.0),
        avg_days: (float) ($ctx['avg_days'] ?? 0),
        avg_hours: (float) ($ctx['avg_hours'] ?? 0),
        bloque_hours: (int) ($ctx['bloque_hours'] ?? 0),
        tokens_bloques: $ctx['tokens_bloques'] ?? [],
        active_alerts: $ctx['active_alerts'] ?? [],
        recent_alerts: $ctx['recent_alerts'] ?? [],
        by_form_stats: $ctx['by_form_stats'] ?? [],
        daily_stats: $ctx['daily_stats'] ?? [],
        smtp_status: (string) ($ctx['smtp_status'] ?? 'inconnu'),
        smtp_detail: (string) ($ctx['smtp_detail'] ?? ''),
        smtp_debug_log: (string) ($ctx['smtp_debug_log'] ?? ''),
        mail_logs: $ctx['mail_logs'] ?? [],
        last_remind: (string) ($ctx['last_remind'] ?? ''),
        last_alert_check: (string) ($ctx['last_alert_check'] ?? ''),
        audit_filters: $ctx['audit_filters'] ?? [],
        audit_total: (int) ($ctx['audit_total'] ?? 0),
        audit_total_pages: (int) ($ctx['audit_total_pages'] ?? 1),
        audit_page: (int) ($ctx['audit_page'] ?? 1),
        audit_logs: $ctx['audit_logs'] ?? [],
        action_types: $ctx['action_types'] ?? [],
        audit_base_url: (string) ($ctx['audit_base_url'] ?? 'index.php?p=monitoring'),
        audit_base_qs: (string) ($ctx['audit_base_qs'] ?? ''),
    );
}

/**
 * @param array<string, mixed> $ctx
 */
function render_form_selector_panel(array $ctx): string
{
    return AdminFormsRenderer::getInstance()->renderSelectorPanel(_build_admin_forms_context($ctx));
}

/**
 * @param array<string, mixed> $ctx
 */
function render_import_json_panel(array $ctx): string
{
    return AdminFormsRenderer::getInstance()->renderImportJsonPanel(_build_admin_forms_context($ctx));
}

/**
 * @param array<string, mixed> $ctx
 */
function render_prompt_ia_panel(array $ctx): string
{
    return AdminFormsRenderer::getInstance()->renderPromptIaPanel(_build_admin_forms_context($ctx));
}

/**
 * @param array<string, mixed> $ctx
 */
function render_new_form_panel(array $ctx): string
{
    return AdminFormsRenderer::getInstance()->renderNewFormPanel(_build_admin_forms_context($ctx));
}

/**
 * @param array<string, mixed> $ctx
 */
function render_top_action_bar(array $ctx): string
{
    return AdminFormsRenderer::getInstance()->renderTopActionBar(_build_admin_forms_context($ctx));
}

/**
 * @param array<string, mixed> $ctx
 */
function render_form_info_section(array $ctx): string
{
    return AdminFormsRenderer::getInstance()->renderFormInfoSection(_build_admin_forms_context($ctx));
}

/**
 * @param array<string, mixed> $ctx
 */
function render_owners_section(array $ctx): string
{
    return AdminFormsRenderer::getInstance()->renderOwnersSection(_build_admin_forms_context($ctx));
}

/**
 * @param array<string, mixed> $ctx
 */
function render_workflow_diagram_section(array $ctx): string
{
    return AdminFormsRenderer::getInstance()->renderWorkflowDiagramSection(_build_admin_forms_context($ctx));
}

/**
 * @param array<string, mixed> $ctx
 */
function render_form_fields_section(array $ctx): string
{
    return AdminFormsRenderer::getInstance()->renderFormFieldsSection(_build_admin_forms_context($ctx));
}

/**
 * @param array<string, mixed> $ctx
 */
function render_admin_forms_page(array $ctx): void
{
    AdminFormsRenderer::getInstance()->renderPage(_build_admin_forms_context($ctx));
}

// ── ADMIN SETTINGS RENDER ─────────────────────────────────────────

function admin_settings_page_css(): string
{
    return AdminSettingsRenderer::getInstance()->getPageCss();
}

/**
 * @param array<string, mixed> $state
 */
function _build_admin_settings_context(array $state): \App\Render\AdminSettingsContext
{
    return new \App\Render\AdminSettingsContext(
        success: (string) ($state['success'] ?? ''),
        error: (string) ($state['error'] ?? ''),
        test: (string) ($state['test'] ?? ''),
        verify_result: $state['verify_result'] ?? null,
    );
}

function render_admin_settings_content(array $state): string
{
    return AdminSettingsRenderer::getInstance()->renderContent(_build_admin_settings_context($state));
}

function render_admin_settings_after_main(): string
{
    return AdminSettingsRenderer::getInstance()->renderAfterMain();
}
