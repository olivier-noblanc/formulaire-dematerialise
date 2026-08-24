<?php

declare(strict_types=1);
/**
 * @var bool   $is_admin
 * @var string $action_msg
 */
$back_link = $is_admin
    ? '<a href="index.php?p=dashboard" class="back-link">← Retour au tableau de bord</a>'
    : '<a href="index.php?p=my_submissions" class="back-link">← Retour à mes demandes</a>';

$msg_html = '';
if ($action_msg !== '') {
    $msg_escaped = \App\Core\App::html()->escape($action_msg);
    $msg_html = <<<HTML
          <div class="msg-info" role="status" aria-live="polite">{$msg_escaped}</div>
        HTML;
}

return $back_link . "\n" . $msg_html;
