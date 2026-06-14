<?php
// form.php?f=onboarding — affiche et traite le formulaire d'un slug donné
require_once __DIR__ . '/helpers.php';

$pdo  = get_pdo();
$slug = trim($_GET['f'] ?? '');

$form = $pdo->prepare("SELECT * FROM forms WHERE slug = ? AND actif = 1");
$form->execute([$slug]);
$form = $form->fetch(PDO::FETCH_ASSOC);

if (!$form) {
    http_response_code(404);
    if (TEST_MODE) { test_json_response(['error' => 'Formulaire introuvable', 'slug' => $slug]); }
    die('<p>Formulaire introuvable.</p>');
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
        die('Token CSRF invalide. Veuillez réessayer.');
    }

    // Validation dynamique des champs obligatoires
    foreach ($form_fields as $field) {
        if ($field['required'] && $field['field_type'] !== 'checkbox') {
            if (empty(trim($_POST[$field['field_name']] ?? ''))) {
                $field_errors[$field['field_name']] = 'Ce champ est obligatoire';
            }
        }
    }

    if (empty($field_errors)) {
        $now  = date('Y-m-d H:i:s');
        $data = [];
        foreach ($_POST as $k => $v) {
            $data[htmlspecialchars($k)] = is_array($v) ? implode(', ', $v) : trim($v);
        }

        $pdo->prepare("INSERT INTO submissions (form_id, data, submitted_by, submitted_at) VALUES (?,?,?,?)")
            ->execute([$form['id'], json_encode($data, JSON_UNESCAPED_UNICODE), $submitted_by, $now]);

        $submission_id = (int)$pdo->lastInsertId();
        advance_workflow($submission_id);

        // Envoyer un email de confirmation à l'agent
        $confirm_subject = 'Demande enregistrée — ' . $form['label'];
        $confirm_body = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;color:#222;">
  <h2 style="color:#003189;">✓ Demande enregistrée</h2>
  <p>Votre demande <strong>' . h($form['label']) . '</strong> a bien été enregistrée le ' . h(date('d/m/Y à H:i')) . '.</p>
  <p>Le workflow de validation a été déclenché. Vous serez notifié par email lorsque votre demande sera traitée ou si un refus est émis.</p>
  <p style="font-size:12px;color:#999;margin-top:24px;">Workflow DREETS — Ne pas répondre à cet email</p>
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

    switch ($field['field_type']) {
        case 'date':
            $val = h($posted_val ?? '');
            return <<<HTML
<div class="field"><label for="{$name}">{$label}{$req_span}</label><input type="date" id="{$name}" name="{$name}"{$required_attr}{$aria_attr} class="{$error_class}" value="{$val}"><span class="hint">Format : JJ/MM/AAAA</span>{$error_html}</div>
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
<div class="field"><label for="{$name}">{$label}{$req_span}</label><select id="{$name}" name="{$name}"{$required_attr}{$aria_attr} class="{$error_class}">{$options_html}</select>{$error_html}</div>
HTML;

        case 'checkbox':
            $checked = !empty($posted_val) ? ' checked' : '';
            return <<<HTML
<label class="checkbox-item"><input type="checkbox" name="{$name}" value="1"{$checked}> {$label}</label>
HTML;

        case 'textarea':
            $val = h($posted_val ?? '');
            return <<<HTML
<div class="field full"><label for="{$name}">{$label}{$req_span}</label><textarea id="{$name}" name="{$name}"{$required_attr}{$aria_attr} class="{$error_class}">{$val}</textarea>{$error_html}</div>
HTML;

        default: // text
            $val = h($posted_val ?? '');
            return <<<HTML
<div class="field"><label for="{$name}">{$label}{$req_span}</label><input type="text" id="{$name}" name="{$name}"{$required_attr}{$aria_attr} class="{$error_class}" value="{$val}">{$error_html}</div>
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
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='15' fill='%23003189'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial'>D</text></svg>">
  <?php require_once __DIR__ . '/style.php'; ?>
  <style>
    /* Overrides */
    body { padding: 2rem 1rem; }
    .container { max-width: 780px; padding: 0; }
    h1 { font-size: 1.5rem; margin-bottom: .25rem; }

    /* Page-specific */
    .agent-info { font-size: .85rem; color: #555; margin-bottom: 2rem; }
    fieldset.card { border: 1px solid #ddd; }
    legend { font-size: 1rem; color: #003189; border-bottom: 2px solid #003189; padding-bottom: .5rem; margin-bottom: 1.25rem; text-transform: uppercase; letter-spacing: .05em; width: 100%; }
    .field.full { grid-column: 1 / -1; }
    .btn-submit { background: #003189; color: #fff; border: none; padding: .75rem 2.5rem; font-size: 1rem; font-family: inherit; border-radius: 3px; cursor: pointer; display: block; margin: 0 auto; }
    .btn-submit:hover { background: #002270; }
    .no-fields { text-align: center; padding: 2rem; color: #888; font-style: italic; }
    @media (max-width: 600px) { .grid-2 { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<a href="#main-content" class="skip-link">Aller au contenu principal</a>
<div class="bandeau"><strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités <span>Connecté en tant que : <strong><?= h(get_auth_user()) ?></strong></span> <span><a href="my_validations.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;">✅ Mes validations</a> <a href="my_submissions.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;margin-left:8px;">📋 Mes demandes</a> <a href="docs.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;margin-left:8px;">📖 Documentation</a><?php if (is_admin_user()): ?> <a href="admin_settings.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;margin-left:8px;">⚙ Paramètres</a><?php endif; ?></span></div>
<div class="container" id="main-content">
  <h1><?= h($form['label']) ?></h1>
  <?php if ($form['description']): ?><p class="agent-info"><?= h($form['description']) ?></p><?php endif; ?>
  <p class="agent-info">Formulaire rempli par : <strong><?= h($submitted_by) ?></strong></p>

  <?php if ($existing_submission && !$success): ?>
    <div class="warn-box">
      <p><strong>⚠ Attention :</strong> Vous avez déjà une demande en cours pour ce formulaire (soumise le <?= h(date('d/m/Y à H:i', strtotime($existing_submission['submitted_at']))) ?>).</p>
      <p>Vous pouvez tout de même soumettre une nouvelle demande si nécessaire.</p>
      <p><a href="submission_view.php?id=<?= (int)$existing_submission['id'] ?>" style="color:#b45309;font-weight:bold;">Voir la demande existante →</a></p>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="success">
      <strong>✓ Demande enregistrée</strong>
      Le workflow de validation a été déclenché automatiquement. Un email de confirmation vous a été envoyé.
    </div>
  <?php else: ?>
    <?php if (!empty($field_errors)): ?>
      <div class="errors"><strong>Veuillez corriger les champs suivants :</strong><ul><?php foreach ($field_errors as $fn => $fe): ?><li><?= h($field_labels[$fn] ?? $fn) ?> : <?= h($fe) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form method="POST" novalidate>
      <?= csrf_field() ?>

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
        <button type="submit" class="btn-submit">Envoyer la déclaration</button>
      <?php endif; ?>
    </form>
  <?php endif; ?>
</div>

<?= render_footer() ?>
</body>
</html>
