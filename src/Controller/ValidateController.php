<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Contrôleur de la page Validation (accept/refuse de formulaires).
 *
 * Gère le workflow de validation : CSRF, tokens, email, DB, pièces jointes.
 * Routing : ?token=XXX → validate (auto-détecté dans index.php).
 */
final class ValidateController extends BaseController
{
    public function handle(): void
    {
        $result = ['status' => 'invalid', 'data' => null];
        $token  = '';

        // ── POST — Exécute l'action ──
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->security->requireCsrf();

            $token = trim($_POST['token'] ?? '');
            $action = trim($_POST['action'] ?? '');
            $comment = trim($_POST['comment'] ?? '');
            $motif = trim($_POST['motif'] ?? '');
            if ($action === 'refuser' && $motif !== '') {
                $comment = $comment !== '' ? ($motif . ' — ' . $comment) : $motif;
            }

            try {
                if ($token) $token = validate_input($token, 'token');
                if ($action) $action = validate_input($action, 'action');
            } catch (\InvalidArgumentException $e) {
                $error = 'Données invalides.';
                /** @phpstan-ignore-next-line if.alwaysTrue */
                if (TEST_MODE) { test_json_response(['error' => 'Données invalides', 'token' => substr($token, 0, 8) . '...', 'action' => $action]); }
            }

            if (!isset($error)) {
                if ($action === 'refuser' && empty(trim($comment))) {
                    // Ne pas traiter — on affiche la page avec un message d'erreur
                } elseif ($token && in_array($action, ['valider', 'refuser'])) {
                    $pre_ctx = App::workflow()->getTokenWithContext((string)$token);
                    $pre_validator_fields = [];
                    if ($pre_ctx && !empty($pre_ctx['form_id'])) {
                        $pre_validator_fields = App::validatorData()->getFormValidatorFields(
                            (string)$pre_ctx['form_id'],
                            isset($pre_ctx['step_id']) ? (string)$pre_ctx['step_id'] : null
                        );
                    }

                    if ($action === 'valider' && !empty($pre_validator_fields)) {
                        $missing = [];
                        foreach ($pre_validator_fields as $vf) {
                            if (!empty($vf['required'])) {
                                $fname = (string)($vf['field_name'] ?? '');
                                if ($fname === '') {
                                    continue;
                                }
                                $val = trim((string)($_POST[$fname] ?? ''));
                                if ($val === '') {
                                    $missing[] = App::html()->tJargon((string)($vf['label'] ?? $fname));
                                }
                            }
                        }
                        if (!empty($missing)) {
                            $error = 'Champs obligatoires manquants : ' . implode(', ', $missing);
                            /** @phpstan-ignore-next-line if.alwaysTrue */
                            if (TEST_MODE) {
                                test_json_response([
                                    'error'   => $error,
                                    'action'  => $action,
                                    'token'   => substr((string)$token, 0, 8) . '...',
                                    'missing' => $missing,
                                ]);
                            }
                        }
                    }

                    if (!isset($error)) {
                        $done_by = $this->auth->getUser();
                        $result = App::workflow()->validateToken((string)$token, (string)$action, $comment, $done_by);

                        /** @phpstan-ignore-next-line if.alwaysTrue */
                        if (TEST_MODE) {
                            test_json_response([
                                'action'  => $action,
                                'result'  => $result,
                                'token'   => substr((string)$token, 0, 8) . '...',
                                'comment' => $comment,
                            ]);
                        }

                        if ($result['status'] === 'ok') {
                            $success = true;

                            $token_ctx = $result['data'] ?? [];
                            if (!empty($token_ctx['form_id'])) {
                                $form_id = (string)$token_ctx['form_id'];
                                $step_id = isset($token_ctx['step_id']) ? (string)$token_ctx['step_id'] : null;
                                $subm_id = isset($token_ctx['submission_id']) ? (string)$token_ctx['submission_id'] : '';
                                $validator_fields = App::validatorData()->getFormValidatorFields($form_id, $step_id);
                                if (!empty($validator_fields) && $subm_id !== '') {
                                    foreach ($validator_fields as $vf) {
                                        $fname = (string)($vf['field_name'] ?? '');
                                        if ($fname === '') {
                                            continue;
                                        }
                                        $val = trim((string)($_POST[$fname] ?? ''));
                                        if ($val !== '') {
                                            App::validatorData()->saveValidatorData(
                                                $subm_id,
                                                $fname,
                                                $val,
                                                'validator',
                                                $step_id,
                                                null,
                                                isset($token_ctx['email']) ? (string)$token_ctx['email'] : null,
                                                isset($token_ctx['id']) ? (string)$token_ctx['id'] : null
                                            );
                                        } else {
                                            App::validatorData()->deleteValidatorData($subm_id, $fname);
                                        }
                                    }
                                }
                            }
                        } else {
                            $error = $result['status'] === 'invalid' ? 'Lien invalide ou expiré.' :
                                     ($result['status'] === 'already_done' ? 'Cette tâche a déjà été traitée.' :
                                     ($result['status'] === 'closed' ? 'Le workflow est déjà terminé.' :
                                     ($result['status'] === 'expired' ? 'Ce lien a expiré.' : 'Erreur inconnue.')));
                        }
                    }
                } else {
                    /** @phpstan-ignore-next-line if.alwaysTrue */
                    if (TEST_MODE) { test_json_response(['error' => 'Données invalides', 'token' => $token, 'action' => $action]); }
                    $error = 'Données invalides.';
                }
            }
        }

