<?php

declare(strict_types=1);

if (!isset($data[\App\Enum\SubmissionField::VALIDATIONS->value]) || !is_array($data[\App\Enum\SubmissionField::VALIDATIONS->value]) || !((bool) ($data[\App\Enum\SubmissionField::VALIDATIONS->value]))) {
    return '';
}

$items_html = '';
foreach ($data[\App\Enum\SubmissionField::VALIDATIONS->value] as $v) {
    $action = (string) ($v['action'] ?? '');
    $is_valid = $action === \App\Enum\ValidationAction::Valider->value;
    $is_annule = $action === \App\Enum\ValidationAction::Annule->value;
    $icon = $is_valid ? '✅' : ($is_annule ? '⚠️' : '❌');
    $step_label = \App\Core\App::html()->escape((string) ($v['step_label'] ?? ''));
    $email_display = \App\Core\App::html()->displayUser((string) ($v['email'] ?? ''));
    $color_cls = $is_valid ? 'text-valide' : ($is_annule ? 'text-annule' : 'text-refuse');
    $action_label = $is_valid ? 'Validé' : ($is_annule ? 'Annulé' : 'Refusé');
    $date = \App\Core\App::html()->escape((string) ($v['date'] ?? ''));

    $done_by_html = '';
    $done_by = (string) ($v['done_by'] ?? '');
    if ($done_by !== '' && strcasecmp($done_by, (string) ($v['email'] ?? '')) !== 0) {
        $done_by_display = \App\Core\App::html()->displayUser($done_by);
        $done_by_html = <<<HTML
                      <div class="val-done-by"><span aria-hidden="true">👤</span> Action effectuée par : {$done_by_display}</div>
            HTML;
    }

    $comment_html = '';
    if ((bool) ($v['commentaire'] ?? '')) {
        $comment = \App\Core\App::html()->escape((string) ($v['commentaire'] ?? ''));
        $comment_html = <<<HTML
                      <div class="val-comment"><span aria-hidden="true">💬</span> {$comment}</div>
            HTML;
    }

    $items_html .= <<<HTML
            <div class="val-item">
              <div class="val-icon"><span aria-hidden="true">{$icon}</span></div>
              <div class="val-content">
                <div class="val-header">
                  {$step_label} — {$email_display}
                  <span class="{$color_cls}">
                    {$action_label}
                  </span>
                </div>
                <div class="val-detail">{$date}</div>
                {$done_by_html}
                {$comment_html}
              </div>
            </div>
        HTML;
}

return <<<HTML
      <!-- Historique des validations -->
      <div class="card">
        <h2><span aria-hidden="true">📝</span> Historique des validations</h2>
        {$items_html}
      </div>
    HTML;
