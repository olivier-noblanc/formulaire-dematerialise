<?php

declare(strict_types=1);

/**
 * Admin wrappers (forms + settings).
 *
 * Loaded by lib_wrappers.php (main loader).
 * All functions here are thin wrappers delegating to OOP classes.
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
 * @param array{valid: bool, errors: string[], warnings: string[]} $result
 */
function format_validation_results(array $result): string
{
    return \App\Forms\FormJsonValidator::formatResults($result);
}

// ── SAMPLE FORMS ─────────────────────────────────────────────────

function populate_sample_forms(\PDO $pdo): string
{
    return new \App\Forms\SampleFormsService(new \App\Repository\FormRepository(\App\Core\App::db()))->populate();
}

// ── ADMIN FORMS HANDLERS ─────────────────────────────────────────

/**
 * @return array<string, mixed>|null
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
 * @param array<string, mixed> $ctx
 */
function _build_admin_forms_context(array $ctx): \App\Render\AdminFormsContext
{
    return \App\Render\AdminFormsContext::fromLegacyArray($ctx);
}

/**
 * @param array<string, mixed> $ctx
 */
function _build_submission_view_context(array $ctx): \App\Render\SubmissionViewContext
{
    return \App\Render\SubmissionViewContext::fromLegacyArray($ctx);
}

/**
 * @param array<string, mixed> $ctx
 */
function _build_monitoring_context(array $ctx): \App\Render\MonitoringContext
{
    return \App\Render\MonitoringContext::fromLegacyArray($ctx);
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
    return \App\Render\AdminSettingsContext::fromLegacyArray($state);
}

/**
 * @param array<string, mixed> $state
 */
function render_admin_settings_content(array $state): string
{
    return AdminSettingsRenderer::getInstance()->renderContent(_build_admin_settings_context($state));
}

function render_admin_settings_after_main(): string
{
    return AdminSettingsRenderer::getInstance()->renderAfterMain();
}
