<?php

declare(strict_types=1);
/**
 * @var string                                                                                                  $status
 * @var bool                                                                                                    $is_admin
 * @var string                                                                                                  $user
 * @var list<array{id?: string, ordre?: int, email?: string, done_at?: string|null}>                            $all_tokens
 */
if ($status !== \App\Enum\SubmissionStatus::EnCours->value) {
    return '';
}

$my_pending = array_filter($all_tokens, fn(array $tok): bool => !($tok['done_at']) && ($is_admin || $tok['email'] === $user));

if ($my_pending === []) {
    return '';
}

$options_html = '';
foreach ($my_pending as $mpt) {
    $id    = \App\Core\App::html()->escape((string) ($mpt['id'] ?? ''));
    $ordre = (int) ($mpt['ordre'] ?? 0);
    $email = \App\Core\App::html()->displayUser((string) ($mpt['email'] ?? ''));
    $options_html .= "<option value=\"{$id}\">Étape {$ordre} — {$email}</option>";
}

$csrf = \App\Core\App::security()->csrfField();

return <<<HTML
        <div class="actions-bar mt-0">
          <strong class="u-col-fon-16"><span aria-hidden="true">🔄</span> Déléguer ma validation :</strong>
          <form method="POST" class="u-ali-dis-fle-gap">
            {$csrf}
            <input type="hidden" name="action" value="delegate_token">
            <select name="token_id" class="input-filter-4">
              {$options_html}
            </select>
            <input type="email" name="delegate_to" placeholder="email@exemple.invalid" required class="input-filter-3">
            <input type="text" name="delegate_reason" placeholder="Motif (optionnel)" class="input-filter-2">
            <button type="submit" class="btn btn-secondary u-bac-col-fon-pad"><span aria-hidden="true">🔄</span> Déléguer</button>
          </form>
        </div>
    HTML;