        // ── GET — Affichage uniquement ──
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $token = trim($_GET['token'] ?? '');

            if ($token) {
                if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
                    $result = ['status' => 'invalid'];
                } else {
                    $this->audit->log('token_view', 'token:' . substr($token, 0, 8) . '...', 'Consultation page de validation', '');

                    $data = App::workflow()->getTokenWithContext($token);

                    if (!$data) {
                        $result = ['status' => 'invalid'];
                    } elseif ($data['done_at']) {
                        $result = ['status' => 'already_done', 'data' => $data];
                    } elseif ($data['closed_at']) {
                        $result = ['status' => 'closed', 'data' => $data];
                    } elseif (!empty($data['expires_at'])) {
                        $exp_ts = strtotime($data['expires_at']);
                        if ($exp_ts !== false && $exp_ts < time()) {
                            $result = ['status' => 'expired', 'data' => $data];
                        } else {
                            $result = ['status' => 'pending', 'data' => $data];
                        }
                    } else {
                        $result = ['status' => 'ok', 'data' => $data];
                    }
                }
            } else {
                $result = ['status' => 'invalid'];
            }

            /** @phpstan-ignore-next-line booleanAnd.leftAlwaysTrue */
            if (TEST_MODE && !isset($_GET['screenshot'])) {
                $response = [
                    '_test_mode' => true,
                    'token_hash' => substr($token, 0, 8) . '...',
                    'result'     => $result['status'],
                ];
                if (isset($data)) {
                    $response['step_label']  = $data['step_label'] ?? '';
                    $response['form_label']  = $data['form_label'] ?? '';
                    $response['submission_id'] = $data['submission_id'] ?? null;
                    $response['csrf_token']  = $this->security->generateCsrfToken();
                }
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }
        }

        // ── Rendu HTML ──
        $pageCss = '';
        ob_start();
        ?>
<div class="card">

<?php if (isset($success)): ?>
  <h1>Validation effectuée</h1>
  <p class="ok">Action effectuée avec succès.</p>
  <div style="margin-top:1.5rem;display:flex;gap:.5rem;justify-content:center;">
    <a href="index.php?p=my_validations" class="btn btn-secondary">Mes validations</a>
    <a href="index.php" class="btn btn-secondary">Accueil</a>
  </div>

<?php elseif (isset($error)): ?>
  <h1>Erreur</h1>
  <p class="err"><?= \App\Core\App::html()->escape($error) ?></p>
  <div style="margin-top:1.5rem;display:flex;gap:.5rem;justify-content:center;">
    <a href="index.php?p=my_validations" class="btn btn-secondary">Mes validations</a>
    <a href="index.php" class="btn btn-secondary">Accueil</a>
  </div>

<?php elseif ($result['status'] === 'invalid'): ?>
  <h1>Lien invalide</h1>
  <p class="err">Ce lien est introuvable ou expiré.</p>
  <div style="margin-top:1.5rem;display:flex;gap:.5rem;justify-content:center;">
    <a href="index.php?p=my_validations" class="btn btn-secondary">Mes validations</a>
    <a href="index.php" class="btn btn-secondary">Accueil</a>
  </div>

