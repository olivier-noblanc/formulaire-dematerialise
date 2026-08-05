<?php
/**
 * @var int                                 $deadline_ts
 * @var string                              $status
 * @var array{urgency?: string}             $dl_info
 * @var int                                 $days_remaining
 */
if (!$deadline_ts || $status !== \App\Enum\SubmissionStatus::EnCours->value) {
    return '';
}

$urgency = (string) ($dl_info['urgency'] ?? '');
$dl_cls  = $urgency === \App\Enum\UrgencyLevel::Overdue->value ? 'overdue' : ($urgency === \App\Enum\UrgencyLevel::Critical->value ? 'urgent' : 'ok');
$dl_icon = $urgency === \App\Enum\UrgencyLevel::Overdue->value ? '🚨' : ($urgency === \App\Enum\UrgencyLevel::Critical->value ? '⚠️' : '📅');

if ($days_remaining < 0) {
    $dl_text = 'Date dépassée de ' . abs($days_remaining) . ' jour(s)';
} elseif ($days_remaining === 0) {
    $dl_text = "C'est aujourd'hui !";
} else {
    $dl_text = "Plus que {$days_remaining} jour(s)";
}

$dl_date   = \App\Core\App::html()->escape(date('d/m/Y', $deadline_ts));
$dl_text_h = \App\Core\App::html()->escape($dl_text);

return <<<HTML
      <!-- Deadline -->
      <div class="deadline-card {$dl_cls}">
        <div class="dl-icon"><span aria-hidden="true">{$dl_icon}</span></div>
        <div class="dl-text">
          <div class="dl-date">Date cible : {$dl_date}</div>
          <div class="dl-remaining {$dl_cls}">{$dl_text_h}</div>
        </div>
      </div>
    HTML;
