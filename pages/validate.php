<?php
require_once dirname(__DIR__) . '/helpers.php';

// Initialisation par défaut — évite les variables indéfinies si aucune branche
// ci-dessous ne les renseigne (ex: POST « refuser » sans commentaire).
$result = ['status' => 'invalid', 'data' => null];
$token  = '';

// Traitement du POST — exécute l'action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérification CSRF
    require_csrf();

    $token = trim($_POST['token'] ?? '');
    $action = trim($_POST['action'] ?? '');
    $comment = trim($_POST['comment'] ?? '');
    $motif = trim($_POST['motif'] ?? '');
    // Côté serveur : préfixer le commentaire avec le motif (remplace le JS inline)
    if ($action === 'refuser' && $motif !== '') {
        $comment = $comment !== '' ? ($motif . ' — ' . $comment) : $motif;
    }

    // Sécurité (S-07/A-01) : valider le format du token et de l'action
    try {
        if ($token) $token = validate_input($token, 'token');
        if ($action) $action = validate_input($action, 'action');
    } catch (\InvalidArgumentException $e) {
        $error = 'Données invalides.';
        /** @phpstan-ignore-next-line if.alwaysTrue */
        if (TEST_MODE) { test_json_response(['error' => 'Données invalides', 'token' => substr($token, 0, 8) . '...', 'action' => $action]); }
    }
    
    if (!isset($error)) {
    // Le refus nécessite un commentaire obligatoire
    if ($action === 'refuser' && empty(trim($comment))) {
        // Ne pas traiter — on affiche la page avec un message d'erreur
        // L'utilisateur doit fournir un motif de refus
    } elseif ($token && in_array($action, ['valider', 'refuser'])) {
        // ── P1-C / issue #7 : pré-charger le contexte du token SANS le valider,
        $pre_ctx = get_token_with_context((string)$token);
        $pre_validator_fields = [];
        if ($pre_ctx && !empty($pre_ctx['form_id'])) {
            $pre_validator_fields = get_form_validator_fields(
                (string)$pre_ctx['form_id'],
                isset($pre_ctx['step_id']) ? (string)$pre_ctx['step_id'] : null
            );
        }

        // ── P1-C / issue #7 : pour action=valider, vérifier que tous les champs
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
                        $missing[] = t_jargon((string)($vf['label'] ?? $fname));
                    }
                }
            }
            if (!empty($missing)) {
                $error = 'Champs obligatoires manquants : ' . implode(', ', $missing);
                // Mode test : renvoyer du JSON pour permettre les tests automatisés
                // de cette nouvelle branche d'erreur (issue #7).
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

        // ── Si pas d'erreur de champs required, procéder à validate_token() ──
        if (!isset($error)) {
            // v10.0.2 — Passer le user logged-on (get_auth_user) à validate_token
            // pour stocker qui a réellement cliqué (différent de l'email du token
            // qui peut être une shared mailbox)
            $done_by = get_auth_user();
            $result = validate_token((string)$token, (string)$action, $comment, $done_by);

            // Mode test : renvoyer JSON
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

                // ── A-13 : sauvegarder les champs validator (filled_by='validator') ──
                $token_ctx = $result['data'] ?? [];
                if (!empty($token_ctx['form_id'])) {
                    $form_id = (string)$token_ctx['form_id'];
                    $step_id = isset($token_ctx['step_id']) ? (string)$token_ctx['step_id'] : null;
                    $subm_id = isset($token_ctx['submission_id']) ? (string)$token_ctx['submission_id'] : '';
                    $validator_fields = get_form_validator_fields($form_id, $step_id);
                    if (!empty($validator_fields) && $subm_id !== '') {
                        foreach ($validator_fields as $vf) {
                            $fname = (string)($vf['field_name'] ?? '');
                            if ($fname === '') {
                                continue;
                            }
                            $val = trim((string)($_POST[$fname] ?? ''));
                            if ($val !== '') {
                                save_validator_data(
                                    $subm_id,
                                    $fname,
                                    $val,
                                    'validator',
                                    $step_id,
                                    null,                                // step_label résolu auto par save_validator_data
                                    isset($token_ctx['email']) ? (string)$token_ctx['email'] : null,
                                    isset($token_ctx['id']) ? (string)$token_ctx['id'] : null
                                );
                            } else {
                                // P1-C / issue #8 : champ vide soumis → on efface
                                delete_validator_data($subm_id, $fname);
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
        } // fin if (!isset($error)) après pre-check required
    } else {
        /** @phpstan-ignore-next-line if.alwaysTrue */
        if (TEST_MODE) { test_json_response(['error' => 'Données invalides', 'token' => $token, 'action' => $action]); }
        $error = 'Données invalides.';
    }
} // fin if (!isset($error)) (L34)
} // fin if ($_SERVER['REQUEST_METHOD'] === 'POST') (L10)

// GET request — affichage uniquement (pas d'effet de bord)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = trim($_GET['token'] ?? '');

    // Sécurité (S-09) : limiter les tentatives de consultation de token (anti-énumération)
    if ($token && !rate_limit_check('validate_view', 30, 60)) {
        render_error_page(429, 'Trop de requêtes',
            'Vous avez effectué trop de consultations de liens de validation en peu de temps. Veuillez patienter quelques instants.',
            'Attendez environ 1 minute avant de réessayer.');
    }

    if ($token) {
        // Sécurité (S-09) : vérifier le format du token avant la requête DB
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            $result = ['status' => 'invalid'];
        } else {
            // Journaliser la consultation du token pour audit (S-09)
            app_log('token_view', 'token:' . substr($token, 0, 8) . '...', 'Consultation page de validation');

            // A-18 : utiliser la fonction centralisée au lieu de dupliquer la jointure
            $data = get_token_with_context($token);

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
        } // fin else token format valide
    } else {
        $result = ['status' => 'invalid'];
    }

    // Mode test : GET renvoie JSON au lieu du HTML (sauf si ?screenshot=1 pour captures d'écran)
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
            $response['csrf_token']  = generate_csrf_token();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}
