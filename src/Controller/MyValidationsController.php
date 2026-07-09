<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Contrôleur de la page "Mes validations" (dashboard validateur).
 */
final class MyValidationsController extends BaseController
{
    public function handle(): void
    {
        $user = App::auth()->getUser();
        $pdo  = $this->db->getPdo();
        $search = trim($_GET['search'] ?? '');

        $delegationMsg = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delegate_token') {
            $this->security->requireCsrf();
            $tokenId = trim($_POST['token_id'] ?? '');
            $delegateTo = trim($_POST['delegate_to'] ?? '');
            $delegateReason = trim($_POST['delegate_reason'] ?? '');
            $result = delegate_token($tokenId, $delegateTo, $delegateReason);
            $delegationMsg = $result['message'];
        }

        $pendingStmt = $pdo->prepare("
            SELECT t.id as token_id, t.token, t.sent_at, t.expires_at, t.relance_count,
                   t.step_id, t.email,
                   st.label as step_label, st.ordre,
                   s.id as submission_id, s.data, s.submitted_at, s.status as sub_status,
                   f.label as form_label, f.slug as form_slug
            FROM tokens t
            JOIN steps st ON st.id = t.step_id
            JOIN submissions s ON s.id = t.submission_id
            JOIN forms f ON f.id = s.form_id
            WHERE t.email = ? AND t.done_at IS NULL AND s.status = 'en_cours'
            ORDER BY t.sent_at DESC
        ");
        if ($search) {
            $pendingStmt = $pdo->prepare("
                SELECT t.id as token_id, t.token, t.sent_at, t.expires_at, t.relance_count,
                       t.step_id, t.email,
                       st.label as step_label, st.ordre,
                       s.id as submission_id, s.data, s.submitted_at, s.status as sub_status,
                       f.label as form_label, f.slug as form_slug
                FROM tokens t
                JOIN steps st ON st.id = t.step_id
                JOIN submissions s ON s.id = t.submission_id
                JOIN forms f ON f.id = s.form_id
                WHERE t.email = ? AND t.done_at IS NULL AND s.status = 'en_cours'
                  AND (f.label LIKE ? OR s.data LIKE ?)
                ORDER BY t.sent_at DESC
            ");
            $pendingStmt->execute([$user, '%' . $search . '%', '%' . $search . '%']);
        } else {
            $pendingStmt->execute([$user]);
        }
        $pendingTokens = $pendingStmt->fetchAll(\PDO::FETCH_ASSOC);

        $doneStmt = $pdo->prepare("
            SELECT t.id as token_id, t.done_at, t.sent_at,
                   st.label as step_label, st.ordre,
                   s.id as submission_id, s.data, s.submitted_at, s.status as sub_status,
                   f.label as form_label, f.slug as form_slug
            FROM tokens t
            JOIN steps st ON st.id = t.step_id
            JOIN submissions s ON s.id = t.submission_id
            JOIN forms f ON f.id = s.form_id
            WHERE t.email = ? AND t.done_at IS NOT NULL
            ORDER BY t.done_at DESC
            LIMIT 50
        ");
        $doneStmt->execute([$user]);
        $doneTokens = $doneStmt->fetchAll(\PDO::FETCH_ASSOC);

        $pendingCount = count($pendingTokens);
        $doneCount = count($doneTokens);
        $activeTab = $_GET['tab'] ?? 'pending';

        $allStepsBySub = [];
        if (!empty($pendingTokens)) {
            $pendingSubIds = array_values(array_unique(array_column($pendingTokens, 'submission_id')));
            $psph = implode(',', array_fill(0, count($pendingSubIds), '?'));
            $batchStepsStmt = $pdo->prepare("
                SELECT s.id as submission_id, st.id, st.label, st.ordre,
                       GROUP_CONCAT(t2.done_at, '|') as dones
                FROM submissions s
                JOIN steps st ON st.form_id = s.form_id AND st.actif = 1
                LEFT JOIN tokens t2 ON t2.step_id = st.id AND t2.submission_id = s.id
                WHERE s.id IN ($psph)
                GROUP BY s.id, st.id
                ORDER BY s.id, st.ordre
            ");
            $batchStepsStmt->execute($pendingSubIds);
            foreach ($batchStepsStmt->fetchAll(\PDO::FETCH_ASSOC) as $asRow) {
                $allStepsBySub[$asRow['submission_id']][] = $asRow;
            }
        }

        $myVdStmt = $pdo->prepare("SELECT svd.*, s.form_id, f.label as form_label
                                    FROM submission_validator_data svd
                                    JOIN submissions s ON s.id = svd.submission_id
                                    JOIN forms f ON f.id = s.form_id
                                    WHERE svd.filled_by_email = ?
                                    ORDER BY svd.filled_at DESC
                                    LIMIT 50");
        $myVdStmt->execute([$user]);
        $myVdRows = $myVdStmt->fetchAll(\PDO::FETCH_ASSOC);

        ob_start();
        ?>
  <h1><span aria-hidden="true">✅</span> Mes validations</h1>

  <div class="stats">
    <a href="index.php?p=my_validations&tab=pending" class="stat warning <?= $activeTab === 'pending' ? 'active' : '' ?>"><strong><?= $pendingCount ?></strong><span>En attente</span></a>
    <a href="index.php?p=my_validations&tab=done" class="stat success <?= $activeTab === 'done' ? 'active' : '' ?>"><strong><?= $doneCount ?></strong><span>Traitées</span></a>
  </div>

  <?php if ($delegationMsg): ?>
    <div class="msg-info" role="status" aria-live="polite"><?= h($delegationMsg) ?></div>
  <?php endif; ?>

  <?= render_search_bar('index.php?p=my_validations', $search, 'Rechercher un formulaire...', ['tab' => $activeTab]) ?>

  <?php if ($activeTab === 'pending'): ?>
  <div id="tab-pending">
    <?php if (empty($pendingTokens)): ?>
      <div class="empty-state">
        <div class="empty-icon" aria-hidden="true">🎉</div>
        <p>Aucune validation en attente — vous êtes à jour !</p>
      </div>
    <?php else: ?>
      <?php foreach ($pendingTokens as $tk):
          $data = json_decode($tk['data'], true) ?: [];
          $expired = !empty($tk['expires_at']) && strtotime($tk['expires_at']) < time();
          $nomAgent = h(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? ''));
          $allSteps = $allStepsBySub[$tk['submission_id']] ?? [];
      ?>
      <div class="validation-card <?= $expired ? 'expired' : 'pending' ?>">
        <div class="vc-header">
          <div>
            <div class="vc-title"><?= h($tk['form_label']) ?> — Étape <?= (int)$tk['ordre'] ?> : <?= h($tk['step_label']) ?></div>
            <div class="vc-meta">
              Agent : <strong><?= $nomAgent ?: h($tk['data'] ? 'Inconnu' : '') ?></strong>
              <?php if (!empty($data['affectation'])): ?> — <?= h($data['affectation']) ?><?php endif; ?>
              <br>Soumis le <?= h(date('d/m/Y à H:i', strtotime($tk['submitted_at']))) ?>
              <?php if ($tk['relance_count'] > 0): ?>
                <br><span style="color:#b45309;">Relance(s) : <?= (int)$tk['relance_count'] ?></span>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($expired): ?>
            <span class="expired-badge"><span aria-hidden="true">⏰</span> Expiré</span>
          <?php else: ?>
            <span class="badge badge-warn"><span aria-hidden="true">⏳</span> En attente de votre validation</span>
          <?php endif; ?>
        </div>

        <div class="vc-body">
          <div class="workflow-mini">
            <?php foreach ($allSteps as $i => $as):
                $dones = array_filter(explode('|', $as['dones'] ?? ''));
                $allDone = !empty($dones) && !in_array('', $dones) && !in_array(null, $dones, true);
                if ($allDone) {
                    $cls = 'wf-step-done';
                    $icon = '✓';
                } elseif ($as['ordre'] == $tk['ordre']) {
                    $cls = 'wf-step-current';
                    $icon = '⏳';
                } else {
                    $cls = 'wf-step-upcoming';
                    $icon = '○';
                }
            ?>
              <?php if ($i > 0): ?><span class="wf-arrow">→</span><?php endif; ?>
              <span class="wf-step-mini <?= $cls ?>" aria-hidden="true"><?= $icon ?> <?= h($as['label']) ?></span>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="vc-actions">
          <?php if (!$expired): ?>
            <a href="index.php?p=validate&token=<?= urlencode($tk['token']) ?>" class="btn btn-primary"><span aria-hidden="true">✓</span> Valider / Refuser</a>
          <?php else: ?>
            <span style="font-size:.85rem;color:#c0392b;">Token expiré — contactez un administrateur pour régénérer</span>
          <?php endif; ?>
          <details style="margin-left:.5rem;">
            <summary class="btn btn-secondary" style="font-size:.8rem;padding:.4rem .75rem;cursor:pointer;display:inline;"><span aria-hidden="true">🔄</span> Déléguer</summary>
            <form method="POST" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-top:.5rem;padding:.75rem;background:#f8f8fc;border-radius:4px;border:1px solid #ddd;">
              <?= App::security()->csrfField() ?>
              <input type="hidden" name="action" value="delegate_token">
              <input type="hidden" name="token_id" value="<?= h($tk['token_id']) ?>">
              <input type="email" name="delegate_to" placeholder="email@dreets.gouv.fr" required style="padding:.3rem .5rem;font-size:.8rem;border:1px solid #aaa;border-radius:3px;width:220px;">
              <input type="text" name="delegate_reason" placeholder="Motif (optionnel)" style="padding:.3rem .5rem;font-size:.8rem;border:1px solid #aaa;border-radius:3px;width:180px;">
              <button type="submit" style="font-size:.8rem;padding:.3rem .75rem;background:#6c3483;color:#fff;border:none;border-radius:3px;cursor:pointer;">Confirmer</button>
            </form>
          </details>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php else: ?>
  <div id="tab-done">
    <?php if (empty($doneTokens)): ?>
      <div class="empty-state">
        <div class="empty-icon" aria-hidden="true">📋</div>
        <p>Vous n'avez encore validé aucune demande.</p>
      </div>
    <?php else: ?>
      <?php foreach ($doneTokens as $tk):
          $data = json_decode($tk['data'], true) ?: [];
          $nomAgent = h(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? ''));

          $actionLabel = 'Validé';
          $actionCls = 'badge-ok';
          if ($tk['sub_status'] === 'refuse') {
              $refusedByMe = false;
              if (isset($data['validations'])) {
                  foreach ($data['validations'] as $v) {
                      if ($v['action'] === 'refuser' && (string)($v['email'] ?? '') === $user) {
                          $refusedByMe = true;
                          break;
                      }
                  }
              }
              if ($refusedByMe) {
                  $actionLabel = 'Refusé';
                  $actionCls = 'badge-err';
              } else {
                  $actionLabel = 'Validé (refusé ailleurs)';
                  $actionCls = 'badge-warn';
              }
          }
      ?>
      <div class="validation-card done">
        <div class="vc-header">
          <div>
            <div class="vc-title"><?= h($tk['form_label']) ?> — <?= h($tk['step_label']) ?></div>
            <div class="vc-meta">
              Agent : <strong><?= $nomAgent ?></strong>
              <br>Soumis le <?= h(date('d/m/Y à H:i', strtotime($tk['submitted_at']))) ?>
            </div>
          </div>
          <span class="badge <?= $actionCls ?>"><?= $actionLabel ?></span>
        </div>
        <div class="vc-body">
          <div class="done-info">Traitée le <strong><?= h(date('d/m/Y à H:i', strtotime($tk['done_at']))) ?></strong></div>
          <div class="done-date">Délai de traitement : <?php
            $doneTs = strtotime($tk['done_at']); $sentTs = strtotime($tk['sent_at']);
            if ($doneTs && $sentTs) {
                $diffSec = $doneTs - $sentTs;
                if ($diffSec >= 86400) {
                    $days = (int)floor($diffSec / 86400);
                    $hours = (int)floor(($diffSec % 86400) / 3600);
                    echo h($days . ' j ' . $hours . ' h');
                } elseif ($diffSec >= 3600) {
                    $hours = (int)floor($diffSec / 3600);
                    $mins = (int)floor(($diffSec % 3600) / 60);
                    echo h($hours . ' h ' . ($mins > 0 ? $mins . ' min' : ''));
                } else {
                    $mins = (int)floor($diffSec / 60);
                    echo h($mins . ' min');
                }
            } else {
                echo '?';
            }
          ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($myVdRows)): ?>
  <details style="margin-top: 1.5rem;">
    <summary style="cursor:pointer;font-weight:600;color:var(--c-primary, #003189);font-size:.9rem;">
      📝 Champs validateur que j'ai remplis (<?= count($myVdRows) ?>)
    </summary>
    <div class="card" style="margin-top:.5rem;">
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Formulaire</th>
            <th>Étape</th>
            <th>Champ</th>
            <th>Valeur</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($myVdRows as $r):
              $rFilledAt  = isset($r['filled_at'])    ? (string)$r['filled_at']    : '';
              $rFormLabel = isset($r['form_label'])   ? (string)$r['form_label']   : '';
              $rSubId     = isset($r['submission_id']) ? (string)$r['submission_id'] : '';
              $rStepLabel = isset($r['step_label'])   ? (string)$r['step_label']   : '';
              $rFieldLbl  = isset($r['field_label'])  ? (string)$r['field_label']  : '';
              $rFieldName = isset($r['field_name'])   ? (string)$r['field_name']   : '';
              $rValue     = isset($r['value'])        ? (string)$r['value']        : '';
              $ts = $rFilledAt !== '' ? strtotime($rFilledAt) : false;
              $rValueShort = mb_strimwidth($rValue, 0, 80, '…', 'UTF-8');
          ?>
            <tr>
              <td><?= $ts !== false ? h(date('d/m/Y H:i', $ts)) : '—' ?></td>
              <td>
                <a href="index.php?p=submission_view&id=<?= urlencode($rSubId) ?>"><?= h($rFormLabel) ?></a>
              </td>
              <td><?= h(App::html()->tJargon($rStepLabel)) ?></td>
              <td><?= h(App::html()->tJargon($rFieldLbl !== '' ? $rFieldLbl : $rFieldName)) ?></td>
              <td><?= h($rValueShort) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </details>
  <?php endif; ?>
<?php
        $content = (string)ob_get_clean();
        echo $this->renderPage('Mes validations', 'mes_validations', '', $content);
    }
}
