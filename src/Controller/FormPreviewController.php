<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;
use App\Enum\FieldType;
use App\Enum\FilledBy;

/**
 * Contrôleur de la page Prévisualisation de formulaire.
 */
final class FormPreviewController extends BaseController
{
    public function handle(): void
    {
        App::auth()->requireAdmin();

        $formId = trim($_GET['form_id'] ?? '');

        $form = $this->formRepo->findById($formId);

        if (!$form) {
            (new \App\Render\ErrorRenderer())->errorPage(
                404,
                'Formulaire introuvable',
                'Le formulaire demandé n\'existe pas.',
                'Retournez au tableau de bord pour voir les formulaires disponibles.'
            );
        }

        $formFields = App::validatorData()->getFormFields($form['id'], FilledBy::Demandeur->value);

        $grouped = [];
        foreach ($formFields as $field) {
            $group = $field['card_group'] ?: 'Général';
            $grouped[$group][] = $field;
        }

        $workflowSteps = App::workflow()->getWorkflowSteps($form['id']);

        ob_start();
        ?>
  <div class="preview-banner"><span aria-hidden="true">👁</span> Mode prévisualisation — Ce formulaire n'est pas soumis, les données ne sont pas enregistrées <a href="index.php?p=admin_forms&form_id=<?= urlencode((string) ($form['id'] ?? '')) ?>" style="color:#b45309;font-size:.85rem;margin-left:1rem;"><span aria-hidden="true">⚙</span> Retour à l'édition</a></div>

  <h1><?= \App\Core\App::html()->escape($form['label']) ?></h1>
  <?php if ($form['description']): ?><p style="font-size:.85rem;color:#555;margin-bottom:2rem;"><?= \App\Core\App::html()->escape($form['description']) ?></p><?php endif; ?>
  <p style="font-size:.85rem;color:#555;margin-bottom:1.5rem;">Formulaire rempli par : <strong><?= \App\Core\App::html()->escape(App::auth()->getUser()) ?></strong></p>

  <?php if ($workflowSteps !== []): ?>
  <div class="workflow-preview">
    <h3>🔀 Circuit de validation qui sera suivi</h3>
    <div class="wf-flow">
      <?php foreach ($workflowSteps as $i => $ws):
          $emails = array_filter(explode('|', $ws['recipient_emails'] ?? ''));
          ?>
        <?php if ($i > 0): ?><span class="wf-arrow">→</span><?php endif; ?>
        <div class="wf-step">
          <div class="wf-step-label"><?= \App\Core\App::html()->escape($ws['step_label']) ?></div>
          <div class="wf-step-emails">
            <?php foreach ($emails as $email): ?>
              <span class="wf-step-email"><?= \App\Core\App::html()->escape($email) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($grouped !== []): ?>
  <form id="preview-form" style="pointer-events:none;">
    <?php foreach ($grouped as $groupName => $fields): ?>
    <div class="card" style="margin-bottom:1.5rem;">
      <h3 style="margin-bottom:1rem;"><?= \App\Core\App::html()->escape($groupName) ?></h3>
      <?php foreach ($fields as $field):
          $fieldName = \App\Core\App::html()->escape($field['field_name']);
          $fieldLabel = \App\Core\App::html()->escape($field['label']);
          $required = empty($field['required']) ? '' : 'required';
          $placeholder = empty($field['hint']) ? '' : \App\Core\App::html()->escape($field['hint']);
          ?>
      <div class="field">
        <label for="preview_<?= $fieldName ?>"><?= $fieldLabel ?> <?= empty($field['required']) ? '' : '<span style="color:#c0392b;">*</span>' ?></label>
        <?php if ($field['field_type'] === FieldType::Textarea->value): ?>
          <textarea id="preview_<?= $fieldName ?>" name="<?= $fieldName ?>" rows="3" <?= $required ?> placeholder="<?= $placeholder ?>"></textarea>
        <?php elseif ($field['field_type'] === FieldType::Select->value && !empty($field['options'])): ?>
          <select id="preview_<?= $fieldName ?>" name="<?= $fieldName ?>" <?= $required ?>>
            <option value="">— Sélectionner —</option>
            <?php foreach (is_array($field['options']) ? $field['options'] : (json_decode((string) $field['options'], true) ?: []) as $opt): ?>
              <option value="<?= \App\Core\App::html()->escape((string) $opt) ?>"><?= \App\Core\App::html()->escape((string) $opt) ?></option>
            <?php endforeach; ?>
          </select>
        <?php elseif ($field['field_type'] === FieldType::Checkbox->value): ?>
          <label class="checkbox-item">
            <input type="checkbox" id="preview_<?= $fieldName ?>" name="<?= $fieldName ?>" <?= $required ?>>
            <?= $fieldLabel ?>
          </label>
        <?php elseif ($field['field_type'] === FieldType::Date->value): ?>
          <input type="date" id="preview_<?= $fieldName ?>" name="<?= $fieldName ?>" <?= $required ?>>
        <?php else: ?>
          <input type="text" id="preview_<?= $fieldName ?>" name="<?= $fieldName ?>" <?= $required ?> placeholder="<?= $placeholder ?>">
        <?php endif; ?>
        <?php if (!empty($field['hint'])): ?>
          <span class="hint"><?= \App\Core\App::html()->escape($field['hint']) ?></span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </form>
  <?php else: ?>
    <p class="empty-state">Aucun champ configuré pour ce formulaire.</p>
  <?php endif; ?>
<?php
        $content = (string) ob_get_clean();
        echo $this->renderPage('Prévisualisation — ' . $form['label'], 'form_preview', '', $content);
    }
}