?>
<?php
$page_css = '';
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
  <p class="err"><?= h($error) ?></p>
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
  <span class="badge"><?= h($data['step_label']) ?></span>
  <h1>Déjà validé</h1>
  <p class="info">Tâche validée le <?= h(date('d/m/Y à H:i', strtotime((string)($data['done_at'] ?? 'now')))) ?></p>
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
    $nom = h(($d['prenom'] ?? '') . ' ' . ($d['nom'] ?? ''));
    $pdo = get_pdo();

    // Récupérer toutes les étapes du workflow pour afficher la progression
    $wf_steps = $pdo->prepare("
        SELECT st.id, st.label, st.ordre,
               GROUP_CONCAT(t2.done_at, '|') as dones,
               GROUP_CONCAT(t2.email, '|') as emails
        FROM steps st
        LEFT JOIN tokens t2 ON t2.step_id = st.id AND t2.submission_id = ?
        WHERE st.form_id = ? AND st.actif = 1
        GROUP BY st.id
        ORDER BY st.ordre, st.id
    ");
    $wf_steps->execute([$data['submission_id'] ?? '', $data['form_id'] ?? '']);
    $all_wf_steps = $wf_steps->fetchAll(PDO::FETCH_ASSOC);
  ?>
  <a href="index.php?p=my_validations" class="back-link">← Mes validations</a>
  <span class="badge"><?= h($data['step_label']) ?></span>
  <h1>Action requise</h1>

  <?php // ── ITER1-C / Action C.3 : encadré « Que devez-vous faire ? » ──
        // M. Robert (70 ans) : la page de validation était confuse (parcours 7/10).
        // On dit clairement l'action attendue AVANT d'afficher les détails techniques. ?>
  <aside class="what-to-do-box" role="region" aria-label="Que devez-vous faire ?">
    <span class="what-to-do-title">Que devez-vous faire ?</span>
    Vous devez <strong>valider</strong> ou <strong>refuser</strong> cette demande. Choisissez une action ci-dessous.
  </aside>

  <!-- ITER1-C / Action C.2 : « Progression du circuit » → « Avancement des étapes »
       (jargon « circuit » → français courant « étapes »). -->
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
          <span>Étape <?= (int)$ws['ordre'] ?> — <?= h($ws['label']) ?><?= $is_current ? ' (votre tour)' : '' ?><?= $all_done ? ' — validée' : '' ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Affichage des détails du formulaire -->
  <div class="validation-details">
    <h2>Détails du formulaire</h2>
    <p><strong>Dossier :</strong> <?= $nom ?></p>
    <p><strong>Étape :</strong> <?= h($data['step_label']) ?></p>
    <?php
      // Exclure UNIQUEMENT les champs validateur du step COURANT de "Détails"
      // (ils apparaissent en éditable plus bas dans "Informations à compléter").
      // Les champs validateur des steps PRÉCÉDENTS doivent être visibles ici.
      $current_step_field_names = [];
      $vf_list = get_form_validator_fields($data['form_id'], $data['step_id'] ?? null);
      foreach ($vf_list as $vf) {
          $current_step_field_names[] = $vf['field_name'];
      }
      $exclude_keys = array_merge(['validations', 'csrf_token'], $current_step_field_names);
    ?>
    <?= render_submission_data($d, $exclude_keys) ?>
  </div>

  <!-- Données saisies par les validateurs précédents -->
  <?php
    // Récupérer TOUTES les données validateur (tous steps confondus)
    $all_validator_data = get_submission_validator_data($data['submission_id'] ?? '');
    // Indexer par field_name
    $all_vd_by_field = [];
    foreach ($all_validator_data as $avd) {
        $all_vd_by_field[$avd['field_name']] = $avd;
    }
    // Récupérer les infos de tous les champs validateur du formulaire (tous steps)
    $all_validator_fields = get_form_validator_fields($data['form_id']);
    $field_labels = [];
    foreach ($all_validator_fields as $avf) {
        $field_labels[$avf['field_name']] = $avf['label'];
    }

    // Filtrer : ne montrer que les champs qui ont une valeur ET qui ne sont PAS
    // du step courant (ceux du step courant sont en éditable plus bas)
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
        $value = $pvd['value'] === '1' ? '✓ Oui' : h($pvd['value']);
        $step_lbl = h($pvd['step_label'] ?? '');
    ?>
      <p><strong><?= h(t_jargon($label)) ?>:</strong> <?= $value ?>
      <?php if ($step_lbl): ?><br><small style="color:#666;">Étape : <?= $step_lbl ?></small><?php endif; ?>
      </p>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Pièces jointes -->
  <?php
    $attachments = get_attachments($data['submission_id'] ?? '');
    // FILE-VISIBILITY : filtrer les pièces jointes dont le champ correspondant
    $visible_attachments = [];
    if (!empty($attachments)) {
        $owner_only_fields = [];
        $form_fields = get_form_fields((string)($data['form_id'] ?? ''));
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
      <p><?= get_file_icon($att['mime_type']) ?> <a href="index.php?p=download&id=<?= urlencode($att['id']) ?>" style="color:var(--c-primary-dark);text-decoration:underline;"><?= h($att['original_name']) ?></a> <span style="color:#595959;font-size:.85rem;">(<?= format_file_size((int)$att['file_size']) ?>)</span></p>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Formulaire de validation/refus (U-04 — Refus mobile frictionnel) -->
  <form method="post" id="validation-form">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= h((string)$token) ?>">

    <!-- Champs validateur (filled_by='validator') — Option A
         Bug #3 (P0-A) : ce bloc était rendu AVANT l'ouverture du <form>, donc
         les <input> générés par render_field() n'étaient jamais soumis. On le
         déplace à l'intérieur du <form>, juste après le token caché et avant
         les motifs de refus, pour que les valeurs saisies soient bien POSTées. -->
    <?php
      $validator_fields = get_form_validator_fields($data['form_id'], $data['step_id'] ?? null);
      $validator_data = get_submission_validator_data($data['submission_id'] ?? '', $data['step_id'] ?? null);
      // Indexer les données existantes par field_name pour lookup rapide
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
          // Préserver la saisie utilisateur en cas d'erreur de validation :
          // on priorise $_POST (valeurs que le validateur vient de saisir) sur
          // les données déjà enregistrées en base (valeurs d'une précédente validation).
          $existing_val = $_POST[$vf['field_name']]
              ?? $validator_data_index[$vf['field_name']]
              ?? '';
      ?>
        <div style="margin-bottom: 1rem;">
          <?php
              // render_field() génère déjà le <label>, l'<input>, le hint et le required.
              // NE PAS ajouter de label ou hint manuel en plus — sinon ils apparaissent en double.
              echo render_field($vf, $existing_val, [], '', false);
          ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Motifs de refus prédéfinis — radios touch-friendly (min 44px Apple HIG) -->
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

    <!-- Champ précisions complémentaires : reste le portenaire de $_POST['comment'] côté serveur.
         Toujours visible (pas de toggle display:none) pour la cible 40-60 ans.
         La valeur est préservée en cas d'erreur de validation pour éviter à l'utilisateur
         de retaper son commentaire. -->
    <div class="form-group">
      <label for="comment">Précisions complémentaires <span class="hint">(recommandé pour le refus, optionnel pour la validation)</span></label>
      <textarea id="comment" name="comment" rows="4" placeholder="Ex : il manque le justificatif de domicile de moins de 3 mois..."><?= h($_POST['comment'] ?? '') ?></textarea>
    </div>

    <!-- Boutons d'action : Valider (vert) + Confirmer le refus (rouge Marianne).
         "Confirmer le refus" est type=button : il ouvre le récapitulatif, ne soumet pas. -->
    <div class="submit-buttons">
      <button type="submit" name="action" value="valider" class="btn-validate"><span aria-hidden="true">✅</span> Valider</button>
      <button type="button" id="btn-show-refusal-recap" class="btn-refuse-confirm" aria-haspopup="dialog" aria-expanded="false" aria-controls="refusal-recap"><span aria-hidden="true">❌</span> Confirmer le refus</button>
    </div>

    <!-- Récapitulatif avant confirmation du refus (U-04).
         Caché par défaut (attribut hidden), role=alert pour annoncer aux lecteurs d'écran.
         tabindex=-1 pour permettre le focus programmatique. -->
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
echo render_page('Validation', 'mes_validations', $page_css, $content);
