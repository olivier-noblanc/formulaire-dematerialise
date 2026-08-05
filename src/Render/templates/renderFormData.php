<?php
/**
 * @var array<string, mixed>                                       $data
 * @var array<string, array{card_group?: string, label?: string}>  $field_info
 */
$items_html = '';
$current_group = '';
foreach ($data as $k => $v) {
    if ($k === 'validations') {
        continue;
    }
    if ($v === '') {
        continue;
    }
    if ($v === null) {
        continue;
    }
    if ($v === '0' && $v !== '0') {
        continue;
    }

    $group = isset($field_info[$k]) ? $field_info[$k]['card_group'] : '';
    $label = isset($field_info[$k])
        ? $field_info[$k]['label']
        : ucfirst(is_string($k) ? str_replace('_', ' ', preg_replace('/^[a-z]+_/', '', $k) ?? $k) : '');
    $display_val = $v === '1' ? '✓ Oui' : ($v === '0' ? 'Non' : \App\Core\App::html()->escape((string) $v));

    if ($group !== $current_group && !$group === '' || $group === null || $group === '0') {
        $current_group = $group;
        $group_h = \App\Core\App::html()->escape($group);
        $items_html .= <<<HTML
                    <div class="data-group-title">{$group_h}</div>
            HTML;
    }

    $label_h = \App\Core\App::html()->escape((string) $label);
    $items_html .= <<<HTML
                <div class="data-item">
                  <div class="data-label">{$label_h}</div>
                  <div class="data-value">{$display_val}</div>
                </div>
        HTML;
}

return <<<HTML
      <!-- Données du formulaire -->
      <div class="card">
        <h2><span aria-hidden="true">📋</span> Données du formulaire</h2>
        <div class="data-grid">
          {$items_html}
        </div>
      </div>
    HTML;
