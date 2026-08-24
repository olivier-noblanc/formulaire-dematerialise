<?php

declare(strict_types=1);

namespace App\Render;

use App\Core\App;
use App\Enum\FieldType;
use App\Enum\SubmissionField;
use App\Repository\FormFieldsTrait;

/**
 * Form & UI rendering helpers.
 *
 * @phpstan-import-type FormFieldRow from FormFieldsTrait
 */
final class FormRenderer
{
    /**
     * Renders a dynamic field as HTML with ARIA error support.
     *
     * @param array{field_name: string, label: string, required: int, field_type: string, hint: string, options: string|null} $field Field definition
     * @param mixed  $posted_val   Posted value (or null)
     * @param array<string, string>  $field_errors Validation errors by field_name
     * @param string $datalist_id  LDAP autocompletion datalist ID
     * @param bool   $disabled     If true, field is disabled (preview mode)
     * @return string HTML of the field
     */
    public function field(array $field, mixed $posted_val, array $field_errors, string $datalist_id = '', bool $disabled = false): string
    {
        $name          = \App\Core\App::html()->escape($field['field_name']);
        $label         = \App\Core\App::html()->escape(t_jargon($field['label']));
        $req_span      = $field['required'] === 1 ? ' <span class="req">*</span>' : '';
        $required_attr = (!$disabled && $field['required'] === 1) ? ' required aria-required="true"' : '';
        $error_class   = isset($field_errors[(string) $field['field_name']]) ? ' field-error' : '';
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

        $user_hint = (bool) ($field['hint']) ? '<span class="hint">' . \App\Core\App::html()->escape(t_jargon($field['hint'])) . '</span>' : '';

        $auto_hint_html = '';
        if ($auto_hint_text !== '') {
            $auto_hint_html = '<span id="' . $auto_hint_id . '" class="field-hint">' . \App\Core\App::html()->escape($auto_hint_text) . '</span>';
        }

        $described_ids = [];
        if (!$disabled && $auto_hint_text !== '') {
            $described_ids[] = $auto_hint_id;
        }
        if (!$disabled && isset($field_errors[(string) $field['field_name']])) {
            $described_ids[] = 'err-' . $name;
        }
        $aria_attr = '';
        if (!$disabled && $described_ids !== []) {
            $aria_attr = ' aria-describedby="' . implode(' ', $described_ids) . '"';
        }
        if (!$disabled && isset($field_errors[(string) $field['field_name']])) {
            $aria_attr .= ' aria-invalid="true"';
        }

        $error_html = '';
        if (!$disabled && isset($field_errors[(string) $field['field_name']])) {
            $error_html = '<span id="err-' . $name . '" class="error-hint">' . \App\Core\App::html()->escape($field_errors[(string) $field['field_name']]) . '</span>';
        }

        $placeholder_attr = $placeholder !== '' ? ' placeholder="' . \App\Core\App::html()->escape($placeholder) . '"' : '';

        $template = 'form_field_' . $field['field_type'] . '.php';
        if (!file_exists(__DIR__ . '/templates/' . $template)) {
            $template = 'form_field_default.php';
        }
        return $this->loadTemplate($template, [
            'name'            => $name,
            'label'           => $label,
            'req_span'        => $req_span,
            'required_attr'   => $required_attr,
            'aria_attr'       => $aria_attr,
            'error_class'     => $error_class,
            'disabled_attr'   => $disabled_attr,
            'auto_hint_html'  => $auto_hint_html,
            'user_hint'       => $user_hint,
            'error_html'      => $error_html,
            'placeholder_attr' => $placeholder_attr,
            'html5_type'      => $html5_type,
            'html5_extra'     => $html5_extra,
            'val'             => \App\Core\App::html()->escape($posted_val ?? ''),
            'maxlength'       => ' maxlength="500"',
            'list_attr'       => $datalist_id === '' || $datalist_id === '0' ? '' : ' list="' . \App\Core\App::html()->escape($datalist_id) . '"',
            'checked'         => in_array($posted_val, ['', null, '0'], true) ? '' : ' checked',
            'options_html'    => (function () use ($field, $posted_val): string {
                $opts_raw = $field['options'] ?? '[]';
                $opts     = json_decode($opts_raw, true) ?? [];
                $html     = '<option value="">— Sélectionner —</option>';
                foreach ($opts as $opt) {
                    $sel = ($posted_val === $opt) ? ' selected' : '';
                    $html .= '<option value="' . \App\Core\App::html()->escape($opt) . '"' . $sel . '>' . \App\Core\App::html()->escape($opt) . '</option>';
                }
                return $html;
            })(),
            'accept'          => implode(',', array_map(fn(string $ext): string => '.' . $ext, App::attachment()->getAllowedExtensions())),
            'textarea_maxlength' => $textarea_maxlength,
        ]);
    }