<?php elseif ($result['status'] === 'already_done'): ?>
  <?php $data = $result['data'] ?? []; ?>
  <span class="badge"><?= \App\Core\App::html()->escape($data['step_label']) ?></span>
  <h1>Déjà validé</h1>
  <p class="info">Tâche validée le <?= \App\Core\App::html()->escape(date('d/m/Y à H:i', (int) strtotime((string)($data['done_at'] ?? 'now')))) ?></p>
  <div style="margin-top:1.5rem;display:flex;gap:.5rem;justify-content:center;">
    <a href="index.php?p=my_validations" class="btn btn-secondary">Mes validations</a>
    <a href="index.php" class="btn btn-secondary">Accueil</a>
  </div>

<?php elseif ($result['status'] === 'closed'): ?>
  <h1>Workflow terminé</h1>
  <p class="info">Ce dossier est déjà clôturé.</p>
  <div style="margin-top:1.5rem;display:flex;gap:.5rem;justify-content:center;">
    <a href="index.php?p=my_validations" class="btn btn-secondary">Mes validations</a>
    <a href="index.php" class="btn btn-secondary">Accueil</a>
  </div>

<?php elseif ($result['status'] === 'expired'): ?>
  <h1>Lien expiré</h1>
  <p class="err">Ce lien de validation a expiré. Veuillez contacter l'expéditeur pour obtenir un nouveau lien.</p>
  <div style="margin-top:1.5rem;display:flex;gap:.5rem;justify-content:center;">
    <a href="index.php?p=my_validations" class="btn btn-secondary">Mes validations</a>
    <a href="index.php" class="btn btn-secondary">Accueil</a>
  </div>

