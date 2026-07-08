<?php
// my_validations.php — Dashboard validateur : tâches en attente + historique
require_once dirname(__DIR__) . '/helpers.php';

$user = get_auth_user();
$pdo  = \App\Core\App::db()->getPdo();
$search = trim($_GET['search'] ?? '');

// Traitement de la delegation
$delegation_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delegate_token') {
    \App\Core\App::security()->requireCsrf();
    $token_id = trim($_POST['token_id'] ?? '');
    $delegate_to = trim($_POST['delegate_to'] ?? '');
    $delegate_reason = trim($_POST['delegate_reason'] ?? '');
    $result = delegate_token($token_id, $delegate_to, $delegate_reason);
    $delegation_msg = $result['message'];
}

// ── Tokens en attente pour cet utilisateur ──
$pending_stmt = $pdo->prepare("
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
    $pending_stmt = $pdo->prepare("
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
    $pending_stmt->execute([$user, '%' . $search . '%', '%' . $search . '%']);
} else {
    $pending_stmt->execute([$user]);
}
$pending_tokens = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Tokens déjà traités par cet utilisateur (historique) ──
$done_stmt = $pdo->prepare("
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
$done_stmt->execute([$user]);
$done_tokens = $done_stmt->fetchAll(PDO::FETCH_ASSOC);

// Compteurs
$pending_count = count($pending_tokens);
$done_count = count($done_tokens);
$active_tab = $_GET['tab'] ?? 'pending';

// A-13: optimisé — était N+1 (1 requête steps+tokens par token en attente)
// Batch: 1 seule requête pour précharger tous les mini-workflows des soumissions en attente.
$all_steps_by_sub = [];
if (!empty($pending_tokens)) {
    $pending_sub_ids = array_values(array_unique(array_column($pending_tokens, 'submission_id')));
    $psph = implode(',', array_fill(0, count($pending_sub_ids), '?'));
    $batch_steps_stmt = $pdo->prepare("
        SELECT s.id as submission_id, st.id, st.label, st.ordre,
               GROUP_CONCAT(t2.done_at, '|') as dones
        FROM submissions s
        JOIN steps st ON st.form_id = s.form_id AND st.actif = 1
        LEFT JOIN tokens t2 ON t2.step_id = st.id AND t2.submission_id = s.id
        WHERE s.id IN ($psph)
        GROUP BY s.id, st.id
        ORDER BY s.id, st.ordre
    ");
    $batch_steps_stmt->execute($pending_sub_ids);
    foreach ($batch_steps_stmt->fetchAll(PDO::FETCH_ASSOC) as $as_row) {
        $all_steps_by_sub[$as_row['submission_id']][] = $as_row;
    }
}

// Vérifier si un token est expiré
/**
 * @param array<string, mixed> $token
 */
function is_token_expired(array $token): bool {
    if (empty($token['expires_at'])) return false;
    $exp_ts = strtotime($token['expires_at']);
    return ($exp_ts !== false && $exp_ts < time());
}
?>
<?php
$page_css = '';
ob_start();
?>
  <h1><span aria-hidden="true">✅</span> Mes validations</h1>
  <?php // v10.0.6 — subtitle supprimé (redondant avec le titre h1) ?>

  <div class="stats">
    <a href="index.php?p=my_validations&tab=pending" class="stat warning <?= $active_tab === 'pending' ? 'active' : '' ?>"><strong><?= $pending_count ?></strong><span>En attente</span></a>
    <a href="index.php?p=my_validations&tab=done" class="stat success <?= $active_tab === 'done' ? 'active' : '' ?>"><strong><?= $done_count ?></strong><span>Traitées</span></a>
  </div>

  <!-- Barre de recherche -->
  <?php if ($delegation_msg): ?>
    <div class="msg-info" role="status" aria-live="polite"><?= h($delegation_msg) ?></div>
  <?php endif; ?>

  <?= render_search_bar('index.php?p=my_validations', $search, 'Rechercher un formulaire...', ['tab' => $active_tab]) ?>

  <!-- Onglet : En attente -->
  <?php if ($active_tab === 'pending'): ?>
  <div id="tab-pending">
    <?php if (empty($pending_tokens)): ?>
      <div class="empty-state">
        <div class="empty-icon" aria-hidden="true">🎉</div>
        <p>Aucune validation en attente — vous êtes à jour !</p>
      </div>
    <?php else: ?>
      <?php foreach ($pending_tokens as $tk):
          $data = json_decode($tk['data'], true) ?: [];
          $expired = is_token_expired($tk);
          $nom_agent = h(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? ''));

          // A-13: optimisé — était N+1, mini-workflow préchargé en batch ci-dessus
          $all_steps = $all_steps_by_sub[$tk['submission_id']] ?? [];
      ?>
      <div class="validation-card <?= $expired ? 'expired' : 'pending' ?>">
        <div class="vc-header">
          <div>
            <div class="vc-title"><?= h($tk['form_label']) ?> — Étape <?= (int)$tk['ordre'] ?> : <?= h($tk['step_label']) ?></div>
            <div class="vc-meta">
              Agent : <strong><?= $nom_agent ?: h($tk['data'] ? 'Inconnu' : '') ?></strong>
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

        <!-- Mini workflow -->
        <div class="vc-body">
          <div class="workflow-mini">
            <?php foreach ($all_steps as $i => $as):
                $dones = array_filter(explode('|', $as['dones'] ?? ''));
                $all_done = !empty($dones) && !in_array('', $dones) && !in_array(null, $dones, true);
                // Déterminer le statut
                if ($all_done) {
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

          <?php // v10.1.3 — Données clés supprimées (less is more).
                // Le validateur voit les détails en cliquant "Valider / Refuser"
                // qui mène à la page validate.php avec toutes les infos. ?>
        </div>

        <div class="vc-actions">
          <?php if (!$expired): ?>
            <a href="index.php?p=validate&token=<?= urlencode($tk['token']) ?>" class="btn btn-primary"><span aria-hidden="true">✓</span> Valider / Refuser</a>
          <?php else: ?>
            <span style="font-size:.85rem;color:#c0392b;">Token expiré — contactez un administrateur pour régénérer</span>
          <?php endif; ?>
          <!-- Bouton delegation -->
          <details style="margin-left:.5rem;">
            <summary class="btn btn-secondary" style="font-size:.8rem;padding:.4rem .75rem;cursor:pointer;display:inline;"><span aria-hidden="true">🔄</span> Déléguer</summary>
            <form method="POST" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-top:.5rem;padding:.75rem;background:#f8f8fc;border-radius:4px;border:1px solid #ddd;">
              <?= \App\Core\App::security()->csrfField() ?>
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
  <!-- Onglet : Historique -->
  <div id="tab-done">
    <?php if (empty($done_tokens)): ?>
      <div class="empty-state">
        <div class="empty-icon" aria-hidden="true">📋</div>
        <p>Vous n'avez encore validé aucune demande.</p>
      </div>
    <?php else: ?>
      <?php foreach ($done_tokens as $tk):
          $data = json_decode($tk['data'], true) ?: [];
          $nom_agent = h(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? ''));

          // Trouver l'action (validé ou refusé) dans les validations
          $action_label = 'Validé';
          $action_cls = 'badge-ok';
          if (isset($data['validations'])) {
              foreach (array_reverse($data['validations']) as $v) {
                  // Chercher la validation de cet utilisateur pour cette étape
                  // On ne peut pas matcher exactement sans l'email du token done, donc on se base sur le statut
              }
          }
          if ($tk['sub_status'] === 'refuse') {
              // Vérifier si c'est CE validateur (email courant) qui a refusé.
              // On compare $v['email'] à $user pour ne pas afficher "Refusé" sur
              // l'historique d'un validateur qui avait validé à une étape précédente.
              $refused_by_me = false;
              if (isset($data['validations'])) {
                  foreach ($data['validations'] as $v) {
                      if ($v['action'] === 'refuser' && (string)($v['email'] ?? '') === $user) {
                          $refused_by_me = true;
                          break;
                      }
                  }
              }
              if ($refused_by_me) {
                  $action_label = 'Refusé';
                  $action_cls = 'badge-err';
              } else {
                  // Soumission refusée par un autre validateur → on l'indique
                  // distinctement pour éviter la confusion.
                  $action_label = 'Validé (refusé ailleurs)';
                  $action_cls = 'badge-warn';
              }
          }
      ?>
      <div class="validation-card done">
        <div class="vc-header">
          <div>
            <div class="vc-title"><?= h($tk['form_label']) ?> — <?= h($tk['step_label']) ?></div>
            <div class="vc-meta">
              Agent : <strong><?= $nom_agent ?></strong>
              <br>Soumis le <?= h(date('d/m/Y à H:i', strtotime($tk['submitted_at']))) ?>
            </div>
          </div>
          <span class="badge <?= $action_cls ?>"><?= $action_label ?></span>
        </div>
        <div class="vc-body">
          <div class="done-info">Traitée le <strong><?= h(date('d/m/Y à H:i', strtotime($tk['done_at']))) ?></strong></div>
          <div class="done-date">Délai de traitement : <?php
            $done_ts = strtotime($tk['done_at']); $sent_ts = strtotime($tk['sent_at']);
            if ($done_ts && $sent_ts) {
                $diff_sec = $done_ts - $sent_ts;
                // Formatage lisible : "X j Y h" si > 48h, sinon "X h Y min"
                if ($diff_sec >= 86400) {
                    $days = (int)floor($diff_sec / 86400);
                    $hours = (int)floor(($diff_sec % 86400) / 3600);
                    echo h($days . ' j ' . $hours . ' h');
                } elseif ($diff_sec >= 3600) {
                    $hours = (int)floor($diff_sec / 3600);
                    $mins = (int)floor(($diff_sec % 3600) / 60);
                    echo h($hours . ' h ' . ($mins > 0 ? $mins . ' min' : ''));
                } else {
                    $mins = (int)floor($diff_sec / 60);
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

  <?php
  // P2-C : Afficher les champs validator remplis par l'utilisateur courant.
  // v10.1.3 — Less is more : remplacé le tableau toujours visible par un
  // <details> repliable. L'utilisateur déplie seulement s'il veut voir l'historique.
  $my_vd_stmt = $pdo->prepare("SELECT svd.*, s.form_id, f.label as form_label
                                FROM submission_validator_data svd
                                JOIN submissions s ON s.id = svd.submission_id
                                JOIN forms f ON f.id = s.form_id
                                WHERE svd.filled_by_email = ?
                                ORDER BY svd.filled_at DESC
                                LIMIT 50");
  $my_vd_stmt->execute([$user]);
  $my_vd_rows = $my_vd_stmt->fetchAll(PDO::FETCH_ASSOC);
  if (!empty($my_vd_rows)):
  ?>
  <details style="margin-top: 1.5rem;">
    <summary style="cursor:pointer;font-weight:600;color:var(--c-primary, #003189);font-size:.9rem;">
      📝 Champs validateur que j'ai remplis (<?= count($my_vd_rows) ?>)
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
          <?php foreach ($my_vd_rows as $r):
              $r_filled_at  = isset($r['filled_at'])    ? (string)$r['filled_at']    : '';
              $r_form_label = isset($r['form_label'])   ? (string)$r['form_label']   : '';
              $r_sub_id     = isset($r['submission_id']) ? (string)$r['submission_id'] : '';
              $r_step_label = isset($r['step_label'])   ? (string)$r['step_label']   : '';
              $r_field_lbl  = isset($r['field_label'])  ? (string)$r['field_label']  : '';
              $r_field_name = isset($r['field_name'])   ? (string)$r['field_name']   : '';
              $r_value      = isset($r['value'])        ? (string)$r['value']        : '';
              $ts = $r_filled_at !== '' ? strtotime($r_filled_at) : false;
              $r_value_short = mb_strimwidth($r_value, 0, 80, '…', 'UTF-8');
          ?>
            <tr>
              <td><?= $ts !== false ? h(date('d/m/Y H:i', $ts)) : '—' ?></td>
              <td>
                <a href="index.php?p=submission_view&id=<?= urlencode($r_sub_id) ?>"><?= h($r_form_label) ?></a>
              </td>
              <td><?= h(t_jargon($r_step_label)) ?></td>
              <td><?= h(t_jargon($r_field_lbl !== '' ? $r_field_lbl : $r_field_name)) ?></td>
              <td><?= h($r_value_short) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </details>
  <?php endif; ?>

<?php
$content = ob_get_clean();
if ($content === false) { $content = ''; }
echo render_page('Mes validations', 'mes_validations', $page_css, $content);
