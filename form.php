<?php
// form.php?f=onboarding — affiche et traite le formulaire d'un slug donné
require_once __DIR__ . '/helpers.php';

$pdo  = get_pdo();
$slug = trim($_GET['f'] ?? '');

$form = $pdo->prepare("SELECT * FROM forms WHERE slug = ? AND actif = 1");
$form->execute([$slug]);
$form = $form->fetch(PDO::FETCH_ASSOC);

if (!$form) {
    if (TEST_MODE) { test_json_response(['error' => 'Formulaire introuvable', 'slug' => $slug]); }
    render_error_page(404, 'Formulaire introuvable',
        'Le formulaire demandé n\'existe pas ou a été désactivé.',
        'Vérifiez l\'adresse dans votre navigateur. Vous pouvez retourner à l\'accueil pour voir les formulaires disponibles.');
}

$submitted_by = get_auth_user();
$field_errors = [];
$success      = false;

// Vérifier si l'agent a déjà une soumission en cours pour ce formulaire
$existing_stmt = $pdo->prepare("SELECT id, submitted_at FROM submissions WHERE form_id = ? AND submitted_by = ? AND status = 'en_cours' ORDER BY submitted_at DESC LIMIT 1");
$existing_stmt->execute([$form['id'], $submitted_by]);
$existing_submission = $existing_stmt->fetch(PDO::FETCH_ASSOC);

// Charger les champs dynamiques du formulaire, ordonnés par ordre
$fields_stmt = $pdo->prepare("SELECT * FROM form_fields WHERE form_id = ? ORDER BY ordre, id");
$fields_stmt->execute([$form['id']]);
$form_fields = $fields_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        if (TEST_MODE) { test_json_response(['error' => 'CSRF invalide', 'http_code' => 403]); }
        render_error_page(403, 'Requête invalide', 'Le jeton de sécurité (CSRF) de votre session est invalide ou a expiré. Cela peut arriver si votre session a été inactive trop longtemps ou si la page est restée ouverte depuis longtemps.', 'Rechargez la page et réessayez. Si le problème persiste, fermez tous les onglets de l\'application et reconnectez-vous.');
    }

    // Validation dynamique des champs obligatoires
    foreach ($form_fields as $field) {
        if ($field['required'] && $field['field_type'] !== 'checkbox') {
            if (empty(trim($_POST[$field['field_name']] ?? ''))) {
                $field_errors[$field['field_name']] = 'Ce champ est obligatoire';
            }
        }
    }

    // Validation des fichiers uploades
    $file_errors = [];
    foreach ($form_fields as $field) {
        if ($field['field_type'] === 'file') {
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

    if (empty($field_errors) && empty($file_errors)) {
        $now  = date('Y-m-d H:i:s');
        $data = [];
        foreach ($_POST as $k => $v) {
            $data[htmlspecialchars($k)] = is_array($v) ? implode(', ', $v) : trim($v);
        }

        // Ajouter les noms de fichiers uploades dans les donnees
        foreach ($form_fields as $field) {
            if ($field['field_type'] === 'file') {
                $fname = $field['field_name'];
                if (!empty($_FILES[$fname]['name'])) {
                    $data[$fname] = $_FILES[$fname]['name'];
                }
            }
        }

        $rgpd_consent = !empty($_POST['rgpd_consent']) ? 1 : 0;
        $submission_id = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, rgpd_consent) VALUES (?,?,?,?,?,?)")
            ->execute([$submission_id, $form['id'], json_encode($data, JSON_UNESCAPED_UNICODE), $submitted_by, $now, $rgpd_consent]);

        // Traiter les fichiers uploades
        foreach ($form_fields as $field) {
            if ($field['field_type'] === 'file') {
                $fname = $field['field_name'];
                if (!empty($_FILES[$fname]['name']) && $_FILES[$fname]['error'] !== UPLOAD_ERR_NO_FILE) {
                    $upload_result = handle_file_upload($_FILES[$fname], $submission_id, $fname);
                    if (!$upload_result['success']) {
                        $file_errors[$fname] = $upload_result['message'];
                    }
                }
            }
        }

        advance_workflow($submission_id);

        // Envoyer un email de confirmation à l'agent
        $confirm_subject = 'Demande enregistrée — ' . $form['label'];
        $confirm_body = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;color:#222;">
  <h2 style="color:#003189;">✓ Demande enregistrée</h2>
  <p>Votre demande <strong>' . h($form['label']) . '</strong> a bien été enregistrée le ' . h(date('d/m/Y à H:i')) . '.</p>
  <p>Le workflow de validation a été déclenché. Vous serez notifié par email lorsque votre demande sera traitée ou si un refus est émis.</p>
  <p style="font-size:12px;color:#999;margin-top:24px;">FluxDREETS — Ne pas répondre à cet email</p>
</body></html>';
        send_mail($submitted_by, $confirm_subject, $confirm_body);

        $success = true;

        // Mode test : renvoyer JSON au lieu du HTML
        if (TEST_MODE) {
            // Récupérer les tokens générés
            $tok_stmt = $pdo->prepare("SELECT t.id, t.step_id, t.email, t.token, t.sent_at, st.label as step_label, st.ordre FROM tokens t JOIN steps st ON st.id = t.step_id WHERE t.submission_id = ? ORDER BY st.ordre");
            $tok_stmt->execute([$submission_id]);
            $generated_tokens = $tok_stmt->fetchAll(PDO::FETCH_ASSOC);
            test_json_response([
                'success'        => true,
                'submission_id'  => $submission_id,
                'form_slug'      => $slug,
                'form_label'     => $form['label'],
                'submitted_by'   => $submitted_by,
                'data'           => $data,
                'tokens'         => $generated_tokens,
                'mails_count'    => count($GLOBALS['_test_mails']),
            ]);
        }
    } elseif (TEST_MODE) {
        // Erreurs de validation en mode test
        test_json_response(['error' => 'Erreurs de validation', 'field_errors' => $field_errors]);
    }
}

