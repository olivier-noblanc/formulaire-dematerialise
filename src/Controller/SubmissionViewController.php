<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;
use App\Enum\SubmissionStatus;

/**
 * Contrôleur de la page Détail d'une soumission (submission_view).
 */
final class SubmissionViewController extends BaseController
{
    public function handle(): void
    {
        $subId = trim($_GET['id'] ?? '');

        if (empty($subId)) {
            // B-EXIT : utiliser redirect() pour mode 'no-exit' (tests PHPUnit)
            $this->redirect('index.php?p=dashboard');
        }

        $sub = $this->submissionRepo->findByIdWithForm($subId);

        if (!$sub) {
            new \App\Render\ErrorRenderer()->errorPage(404, 'Soumission introuvable',
                'La soumission demandée n\'existe pas ou a été supprimée.',
                'Vérifiez que l\'identifiant dans l\'adresse est correct. Retournez à votre tableau de bord pour voir vos demandes.');
        }

        $data = json_decode($sub['data'], true) ?: [];
        $status = $sub['status'] ?? SubmissionStatus::EnCours->value;
        $user = App::auth()->getUser();
        $isAdmin = App::auth()->isAdminEffective();
        $isFormOwner = App::auth()->isFormOwner((string)$sub['form_id']);

        $isValidator = false;
        if (!$isAdmin && !$isFormOwner && $sub['submitted_by'] !== $user) {
            if ($this->tokenRepo->existsForSubmissionAndEmail($subId, $user)) {
                $isValidator = true;
            } else {
                new \App\Render\ErrorRenderer()->errorPage(403, 'Accès non autorisé',
                    'Vous n\'avez pas les droits pour voir cette soumission.',
                    'Seuls l\'auteur, les validateurs et les administrateurs peuvent consulter une soumission.');
            }
        } else {
            $isValidator = $this->tokenRepo->existsForSubmissionAndEmail($subId, $user);
        }

        $canEditValidator = $isAdmin || $isFormOwner;

        // Handle POST actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->security->requireCsrf();
            $action = $_POST['action'] ?? '';

            if ($action === 'cancel_submission' && ($isAdmin || $sub['submitted_by'] === $user)) {
                $this->submissionRepo->cancelById($subId);
                App::audit()->log('submission_cancel', 'submission:' . $subId, 'Soumission annulée par ' . $user);
                // B-EXIT : redirect() au lieu de header()+exit
                $this->redirect('index.php?p=submission_view&id=' . urlencode($subId));
            }

            if ($action === 'delete_submission' && $isAdmin) {
                $this->submissionRepo->deleteCascade($subId);
                App::audit()->log('submission_delete', 'submission:' . $subId, 'Soumission supprimée par ' . $user);
                // B-EXIT : redirect() au lieu de header()+exit
                $this->redirect('index.php?p=dashboard');
            }
        }

        $workflowSteps = App::workflow()->getWorkflowSteps($sub['form_id']);
        $tokens = $this->tokenRepo->findDetailedWithStepsBySubmission($subId);

        $tokensByStep = [];
        foreach ($tokens as $tk) {
            $tokensByStep[$tk['step_id']][] = $tk;
        }

        // Build workflow_steps for SubmissionViewRenderer
        $renderedSteps = [];
        foreach ($workflowSteps as $step) {
            $stepTokens = $tokensByStep[$step['step_id']] ?? [];
            $allDone = true;
            $hasTokens = !empty($stepTokens);
            foreach ($stepTokens as $tk) {
                if (empty($tk['done_at'])) { $allDone = false; break; }
            }
            $stepStatus = $allDone && $hasTokens ? 'validated' : ($hasTokens ? 'current' : 'upcoming');
            $renderedSteps[] = [
                'step_status' => $stepStatus,
                'step_label'  => $step['step_label'],
                'ordre'       => $step['ordre'],
                'tokens'      => $stepTokens,
            ];
        }

        // CS-09 (audit 2026-07-26) : findBySubmissionWithUploader() était un faux-ami
        // (faisait juste un délégué à findBySubmission, sans JOIN sur une table
        // uploader — il n'y a pas de table users). On appelle directement la
        // méthode source, plus honnête sur l'intention.
        $attachments = $this->attachmentRepo->findBySubmission($subId);

        $validatorData = $this->submissionRepo->getValidatorDataOrdered($subId);

        $renderer = new \App\Render\SubmissionViewRenderer();
        $workflowHtml = $renderer->renderWorkflowDiagram($renderedSteps, $status);

        $pageCss = $renderer->pageCss();
        ob_start();
        ?>
  <h1><span aria-hidden="true">📄</span> Détail de la soumission</h1>