<?php elseif ($result['status'] === 'pending' || $result['status'] === 'ok'): ?>
  <?php
    $data = $result['data'] ?? [];
    $d   = json_decode($data['data'] ?? '{}', true);
    $nom = \App\Core\App::html()->escape(($d['prenom'] ?? '') . ' ' . ($d['nom'] ?? ''));

    $all_wf_steps = $this->formRepo->getWorkflowStepsWithTokens(
        (string)($data['form_id'] ?? ''),
        (string)($data['submission_id'] ?? '')
    );
  ?>
  <a href="index.php?p=my_validations" class="back-link">← Mes validations</a>
  <span class="badge"><?= \App\Core\App::html()->escape($data['step_label']) ?></span>
  <h1>Action requise</h1>

  <aside class="what-to-do-box" role="region" aria-label="Que devez-vous faire ?">
    <span class="what-to-do-title">Que devez-vous faire ?</span>
    Vous devez <strong>valider</strong> ou <strong>refuser</strong> cette demande. Choisissez une action ci-dessous.
  </aside>

  <?php if (!empty($all_wf_steps)): ?>
  <div class="wf-progression">
    <h3>Avancement des étapes</h3>
    <div class="wf-steps">
      <?php foreach ($all_wf_steps as $ws):
          $dones_arr = array_filter(explode('|', $ws['dones'] ?? ''), fn($x) => !empty($x));
          $all_done = count($dones_arr) > 0 && count(array_filter(explode('|', $ws['dones'] ?? ''))) === count(array_filter(explode('|', $ws['emails'] ?? '')));
          $is_current = ($ws['id'] == ($data['step_id'] ?? 0));

          if ($all_done) { $cls = 'wf-prog-done'; $icon = '<span aria-hidden="true">✓</span>'; }
          elseif ($is_current) { $cls = 'wf-prog-current'; $icon = '<span aria-hidden="true">⏳</span>'; }
          else { $cls = 'wf-prog-upcoming'; $icon = '○'; }
      ?>
        <div class="wf-prog-step <?= $cls ?>">
          <span class="wf-prog-icon"><?= $icon ?></span>
          <span>Étape <?= (int)$ws['ordre'] ?> — <?= \App\Core\App::html()->escape($ws['label']) ?><?= $is_current ? ' (votre tour)' : '' ?><?= $all_done ? ' — validée' : '' ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="validation-details">
    <h2>Détails du formulaire</h2>
    <p><strong>Dossier :</strong> <?= $nom ?></p>
    <p><strong>Étape :</strong> <?= \App\Core\App::html()->escape($data['step_label']) ?></p>
    <?php
      $current_step_field_names = [];
      $vf_list = App::validatorData()->getFormValidatorFields($data['form_id'], $data['step_id'] ?? null);
      foreach ($vf_list as $vf) {
          $current_step_field_names[] = $vf['field_name'];
      }
      $exclude_keys = array_merge(['validations', 'csrf_token'], $current_step_field_names);
    ?>
    <?= (new \App\Render\FormRenderer())->submissionData($d, $exclude_keys) ?>
  </div>

  <?php
    $all_validator_data = App::validatorData()->getSubmissionValidatorData($data['submission_id'] ?? '');
    $all_vd_by_field = [];
    foreach ($all_validator_data as $avd) {
        $all_vd_by_field[$avd['field_name']] = $avd;
    }
    $all_validator_fields = App::validatorData()->getFormValidatorFields($data['form_id']);
    $field_labels = [];
    foreach ($all_validator_fields as $avf) {
        $field_labels[$avf['field_name']] = $avf['label'];
    }

    $previous_vd_rows = [];
    foreach ($all_vd_by_field as $fname => $vd_row) {
        if (in_array($fname, $current_step_field_names, true)) continue;
        if (empty($vd_row['value'])) continue;
        $previous_vd_rows[] = $vd_row;
    }

    if (!empty($previous_vd_rows)):
  ?>
  <div class="validation-details" style="border-left: 4px solid var(--c-success);">
    <h2>📋 Informations saisies par les validateurs précédents</h2>
    <?php foreach ($previous_vd_rows as $pvd):
        $label = $field_labels[$pvd['field_name']] ?? ucfirst(str_replace('_', ' ', $pvd['field_name']));
        $value = $pvd['value'] === '1' ? '✓ Oui' : \App\Core\App::html()->escape($pvd['value']);
        $step_lbl = \App\Core\App::html()->escape($pvd['step_label'] ?? '');
    ?>
      <p><strong><?= \App\Core\App::html()->escape(App::html()->tJargon($label)) ?>:</strong> <?= $value ?>
      <?php if ($step_lbl): ?><br><small style="color:#666;">Étape : <?= $step_lbl ?></small><?php endif; ?>
      </p>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php
    $attachments = App::attachment()->getAttachments($data['submission_id'] ?? '');
    $visible_attachments = [];
    if (!empty($attachments)) {
        $owner_only_fields = [];
        $form_fields = App::validatorData()->getFormFields((string)($data['form_id'] ?? ''));
        foreach ($form_fields as $ff) {
            if (($ff['field_type'] ?? '') === 'file' && ($ff['visibility'] ?? 'all') === 'owner_only') {
                $owner_only_fields[] = $ff['field_name'];
            }
        }
        foreach ($attachments as $att) {
            if (!in_array($att['field_name'] ?? '', $owner_only_fields, true)) {
                $visible_attachments[] = $att;
            }
        }
    }
    if (!empty($visible_attachments)):
  ?>
  <div class="validation-details">
    <h2><span aria-hidden="true">📎</span> Pièces jointes (<?= count($visible_attachments) ?>)</h2>
    <?php foreach ($visible_attachments as $att): ?>
      <p><?= App::html()->getFileIcon($att['mime_type']) ?> <a href="index.php?p=download&id=<?= urlencode($att['id']) ?>" style="color:var(--c-primary-dark);text-decoration:underline;"><?= \App\Core\App::html()->escape($att['original_name']) ?></a> <span style="color:#595959;font-size:.85rem;">(<?= App::html()->formatFileSize((int)$att['file_size']) ?>)</span></p>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="post" id="validation-form">
    <?= $this->security->csrfField() ?>
    <input type="hidden" name="token" value="<?= \App\Core\App::html()->escape((string)$token) ?>">

    <?php
      $validator_fields = App::validatorData()->getFormValidatorFields($data['form_id'], $data['step_id'] ?? null);
      $validator_data = App::validatorData()->getSubmissionValidatorData($data['submission_id'] ?? '', $data['step_id'] ?? null);
      $validator_data_index = [];
      foreach ($validator_data as $vd) {
          $validator_data_index[$vd['field_name']] = $vd['value'];
      }
      if (!empty($validator_fields)):
    ?>
    <div class="validation-details" style="border-left: 4px solid var(--c-primary);">
      <h2>📝 Informations à compléter</h2>
      <p class="hint" style="margin-bottom: 1rem;">Remplissez les champs ci-dessous lors de la validation.</p>
      <?php foreach ($validator_fields as $vf):
          $existing_val = $_POST[$vf['field_name']]
              ?? $validator_data_index[$vf['field_name']]
              ?? '';
      ?>
        <div style="margin-bottom: 1rem;">
          <?php
              echo (new \App\Render\FormRenderer())->field($vf, $existing_val, [], '', false);
          ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <fieldset class="refusal-section">
      <legend class="refusal-legend">Motif du refus <span class="req" aria-hidden="true">*</span></legend>
      <span class="hint refusal-hint">Sélectionnez un motif. Vous pourrez préciser en complément ci-dessous.</span>
      <div class="refusal-motif-list" role="radiogroup" aria-label="Motif du refus">
        <label class="refusal-motif-radio">
          <input type="radio" name="motif" value="Information manquante"<?= (($_POST['motif'] ?? '') === 'Information manquante') ? ' checked' : '' ?>>
          <span class="refusal-motif-icon" aria-hidden="true">📄</span>
          <span class="refusal-motif-label">Information manquante</span>
        </label>
        <label class="refusal-motif-radio">
          <input type="radio" name="motif" value="Hors périmètre"<?= (($_POST['motif'] ?? '') === 'Hors périmètre') ? ' checked' : '' ?>>
          <span class="refusal-motif-icon" aria-hidden="true">🚫</span>
          <span class="refusal-motif-label">Hors périmètre</span>
        </label>
        <label class="refusal-motif-radio">
          <input type="radio" name="motif" value="Non conforme"<?= (($_POST['motif'] ?? '') === 'Non conforme') ? ' checked' : '' ?>>
          <span class="refusal-motif-icon" aria-hidden="true">⚠️</span>
          <span class="refusal-motif-label">Non conforme</span>
        </label>
        <label class="refusal-motif-radio">
          <input type="radio" name="motif" value="Autre motif"<?= (($_POST['motif'] ?? '') === 'Autre motif') ? ' checked' : '' ?>>
          <span class="refusal-motif-icon" aria-hidden="true">✏️</span>
          <span class="refusal-motif-label">Autre motif</span>
        </label>
      </div>
    </fieldset>

    <div class="form-group">
      <label for="comment">Précisions complémentaires <span class="hint">(recommandé pour le refus, optionnel pour la validation)</span></label>
      <textarea id="comment" name="comment" rows="4" placeholder="Ex : il manque le justificatif de domicile de moins de 3 mois..."><?= \App\Core\App::html()->escape($_POST['comment'] ?? '') ?></textarea>
    </div>

    <div class="submit-buttons">
      <button type="submit" name="action" value="valider" class="btn-validate"><span aria-hidden="true">✅</span> Valider</button>
      <button type="button" id="btn-show-refusal-recap" class="btn-refuse-confirm" aria-haspopup="dialog" aria-expanded="false" aria-controls="refusal-recap"><span aria-hidden="true">❌</span> Confirmer le refus</button>
    </div>

    <div id="refusal-recap" class="refusal-summary" role="alert" aria-live="assertive" hidden tabindex="-1">
      <h3 class="refusal-summary-title"><span aria-hidden="true">⚠️</span> Confirmation du refus</h3>
      <p class="refusal-summary-text">Vous allez refuser cette demande pour le motif suivant : <strong id="refusal-recap-motif">—</strong></p>
      <p class="refusal-summary-warning">Cette action est <strong>irréversible</strong>. Le demandeur sera notifié par email.</p>
      <div class="refusal-summary-actions">
        <button type="submit" name="action" value="refuser" class="btn-refuse-definitive" formnovalidate><span aria-hidden="true">✓</span> Oui, refuser définitivement</button>
        <button type="button" id="btn-cancel-refusal" class="btn-refuse-cancel">Annuler</button>
      </div>
    </div>

    <?php if (isset($_POST['action']) && $_POST['action'] === 'refuser' && empty($comment)): ?>
    <div class="msg-error" role="alert" aria-live="assertive" style="margin-top:1rem;">Veuillez sélectionner un motif de refus et/ou saisir des précisions avant de confirmer.</div>
    <?php endif; ?>
  </form>
<?php endif; ?>

<?php
        $content = ob_get_clean();
        if ($content === false) { $content = ''; }
        echo $this->renderPage('Validation', 'mes_validations', $pageCss, $content);
    }
}
