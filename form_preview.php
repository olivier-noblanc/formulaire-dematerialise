<?php
// form_preview.php — Previsualisation du formulaire tel que l'agent le verra
require_once __DIR__ . '/helpers.php';

if (!is_admin_user() && !is_super_admin()) {
    header('Location: admin_access.php');
    exit;
}

$pdo = get_pdo();
$form_id = (int)($_GET['form_id'] ?? 0);

$form = $pdo->prepare("SELECT * FROM forms WHERE id = ?");
$form->execute([$form_id]);
$form = $form->fetch(PDO::FETCH_ASSOC);

if (!$form) { http_response_code(404); die('Formulaire introuvable.'); }

// Charger les champs
$fields_stmt = $pdo->prepare("SELECT * FROM form_fields WHERE form_id = ? ORDER BY ordre, id");
$fields_stmt->execute([$form['id']]);
$form_fields = $fields_stmt->fetchAll(PDO::FETCH_ASSOC);

// Regrouper par card_group
$grouped = [];
foreach ($form_fields as $field) {
    $group = $field['card_group'] ?: 'Général';
    $grouped[$group][] = $field;
}

// Charger les etapes du circuit de validation
$steps_stmt = $pdo->prepare("
    SELECT st.*, GROUP_CONCAT(sr.email, '|') as emails
    FROM steps st
    JOIN step_recipients sr ON sr.step_id = st.id
    WHERE st.form_id = ? AND st.actif = 1
    GROUP BY st.id
    ORDER BY st.ordre ASC, st.id ASC
");
$steps_stmt->execute([$form['id']]);
$workflow_steps = $steps_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Prévisualisation — <?= h($form['label']) ?></title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='15' fill='%23003189'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial'>D</text></svg>">
  <?php require_once __DIR__ . '/style.php'; ?>
  <style>
    .container { max-width: 780px; }
    .preview-banner { background: #fff3e0; border: 2px dashed #b45309; border-radius: 6px; padding: 1rem 1.5rem; margin-bottom: 2rem; text-align: center; color: #b45309; font-weight: bold; }
    fieldset.card { border: 1px solid #ddd; }
    legend { font-size: 1rem; color: #003189; border-bottom: 2px solid #003189; padding-bottom: .5rem; margin-bottom: 1.25rem; text-transform: uppercase; letter-spacing: .05em; width: 100%; }
    .field.full { grid-column: 1 / -1; }
    .btn-submit { background: #ccc; color: #888; border: none; padding: .75rem 2.5rem; font-size: 1rem; font-family: inherit; border-radius: 3px; cursor: not-allowed; display: block; margin: 0 auto; }
    .workflow-preview { background: #f0f4ff; border: 1px solid #003189; border-radius: 6px; padding: 1.25rem; margin-bottom: 2rem; }
    .workflow-preview h3 { color: #003189; margin-bottom: 1rem; font-size: 1rem; }
    .wf-flow { display: flex; align-items: flex-start; gap: .5rem; overflow-x: auto; padding-bottom: .5rem; }
    .wf-step-box { background: #003189; color: #fff; border-radius: 6px; padding: .75rem 1rem; min-width: 140px; text-align: center; flex-shrink: 0; }
    .wf-step-box .step-num { font-size: .75rem; opacity: .7; margin-bottom: .25rem; }
    .wf-step-box .step-title { font-weight: bold; font-size: .9rem; margin-bottom: .35rem; }
    .wf-step-box .step-emails { font-size: .7rem; opacity: .8; line-height: 1.4; }
    .wf-arrow { color: #003189; font-size: 1.5rem; font-weight: bold; flex-shrink: 0; padding-top: .75rem; }
    @media (max-width: 600px) { .grid-2 { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<div class="bandeau">
  <strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités
  <span>Connecté en tant que : <strong><?= h(get_auth_user()) ?></strong></span>
  <span><a href="admin_forms.php?form_id=<?= (int)$form['id'] ?>" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;">⚙ Retour à l'édition</a></span>
</div>
<div class="container">
  <div class="preview-banner">👁 Mode prévisualisation — Ce formulaire n'est pas soumis, les données ne sont pas enregistrées</div>

  <h1><?= h($form['label']) ?></h1>
  <?php if ($form['description']): ?><p style="font-size:.85rem;color:#555;margin-bottom:2rem;"><?= h($form['description']) ?></p><?php endif; ?>
  <p style="font-size:.85rem;color:#555;margin-bottom:1.5rem;">Formulaire rempli par : <strong><?= h(get_auth_user()) ?></strong></p>

  <?php if (!empty($workflow_steps)): ?>
  <div class="workflow-preview">
    <h3>🔀 Circuit de validation qui sera suivi</h3>
    <div class="wf-flow">
      <?php foreach ($workflow_steps as $i => $ws):
        $emails = array_filter(explode('|', $ws['emails'] ?? ''));
      ?>
        <?php if ($i > 0): ?><span class="wf-arrow">→</span><?php endif; ?>
        <div class="wf-step-box">
          <div class="step-num">Étape <?= (int)$ws['ordre'] ?></div>
          <div class="step-title"><?= h($ws['label']) ?></div>
          <div class="step-emails"><?= implode('<br>', array_map('h', $emails)) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <form onsubmit="return false;">
    <?php if (empty($grouped)): ?>
      <p style="text-align:center;padding:2rem;color:#888;font-style:italic;">Aucun champ configuré pour ce formulaire.</p>
    <?php else: ?>
      <?php foreach ($grouped as $card_title => $card_fields):
        // Séparer checkboxes des autres
        $checkboxes = [];
        $non_checkboxes = [];
        foreach ($card_fields as $cf) {
            if ($cf['field_type'] === 'checkbox') $checkboxes[] = $cf;
            else $non_checkboxes[] = $cf;
        }
      ?>
        <fieldset class="card">
          <legend><?= h($card_title) ?></legend>
          <?php if (!empty($non_checkboxes)): ?>
            <div class="grid-2">
              <?php foreach ($non_checkboxes as $cf):
                $req = $cf['required'] ? ' <span class="req">*</span>' : '';
                $req_attr = ($cf['required'] && $cf['field_type'] !== 'checkbox') ? ' required' : '';
                $disabled = 'disabled';
              ?>
                <?php if ($cf['field_type'] === 'date'): ?>
                  <div class="field"><label><?= h($cf['label']) ?><?= $req ?></label><input type="date" <?= $disabled ?>><span class="hint">Format : JJ/MM/AAAA</span></div>
                <?php elseif ($cf['field_type'] === 'select'):
                  $opts = json_decode($cf['options'] ?? '[]', true) ?: [];
                ?>
                  <div class="field"><label><?= h($cf['label']) ?><?= $req ?></label><select <?= $disabled ?>><option>— Sélectionner —</option><?php foreach ($opts as $o): ?><option><?= h($o) ?></option><?php endforeach; ?></select></div>
                <?php elseif ($cf['field_type'] === 'textarea'): ?>
                  <div class="field full"><label><?= h($cf['label']) ?><?= $req ?></label><textarea <?= $disabled ?> rows="3"></textarea></div>
                <?php else: ?>
                  <div class="field"><label><?= h($cf['label']) ?><?= $req ?></label><input type="text" <?= $disabled ?> placeholder="<?= h($cf['label']) ?>"></div>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if (!empty($checkboxes)): ?>
            <div class="checkboxes"<?php if (!empty($non_checkboxes)) echo ' style="margin-top:1rem;"'; ?>>
              <?php foreach ($checkboxes as $cf): ?>
                <label class="checkbox-item"><input type="checkbox" disabled> <?= h($cf['label']) ?></label>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </fieldset>
      <?php endforeach; ?>
      <button type="button" class="btn-submit">Envoyer la déclaration (désactivé — prévisualisation)</button>
    <?php endif; ?>
  </form>
</div>
<?= render_footer() ?>
</body>
</html>
