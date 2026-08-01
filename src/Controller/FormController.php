<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;
use App\Enum\FieldType;
use App\Enum\FilledBy;

/**
 * Contrôleur du formulaire de demande (form.php?f=<slug>).
 *
 * Affiche un formulaire dynamique (champs issus de la table form_fields,
 * filtrés par filled_by='demandeur'), gère la soumission POST (validation,
 * persistance, upload de fichiers, déclenchement du workflow), envoie un
 * email de confirmation à l'agent, et renvoie du JSON en mode test.
 */
final class FormController extends BaseController
{
    /**
     * Point d'entrée du contrôleur — reproduit à l'identique la logique
     * historique de form.php (validation slug, fetch formulaire, POST
     * handler, rendu HTML ou JSON test).
     */
    public function handle(): void
    {
        $this->db->getPdo();
        $slug = trim($_GET['f'] ?? '');

        // Sécurité (A-01) : valider le slug du formulaire
        if ($slug !== '' && $slug !== '0') {
            try {
                $slug = validate_input($slug, 'slug', ['max_length' => 100]);
            } catch (\InvalidArgumentException) {
                new \App\Render\ErrorRenderer()->errorPage(
                    400,
                    'Paramètre invalide',
                    'Le paramètre de formulaire fourni est invalide.',
                    'Vérifiez l\'adresse dans votre navigateur.'
                );
            }
        }

        $form = $this->formRepo->findActiveBySlug((string) $slug);

        if (!$form) {
            /** @phpstan-ignore-next-line if.alwaysTrue */
            if (TEST_MODE) {
                test_json_response(['error' => 'Formulaire introuvable', 'slug' => $slug]);
            }
            new \App\Render\ErrorRenderer()->errorPage(
                404,
                'Formulaire introuvable',
                'Le formulaire demandé n\'existe pas ou a été désactivé.',
                'Vérifiez l\'adresse dans votre navigateur. Vous pouvez retourner à l\'accueil pour voir les formulaires disponibles.'
            );
            return;
        }

        $submitted_by = $this->auth->getUser();
        $field_errors = [];
        $file_errors  = [];
        $success      = false;

        // Vérifier si l'agent a déjà une soumission en cours pour ce formulaire
        $existing_submission = $this->submissionRepo->findActiveByFormAndSubmitter($form['id'], $submitted_by);

        // Palier de confirmation (v34) : avoir plusieurs soumissions actives sur un
        // même formulaire est un cas métier légitime (ex. onboarding : un manager
        // gère plusieurs arrivées ; matériel : plusieurs demandes distinctes), donc
        // ce n'est plus bloqué en base (index unique retiré). On informe l'agent et
        // on demande une confirmation explicite avant de soumettre une nouvelle
        // demande, pour éviter uniquement les doublons accidentels.
        /** @phpstan-ignore-next-line if.alwaysTrue */
        $confirmed = TEST_MODE || isset($_GET['confirmed']) || isset($_POST['confirmed']);
        /** @phpstan-ignore booleanNot.alwaysFalse, booleanAnd.alwaysFalse */
        if ($existing_submission !== null && !$confirmed) {
            echo $this->renderPage(
                $this->html->h($this->html->tJargon($form['label'])),
                'forms',
                $this->renderPageCss(),
                $this->renderConfirmDuplicate($form, $existing_submission, (string) $slug)
            );
            return;
        }

        // Charger les champs dynamiques du formulaire, ordonnés par ordre.
        // Exclure les champs réservés aux validateurs (filled_by='validator').
        $all_form_fields = App::validatorData()->getFormFields($form['id']);
        $form_fields = array_filter($all_form_fields, fn(array $f): bool => empty($f['filled_by']) || $f['filled_by'] === FilledBy::Demandeur->value);

        // Pour les champs avec condition : préparer les données pour le JS
        // Les champs conditionnels sont affichés mais masqués par le JS
        // Leurs required sont retirés côté serveur si la condition n'est pas remplie
        $field_values = $_POST;
        $conditional_fields = [];
        foreach ($form_fields as $f) {
            if (!empty($f['condition'])) {
                $conditional_fields[$f['field_name']] = $f['condition'];
            }
        }

        $submission_id = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->security->requireCsrf();

            // Validation dynamique des champs obligatoires (sauf File, traité à part)
            // B14 fix : avant, ce loop s'appliquait à TOUS les champs y compris les
            // champ de type file — or $_POST[$file_field_name] est vide pour les
            // uploads (les fichiers vont dans $_FILES), donc $field_errors[$fname]
            // = 'Ce champ est obligatoire' était systématiquement setté. Ensuite,
            // $field_errors + $file_errors favorise $field_errors (opérateur +
            // conserve la 1ère clé), ce qui faisait afficher 'Ce champ est
            // obligatoire' (générique) au lieu de 'Ce fichier est obligatoire'
            // (spécifique, setté par le second loop ci-dessous).
            //
            // B-F1 fix (audit fonctionnel 2026-07-26) : validation du format email
            // sur les champs field_type=email. Avant, une valeur comme 'toto' était
            // acceptée et stockée en base, puis affichée dans les emails de
            // notification aux validateurs — pas de bounce visible côté app mais
            // dégradation silencieuse de la qualité des données.
            foreach ($form_fields as $field) {
                if ($field['field_type'] === FieldType::File->value) {
                    continue; // les fichiers sont validés dans le second loop
                }
                $value = trim((string)($_POST[$field['field_name']] ?? ''));
                if ($field['required'] && in_array($value, ['', '0'], true)) {
                    $field_errors[$field['field_name']] = 'Ce champ est obligatoire';
                    continue;
                }
                // B-F1 : validation format email pour les champs field_type=email
                if ($value !== '' && $field['field_type'] === FieldType::Email->value
                    && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $field_errors[$field['field_name']] = 'Adresse email invalide';
                }
            }

            // Validation des fichiers uploadés
            $file_errors = [];
            foreach ($form_fields as $field) {
                if ($field['field_type'] === FieldType::File->value) {
                    $fname = $field['field_name'];
                    if ($field['required'] && empty($_FILES[$fname]['name'])) {
                        $file_errors[$fname] = 'Ce fichier est obligatoire';
                    }
                }
            }

            // Validation du consentement RGPD
            if (empty($_POST['rgpd_consent'])) {
                $field_errors['rgpd_consent'] = 'Vous devez accepter le traitement de vos données pour soumettre le formulaire.';
            }

            if ($field_errors === [] && $file_errors === []) {
                $now  = date('Y-m-d H:i:s');
                $data = [];
                // Sécurité : exclure les champs internes du JSON de données métier
                $exclude_keys = ['csrf_token', 'rgpd_consent', 'action', 'MAX_FILE_SIZE'];
                foreach ($_POST as $k => $v) {
                    if (in_array($k, $exclude_keys, true)) {
                        continue;
                    }
                    $data[$k] = is_array($v) ? implode(', ', $v) : trim((string) $v);
                }

                // Ajouter les noms de fichiers uploadés dans les données
                foreach ($form_fields as $field) {
                    if ($field['field_type'] === FieldType::File->value) {
                        $fname = $field['field_name'];
                        if (!empty($_FILES[$fname]['name'])) {
                            $data[$fname] = $_FILES[$fname]['name'];
                        }
                    }
                }

                $rgpd_consent  = empty($_POST['rgpd_consent']) ? 0 : 1;
                $submission_id = $this->submissionRepo->createWithRgpd([
                    'form_id'       => $form['id'],
                    'data'          => json_encode($data, JSON_UNESCAPED_UNICODE),
                    'submitted_by'  => $submitted_by,
                    'submitted_at'  => $now,
                    'rgpd_consent'  => $rgpd_consent,
                ]);

                // Traiter les fichiers uploadés — AVANT d'invoquer advance_workflow() et
                // d'envoyer l'email de confirmation. Si un upload échoue (taille, format,
                // erreur disque), on ne déclenche PAS le workflow et on n'envoie PAS
                // l'email — l'utilisateur reste sur le formulaire avec l'erreur affichée
                // à côté du champ fichier, et la soumission est marquée "incomplète".
                // La soumission reste en base (traçabilité) mais son statut est forcé
                // à "en_cours" sans tokens générés.
                foreach ($form_fields as $form_field) {
                    if ($form_field['field_type'] === FieldType::File->value) {
                        $fname = $form_field['field_name'];
                        if (!empty($_FILES[$fname]['name']) && $_FILES[$fname]['error'] !== UPLOAD_ERR_NO_FILE) {
                            $upload_result = App::attachment()->handleFileUpload($_FILES[$fname], $submission_id, $fname);
                            if (!$upload_result['success']) {
                                $file_errors[$fname] = $upload_result['message'];
                            }
                        }
                    }
                }

                // Si un upload a échoué, on nettoie la soumission (pour ne pas laisser
                // de soumission orpheline sans fichiers) et on retourne au formulaire.
                if ($file_errors !== []) {
                    // Supprimer la soumission invalide (et ses pièces jointes partielles)
                    $this->submissionRepo->deleteById($submission_id);
                    // Note : advance_workflow() n'a pas encore été appelé → pas de tokens à nettoyer
                    // On ne set PAS $success = true → le formulaire est ré-affiché
                } else {
                    App::workflow()->advanceWorkflow($submission_id);

                    // Envoyer un email de confirmation à l'agent
                    $confirm_subject = 'Demande enregistrée — ' . $form['label'];
                    $confirm_body = App::mail()->renderEmailTemplate(
                        '✓ Demande enregistrée',
                        '<p>Votre demande <strong>'
                        . $this->html->h($this->html->tJargon($form['label']))
                        . '</strong> a bien été enregistrée le '
                        . $this->html->h(date('d/m/Y à H:i'))
                        . '.</p><p>'
                        . $this->html->h($this->html->tJargon(
                            'Le workflow de validation a été déclenché. Vous serez notifié par email lorsque votre demande sera traitée ou si un refus est émis.'
                        ))
                        . '</p>'
                    );
                    App::mail()->send($submitted_by, $confirm_subject, $confirm_body);

                    $success = true;
                }

                // Mode test : renvoyer JSON au lieu du HTML
                /** @phpstan-ignore-next-line if.alwaysTrue */
                if (TEST_MODE) {
                    $generated_tokens = $this->tokenRepo->findWithStepsBySubmission($submission_id);
                    test_json_response([
                        'success'       => true,
                        'submission_id' => $submission_id,
                        'form_slug'     => $slug,
                        'form_label'    => $form['label'],
                        'submitted_by'  => $submitted_by,
                        'data'          => $data,
                        'tokens'        => $generated_tokens,
                        'mails_count'   => count($GLOBALS['_test_mails']),
                    ]);
                }
            /** @phpstan-ignore-next-line elseif.alwaysFalse */
            } elseif (TEST_MODE) {
                // Erreurs de validation en mode test
                test_json_response(['error' => 'Erreurs de validation', 'field_errors' => $field_errors]);
            }
        }

