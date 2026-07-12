<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;
use App\Controller\AdminFormsHandlers;

/**
 * Contrôleur de la page Gestion des formulaires (admin).
 */
final class AdminFormsController extends BaseController
{
    public function handle(): void
    {
        App::auth()->requireAdmin();

        $pdo = $this->db->getPdo();
        $forms = _dbm_q($pdo, "SELECT id, label FROM forms ORDER BY label")->fetchAll(\PDO::FETCH_ASSOC);

        $formId = trim($_GET['form_id'] ?? '');
        $editStepId = trim($_GET['edit_step'] ?? '');
        $editFieldId = trim($_GET['edit_field'] ?? '');

        try {
            if ($formId) $formId = validate_input($formId, 'uuid');
            if ($editStepId) $editStepId = validate_input($editStepId, 'uuid');
            if ($editFieldId) $editFieldId = validate_input($editFieldId, 'uuid');
        } catch (\InvalidArgumentException $e) {
            App::audit()->securityLog('invalid_admin_forms_id', 'form_id=' . substr((string)$formId, 0, 20) . ' edit_step=' . substr((string)$editStepId, 0, 20) . ' edit_field=' . substr((string)$editFieldId, 0, 20));
            (new \App\Render\ErrorRenderer())->errorPage(400, 'Paramètre invalide', 'Un des identifiants fournis est invalide.', 'Vérifiez l\'URL et réessayez.');
        }

        $action = $_POST['action'] ?? '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->security->requireCsrf();
        }

        $errorMsg       = '';
        $successMsg     = '';
        $validationHtml = '';
        $preservedJson  = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' || $action) {
            $result = AdminFormsHandlers::dispatch($pdo, $action, (string)$formId);
            if ($result !== null) {
                if (isset($result['json_output']) && isset($result['filename'])) {
                    header('Content-Type: application/json; charset=utf-8');
                    header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
                    echo $result['json_output'];
                    exit;
                }
                if (isset($result['redirect'])) {
                    header('Location: ' . $result['redirect']);
                    exit;
                }
                if (isset($result['error']))           $errorMsg       = $result['error'];
                if (isset($result['success']))         $successMsg     = $result['success'];
                if (isset($result['validation_html'])) $validationHtml = $result['validation_html'];
                if (isset($result['preserved_json']))  $preservedJson  = $result['preserved_json'];
                if (isset($result['form_id']))         $formId         = $result['form_id'];
            }
        }

        $selectedForm = null;
        $formFields = [];
        $workflowSteps = [];

        if ($formId) {
            $selectedForm = App::getInstance()->get(\App\Repository\FormRepository::class)->findById($formId);

            if ($selectedForm) {
                $formFields = App::validatorData()->getFormFields($formId);
                $workflowSteps = App::workflow()->getWorkflowSteps($formId);
            }
        }

        $pageCss = '';
        ob_start();
        ?>
  <h1><span aria-hidden="true">⚙</span> Gestion des formulaires</h1>

  <?= (new \App\Render\ErrorRenderer())->messages(['success'=>$successMsg, 'error'=>$errorMsg]) ?>
  <?= $validationHtml ?>

  <!-- Sélecteur de formulaire -->
  <div class="card">
    <h2>Sélectionner un formulaire</h2>
    <form method="GET" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
      <input type="hidden" name="p" value="admin_forms">
      <select name="form_id" onchange="this.form.submit()" style="flex:1;min-width:250px;">
        <option value="">— Sélectionner un formulaire —</option>
        <?php foreach ($forms as $f): ?>
          <option value="<?= \App\Core\App::html()->escape($f['id']) ?>" <?= $formId === $f['id'] ? 'selected' : '' ?>><?= \App\Core\App::html()->escape($f['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if ($selectedForm): ?>
  <!-- Formulaire sélectionné -->
  <div class="card">
    <h2><?= \App\Core\App::html()->escape($selectedForm['label']) ?></h2>
    <div class="form-info">
      <p><strong>Slug :</strong> <?= \App\Core\App::html()->escape($selectedForm['slug']) ?></p>
      <p><strong>Description :</strong> <?= \App\Core\App::html()->escape($selectedForm['description'] ?? '') ?></p>
      <p><strong>Statut :</strong> <?= $selectedForm['actif'] ? 'Actif' : 'Inactif' ?></p>
    </div>

    <div style="display:flex;gap:.5rem;margin-top:1rem;">
      <a href="index.php?p=form_preview&form_id=<?= urlencode($formId) ?>" class="btn btn-secondary"><span aria-hidden="true">👁</span> Prévisualiser</a>
      <a href="index.php?p=form_tracking&f=<?= urlencode($formId) ?>" class="btn btn-secondary"><span aria-hidden="true">📊</span> Suivi</a>
    </div>
  </div>

  <!-- Champs du formulaire -->
  <div class="card">
    <h2>Champs du formulaire (<?= count($formFields) ?>)</h2>
    <?php if (empty($formFields)): ?>
      <p class="empty-state">Aucun champ configuré.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Ordre</th><th>Nom</th><th>Label</th><th>Type</th><th>Obligatoire</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($formFields as $field): ?>
          <tr>
            <td><?= (int)$field['ordre'] ?></td>
            <td><code><?= \App\Core\App::html()->escape($field['field_name']) ?></code></td>
            <td><?= \App\Core\App::html()->escape($field['label']) ?></td>
            <td><?= \App\Core\App::html()->escape($field['field_type']) ?></td>
            <td><?= $field['required'] ? 'Oui' : 'Non' ?></td>
            <td>
              <a href="index.php?p=admin_forms&form_id=<?= urlencode($formId) ?>&edit_field=<?= urlencode($field['id']) ?>" class="btn btn-secondary" style="font-size:.75rem;padding:.2rem .5rem;">Modifier</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- Étapes du workflow -->
  <div class="card">
    <h2>Étapes du circuit de validation (<?= count($workflowSteps) ?>)</h2>
    <?php if (empty($workflowSteps)): ?>
      <p class="empty-state">Aucune étape configurée.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Ordre</th><th>Label</th><th>Destinataires</th><th>Statut</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($workflowSteps as $step):
          $emails = array_filter(explode('|', $step['recipient_emails'] ?? ''));
        ?>
          <tr>
            <td><?= (int)$step['ordre'] ?></td>
            <td><?= \App\Core\App::html()->escape($step['label']) ?></td>
            <td><?= \App\Core\App::html()->escape(implode(', ', $emails)) ?></td>
            <td><span class="badge <?= $step['actif'] ? 'badge-ok' : 'badge-err' ?>"><?= $step['actif'] ? 'Active' : 'Inactive' ?></span></td>
            <td>
              <a href="index.php?p=admin_forms&form_id=<?= urlencode($formId) ?>&edit_step=<?= urlencode($step['id']) ?>" class="btn btn-secondary" style="font-size:.75rem;padding:.2rem .5rem;">Modifier</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <?php endif; ?>
<?php
        $content = (string)ob_get_clean();
        echo $this->renderPage('Gestion des formulaires', 'admin_forms', $pageCss, $content);
    }
}
