<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Contrôleur de la page Détail d'une soumission (submission_view).
 */
final class SubmissionViewController extends BaseController
{
    public function handle(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/render_submission_view.php';
        require_once dirname(__DIR__, 2) . '/lib/render_submission_view_sections.php';

        $pdo = $this->db->getPdo();
        $subId = trim($_GET['id'] ?? '');

        if (empty($subId)) {
            header('Location: index.php?p=dashboard');
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT s.*, f.label as form_label, f.slug as form_slug, f.deadline_field
            FROM submissions s
            JOIN forms f ON f.id = s.form_id
            WHERE s.id = ?
        ");
        $stmt->execute([$subId]);
        $sub = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$sub) {
            render_error_page(404, 'Soumission introuvable',
                'La soumission demandée n\'existe pas ou a été supprimée.',
                'Vérifiez que l\'identifiant dans l\'adresse est correct. Retournez à votre tableau de bord pour voir vos demandes.');
        }

        $data = json_decode($sub['data'], true) ?: [];
        $status = $sub['status'] ?? 'en_cours';
        $user = App::auth()->getUser();
        $isAdmin = App::auth()->isAdminEffective();
        $isFormOwner = App::auth()->isFormOwner((string)$sub['form_id']);

        if (!$isAdmin && $sub['submitted_by'] !== $user) {
            $validatorCheck = $pdo->prepare("SELECT 1 FROM tokens WHERE submission_id = ? AND email = ?");
            $validatorCheck->execute([$subId, $user]);
            if (!$validatorCheck->fetch()) {
                render_error_page(403, 'Accès non autorisé',
                    'Vous n\'avez pas les droits pour voir cette soumission.',
                    'Seuls l\'auteur, les validateurs et les administrateurs peuvent consulter une soumission.');
            }
        }

        $isValidator = false;
        $validatorCheck = $pdo->prepare("SELECT 1 FROM tokens WHERE submission_id = ? AND email = ?");
        $validatorCheck->execute([$subId, $user]);
        if ($validatorCheck->fetch()) {
            $isValidator = true;
        }

        $canEditValidator = $isAdmin || $isFormOwner;

        // Handle POST actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->security->requireCsrf();
            $action = $_POST['action'] ?? '';

            if ($action === 'cancel_submission' && ($isAdmin || $sub['submitted_by'] === $user)) {
                $pdo->prepare("UPDATE submissions SET status = 'annule', closed_at = datetime('now') WHERE id = ? AND status = 'en_cours'")
                    ->execute([$subId]);
                App::audit()->log('submission_cancel', 'submission:' . $subId, 'Soumission annulée par ' . $user);
                header('Location: index.php?p=submission_view&id=' . urlencode($subId));
                exit;
            }

            if ($action === 'delete_submission' && $isAdmin) {
                $pdo->exec('PRAGMA foreign_keys = ON');
                $pdo->prepare("DELETE FROM submission_validator_data WHERE submission_id = ?")->execute([$subId]);
                $pdo->prepare("DELETE FROM alert_log WHERE submission_id = ?")->execute([$subId]);
                $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$subId]);
                $pdo->prepare("DELETE FROM attachments WHERE submission_id = ?")->execute([$subId]);
                $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
                App::audit()->log('submission_delete', 'submission:' . $subId, 'Soumission supprimée par ' . $user);
                header('Location: index.php?p=dashboard');
                exit;
            }
        }

        $workflowSteps = get_workflow_steps($sub['form_id']);
        $tokens = $pdo->prepare("
            SELECT t.*, st.label as step_label, st.ordre
            FROM tokens t
            JOIN steps st ON st.id = t.step_id
            WHERE t.submission_id = ?
            ORDER BY st.ordre ASC, t.sent_at ASC
        ");
        $tokens->execute([$subId]);
        $tokens = $tokens->fetchAll(\PDO::FETCH_ASSOC);

        $tokensByStep = [];
        foreach ($tokens as $tk) {
            $tokensByStep[$tk['step_id']][] = $tk;
        }

        $attachments = $pdo->prepare("
            SELECT a.*, u.display_name as uploader_name
            FROM attachments a
            LEFT JOIN users u ON u.email = a.uploaded_by
            WHERE a.submission_id = ?
            ORDER BY a.uploaded_at ASC
        ");
        $attachments->execute([$subId]);
        $attachments = $attachments->fetchAll(\PDO::FETCH_ASSOC);

        $validatorData = $pdo->prepare("
            SELECT * FROM submission_validator_data
            WHERE submission_id = ?
            ORDER BY filled_at ASC, field_name ASC
        ");
        $validatorData->execute([$subId]);
        $validatorData = $validatorData->fetchAll(\PDO::FETCH_ASSOC);

        $pageCss = '';
        ob_start();
        ?>
  <h1><span aria-hidden="true">📄</span> Détail de la soumission</h1>

  <div class="card">
    <h2><?= h($sub['form_label']) ?></h2>
    <p style="font-size:.85rem;color:#555;">
      Soumis par <strong><?= h($sub['submitted_by']) ?></strong> le <?= h(date('d/m/Y à H:i', strtotime($sub['submitted_at']))) ?>
      <?php if ($sub['closed_at']): ?>
        — Clôturé le <?= h(date('d/m/Y à H:i', strtotime($sub['closed_at']))) ?>
      <?php endif; ?>
    </p>
    <p><strong>Statut :</strong> <span class="badge <?= $status === 'valide' ? 'badge-ok' : ($status === 'refuse' ? 'badge-err' : ($status === 'annule' ? 'badge-annule' : 'badge-warn')) ?>"><?= h($status) ?></span></p>
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
          <td><strong><?= h(App::html()->tJargon($key)) ?></strong></td>
          <td><?= h($valStr) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Circuit de validation -->
  <div class="card">
    <h2>Circuit de validation</h2>
    <div class="workflow">
      <?php foreach ($workflowSteps as $i => $step):
        $stepTokens = $tokensByStep[$step['id']] ?? [];
        $allDone = true;
        foreach ($stepTokens as $tk) {
            if (empty($tk['done_at'])) { $allDone = false; break; }
        }
        $cls = $allDone && !empty($stepTokens) ? 'done' : (!empty($stepTokens) ? 'current' : 'upcoming');
      ?>
        <?php if ($i > 0): ?><span class="wf-arrow">→</span><?php endif; ?>
        <div class="wf-step <?= $cls ?>">
          <div class="wf-step-label"><?= h($step['label']) ?></div>
          <?php foreach ($stepTokens as $tk): ?>
            <div class="wf-step-detail">
              <?= h($tk['email']) ?>
              <?php if (!empty($tk['done_at'])): ?>
                <span class="badge badge-ok">✓ <?= h(date('d/m/Y H:i', strtotime($tk['done_at']))) ?></span>
              <?php else: ?>
                <span class="badge badge-warn">⏳ En attente</span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Pièces jointes -->
  <?php if (!empty($attachments)): ?>
  <div class="card">
    <h2>Pièces jointes (<?= count($attachments) ?>)</h2>
    <table>
      <thead><tr><th>Fichier</th><th>Taille</th><th>Ajouté par</th><th>Date</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($attachments as $att): ?>
        <tr>
          <td><?= h($att['original_name']) ?></td>
          <td><?= h(format_bytes((int)$att['file_size'])) ?></td>
          <td><?= h($att['uploader_name'] ?? $att['uploaded_by']) ?></td>
          <td><?= h(date('d/m/Y H:i', strtotime($att['uploaded_at']))) ?></td>
          <td>
            <a href="index.php?p=download&id=<?= urlencode($att['id']) ?>" class="btn btn-secondary" style="font-size:.75rem;padding:.2rem .5rem;">Télécharger</a>
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
          <td><?= h($vd['field_label'] ?? $vd['field_name']) ?></td>
          <td><?= h($vd['value']) ?></td>
          <td><?= h($vd['filled_by_email'] ?? '') ?></td>
          <td><?= h($vd['filled_at'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Actions -->
  <div class="card-actions" style="margin-top:1.5rem;">
    <a href="index.php?p=my_submissions" class="btn btn-secondary"><span aria-hidden="true">←</span> Retour</a>
    <?php if ($status === 'en_cours' && ($isAdmin || $sub['submitted_by'] === $user)): ?>
      <a href="index.php?p=confirm_action&action=cancel_submission&submission_id=<?= urlencode($subId) ?>&from=<?= urlencode('index.php?p=submission_view&id=' . $subId) ?>" class="btn btn-danger"><span aria-hidden="true">🗑</span> Annuler</a>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
      <a href="index.php?p=confirm_action&action=delete_submission&submission_id=<?= urlencode($subId) ?>&from=<?= urlencode('index.php?p=dashboard') ?>" class="btn btn-danger"><span aria-hidden="true">🗑</span> Supprimer</a>
    <?php endif; ?>
    <?php if (!empty($attachments) || true): ?>
      <a href="index.php?p=download&mode=export_submission&submission_id=<?= urlencode($subId) ?>" class="btn btn-secondary"><span aria-hidden="true">📥</span> Exporter JSON</a>
    <?php endif; ?>
  </div>
<?php
        $content = (string)ob_get_clean();
        echo $this->renderPage('Détail soumission', 'submission_view', $pageCss, $content);
    }
}
