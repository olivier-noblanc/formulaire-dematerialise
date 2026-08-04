<?php foreach ($messages as $msg): ?>
  <?php if ($msg !== ''): ?>
    <div class="msg-info" role="status" aria-live="polite"><?= \App\Core\App::html()->escape($msg) ?></div>
  <?php endif ?>
<?php endforeach ?>