        // Mode test : GET renvoie les métadonnées du formulaire en JSON
        // B-EXIT (audit 2026-07-26) : utiliser test_json_response() au lieu de
        // echo+exit direct pour permettre le mode 'no-exit' (tests PHPUnit).
        /** @phpstan-ignore-next-line booleanAnd.leftAlwaysTrue */
        if (TEST_MODE && $_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['screenshot'])) {
            $fields_list = [];
            foreach ($form_fields as $form_field) {
                $fields_list[] = [
                    'field_name' => $form_field['field_name'],
                    'label'      => $form_field['label'],
                    'field_type' => $form_field['field_type'],
                    'required'   => (bool) $form_field['required'],
                    'options'    => $form_field['options'] ? json_decode($form_field['options'], true) : null,
                    'card_group' => $form_field['card_group'],
                ];
            }
            test_json_response([
                'form'         => [
                    'id'          => $form['id'],
                    'slug'        => $form['slug'],
                    'label'       => $form['label'],
                    'description' => $form['description'],
                ],
                'fields'       => $fields_list,
                'csrf_token'   => $this->security->generateCsrfToken(),
                'submitted_by' => $submitted_by,
            ]);
        }

        // Regrouper les champs par card_group pour le rendu visuel
        $grouped       = [];
        $field_labels  = [];
        foreach ($form_fields as $form_field) {
            $group = $form_field['card_group'] ?? 'Général';
            $grouped[$group][] = $form_field;
            $field_labels[$form_field['field_name']] = $form_field['label'];
        }

        // Valeurs à pré-remplir — prioriser $_POST (ré-affichage après erreur de validation)
        $field_values       = $_POST;
        $ldap_datalist_id   = '';
        $ldap_datalist_html = '';

        $page_css = $this->renderPageCss();
        $content  = $this->renderContent(
            $form,
            $submitted_by,
            $existing_submission,
            $success,
            $submission_id,
            $grouped,
            $field_errors,
            $file_errors,
            $field_values,
            $ldap_datalist_id,
            $ldap_datalist_html,
            (string) $slug
        );

        echo $this->renderPage(
            $this->html->h($this->html->tJargon($form['label'])),
            'forms',
            $page_css,
            $content
        );
    }

    /**
     * CSS spécifique à la page formulaire (nowdoc statique — sans interpolation).
     */
    private function renderPageCss(): string
    {
        return '';
    }

    /**
     * Rendu HTML de l'écran de confirmation affiché quand l'agent a déjà une
     * soumission en cours sur ce formulaire (v34 — remplace le blocage en base).
     *
     * @param array{id: string, slug: string, label: string, description: string|null, actif: int, created_at: string, deadline_field: string} $form
     * @param array{submitted_at: string|null, id: string}       $existing_submission
     */
    private function renderConfirmDuplicate(array $form, array $existing_submission, string $slug): string
    {
        $h       = $this->html->h(...);
        $tJargon = $this->html->tJargon(...);
        $date    = $h(date('d/m/Y à H:i', (int) strtotime((string) ($existing_submission['submitted_at'] ?? ''))));
        $existingId = urlencode((string) ($existing_submission['id'] ?? ''));
        $confirmUrl = 'index.php?p=form&f=' . urlencode($slug) . '&confirmed=1';

        ob_start();
        ?>
  <h1><?= $h($tJargon($form['label'])) ?></h1>
  <div class="warn-box">
    <p><strong><span aria-hidden="true">⚠</span> Attention :</strong> Vous avez déjà une demande en cours pour ce formulaire (soumise le <?= $date ?>).</p>
    <p>Voulez-vous vraiment en soumettre une nouvelle ?</p>
  </div>
  <div class="u-mt-15-d-flex-gap-05-jc-center-70238d">
    <a href="index.php?p=submission_view&id=<?= $existingId ?>" class="btn btn-secondary">Voir la demande existante</a>
    <a href="<?= $h($confirmUrl) ?>" class="btn btn-primary">Soumettre quand même</a>
    <a href="index.php" class="btn btn-secondary">Annuler</a>
  </div>
<?php
        $content = ob_get_clean();
        return $content === false ? '' : $content;
    }

    /**
     * Rendu HTML du formulaire (titre, champs, consentement RGPD,
     * bouton submit, script de progression). Reproduit à l'identique la
     * structure HTML historique de form.php (output buffering + inline PHP).
     *
     * @param array{label: string, description: string|null}  $form
     * @param array{submitted_at: string|null, id: string}|null $existing_submission
     * @param array<string, list<array<string, mixed>>>       $grouped  Clé=nom du groupe, valeur=liste des champs
     * @param array<string, string>                           $field_errors
     * @param array<string, mixed>                            $file_errors  Erreurs spécifiques aux uploads
     * @param array<string, string>                           $field_values
     */
    private function renderContent(
        array $form,
        string $submitted_by,
        $existing_submission,
        bool $success,
        string $submission_id,
        array $grouped,
        array $field_errors,
        array $file_errors,
        array $field_values,
        string $ldap_datalist_id,
        string $ldap_datalist_html,
        string $slug
    ): string {
        // Les variables locales sont nécessaires pour le template inline ci-dessous.
        $h        = $this->html->h(...);
        $tJargon  = $this->html->tJargon(...);

        ob_start();
        ?>
  <?php // S4-UI / Action 1 : anti-jargon sur le titre + description du formulaire.?>
  <h1><?= $h($tJargon($form['label'])) ?></h1>
  <?php if ($form['description']): ?><p class="agent-info"><?= $h($tJargon($form['description'])) ?></p><?php endif; ?>
  <p class="agent-info">Formulaire rempli par : <strong><?= $h($submitted_by) ?></strong></p>

  <?php if ($existing_submission && !$success): ?>
    <div class="warn-box">
      <p><strong><span aria-hidden="true">⚠</span> Attention :</strong> Vous avez déjà une demande en cours pour ce formulaire (soumise le <?= $h(date('d/m/Y à H:i', (int) strtotime((string) ($existing_submission['submitted_at'] ?? '')))) ?>).</p>
      <p>Vous pouvez tout de même soumettre une nouvelle demande si nécessaire.</p>
      <p><a href="index.php?p=submission_view&id=<?= urlencode((string) ($existing_submission['id'] ?? '')) ?>" class="u-c-warning-fw-bold-3570af">Voir la demande existante →</a></p>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="success">
      <strong><span aria-hidden="true">✓</span> Demande enregistrée</strong>
      <?= $h($tJargon('Le workflow de validation a été déclenché automatiquement.')) ?> Un email de confirmation vous a été envoyé.
    </div>
    <div class="u-mt-15-d-flex-gap-05-jc-center-e4952c">
      <a href="index.php?p=submission_view&id=<?= urlencode($submission_id) ?>" class="btn btn-primary">Voir ma demande</a>
      <a href="index.php?p=my_submissions" class="btn btn-secondary">Mes demandes</a>
      <a href="index.php" class="btn btn-secondary">Accueil</a>
    </div>
  <?php else: ?>
    <form method="POST" action="index.php?p=form&f=<?= urlencode($slug) ?>" enctype="multipart/form-data" id="form-main">
      <?= $this->security->csrfField() ?>
      <?php if ($existing_submission !== null): ?><input type="hidden" name="confirmed" value="1"><?php endif; ?>
    <?php // ITER1-B / Action B : encadré « Aide » en haut du formulaire.?>
    <aside class="form-help-box" aria-label="Aide pour remplir le formulaire">
      <span class="form-help-icon" aria-hidden="true">💡</span>
      <span class="form-help-text">
        <?php // U-08 : indicateur de progression (uniquement si >1 section)?>
        <?= new \App\Render\FormRenderer()->formProgressIndicator($grouped) ?>
        <?php foreach ($grouped as $card_title => $card_fields): ?>
          <?php
          // Séparer les checkboxes des autres champs pour le rendu
          $checkboxes = [];
            $non_checkboxes = [];
            foreach ($card_fields as $card_field) {
                if ($card_field['field_type'] === FieldType::Checkbox->value) {
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
                  <?php $cond = empty($non_checkbox['condition']) ? '' : ' data-condition="' . htmlspecialchars((string) $non_checkbox['condition'], ENT_QUOTES) . '"'; ?>
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
                  echo ' class="u-mt-1-9dae1d"';
              } ?>>
                <?php foreach ($checkboxes as $checkbox): ?>
                  <?php $cond = empty($checkbox['condition']) ? '' : ' data-condition="' . htmlspecialchars((string) $checkbox['condition'], ENT_QUOTES) . '"'; ?>
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
        <div class="card u-bg-lavender-border-510553">
          <label class="checkbox-item u-fs-sm-lh-15-52f832">
            <input type="checkbox" name="rgpd_consent" value="1" required aria-required="true"<?= empty($_POST['rgpd_consent']) ? '' : ' checked' ?>>
            J'accepte le traitement de mes données personnelles dans le cadre de cette procédure.
          </label>
          <?php // Message d'erreur si le consentement RGPD a été oublié lors d'une soumission précédente?>
          <?php if (!empty($field_errors['rgpd_consent'])): ?>
            <p class="error-hint u-c-danger-fs-xs-mt-05-ml-17-8b8964" role="alert">
              <?= $h($field_errors['rgpd_consent']) ?>
            </p>
          <?php endif; ?>
          <p class="u-c-muted-fs-xxs-mt-05-ml-17-704c93">
            <?php // S4-UI / Action 1 : la mention légale contient « dématérialisation » → on traduit.?>
            <?= $h($tJargon($this->settings->get('legal_mentions', 'Les données collectées sont traitées dans le cadre de la dématérialisation des procédures internes de la DREETS. Conformément au RGPD, vous disposez d\'un droit d\'accès, de rectification et d\'effacement de vos données. Durée de conservation : 24 mois après clôture.'))) ?>
          </p>
        </div>
        <div class="form-actions u-mt-15-gap-1-jc-center-fw-wrap-e1f70a">
          <button type="submit" class="btn-submit">✓ Envoyer ma demande</button>
        </div>
      <?php endif; ?>
    </form>
    <script src="assets.php?type=js&file=form-progress" nonce="<?= \App\Core\App::security()->getScriptNonce() ?>"></script>
    <script src="assets.php?type=js&file=form-conditions" nonce="<?= \App\Core\App::security()->getScriptNonce() ?>"></script>
  <?php endif; ?>
<?php
        $content = ob_get_clean();
        return $content === false ? '' : $content;
    }
}
