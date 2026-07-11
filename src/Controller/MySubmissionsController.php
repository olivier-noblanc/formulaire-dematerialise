<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Contrôleur de la page "Mes demandes" pour l'agent connecté.
 */
final class MySubmissionsController extends BaseController
{
    public function handle(): void
    {
        $user = App::auth()->getUser();
        $pdo  = $this->db->getPdo();
        $search = trim($_GET['search'] ?? '');
        $statusFilter = $_GET['statut'] ?? 'tous';

        if (!function_exists('simplify_form_label')) {
            function simplify_form_label(string $label): string {
                $map = [
                    'Accès SI'    => 'Demande d\'accès aux outils informatiques',
                    'Onboarding'  => 'Accueil d\'un nouvel agent',
                    'Outboarding' => 'Départ d\'un agent',
                ];
                $trimmed = trim($label);
                foreach ($map as $jargon => $clair) {
                    if (strcasecmp($trimmed, $jargon) === 0) {
                        $label = $clair;
                        break;
                    }
                }
                return App::html()->tJargon($label);
            }
        }

        $where = ['s.submitted_by = ?'];
        $params = [$user];
        if ($search) {
            $where[] = "(f.label LIKE ? OR s.data LIKE ?)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        if ($statusFilter === 'en_cours') { $where[] = "s.status = 'en_cours'"; }
        elseif ($statusFilter === 'valide') { $where[] = "s.status = 'valide'"; }
        elseif ($statusFilter === 'refuse') { $where[] = "s.status = 'refuse'"; }
        elseif ($statusFilter === 'annule') { $where[] = "s.status = 'annule'"; }
        $whereSql = implode(' AND ', $where);

        $submissions = $this->submissionRepo->findPaginatedBySubmitter($user, $whereSql, $params, 0, 0);

        $workflowStepsByForm = [];
        $tokensBySub = [];
        if (!empty($submissions)) {
            $formIds = array_values(array_unique(array_column($submissions, 'form_id')));
            if (!empty($formIds)) {
                $workflowStepsByForm = $this->formRepo->getWorkflowStepsByFormIds($formIds);
            }

            $subIds = array_column($submissions, 'id');
            $tokensBySub = $this->tokenRepo->findBySubmissionIds($subIds);
        }

        foreach ($submissions as &$sub) {
            $sid = $sub['id'];
            $sub['workflow_steps'] = $workflowStepsByForm[$sub['form_id']] ?? [];
            $sub['tokens'] = $tokensBySub[$sid] ?? [];

            $tokensByStep = [];
            foreach ($sub['tokens'] as $tok) {
                $tokensByStep[$tok['step_id']][] = $tok;
            }

            foreach ($sub['workflow_steps'] as &$ws) {
                $stepId = $ws['step_id'];
                if (!isset($tokensByStep[$stepId]) || empty($tokensByStep[$stepId])) {
                    $ws['step_status'] = 'upcoming';
                    $ws['step_detail'] = '';
                } else {
                    $allDone = true;
                    $detailParts = [];
                    foreach ($tokensByStep[$stepId] as $tok) {
                        if (!empty($tok['done_at'])) {
                            $detailParts[] = App::html()->displayUser($tok['email']) . ' <span aria-hidden="true">✓</span>';
                        } else {
                            $allDone = false;
                            $detailParts[] = App::html()->displayUser($tok['email']) . ' <span aria-hidden="true">⏳</span>';
                        }
                    }
                    $ws['step_status'] = $allDone ? 'validated' : 'current';
                    $ws['step_detail'] = implode('<br>', $detailParts);
                }
            }
            unset($ws);

            $total = count($sub['workflow_steps']);
            $done = count(array_filter($sub['workflow_steps'], fn($s) => $s['step_status'] === 'validated'));
            $sub['progress_pct'] = $total > 0 ? round(($done / $total) * 100) : 0;
            $sub['progress_done'] = $done;
            $sub['progress_total'] = $total;
        }
        unset($sub);

        $statusCounts = $this->submissionRepo->getStatusCountsBySubmitter($user);
        $totalCount = 0;
        $enCoursCount = 0;
        $valideCount = 0;
        $refuseCount = 0;
        $annuleCount = 0;
        foreach ($statusCounts as $row) {
            $totalCount += (int)$row['cnt'];
            if ($row['status'] === 'valide') $valideCount = (int)$row['cnt'];
            elseif ($row['status'] === 'refuse') $refuseCount = (int)$row['cnt'];
            elseif ($row['status'] === 'annule') $annuleCount = (int)$row['cnt'];
            else $enCoursCount += (int)$row['cnt'];
        }
        unset($row);

        ob_start();
        ?>
  <h1><span aria-hidden="true">📋</span> Mes demandes</h1>

  <?php if ($totalCount > 0): ?>
  <div class="stats">
    <a href="index.php?p=my_submissions&statut=tous" class="stat <?= $statusFilter === 'tous' ? 'active' : '' ?>"><strong><?= $totalCount ?></strong><span>Total</span></a>
    <a href="index.php?p=my_submissions&statut=en_cours" class="stat en-cours <?= $statusFilter === 'en_cours' ? 'active' : '' ?>"><strong><?= $enCoursCount ?></strong><span>En cours</span></a>
    <a href="index.php?p=my_submissions&statut=valide" class="stat valide <?= $statusFilter === 'valide' ? 'active' : '' ?>"><strong><?= $valideCount ?></strong><span>Validées</span></a>
    <a href="index.php?p=my_submissions&statut=refuse" class="stat refuse <?= $statusFilter === 'refuse' ? 'active' : '' ?>"><strong><?= $refuseCount ?></strong><span>Refusées</span></a>
    <a href="index.php?p=my_submissions&statut=annule" class="stat annule <?= $statusFilter === 'annule' ? 'active' : '' ?>"><strong><?= $annuleCount ?></strong><span>Annulées</span></a>
  </div>

  <div style="margin-bottom:1.5rem;">
    <?= (new \App\Render\FormRenderer())->searchBar('index.php?p=my_submissions', $search, 'Rechercher...', ['statut' => $statusFilter]) ?>
  </div>
  <?php endif; ?>

  <?php if (empty($submissions)): ?>
    <div class="empty-state">
      <div class="empty-icon" aria-hidden="true">📝</div>
      <p>Vous n'avez encore soumis aucune demande.</p>
      <?php
        $activeForms = $this->formRepo->findActiveSlugsAndLabels();
        if (!empty($activeForms)):
      ?>
        <p style="font-size:.9rem;color:#555;margin-bottom:.5rem;">Formulaires disponibles :</p>
        <?php foreach ($activeForms as $af): ?>
          <a href="index.php?p=form&f=<?= \App\Core\App::html()->escape($af['slug']) ?>" class="btn btn-primary" style="margin:.25rem;"><?= \App\Core\App::html()->escape(simplify_form_label($af['label'])) ?></a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <?php foreach ($submissions as $sub):
        $data = json_decode($sub['data'], true);
        $status = $sub['status'] ?? 'en_cours';
        $statusLabel = $status === 'valide' ? '✓ Validée' : ($status === 'refuse' ? '❌ Refusée' : ($status === 'annule' ? '🗑 Annulée' : '⏳ En cours'));
        $badgeCls = $status === 'valide' ? 'badge-valide' : ($status === 'refuse' ? 'badge-refuse' : ($status === 'annule' ? 'badge-annule' : 'badge-en-cours'));

        $deadlineField = $sub['deadline_field'] ?? '';
        $deadlineVal = $deadlineField ? ($data[$deadlineField] ?? '') : '';
        $deadlineBadge = '';
        if (!empty($deadlineVal) && $status === 'en_cours') {
            $dl = calculate_deadline_urgency($deadlineVal, $status);
            $dlDays = $dl['days_left'];
            if ($dlDays !== null) {
                if ($dlDays < 0) $deadlineBadge = '<span class="deadline-badge overdue"><span aria-hidden="true">🚨</span> J+' . abs($dlDays) . '</span>';
                elseif ($dlDays <= 2) $deadlineBadge = '<span class="deadline-badge urgent"><span aria-hidden="true">⚠️</span> J-' . $dlDays . '</span>';
                elseif ($dlDays <= 5) $deadlineBadge = '<span class="deadline-badge ok"><span aria-hidden="true">📅</span> J-' . $dlDays . '</span>';
            }
        }

        $pct = $sub['progress_pct'];
        $fillCls = $pct === 100 ? 'complete' : 'in-progress';
    ?>
    <div class="sub-card">
      <a href="index.php?p=submission_view&id=<?= urlencode($sub['id']) ?>" style="text-decoration:none;color:inherit;">
      <div class="sub-card-header">
        <div>
          <div class="sub-card-title"><?= \App\Core\App::html()->escape(simplify_form_label($sub['form_label'])) ?> <?= $deadlineBadge ?></div>
          <div class="sub-card-date">Soumis le <?= \App\Core\App::html()->escape(date('d/m/Y à H:i', strtotime($sub['submitted_at']))) ?> — <?= \App\Core\App::html()->escape(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? '')) ?></div>
        </div>
        <span class="badge <?= $badgeCls ?>"><?= $statusLabel ?></span>
      </div>
      </a>
      <div class="sub-card-body">
        <div class="inline-progress">
          <div class="inline-progress-bar">
            <div class="inline-progress-fill <?= $fillCls ?>" style="width:<?= max($pct, 3) ?>%;"></div>
          </div>
          <div class="inline-progress-text"><?= $sub['progress_done'] ?>/<?= $sub['progress_total'] ?> étapes (<?= $pct ?>%)</div>
        </div>

        <div class="timeline-compact">
          <?php foreach ($sub['workflow_steps'] as $ws):
            $cls = $ws['step_status'] === 'validated' ? 'done' : ($ws['step_status'] === 'current' ? 'active' : 'waiting');
            $icon = $ws['step_status'] === 'validated' ? '✓' : ($ws['step_status'] === 'current' ? '⏳' : '○');
          ?>
            <div class="tl-step <?= $cls ?>">
              <span class="tl-icon" aria-hidden="true"><?= $icon ?></span>
              <span class="tl-label"><?= \App\Core\App::html()->escape($ws['step_label']) ?></span>
              <?php if (!empty($ws['step_detail'])): ?>
                <span class="tl-detail"><?= $ws['step_detail'] ?></span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if ($status === 'refuse' && isset($data['validations'])): ?>
          <div class="refusal-box">
            <?php
              foreach ($data['validations'] as $v) {
                  if ($v['action'] === 'refuser') {
                      echo '<strong>Refusé par :</strong> ' . App::html()->displayUser($v['email']) . ' (' . \App\Core\App::html()->escape($v['step_label']) . ')';
                      if (!empty($v['commentaire'])) echo '<br><strong>Motif :</strong> ' . \App\Core\App::html()->escape($v['commentaire']);
                      break;
                  }
              }
            ?>
          </div>
        <?php elseif ($status === 'valide' && isset($data['validations'])): ?>
          <div class="validation-box">
            <?php
              $lastValidator = null;
              foreach ($data['validations'] as $v) {
                  if ($v['action'] === 'valider') {
                      $lastValidator = $v;
                  }
              }
              if ($lastValidator !== null) {
                  echo '<strong>Validée par :</strong> ' . App::html()->displayUser($lastValidator['email']) . ' (' . \App\Core\App::html()->escape($lastValidator['step_label']) . ')';
                  if (!empty($lastValidator['commentaire'])) echo '<br><strong>Commentaire :</strong> ' . \App\Core\App::html()->escape($lastValidator['commentaire']);
              } else {
                  echo '<strong>Demande validée</strong> — circuit complet';
              }
            ?>
          </div>
        <?php endif; ?>

        <div class="card-actions">
          <a href="index.php?p=submission_view&id=<?= urlencode($sub['id']) ?>" class="btn btn-primary" style="font-size:.85rem;"><span aria-hidden="true">👁</span> Voir le détail</a>
          <a href="index.php?p=form&f=<?= \App\Core\App::html()->escape($sub['form_slug']) ?>" class="btn btn-secondary" style="font-size:.85rem;">Nouvelle demande</a>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php
        $content = (string)ob_get_clean();
        echo $this->renderPage('Mes demandes', 'mes_demandes', '', $content);
    }
}
