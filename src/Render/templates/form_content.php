<?php // S4-UI / Action 1 : anti-jargon sur le titre + description du formulaire.?>
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
    <?= \App\Core\App::security()->csrfField() ?>
    <?php if ($existing_submission !== null): ?><input type="hidden" name="confirmed" value="1"><?php endif; ?>
  <?php // ITER1-B / Action B : encadré « Aide » en haut du formulaire.?>
  <aside class="form-help-box" aria-label="Aide pour remplir le formulaire">
    <span class="form-help-icon" aria-hidden="true">💡</span>
    <span class="form-help-text">
      <?php // U-08 : indicateur de progression (uniquement si >1 section)?>
      <?= \App\Core\App::html()->h(\App\Core\App::html()->tJargon(
          'Tous les champs marqués d\'un astérisque (*) sont obligatoires.'
      )) ?>
      <?= new \App\Render\FormRenderer()->formProgressIndicator($grouped) ?>
      <?php foreach ($grouped as $card_title => $card_fields): ?>
        <?php
        // Séparer les checkboxes des autres champs pour le rendu
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
                <?php $cond = (!isset($non_checkbox['condition']) || $non_checkbox['condition'] === '') ? '' : ' data-condition="' . htmlspecialchars((string) $non_checkbox['condition'], ENT_QUOTES) . '"'; ?>
                <div<?php if ($cond !== '' && $cond !== '0') {
                    echo $cond;
                } ?>>
                <?= new \App\Render\FormRenderer()->field($non_checkbox, $field_values[(string) $non_checkbox['field_name']] ?? null, $field_errors + $file_errors, $ldap_datalist_id) ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if ($checkboxes !== []): ?>
            <div class="checkboxes"<?php if ($non_checkboxes !== []) {
                echo ' class="u-mt-1"';
            } ?>>
              <?php foreach ($checkboxes as $checkbox): ?>
                <?php $cond = (!isset($checkbox['condition']) || $checkbox['condition'] === '') ? '' : ' data-condition="' . htmlspecialchars((string) $checkbox['condition'], ENT_QUOTES) . '"'; ?>
                <div<?php if ($cond !== '' && $cond !== '0') {
                    echo $cond;
                } ?>>
                <?= new \App\Render\FormRenderer()->field($checkbox, $field_values[(string) $checkbox['field_name']] ?? null, $field_errors + $file_errors, $ldap_datalist_id) ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </fieldset>
      <?php endforeach; ?>
    </span></aside>

    <?= $ldap_datalist_html ?>

    <?php if ($grouped !== []): ?>
      <div class="card u-bg-lavender-border-primary">
        <label class="checkbox-item u-fs-sm-lh-15">
          <input type="checkbox" name="rgpd_consent" value="1" required aria-required="true"<?= ($_POST['rgpd_consent'] ?? '') === '' ? '' : ' checked' ?>>
          J'accepte le traitement de mes données personnelles dans le cadre de cette procédure.
        </label>
        <?php // Message d'erreur si le consentement RGPD a été oublié lors d'une soumission précédente?>
        <?php if (isset($field_errors['rgpd_consent']) && $field_errors['rgpd_consent'] !== ''): ?>
          <p class="error-hint u-c-danger-fs-xs-mt-05-ml-17" role="alert">
            <?= $h($field_errors['rgpd_consent']) ?>
          </p>
        <?php endif; ?>
        <p class="u-c-muted-fs-xxs-mt-05-ml-17">
          <?php // S4-UI / Action 1 : la mention légale contient « dématérialisation » → on traduit.?>
          <?= $h($tJargon(\App\Core\App::settings()->get('legal_mentions', 'Les données collectées sont traitées dans le cadre de la dématérialisation des procédures internes de la DREETS. Conformément au RGPD, vous disposez d\'un droit d\'accès, de rectification et d\'effacement de vos données. Durée de conservation : 24 mois après clôture.'))) ?>
        </p>
      </div>
      <div class="form-actions u-mt-15-gap-1-jc-center-fw-wrap">
        <button type="submit" class="btn-submit">✓ Envoyer ma demande</button>
      </div>
    <?php endif; ?>
  </form>
  <script src="<?= \App\Core\App::html()->assetUrl(\App\Enum\AssetType::Js, 'form-progress') ?>" nonce="<?= \App\Core\App::security()->getScriptNonce() ?>"></script>
  <script src="<?= \App\Core\App::html()->assetUrl(\App\Enum\AssetType::Js, 'form-conditions') ?>" nonce="<?= \App\Core\App::security()->getScriptNonce() ?>"></script>
<?php endif; ?>