    /**
     * Generates a reusable search bar HTML.
     *
     * @param string $action_url    Form action URL
     * @param string $current_search Current search term
     * @param string $placeholder   Input placeholder text
     * @param array<string, string>  $hidden_fields Additional hidden fields [name => value]
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
    public function submissionData(array $data, array $exclude = [SubmissionField::VALIDATIONS->value, 'csrf_token'], string $format = 'p'): string
    {
        $html = '';
        foreach ($data as $k => $v) {
            if (in_array($v, ['', null, '0'], true)) {
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
     * @param array<string, FormFieldRow[]> $grouped Grouped sections (key = section title, value = fields)
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

        return $this->loadTemplate('form_progress_indicator.php', [
            'section_count' => $section_count,
            'total_fields'  => $total_fields,
        ]);
    }

    /**
     * Rendu HTML de l'écran de confirmation affiché quand l'agent a déjà une
     * soumission en cours sur ce formulaire (v34 — remplace le blocage en base).
     *
     * @param array{id: string, slug: string, label: string, description: string|null, actif: int, created_at: string, deadline_field: string} $form
     * @param array{submitted_at: string|null, id: string}       $existing_submission
     */
    public function confirmDuplicate(array $form, array $existing_submission, string $slug): string
    {
        $h       = \App\Core\App::html()->h(...);
        $tJargon = \App\Core\App::html()->tJargon(...);

        return $this->loadTemplate('form_confirm_duplicate.php', [
            'h'          => $h,
            'tJargon'    => $tJargon,
            'form'       => $form,
            'date'       => $h(date('d/m/Y à H:i', (int) strtotime((string) ($existing_submission['submitted_at'] ?? '')))),
            'existingId' => urlencode((string) ($existing_submission['id'] ?? '')),
            'confirmUrl' => 'index.php?p=form&f=' . urlencode($slug) . '&confirmed=1',
        ]);
    }

    /**
     * Rendu HTML du formulaire (titre, champs, consentement RGPD,
     * bouton submit, script de progression). Reproduit à l'identique la
     * structure HTML historique de form.php (output buffering + inline PHP).
     *
     * @param array{label: string, description: string|null}  $form
     * @param array{submitted_at: string|null, id: string}|null $existing_submission
     * @param array<string, FormFieldRow[]>            $grouped  Clé=nom du groupe, valeur=liste des champs
     * @param array<string, string>                           $field_errors
     * @param array<string, string>                            $file_errors  Erreurs spécifiques aux uploads
     * @param array<string, string>                           $field_values
     */
    public function formContent(
        array $form,
        string $submitted_by,
        $existing_submission,
        bool $success,
        string $submission_id,
        array $grouped,
        array $field_errors,
        array $file_errors,
        array $field_values,
        string $ldap_datalist_id,
        string $ldap_datalist_html,
        string $slug
    ): string {
        $h       = \App\Core\App::html()->h(...);
        $tJargon = \App\Core\App::html()->tJargon(...);

        return $this->loadTemplate('form_content.php', [
            'renderer'            => $this,
            'h'                   => $h,
            'tJargon'             => $tJargon,
            'form'                => $form,
            'submitted_by'        => $submitted_by,
            'existing_submission' => $existing_submission,
            'success'             => $success,
            'submission_id'       => $submission_id,
            'grouped'             => $grouped,
            'field_errors'        => $field_errors,
            'file_errors'         => $file_errors,
            'field_values'        => $field_values,
            'ldap_datalist_id'    => $ldap_datalist_id,
            'ldap_datalist_html'  => $ldap_datalist_html,
            'slug'                => $slug,
        ]);
    }

    /**
     * Charge un template PHP depuis le dossier templates/ avec des variables extraites.
     *
     * @param array<string, mixed> $vars
     */
    private function loadTemplate(string $filename, array $vars = []): string
    {
        $filepath = __DIR__ . '/templates/' . $filename;
        if (!file_exists($filepath)) {
            throw new \RuntimeException("Template not found: {$filepath}");
        }

        extract($vars);
        unset($vars);

        ob_start();
        include $filepath;
        $html = ob_get_clean();
        return $html === false ? '' : $html;
    }
}
