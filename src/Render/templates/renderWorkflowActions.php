<?php

declare(strict_types=1);

/**
 * @var bool                                                                                                    $is_admin
 * @var string                                                                                                  $status
 * @var list<array{id?: string, email?: string, done_at?: string|null}>                                         $all_tokens
 */

if (!$is_admin || $status !== \App\Enum\SubmissionStatus::EnCours->value) {
    return '';
}

$forms_html = '';
foreach ($all_tokens as $all_token) {
    if ((bool) ($all_token['done_at'])) {
        continue;
    }
    $tok_id  = \App\Core\App::html()->escape((string) ($all_token['id'] ?? ''));
    $email   = \App\Core\App::html()->displayUser((string) ($all_token['email'] ?? ''));
    $csrf    = \App\Core\App::security()->csrfField();

    $forms_html .= <<<HTML
                  <form method="POST" class="u-dis-2">
                    {$csrf}
                    <input type="hidden" name="action" value="remind_one">
                    <input type="hidden" name="token_id" value="{$tok_id}">
                    <button type="submit" class="btn btn-secondary btn-xs-4"><span aria-hidden="true">📧</span> Rappeler {$email}</button>
                  </form>
                  <form method="POST" class="u-dis-2">
                    {$csrf}
                    <input type="hidden" name="action" value="regenerate_token">
                    <input type="hidden" name="token_id" value="{$tok_id}">
                    <button type="submit" class="btn btn-secondary btn-xs-4"><span aria-hidden="true">🔄</span> Régénérer {$email}</button>
                  </form>
        HTML;
}

if ($forms_html === '') {
    return '';
}

return <<<HTML
        <div class="actions-bar">
          {$forms_html}
        </div>
    HTML;
