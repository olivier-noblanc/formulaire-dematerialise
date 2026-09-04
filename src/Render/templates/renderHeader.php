<?php

declare(strict_types=1);

/**
 * @var array{form_label?: string, submitted_by?: string, submitted_at?: string, closed_at?: string|null} $sub
 * @var string                                                                                            $sub_id
 * @var string                                                                                            $nom_agent
 * @var string                                                                                            $status_label
 * @var string                                                                                            $status_cls
 */

$form_label  = \App\Core\App::html()->escape((string) ($sub['form_label'] ?? ''));
$submitted_by = \App\Core\App::html()->escape((string) ($sub['submitted_by'] ?? ''));
$submitted_at = \App\Core\App::html()->escape(\App\Core\App::html()->formatDateTimeFr((string) ($sub['submitted_at'] ?? 'now'), false));
$closed_html = '';
if ((bool) ($sub['closed_at'] ?? '')) {
    $closed_at = \App\Core\App::html()->escape(\App\Core\App::html()->formatDateTimeFr((string) ($sub['closed_at'] ?? '')));
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
