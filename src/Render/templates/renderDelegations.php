<?php
if ($delegations === []) {
    return '';
}

$items_html = '';
foreach ($delegations as $delegation) {
    $step_label = \App\Core\App::html()->escape((string) ($delegation['step_label'] ?? ''));
    $from       = \App\Core\App::html()->escape((string) ($delegation['from_email'] ?? ''));
    $to         = \App\Core\App::html()->escape((string) ($delegation['to_email'] ?? ''));
    $date       = \App\Core\App::html()->escape(date('d/m/Y à H:i', (int) strtotime((string) ($delegation['delegated_at'] ?? 'now'))));

    $reason_html = '';
    if (!empty($delegation['reason'])) {
        $reason = \App\Core\App::html()->escape((string) $delegation['reason']);
        $reason_html = <<<HTML
                      <div class="val-comment"><span aria-hidden="true">💬</span> Motif : {$reason}</div>
            HTML;
    }

    $items_html .= <<<HTML
            <div class="val-item">
              <div class="val-icon" aria-hidden="true">🔄</div>
              <div class="val-content">
                <div class="val-header">
                  {$step_label} : {$from} → {$to}
                </div>
                <div class="val-detail">{$date}</div>
                {$reason_html}
              </div>
            </div>
        HTML;
}

return <<<HTML
      <!-- Délégations -->
      <div class="card">
        <h2><span aria-hidden="true">🔄</span> Délégations</h2>
        {$items_html}
      </div>
    HTML;