  <div class="card">
    <h2><?= \App\Core\App::html()->escape($sub['form_label']) ?></h2>
    <p style="font-size:.85rem;color:#555;">
      Soumis par <strong><?= \App\Core\App::html()->escape($sub['submitted_by']) ?></strong> le <?= \App\Core\App::html()->escape(date('d/m/Y à H:i', (int) strtotime($sub['submitted_at'] ?? 'now'))) ?>
      <?php if ($sub['closed_at']): ?>
        — Clôturé le <?= \App\Core\App::html()->escape(date('d/m/Y à H:i', (int) strtotime($sub['closed_at']))) ?>
      <?php endif; ?>
    </p>
    <p><strong>Statut :</strong> <span class="badge <?= $status === SubmissionStatus::Valide->value ? 'badge-ok' : ($status === SubmissionStatus::Refuse->value ? 'badge-err' : ($status === SubmissionStatus::Annule->value ? 'badge-annule' : 'badge-warn')) ?>"><?= \App\Core\App::html()->escape($status) ?></span></p>
  </div>

  <!-- Données de la soumission -->
  <div class="card">
    <h2>Données soumises</h2>
    <table>
      <thead><tr><th>Champ</th><th>Valeur</th></tr></thead>
      <tbody>
      <?php foreach ($data as $key => $value):
        if (in_array($key, ['validations', 'submitted_at', 'closed_at'])) continue;
        $valStr = is_array($value) ? implode(', ', $value) : (string)$value;
      ?>
        <tr>
          <td><strong><?= \App\Core\App::html()->escape(App::html()->tJargon($key)) ?></strong></td>
          <td><?= \App\Core\App::html()->escape($valStr) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Circuit de validation -->
  <?= $workflowHtml ?>

  <!-- Pièces jointes -->
  <?php if (!empty($attachments)): ?>
  <div class="card">
    <h2>Pièces jointes (<?= count($attachments) ?>)</h2>
    <table>
      <thead><tr><th>Fichier</th><th>Taille</th><th>Date</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($attachments as $att): ?>
        <tr>
          <td><?= \App\Core\App::html()->escape($att['original_name']) ?></td>
          <td><?= \App\Core\App::html()->escape(format_bytes((int)$att['file_size'])) ?></td>
          <td><?= \App\Core\App::html()->escape(date('d/m/Y H:i', (int) strtotime($att['uploaded_at']))) ?></td>
          <td>
            <a href="index.php?p=download&id=<?= urlencode((string) ($att['id'] ?? '')) ?>" class="btn btn-secondary" style="font-size:.75rem;padding:.2rem .5rem;">Télécharger</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Données validateur -->
  <?php if (!empty($validatorData) && $canEditValidator): ?>
  <div class="card">
    <h2>Données validateur</h2>
    <table>
      <thead><tr><th>Champ</th><th>Valeur</th><th>Rempli par</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach ($validatorData as $vd): ?>
        <tr>
          <td><?= \App\Core\App::html()->escape($vd['field_label'] ?? $vd['field_name']) ?></td>
          <td><?= \App\Core\App::html()->escape($vd['value']) ?></td>
          <td><?= \App\Core\App::html()->escape($vd['filled_by_email'] ?? '') ?></td>
          <td><?= \App\Core\App::html()->escape($vd['filled_at'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Actions -->
  <div class="card-actions" style="margin-top:1.5rem;">
    <a href="index.php?p=my_submissions" class="btn btn-secondary"><span aria-hidden="true">←</span> Retour</a>
    <?php if ($status === SubmissionStatus::EnCours->value && ($isAdmin || $sub['submitted_by'] === $user)): ?>
      <a href="index.php?p=confirm_action&action=cancel_submission&submission_id=<?= urlencode($subId) ?>&from=<?= urlencode('index.php?p=submission_view&id=' . $subId) ?>" class="btn btn-danger"><span aria-hidden="true">🗑</span> Annuler</a>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
      <a href="index.php?p=confirm_action&action=delete_submission&submission_id=<?= urlencode($subId) ?>&from=<?= urlencode('index.php?p=dashboard') ?>" class="btn btn-danger"><span aria-hidden="true">🗑</span> Supprimer</a>
    <?php endif; ?>
      <a href="index.php?p=download&mode=export_submission&submission_id=<?= urlencode($subId) ?>" class="btn btn-secondary"><span aria-hidden="true">📥</span> Exporter JSON</a>
  </div>
<?php
        $content = (string)ob_get_clean();
        echo $this->renderPage('Détail soumission', 'submission_view', $pageCss, $content);
    }
}
