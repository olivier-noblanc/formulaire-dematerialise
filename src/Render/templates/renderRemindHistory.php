<?php

declare(strict_types=1);
/**
 * @var array                                                                                                                             $pending_with_relance
 * @var string                                                                                                                            $status
 * @var bool                                                                                                                              $is_admin
 * @var list<array{id?: string, email?: string, done_at?: string|null, sent_at?: string|null, relance_count?: int, expires_at?: string|null, relance_at?: string|null}>  $all_tokens
 * @var list<array{detail?: string, created_at?: string, actor?: string}>                                                                 $submission_reminds
 */
$pending_html = '';
if ($pending_with_relance !== [] || ($status === \App\Enum\SubmissionStatus::EnCours->value && $all_tokens !== [])) {
    $pending_tokens = array_filter($all_tokens, fn(array $t): bool => !($t['done_at']));

    if ($pending_tokens !== []) {
        $rows = '';
        foreach ($pending_tokens as $pending_token) {
            $email_display = \App\Core\App::html()->displayUser((string) ($pending_token['email'] ?? ''));
            $relance = (int) ($pending_token['relance_count'] ?? 0);

            $sent_html = '';
            if ((bool) ($pending_token['sent_at'])) {
                $sent_date = \App\Core\App::html()->escape(date('d/m/Y à H:i', (int) strtotime((string) $pending_token['sent_at'])));
                $sent_html = "<span class=\"u-c-muted-fs-xs\">Notifié le : {$sent_date}</span>";
            }

            $last_remind = '';
            if ((bool) ($pending_token['relance_at'])) {
                $last_remind_date = \App\Core\App::html()->escape(date('d/m/Y à H:i', (int) strtotime((string) $pending_token['relance_at'])));
                $last_remind = "<span class=\"u-c-warning-fs-xs\">Dernière relance : {$last_remind_date}</span>";
            }

            $expires_html = '';
            if ((bool) ($pending_token['expires_at'])) {
                $expires_date = \App\Core\App::html()->escape(date('d/m/Y', (int) strtotime((string) $pending_token['expires_at'])));
                $expires_html = "<span class=\"u-c-muted-fs-xs\">Expire le : {$expires_date}</span>";
            }

            $relance_badge = '';
            if ($relance > 0) {
                $sfx = $relance > 1 ? 's' : '';
                $relance_badge = "<span class=\"badge badge-warn\">{$relance} relance{$sfx}</span>";
            }

            $rows .= <<<HTML
                      <div class="flex-gap5-2">
                        <span aria-hidden="true" class="u-fon-5">⏳</span>
                        <strong class="u-fon-2">{$email_display}</strong>
                        {$relance_badge}
                        {$sent_html}
                        {$last_remind}
                        {$expires_html}
                      </div>
                HTML;
        }
        $pending_html = <<<HTML
                  <div class="mb-1">
                    {$rows}
                  </div>
            HTML;
    }
}

$detail_html = '';
if ($submission_reminds !== []) {
    $rows = '';
    foreach ($submission_reminds as $submission_remind) {
        $detail = \App\Core\App::html()->escape((string) ($submission_remind['detail'] ?? ''));
        $date   = \App\Core\App::html()->escape(date('d/m/Y à H:i', (int) strtotime((string) ($submission_remind['created_at'] ?? 'now'))));
        $actor  = \App\Core\App::html()->displayUser((string) ($submission_remind['actor'] ?? ''));
        $rows .= <<<HTML
                  <div class="val-item">
                    <div class="val-icon" aria-hidden="true">🔔</div>
                    <div class="val-content">
                      <div class="val-header">{$detail}</div>
                      <div class="val-detail">{$date} — par {$actor}</div>
                    </div>
                  </div>
            HTML;
    }
    $detail_html = <<<HTML
              <h3 class="caption">Détail des notifications envoyées</h3>
              {$rows}
        HTML;
}

$action_html = '';
if ($is_admin && $status === \App\Enum\SubmissionStatus::EnCours->value) {
    $csrf = \App\Core\App::security()->csrfField();
    $action_html = <<<HTML
            <div class="actions-bar">
              <form method="POST">
                {$csrf}
                <input type="hidden" name="action" value="remind_all">
                <button type="submit" class="btn btn-secondary u-fon-2"><span aria-hidden="true">📧</span> Rappeler tous les validateurs en attente</button>
              </form>
            </div>
        HTML;
}

return <<<HTML
      <!-- v10.1.5 — "Historique des relances" → "Notifications envoyées" -->
      <div class="card">
        <h2><span aria-hidden="true">🔔</span> Notifications envoyées</h2>
        {$pending_html}
        {$detail_html}
        {$action_html}
      </div>
    HTML;
