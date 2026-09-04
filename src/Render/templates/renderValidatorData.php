<?php

declare(strict_types=1);
/**
 * @var list<array{field_name?: string, field_label?: string, value?: string, filled_by_email?: string, step_label?: string, filled_at?: string}>  $validator_data_rows
 * @var array<string, array{label?: string}>                                                                                                     $field_info
 * @var bool                                                                                                                                     $can_edit
 * @var string                                                                                                                                   $sub_id
 */
if ($validator_data_rows === []) {
    return '';
}

$items_html = '';
foreach ($validator_data_rows as $validator_data_row) {
    $field_name = (string) ($validator_data_row['field_name'] ?? '');
    $label = isset($field_info[$field_name])
        ? t_jargon($field_info[$field_name]['label'])
        : t_jargon((string) ($validator_data_row['field_label'] ?? $field_name));
    $label_h = \App\Core\App::html()->escape($label);
    $value_raw = (string) ($validator_data_row['value'] ?? '');
    $display_val = \App\Core\App::html()->escape($value_raw);

    $by_email  = $validator_data_row['filled_by_email'] ?? '';
    $step_lab  = $validator_data_row['step_label'] ?? '';
    $filled_at = $validator_data_row['filled_at'] ?? '';

    $audit_parts   = ['Rempli'];
    if ($by_email !== '') {
        $audit_parts[] = ' par ' . \App\Core\App::html()->displayUser($by_email);
    }
    if ($step_lab !== '') {
        $audit_parts[] = ' — étape : ' . \App\Core\App::html()->escape(t_jargon($step_lab));
    }
    if ($filled_at !== '') {
        // filled_at est en UTC (gmdate) — interprétation UTC explicite.
        $ts = strtotime($filled_at . ' UTC');
        if ($ts !== false) {
            $audit_parts[] = ' le ' . \App\Core\App::html()->escape(date('d/m/Y à H:i', $ts));
        }
    }
    $audit_line = implode('', $audit_parts);

    if ($can_edit) {
        $csrf         = \App\Core\App::security()->csrfField();
        $sub_id_h     = \App\Core\App::html()->escape($sub_id);
        $fname_h      = \App\Core\App::html()->escape($field_name);
        $value_input  = \App\Core\App::html()->escape($value_raw);
        $value_block = <<<HTML
                      <form method="POST" class="flex-gap5-mt-2">
                        {$csrf}
                        <input type="hidden" name="action" value="update_validator_field">
                        <input type="hidden" name="sub_id" value="{$sub_id_h}">
                        <input type="hidden" name="field_name" value="{$fname_h}">
                        <input type="text" name="value" value="{$value_input}" aria-label="Valeur du champ" class="flex">
                        <button type="submit" class="btn btn-secondary btn-xs-3"><span aria-hidden="true">✏️</span> Modifier</button>
                      </form>
            HTML;
    } else {
        $value_block = <<<HTML
                      <div class="data-value">{$display_val}</div>
            HTML;
    }

    $items_html .= <<<HTML
                <div class="data-item styled-box">
                  <div class="data-label">{$label_h}</div>
                  {$value_block}
                  <div class="hint-muted">{$audit_line}</div>
                </div>
        HTML;
}

$edit_hint = $can_edit
    ? '<p class="hint mb-1-2">Informations saisies par les validateurs au cours du circuit. <strong>Vous pouvez modifier ces champs.</strong></p>'
    : '<p class="hint mb-1-2">Informations saisies par les validateurs au cours du circuit.</p>';

return <<<HTML
      <!-- Données des validateurs (filled_by='validator') — Option A -->
      <div class="card u-bor" id="validator-data">
        <h2><span aria-hidden="true">🛡️</span> Données des validateurs</h2>
        {$edit_hint}
        <div class="data-grid">
          {$items_html}
        </div>
      </div>
    HTML;