// Mode test : GET renvoie les métadonnées du formulaire en JSON
if (TEST_MODE && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    $fields_list = [];
    foreach ($form_fields as $f) {
        $fields_list[] = [
            'field_name' => $f['field_name'],
            'label'      => $f['label'],
            'field_type' => $f['field_type'],
            'required'   => (bool)$f['required'],
            'options'    => $f['options'] ? json_decode($f['options'], true) : null,
            'card_group' => $f['card_group'],
        ];
    }
    echo json_encode([
        '_test_mode' => true,
        'form'       => ['id' => $form['id'], 'slug' => $form['slug'], 'label' => $form['label'], 'description' => $form['description']],
        'fields'     => $fields_list,
        'csrf_token' => generate_csrf_token(),
        'submitted_by' => $submitted_by,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Regrouper les champs par card_group pour le rendu visuel
$grouped = [];
$field_labels = [];
foreach ($form_fields as $field) {
    $group = $field['card_group'] ?: 'Général';
    $grouped[$group][] = $field;
    $field_labels[$field['field_name']] = $field['label'];
}

/**
 * Rend un champ dynamique en HTML avec support aria pour les erreurs
 */
function render_field(array $field, mixed $posted_val, array $field_errors): string {
    $name = h($field['field_name']);
    $label = h($field['label']);
    $req_span = $field['required'] ? ' <span class="req">*</span>' : '';
    $required_attr = ($field['required'] && $field['field_type'] !== 'checkbox') ? ' required aria-required="true"' : '';
    $error_class = isset($field_errors[$field['field_name']]) ? ' field-error' : '';
    $aria_attr = '';
    if (isset($field_errors[$field['field_name']])) {
        $aria_attr = ' aria-invalid="true" aria-describedby="err-' . $name . '"';
    }
    $error_html = '';
    if (isset($field_errors[$field['field_name']])) {
        $error_html = '<span id="err-' . $name . '" class="error-hint">' . h($field_errors[$field['field_name']]) . '</span>';
    }

    // Hint textuel depuis la base (colonne hint de form_fields)
    $hint = !empty($field['hint']) ? '<span class="hint">' . h($field['hint']) . '</span>' : '';

    // Détection automatique du type HTML5 basée sur le field_name
    $fn_lower = mb_strtolower($field['field_name'], 'UTF-8');
    $html5_type = 'text'; // défaut
    $html5_extra = '';
    
    if (strpos($fn_lower, 'email') !== false || strpos($fn_lower, 'courriel') !== false || strpos($fn_lower, 'mel') !== false) {
        $html5_type = 'email';
        $html5_extra = ' pattern="[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$"';
    } elseif (strpos($fn_lower, 'tel') !== false || strpos($fn_lower, 'telephone') !== false || strpos($fn_lower, 'portable') !== false || strpos($fn_lower, 'mobile') !== false) {
        $html5_type = 'tel';
        $html5_extra = ' pattern="[0-9+\s\-.]{6,20}"';
    } elseif (strpos($fn_lower, 'montant') !== false || strpos($fn_lower, 'cout') !== false || strpos($fn_lower, 'prix') !== false || strpos($fn_lower, 'salaire') !== false || strpos($fn_lower, 'nombre_jour') !== false || strpos($fn_lower, 'quantite') !== false) {
        $html5_type = 'number';
        $html5_extra = ' step="0.01" min="0"';
    } elseif (strpos($fn_lower, 'heure') !== false) {
        $html5_type = 'time';
    } elseif (strpos($fn_lower, 'url') !== false || strpos($fn_lower, 'lien') !== false || strpos($fn_lower, 'site') !== false) {
        $html5_type = 'url';
    }

    switch ($field['field_type']) {
        case 'date':
            $val = h($posted_val ?? '');
            return <<<HTML
<div class="field"><label for="{$name}">{$label}{$req_span}</label><input type="date" id="{$name}" name="{$name}"{$required_attr}{$aria_attr} class="{$error_class}" value="{$val}"><span class="hint">Format : JJ/MM/AAAA</span>{$hint}{$error_html}</div>
HTML;

        case 'select':
            $opts_raw = $field['options'] ?? '[]';
            $opts = json_decode($opts_raw, true) ?: [];
            $options_html = '<option value="">— Sélectionner —</option>';
            foreach ($opts as $opt) {
                $sel = ($posted_val === $opt) ? ' selected' : '';
                $options_html .= '<option value="' . h($opt) . '"' . $sel . '>' . h($opt) . '</option>';
            }
            return <<<HTML
<div class="field"><label for="{$name}">{$label}{$req_span}</label><select id="{$name}" name="{$name}"{$required_attr}{$aria_attr} class="{$error_class}">{$options_html}</select>{$hint}{$error_html}</div>
HTML;

        case 'checkbox':
            $checked = !empty($posted_val) ? ' checked' : '';
            return <<<HTML
<label class="checkbox-item"><input type="checkbox" name="{$name}" value="1"{$checked}> {$label}</label>
HTML;

        case 'textarea':
            $val = h($posted_val ?? '');
            $maxlength = ' maxlength="5000"';
            return <<<HTML
<div class="field full"><label for="{$name}">{$label}{$req_span}</label><textarea id="{$name}" name="{$name}"{$required_attr}{$aria_attr} class="{$error_class}" placeholder=""{$maxlength}>{$val}</textarea>{$hint}{$error_html}</div>
HTML;

        case 'file':
            $accept = implode(',', array_map(function($ext) { return '.' . $ext; }, get_allowed_extensions()));
            $max_size_mo = round(get_max_file_size() / 1048576, 0);
            return <<<HTML
<div class="field"><label for="{$name}">{$label}{$req_span}</label><input type="file" id="{$name}" name="{$name}"{$required_attr}{$aria_attr} class="{$error_class}" accept="{$accept}"><span class="hint">Formats acceptés : PDF, images, Office, ZIP — Max {$max_size_mo} Mo</span>{$hint}{$error_html}</div>
HTML;

        default: // text — avec détection HTML5 automatique
            $val = h($posted_val ?? '');
            $maxlength = ' maxlength="500"';
            return <<<HTML
<div class="field"><label for="{$name}">{$label}{$req_span}</label><input type="{$html5_type}" id="{$name}" name="{$name}"{$required_attr}{$aria_attr}{$html5_extra} class="{$error_class}" value="{$val}"{$maxlength}>{$hint}{$error_html}</div>
HTML;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($form['label']) ?> — DREETS</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><defs><linearGradient id='g' x1='0' y1='0' x2='1' y2='1'><stop offset='0%25' stop-color='%231E40AF'/><stop offset='100%25' stop-color='%233B82F6'/></linearGradient></defs><rect width='100' height='100' rx='20' fill='url(%23g)'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial' font-weight='bold'>D</text></svg>">
  <?php require_once __DIR__ . '/style.php'; ?>
  <style>
    /* Overrides */
    .container { max-width: 800px; padding: 0; }
    h1 { font-size: var(--text-2xl); margin-bottom: .25rem; }

    /* Page-specific */
    .agent-info { font-size: var(--text-sm); color: var(--c-text-secondary); margin-bottom: 2rem; }
    fieldset.card { border: 1px solid var(--c-border-light); box-shadow: var(--shadow-sm); }
    legend {
      font-size: var(--text-sm);
      color: var(--c-primary-dark);
      border-bottom: 2px solid var(--c-primary-50);
      padding-bottom: var(--sp-sm);
      margin-bottom: 1.25rem;
      text-transform: uppercase;
      letter-spacing: .06em;
      width: 100%;
      font-weight: 700;
    }
    .field.full { grid-column: 1 / -1; }
    .btn-submit {
      background: var(--gradient-primary);
      color: #fff;
      border: none;
      padding: .85rem 2.5rem;
      font-size: var(--text-lg);
      font-family: inherit;
      font-weight: 700;
      border-radius: var(--r-full);
      cursor: pointer;
      display: block;
      margin: 0 auto;
      box-shadow: var(--shadow-md), var(--shadow-colored);
      transition: all var(--duration-fast) var(--ease-out);
    }
    .btn-submit:hover { background: var(--gradient-primary-hover); box-shadow: var(--shadow-lg), var(--shadow-colored); }
    .btn-submit:active { transform: scale(.97); }
    .no-fields { text-align: center; padding: 2rem; color: var(--c-text-tertiary); font-style: italic; }
    @media (max-width: 600px) { .grid-2 { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<a href="#main-content" class="skip-link">Aller au contenu principal</a>
<?= render_nav('forms') ?>
<main class="container" id="main-content">
<?= render_breadcrumb([['Accueil', 'index.php'], ['Mes demandes', 'my_submissions.php'], [$form_label ?? 'Formulaire']]) ?>
  <h1><?= h($form['label']) ?></h1>
  <?php if ($form['description']): ?><p class="agent-info"><?= h($form['description']) ?></p><?php endif; ?>
  <p class="agent-info">Formulaire rempli par : <strong><?= h($submitted_by) ?></strong></p>

  <?php if ($existing_submission && !$success): ?>
    <div class="warn-box">
      <p><strong><span aria-hidden="true">⚠</span> Attention :</strong> Vous avez déjà une demande en cours pour ce formulaire (soumise le <?= h(date('d/m/Y à H:i', strtotime($existing_submission['submitted_at']))) ?>).</p>
      <p>Vous pouvez tout de même soumettre une nouvelle demande si nécessaire.</p>
      <p><a href="submission_view.php?id=<?= urlencode($existing_submission['id']) ?>" style="color:#b45309;font-weight:bold;">Voir la demande existante →</a></p>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="success">
      <strong><span aria-hidden="true">✓</span> Demande enregistrée</strong>
      Le workflow de validation a été déclenché automatiquement. Un email de confirmation vous a été envoyé.
    </div>
    <div style="margin-top:1.5rem;display:flex;gap:.5rem;justify-content:center;">
      <a href="submission_view.php?id=<?= urlencode($submission_id) ?>" class="btn btn-primary">Voir ma demande</a>
      <a href="my_submissions.php" class="btn btn-secondary">Mes demandes</a>
      <a href="index.php" class="btn btn-secondary">Accueil</a>
    </div>
  <?php else: ?>
    <?php if (!empty($field_errors) || !empty($file_errors)): ?>
      <div class="errors"><strong>Veuillez corriger les champs suivants :</strong><ul><?php foreach ($field_errors as $fn => $fe): ?><li><?= h($field_labels[$fn] ?? $fn) ?> : <?= h($fe) ?></li><?php endforeach; ?><?php foreach ($file_errors as $fn => $fe): ?><li><?= h($field_labels[$fn] ?? $fn) ?> : <?= h($fe) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="MAX_FILE_SIZE" value="<?= get_max_file_size() ?>">

      <?php if (empty($grouped)): ?>
        <p class="no-fields">Aucun champ configuré pour ce formulaire. Contactez un administrateur.</p>
      <?php else: ?>
        <?php foreach ($grouped as $card_title => $card_fields): ?>
          <?php
          // Séparer les checkboxes des autres champs pour le rendu
          $checkboxes = [];
          $non_checkboxes = [];
          foreach ($card_fields as $cf) {
              if ($cf['field_type'] === 'checkbox') {
                  $checkboxes[] = $cf;
              } else {
                  $non_checkboxes[] = $cf;
              }
          }
          ?>
          <fieldset class="card">
            <legend><?= h($card_title) ?></legend>
            <?php if (!empty($non_checkboxes)): ?>
              <div class="grid-2">
                <?php foreach ($non_checkboxes as $cf): ?>
                  <?= render_field($cf, $_POST[$cf['field_name']] ?? null, $field_errors) ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if (!empty($checkboxes)): ?>
              <div class="checkboxes"<?php if (!empty($non_checkboxes)) echo ' style="margin-top:1rem;"'; ?>>
                <?php foreach ($checkboxes as $cf): ?>
                  <?= render_field($cf, $_POST[$cf['field_name']] ?? null, $field_errors) ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </fieldset>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if (!empty($grouped)): ?>
        <div class="card" style="background:#f8f8ff;border-color:#003189;">
          <label class="checkbox-item" style="font-size:.85rem;line-height:1.5;">
            <input type="checkbox" name="rgpd_consent" value="1" required aria-required="true">
            J'accepte le traitement de mes données personnelles dans le cadre de cette procédure.
          </label>
          <p style="font-size:.75rem;color:#595959;margin-top:.5rem;margin-left:1.7rem;">
            <?= h(get_setting('legal_mentions', 'Les données collectées sont traitées dans le cadre de la dématérialisation des procédures internes de la DREETS. Conformément au RGPD, vous disposez d\'un droit d\'accès, de rectification et d\'effacement de vos données. Durée de conservation : 24 mois après clôture.')) ?>
          </p>
        </div>
        <button type="submit" class="btn-submit">Envoyer la déclaration</button>
      <?php endif; ?>
    </form>
  <?php endif; ?>
</main>

<?= render_footer() ?>
</body>
</html>
