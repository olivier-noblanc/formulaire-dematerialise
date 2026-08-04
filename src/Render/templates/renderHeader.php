<?php
$form_label  = \App\Core\App::html()->escape((string) ($sub['form_label'] ?? ''));
$submitted_by = \App\Core\App::html()->escape((string) ($sub['submitted_by'] ?? ''));
$submitted_at = \App\Core\App::html()->escape(date('d/m/Y à H:i', (int) strtotime((string) ($sub['submitted_at'] ?? 'now'))));
$closed_html = '';
if (!empty($sub['closed_at'])) {
    $closed_at = \App\Core\App::html()->escape(date('d/m/Y à H:i', (int) strtotime((string) $sub['closed_at'])));
    $closed_html = "<br>Clôturé le : <strong>{$closed_at}</strong>";
}
$agent_display = $nom_agent !== '' ? $nom_agent : $submitted_by;

return <<<HTML
      <!-- Header -->
      <div class="sub-header">
        <div>
          <div class="sub-title">Soumission #{$sub_id} — {$form_label}</div>
          <div class="sub-meta">
            Agent : <strong>{$agent_display}</strong><br>
            Soumis le : <strong>{$submitted_at}</strong>
            {$closed_html}
          </div>
        </div>
        <span class="badge {$status_cls} btn-compact">{$status_label}</span>
      </div>
    HTML;
