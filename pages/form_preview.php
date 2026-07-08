<?php
// form_preview.php — Previsualisation du formulaire tel que l'agent le verra
require_once dirname(__DIR__) . '/helpers.php';
use App\Core\App;

App::auth()->requireAdmin();

$pdo = \App\Core\App::db()->getPdo();
$form_id = trim($_GET['form_id'] ?? '');

$form = $pdo->prepare("SELECT * FROM forms WHERE id = ?");
$form->execute([$form_id]);
$form = $form->fetch(PDO::FETCH_ASSOC);

if (!$form) {
    render_error_page(404, 'Formulaire introuvable',
        'Le formulaire demandé n\'existe pas.',
        'Retournez au tableau de bord pour voir les formulaires disponibles.');
}

// Charger les champs (preview = ce que le demandeur verra → exclure filled_by='validator')
$form_fields = get_form_fields($form['id'], 'demandeur');

// Regrouper par card_group
$grouped = [];
foreach ($form_fields as $field) {
    $group = $field['card_group'] ?: 'Général';
    $grouped[$group][] = $field;
}

// Charger les etapes du circuit de validation
$workflow_steps = get_workflow_steps($form['id']);
?>
<?php
$page_css = '';
ob_start();
?>
  <div class="preview-banner"><span aria-hidden="true">👁</span> Mode prévisualisation — Ce formulaire n'est pas soumis, les données ne sont pas enregistrées <a href="index.php?p=admin_forms&form_id=<?= urlencode($form['id']) ?>" style="color:#b45309;font-size:.85rem;margin-left:1rem;"><span aria-hidden="true">⚙</span> Retour à l'édition</a></div>

  <h1><?= h($form['label']) ?></h1>
  <?php if ($form['description']): ?><p style="font-size:.85rem;color:#555;margin-bottom:2rem;"><?= h($form['description']) ?></p><?php endif; ?>
  <p style="font-size:.85rem;color:#555;margin-bottom:1.5rem;">Formulaire rempli par : <strong><?= h(App::auth()->getUser()) ?></strong></p>

  <?php if (!empty($workflow_steps)): ?>
  <div class="workflow-preview">
    <h3>🔀 Circuit de validation qui sera suivi</h3>
    <div class="wf-flow">
      <?php foreach ($workflow_steps as $i => $ws):
        $emails = array_filter(explode('|', $ws['recipient_emails'] ?? ''));
      ?>
        <?php if ($i > 0): ?><span class="wf-arrow">→</span><?php endif; ?>
        <div class="wf-step-box">
          <div class="step-num">Étape <?= (int)$ws['ordre'] ?></div>
          <div class="step-title"><?= h($ws['step_label']) ?></div>
          <div class="step-emails"><?= implode('<br>', array_map('h', $emails)) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <form>
    <?php if (empty($grouped)): ?>
      <p style="text-align:center;padding:2rem;color:#595959;font-style:italic;">Aucun champ configuré pour ce formulaire.</p>
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
              <?php foreach ($non_checkboxes as $cf): ?>
                <?= render_field($cf, null, [], '', true) ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if (!empty($checkboxes)): ?>
            <div class="checkboxes"<?php if (!empty($non_checkboxes)) echo ' style="margin-top:1rem;"'; ?>>
              <?php foreach ($checkboxes as $cf): ?>
                <?= render_field($cf, null, [], '', true) ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </fieldset>
      <?php endforeach; ?>
      <button type="button" class="btn-submit" disabled>Envoyer la déclaration (désactivé — prévisualisation)</button>
    <?php endif; ?>
  </form>
<?php
$content = (string)ob_get_clean();
echo render_page('Prévisualisation — ' . h($form['label']), 'forms', $page_css, $content, ['raw_title' => true]);
