<?php if (is_array($d) && isset($d[\App\Enum\SubmissionField::VALIDATIONS->value]) && is_array($d[\App\Enum\SubmissionField::VALIDATIONS->value])): ?>
      <h3 class="u-mt-0-mb-1">Historique des validations</h3>
  <?php foreach ($d[\App\Enum\SubmissionField::VALIDATIONS->value] as $validation):
    $step_label = \App\Core\App::html()->escape((string) ($validation['step_label'] ?? ''));
    $email      = \App\Core\App::html()->escape((string) ($validation['email'] ?? ''));
    $action     = (string) ($validation['action'] ?? '');
    $is_valide  = ($action === \App\Enum\ValidationAction::Valider->value);
    $color_class = $is_valide ? 'text-success' : 'text-danger';
    $icon       = $is_valide ? '✅' : '❌';
    $label      = $is_valide ? 'Validé' : 'Refusé';
    $comment    = '';
    if ((bool)($validation['commentaire'])) {
      $c = \App\Core\App::html()->escape((string) $validation['commentaire']);
      $comment = "<br><em>Commentaire :</em> {$c}";
    }
    $val_date_ts = strtotime((string) ($validation['date'] ?? ''));
    $date = $val_date_ts !== false ? \App\Core\App::html()->escape(date('d/m/Y à H:i', $val_date_ts)) : '—';
  ?>
      <div class="validation-item">
        <strong><?= $step_label ?></strong> - <?= $email ?> -
        <span class="<?= $color_class ?>">
          <span aria-hidden="true"><?= $icon ?></span> <?= $label ?>
        </span>
        <?= $comment ?>
        <br><small><?= $date ?></small>
      </div>
  <?php endforeach ?>
      <hr class="u-m-1rem-0">
<?php endif ?>

      <?= $form_data_html ?>

<?php if ($status === \App\Enum\SubmissionStatus::EnCours->value): ?>
      <hr class="u-m-1rem-0">
      <div class="u-d-flex-gap-05-fw-wrap">
<?php if (\App\Core\App::auth()->isAdminEffective()): ?>
  <?php foreach ($tokens as $token):
    if ((bool)($token['done_at'])) { continue; }
    $tid   = \App\Core\App::html()->escape((string) ($token['id'] ?? ''));
    $temail = \App\Core\App::html()->escape((string) ($token['email'] ?? ''));
  ?>
        <form method="POST" class="u-d-inline">
          <?= \App\Core\App::security()->csrfField() ?>
          <input type="hidden" name="action" value="remind_one">
          <input type="hidden" name="token_id" value="<?= $tid ?>">
          <button type="submit" class="btn btn-secondary u-fs-xxs-p-xs2"><span aria-hidden="true">📧</span> Rappeler <?= $temail ?></button>
        </form>
        <form method="POST" class="u-d-inline">
          <?= \App\Core\App::security()->csrfField() ?>
          <input type="hidden" name="action" value="regenerate_token">
          <input type="hidden" name="token_id" value="<?= $tid ?>">
          <button type="submit" class="btn btn-secondary u-fs-xxs-p-xs2"><span aria-hidden="true">🔄</span> Régénérer <?= $temail ?></button>
        </form>
  <?php endforeach ?>
<?php endif ?>
        <a href="<?= $cancel_url ?>" class="btn btn-danger u-fs-xxs-p-xs-td-none"><span aria-hidden="true">🗑</span> Annuler</a>
      </div>
<?php endif ?>
