<?php

declare(strict_types=1);

$steps_html = '';
foreach ($workflow_steps as $i => $ws) {
    $step_cls = (string) ($ws['step_status'] ?? 'upcoming');
    if ($status === \App\Enum\SubmissionStatus::Refuse->value && ($ws['step_status'] ?? '') === 'current') {
        $step_cls = 'refused';
    }

    $connector = $i > 0 ? '<div class="wf-connector"><span class="arrow">→</span></div>' : '';

    $ordre      = (int) ($ws['ordre'] ?? 0);
    $step_label = \App\Core\App::html()->escape((string) ($ws['step_label'] ?? ''));
    $tokens     = $ws['tokens'] ?? [];

    $validators_html = '';
    if ($tokens !== [] && $tokens !== null) {
        foreach ($tokens as $token) {
            $html         = \App\Core\App::html();
            $email        = $html->displayUser((string) ($token['email'] ?? ''));
            $relance      = (int) ($token['relance_count'] ?? 0);
            $done         = (bool) ($token['done_at']);
            $is_current   = ($ws['step_status'] ?? '') === 'current';

            // Texte du title natif : source unique HtmlService::tokenTimelineLines()
            // (mêmes lignes que les tooltips CSS de « Mes demandes »). Échappé,
            // et omis quand il n'y a rien à dire.
            $tooltip_lines = $html->tokenTimelineLines($token);
            $title_attr    = $tooltip_lines === []
                ? ''
                : ' title="' . $html->escape(implode(' — ', $tooltip_lines)) . '"';

            if ($done) {
                $icon = '<span class="wf-check" aria-hidden="true"' . $title_attr . '>✓</span>';
            } elseif ($is_current) {
                $icon = '<span class="wf-pending" aria-hidden="true"' . $title_attr . '>⏳</span>';
            } else {
                $icon = '<span class="wf-waiting" aria-hidden="true">○</span>';
            }

            $relance_html = '';
            if ($relance > 0 && !$done) {
                $sfx   = $relance > 1 ? 's' : '';
                $relance_html = "<span class=\"u-c-warning-fs-xxxs-ml-025\">({$relance} rappel{$sfx})</span>";
            }

            $validators_html .= <<<HTML
                              <div class="wf-validator-item">
                                {$icon}
                                <span>{$email}</span>
                                {$relance_html}
                              </div>
                HTML;
        }
    } else {
        $validators_html = '<span class="wf-waiting">En attente de démarrage</span>';
    }

    $steps_html .= <<<HTML
                  {$connector}
                  <div class="wf-step {$step_cls}">
                    <div class="wf-ordre">Étape {$ordre}</div>
                    <div class="wf-label">{$step_label}</div>
                    <div class="wf-validators">
                      {$validators_html}
                    </div>
                  </div>
        HTML;
}

return <<<HTML
      <!-- Workflow diagram -->
      <div class="card">
        <h2><span aria-hidden="true">🔀</span> Circuit de validation</h2>
        <div class="workflow-diagram">
          <div class="wf-flow">
            {$steps_html}
          </div>
        </div>
    HTML;
