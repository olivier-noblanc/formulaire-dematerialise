<?php

declare(strict_types=1);

namespace App\Render;

use App\Core\App;
use App\Enum\FieldType;

/**
 * Form & UI rendering helpers.
 */
final class FormRenderer
{
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
    public function field(array $field, mixed $posted_val, array $field_errors, string $datalist_id = '', bool $disabled = false): string
    {
        $name          = \App\Core\App::html()->escape($field['field_name']);
        $label         = \App\Core\App::html()->escape(t_jargon($field['label']));
        $req_span      = $field['required'] ? ' <span class="req">*</span>' : '';
        $required_attr = (!$disabled && $field['required']) ? ' required aria-required="true"' : '';
        $error_class   = isset($field_errors[$field['field_name']]) ? ' field-error' : '';
        $disabled_attr = $disabled ? ' disabled' : '';

        $auto_hint_id      = 'hint-' . $name;
        $auto_hint_text    = '';
        $placeholder       = '';
        $textarea_maxlength = 5000;

        $fn_lower    = mb_strtolower($field['field_name'], 'UTF-8');
        $html5_type  = 'text';
        $html5_extra = '';

        if (str_contains($fn_lower, 'email') || str_contains($fn_lower, 'courriel') || str_contains($fn_lower, 'mel')) {
            $html5_type  = 'email';
            $html5_extra = ' pattern="[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$"';
        } elseif (str_contains($fn_lower, 'tel') || str_contains($fn_lower, 'telephone') || str_contains($fn_lower, 'portable') || str_contains($fn_lower, 'mobile')) {
            $html5_type  = 'tel';
            $html5_extra = ' pattern="[0-9+\s\-.]{6,20}"';
        } elseif (str_contains($fn_lower, 'montant') || str_contains($fn_lower, 'cout') || str_contains($fn_lower, 'prix') || str_contains($fn_lower, 'salaire') || str_contains($fn_lower, 'nombre_jour') || str_contains($fn_lower, 'quantite')) {
            $html5_type  = 'number';
            $html5_extra = ' step="0.01" min="0"';
        } elseif (str_contains($fn_lower, 'heure')) {
            $html5_type = 'time';
        } elseif (str_contains($fn_lower, 'url') || str_contains($fn_lower, 'lien') || str_contains($fn_lower, 'site')) {
            $html5_type = 'url';
        }

        $max_size_mo = 0;
        switch ($field['field_type']) {
            case FieldType::Date->value:
                $auto_hint_text = 'Format : jour/mois/année (JJ/MM/AAAA)';
                $placeholder    = 'JJ/MM/AAAA';
                break;
            case FieldType::Email->value:
                $auto_hint_text = 'Exemple : prenom.nom@exemple.invalid';
                $placeholder    = 'prenom.nom@exemple.invalid';
                break;
            case FieldType::Textarea->value:
                $auto_hint_text = 'Texte libre, maximum ' . $textarea_maxlength . ' caractères';
                break;
            case FieldType::File->value:
                $max_size_mo    = round(App::attachment()->getMaxFileSize() / 1048576, 0);
                $auto_hint_text = 'Formats acceptés : PDF, images, Office, ZIP — Max ' . $max_size_mo . ' Mo';
                break;
            case FieldType::Text->value:
                if ($html5_type === 'email') {
                    $auto_hint_text = 'Exemple : prenom.nom@exemple.invalid';
                    $placeholder    = 'prenom.nom@exemple.invalid';
                } elseif ($html5_type === 'tel') {
                    $auto_hint_text = 'Format : 10 chiffres';
                    $placeholder    = '01 23 45 67 89';
                } elseif ($html5_type === 'number') {
                    $auto_hint_text = (str_contains($html5_extra, 'step="0.01"'))
                        ? 'Saisir un montant (décimal autorisé)'
                        : 'Saisir un nombre entier';
                } elseif ($html5_type === 'time') {
                    $auto_hint_text = 'Format : HH:MM (24h)';
                    $placeholder    = '14:30';
                } elseif ($html5_type === 'url') {
                    $auto_hint_text = 'Exemple : https://www.exemple.fr';
                    $placeholder    = 'https://';
                }
                break;
        }

        $user_hint = empty($field['hint']) ? '' : '<span class="hint">' . \App\Core\App::html()->escape(t_jargon($field['hint'])) . '</span>';

        $auto_hint_html = '';
        if ($auto_hint_text !== '') {
            $auto_hint_html = '<span id="' . $auto_hint_id . '" class="field-hint">' . \App\Core\App::html()->escape($auto_hint_text) . '</span>';
        }

        $described_ids = [];
        if (!$disabled && $auto_hint_text !== '') {
            $described_ids[] = $auto_hint_id;
        }
        if (!$disabled && isset($field_errors[$field['field_name']])) {
            $described_ids[] = 'err-' . $name;
        }
        $aria_attr = '';
        if (!$disabled && $described_ids !== []) {
            $aria_attr = ' aria-describedby="' . implode(' ', $described_ids) . '"';
        }
        if (!$disabled && isset($field_errors[$field['field_name']])) {
            $aria_attr .= ' aria-invalid="true"';
        }

        $error_html = '';
        if (!$disabled && isset($field_errors[$field['field_name']])) {
            $error_html = '<span id="err-' . $name . '" class="error-hint">' . \App\Core\App::html()->escape($field_errors[$field['field_name']]) . '</span>';
        }

        $placeholder_attr = $placeholder !== '' ? ' placeholder="' . \App\Core\App::html()->escape($placeholder) . '"' : '';

        switch ($field['field_type']) {
            case FieldType::Email->value:
                $val       = \App\Core\App::html()->escape($posted_val ?? '');
                $maxlength = ' maxlength="500"';
                $list_attr = $datalist_id === '' || $datalist_id === '0' ? '' : ' list="' . \App\Core\App::html()->escape($datalist_id) . '"';
                return <<<HTML
                    <div class="field"><label for="{$name}">{$label}{$req_span}</label><input type="email" id="{$name}" name="{$name}"{$required_attr}{$aria_attr} class="{$error_class}" value="{$val}"{$maxlength}{$placeholder_attr} pattern="[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$"{$list_attr}{$disabled_attr}>{$auto_hint_html}{$user_hint}{$error_html}</div>
                    HTML;

            case FieldType::Date->value:
                $val = \App\Core\App::html()->escape($posted_val ?? '');
                return <<<HTML
                    <div class="field"><label for="{$name}">{$label}{$req_span}</label><input type="date" id="{$name}" name="{$name}"{$required_attr}{$aria_attr} class="{$error_class}" value="{$val}"{$placeholder_attr}{$disabled_attr}>{$auto_hint_html}{$user_hint}{$error_html}</div>
                    HTML;

            case FieldType::Select->value:
                $opts_raw    = $field['options'] ?? '[]';
                $opts        = json_decode($opts_raw, true) ?: [];
                $options_html = '<option value="">— Sélectionner —</option>';
                foreach ($opts as $opt) {
                    $sel = ($posted_val === $opt) ? ' selected' : '';
                    $options_html .= '<option value="' . \App\Core\App::html()->escape($opt) . '"' . $sel . '>' . \App\Core\App::html()->escape($opt) . '</option>';
                }
                return <<<HTML
                    <div class="field"><label for="{$name}">{$label}{$req_span}</label><select id="{$name}" name="{$name}"{$required_attr}{$aria_attr} class="{$error_class}"{$disabled_attr}>{$options_html}</select>{$user_hint}{$error_html}</div>
                    HTML;

            case FieldType::Checkbox->value:
                $checked = empty($posted_val) ? '' : ' checked';
                return <<<HTML
                    <label class="checkbox-item"><input type="checkbox" name="{$name}" value="1"{$checked}{$required_attr}{$disabled_attr}> {$label}{$req_span}</label>
                    HTML;

            case FieldType::Textarea->value:
                $val       = \App\Core\App::html()->escape($posted_val ?? '');
                $maxlength = ' maxlength="' . $textarea_maxlength . '"';
                return <<<HTML
                    <div class="field full"><label for="{$name}">{$label}{$req_span}</label><textarea id="{$name}" name="{$name}"{$required_attr}{$aria_attr} class="{$error_class}"{$placeholder_attr}{$maxlength}{$disabled_attr}>{$val}</textarea>{$auto_hint_html}{$user_hint}{$error_html}</div>
                    HTML;

            case FieldType::File->value:
                $accept = implode(',', array_map(fn($ext) => '.' . $ext, App::attachment()->getAllowedExtensions()));
                return <<<HTML
                    <div class="field"><label for="{$name}">{$label}{$req_span}</label><input type="file" id="{$name}" name="{$name}"{$required_attr}{$aria_attr} class="{$error_class}" accept="{$accept}"{$disabled_attr}>{$auto_hint_html}{$user_hint}{$error_html}</div>
                    HTML;

            default:
                $val       = \App\Core\App::html()->escape($posted_val ?? '');
                $maxlength = ' maxlength="500"';
                return <<<HTML
                    <div class="field"><label for="{$name}">{$label}{$req_span}</label><input type="{$html5_type}" id="{$name}" name="{$name}"{$required_attr}{$aria_attr}{$html5_extra} class="{$error_class}" value="{$val}"{$maxlength}{$placeholder_attr}{$disabled_attr}>{$auto_hint_html}{$user_hint}{$error_html}</div>
                    HTML;
        }
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
    public function searchBar(string $action_url, string $current_search, string $placeholder = 'Rechercher...', array $hidden_fields = []): string
    {
        $html  = '<form method="GET" action="' . \App\Core\App::html()->escape($action_url) . '" class="search-bar" role="search">';
        $html .= '<input type="text" name="search" value="' . \App\Core\App::html()->escape($current_search) . '" placeholder="' . \App\Core\App::html()->escape($placeholder) . '" aria-label="' . \App\Core\App::html()->escape($placeholder) . '" class="search-input">';
        foreach ($hidden_fields as $hname => $hval) {
            $html .= '<input type="hidden" name="' . \App\Core\App::html()->escape($hname) . '" value="' . \App\Core\App::html()->escape($hval) . '">';
        }
        $html .= '<button type="submit" class="btn btn-secondary btn-sm-2">Rechercher</button>';
        if ($current_search !== '') {
            $clear_url = $action_url;
            $sep = (str_contains($clear_url, '?')) ? '&' : '?';
            $parts = [];
            foreach ($hidden_fields as $hname => $hval) {
                $parts[] = \App\Core\App::html()->escape($hname) . '=' . urlencode((string) $hval);
            }
            if ($parts !== []) {
                $clear_url .= (str_contains($clear_url, '?') ? '&' : '?') . implode('&', $parts);
            }
            $html .= ' <a href="' . \App\Core\App::html()->escape($clear_url) . '" class="btn btn-secondary btn-sm-2">&#10005; Effacer</a>';
        }
        return $html . '</form>';
    }

    /**
     * Displays submission data as key/value pairs.
     *
     * @param array<string, mixed>  $data    Submission data (decoded from JSON)
     * @param list<string>  $exclude Keys to exclude from display
     * @param string $format  Output format: 'p' (paragraph), 'inline', 'grid' (vc-data grid)
     * @return string HTML of formatted data
     */
    public function submissionData(array $data, array $exclude = ['validations', 'csrf_token'], string $format = 'p'): string
    {
        $html = '';
        foreach ($data as $k => $v) {
            if (empty($v)) {
                continue;
            }
            if (in_array($k, $exclude, true)) {
                continue;
            }
            $label   = \App\Core\App::html()->escape(ucfirst(str_replace('_', ' ', preg_replace('/^[a-z]+_/', '', $k) ?? $k)));
            $display = $v === '1' ? '<span aria-hidden="true">&#10003;</span>' . ($format === 'grid' ? ' Oui' : '') : \App\Core\App::html()->escape((string) $v);
            if ($format === 'inline') {
                $html .= '<strong>' . $label . ' :</strong> ' . $display . ' &nbsp;';
            } elseif ($format === 'grid') {
                $html .= '<div class="vc-data-item"><div class="vc-data-label">' . $label . '</div><div class="vc-data-value">' . $display . '</div></div>';
            } else {
                $html .= '<p><strong>' . $label . ' :</strong> ' . $display . '</p>';
            }
        }
        return $html;
    }

    /**
     * Renders the progress indicator for multi-section forms (U-08).
     * Returns empty string if the form has only one section.
     *
     * @param array<string, mixed> $grouped Grouped sections (key = section title, value = fields)
     * @return string HTML of the indicator, or '' if single-section
     */
    public function formProgressIndicator(array $grouped): string
    {
        $section_count = count($grouped);
        if ($section_count <= 1) {
            return '';
        }

        $total_fields = 0;
        foreach ($grouped as $fields) {
            foreach ($fields as $field) {
                if (isset($field['field_type']) && $field['field_type'] !== FieldType::File->value) {
                    $total_fields++;
                }
            }
        }
        if ($total_fields === 0) {
            return '';
        }

        $html  = '<div class="form-progress" aria-live="polite">';
        $html .=   '<div class="form-progress-header">';
        $html .=     '<span class="form-progress-label">Étape <strong id="form-progress-current">0</strong> sur ' . $section_count . '</span>';
        $html .=     '<span class="form-progress-count"><span id="form-progress-filled">0</span> / ' . $total_fields . ' champ(s) rempli(s)</span>';
        $html .=   '</div>';
        $html .=   '<div class="form-progress-bar" role="progressbar" '
             . 'aria-valuemin="0" aria-valuemax="' . $total_fields . '" aria-valuenow="0" '
             . 'aria-label="Progression de la saisie du formulaire" id="form-progress-bar">';
        $html .=     '<div class="form-progress-fill w-0" id="form-progress-fill"></div>';
        $html .=   '</div>';
        $html .=   '<input type="hidden" id="form-progress-total-fields" value="' . $total_fields . '">';
        $html .=   '<input type="hidden" id="form-progress-section-count" value="' . $section_count . '">';
        return $html . '</div>';
    }
}
