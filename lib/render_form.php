<?php
declare(strict_types=1);

/**
 * Form & UI rendering helpers — thin wrapper delegating to App\Render\FormRenderer.
 *
 * @package lib
 */

/**
 * Renders a dynamic field as HTML with ARIA error support.
 *
 * @param array<string, mixed>  $field        Field definition (from form_fields table)
 * @param mixed  $posted_val   Posted value (or null)
 * @param array<string, mixed>  $field_errors Validation errors by field_name
 * @param string $datalist_id  LDAP autocompletion datalist ID
 * @param bool   $disabled     If true, field is disabled (preview mode)
 * @return string HTML of the field
 */
function render_field(array $field, mixed $posted_val, array $field_errors, string $datalist_id = '', bool $disabled = false): string {
    static $renderer = null;
    if ($renderer === null) {
        $renderer = new \App\Render\FormRenderer();
    }
    return $renderer->field($field, $posted_val, $field_errors, $datalist_id, $disabled);
}

/**
 * Generates a reusable search bar HTML.
 *
 * @param string $action_url    Form action URL
 * @param string $current_search Current search term
 * @param string $placeholder   Input placeholder text
 * @param array<string, mixed>  $hidden_fields Additional hidden fields [name => value]
 * @return string HTML of the search form
 */
function render_search_bar(string $action_url, string $current_search, string $placeholder = 'Rechercher...', array $hidden_fields = []): string {
    static $renderer = null;
    if ($renderer === null) {
        $renderer = new \App\Render\FormRenderer();
    }
    return $renderer->searchBar($action_url, $current_search, $placeholder, $hidden_fields);
}

/**
 * Generates status filter links (Tous / En cours / Validés / Refusés).
 *
 * @param string $current_status Active status (tous|en_cours|valide|refuse)
 * @param string $base_url       Base URL to append the status parameter to
 * @param string $param_name     URL parameter name (default: statut)
 * @return string HTML of the filter links
 */
function render_status_filter(string $current_status, string $base_url, string $param_name = 'statut'): string {
    static $renderer = null;
    if ($renderer === null) {
        $renderer = new \App\Render\FormRenderer();
    }
    return $renderer->statusFilter($current_status, $base_url, $param_name);
}

/**
 * Displays submission data as key/value pairs.
 *
 * @param array<string, mixed>  $data    Submission data (decoded from JSON)
 * @param list<string>  $exclude Keys to exclude from display
 * @param string $format  Output format: 'p' (paragraph), 'inline', 'grid' (vc-data grid)
 * @return string HTML of formatted data
 */
function render_submission_data(array $data, array $exclude = ['validations', 'csrf_token'], string $format = 'p'): string {
    static $renderer = null;
    if ($renderer === null) {
        $renderer = new \App\Render\FormRenderer();
    }
    return $renderer->submissionData($data, $exclude, $format);
}

/**
 * Renders the progress indicator for multi-section forms (U-08).
 *
 * @param array<string, mixed> $grouped Grouped sections
 * @return string HTML of the indicator, or '' if single-section
 */
function render_form_progress_indicator(array $grouped): string {
    static $renderer = null;
    if ($renderer === null) {
        $renderer = new \App\Render\FormRenderer();
    }
    return $renderer->formProgressIndicator($grouped);
}
