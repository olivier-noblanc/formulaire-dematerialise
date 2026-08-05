<h1><?= $h($tJargon($form['label'])) ?></h1>
<?php if ($form['description']): ?><p class="agent-info"><?= $h($tJargon($form['description'])) ?></p><?php endif; ?>
<p class="agent-info">Formulaire rempli par : <strong><?= $h($submitted_by) ?></strong></p>

<?php if ($existing_submission && !$success): ?>
  <div class="warn-box">
    <p><strong><span aria-hidden="true">⚠</span> Attention :</strong> Vous avez déjà une demande en cours pour ce formulaire (soumise le <?= $h(date('d/m/Y à H:i', (int) strtotime((string) ($existing_submission['submitted_at'] ?? '')))) ?>).</p>
    <p>Vous pouvez tout de même soumettre une nouvelle demande si nécessaire.</p>
    <p><a href="index.php?p=submission_view&id=<?= urlencode((string) ($existing_submission['id'] ?? '')) ?>" class="u-c-warning-fw-bold">Voir la demande existante →</a></p>
  </div>
<?php endif; ?>

<?php if ($success): ?>
  <div class="success">
    <strong><span aria-hidden="true">✓</span> Demande enregistrée</strong>
    <?= $h($tJargon('Le workflow de validation a été déclenché automatiquement.')) ?> Un email de confirmation vous a été envoyé.
  </div>
  <div class="u-mt-15-flex-center">
    <a href="index.php?p=submission_view&id=<?= urlencode((string) $submission_id) ?>" class="btn btn-primary">Voir ma demande</a>
    <a href="index.php?p=my_submissions" class="btn btn-secondary">Mes demandes</a>
    <a href="index.php" class="btn btn-secondary">Accueil</a>
  </div>
<?php else: ?>
  <form method="POST" action="index.php?p=form&f=<?= urlencode((string) $slug) ?>" enctype="multipart/form-data" id="form-main">
    <?= $csrf_field ?>
    <?php if ($existing_submission !== null): ?><input type="hidden" name="confirmed" value="1"><?php endif; ?>
  <aside class="form-help-box" aria-label="Aide pour remplir le formulaire">
    <span class="form-help-icon" aria-hidden="true">💡</span>
    <span class="form-help-text">
      <?= $progress_html ?>
      <?php foreach ($grouped as $card_title => $card_fields): ?>
        <?php
        $checkboxes = [];
        $non_checkboxes = [];
        foreach ($card_fields as $card_field) {
            if ($card_field['field_type'] === \App\Enum\FieldType::Checkbox->value) {
                $checkboxes[] = $card_field;
            } else {
                $non_checkboxes[] = $card_field;
            }
        }
        ?>
        <fieldset class="card">
          <legend><?= $h($card_title) ?></legend>
          <?php if ($non_checkboxes !== []): ?>
            <div class="grid-2">
              <?php foreach ($non_checkboxes as $non_checkbox): ?>
                <?php $cond = $non_checkbox['condition'] ? ' data-condition="' . htmlspecialchars((string) $non_checkbox['condition'], ENT_QUOTES) . '"' : ''; ?>
                <div<?php if ($cond !== '' && $cond !== '0') { echo $cond; } ?>>
                <?= $renderer->field($non_checkbox, $field_values[(string) $non_checkbox['field_name']] ?? null, $field_errors + $file_errors, '') ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if ($checkboxes !== []): ?>
            <div class="checkboxes"<?php if ($non_checkboxes !== []) { echo ' class="u-mt-1"'; } ?>>
              <?php foreach ($checkboxes as $checkbox): ?>
                <?php $cond = $checkbox['condition'] ? ' data-condition="' . htmlspecialchars((string) $checkbox['condition'], ENT_QUOTES) . '"' : ''; ?>
                <div<?php if ($cond !== '' && $cond !== '0') { echo $cond; } ?>>
                <?= $renderer->field($checkbox, $field_values[(string) $checkbox['field_name']] ?? null, $field_errors + $file_errors, '') ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </fieldset>
      <?php endforeach; ?>
    </span></aside>

    <?php if ($grouped !== []): ?>
      <div class="card u-bg-lavender-border-primary">
        <label class="checkbox-item u-fs-sm-lh-15">
          <input type="checkbox" name="rgpd_consent" value="1" required aria-required="true"<?= (bool)($_POST['rgpd_consent']) ? ' checked' : '' ?>>
          J'accepte le traitement de mes données personnelles dans le cadre de cette procédure.
        </label>
        <?php if ((bool)($field_errors['rgpd_consent'])): ?>
          <p class="error-hint u-c-danger-fs-xs-mt-05-ml-17" role="alert">
            <?= $h($field_errors['rgpd_consent']) ?>
          </p>
        <?php endif; ?>
        <p class="u-c-muted-fs-xxs-mt-05-ml-17">
          <?= $h($tJargon($legal_mentions)) ?>
        </p>
      </div>
      <div class="form-actions u-mt-15-gap-1-jc-center-fw-wrap">
        <button type="submit" class="btn-submit">✓ Envoyer ma demande</button>
      </div>
    <?php endif; ?>
  </form>
  <script src="assets.php?type=js&file=form-progress" nonce="<?= \App\Core\App::security()->getScriptNonce() ?>"></script>
  <script src="assets.php?type=js&file=form-conditions" nonce="<?= \App\Core\App::security()->getScriptNonce() ?>"></script>
<?php endif; ?>
