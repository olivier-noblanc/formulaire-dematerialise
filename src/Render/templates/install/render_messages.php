<?php
/** @var array<int, string> $messages */
/** @var array<int, string> $error_messages */
?>
<?php foreach ($messages as $msg): ?>
        <div class="msg-success" role="status" aria-live="polite"><?= inst_h($msg) ?></div>
<?php endforeach; ?>

<?php foreach ($error_messages as $error_message): ?>
        <div class="msg-error" role="alert" aria-live="assertive"><?= inst_h($error_message) ?></div>
<?php endforeach; ?>
