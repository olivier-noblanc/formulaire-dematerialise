<?php
// my_validations.php — Dashboard validateur : tâches en attente + historique
require_once __DIR__ . '/helpers.php';

$user = get_auth_user();
$pdo  = get_pdo();

// ── Tokens en attente pour cet utilisateur ──
$pending_stmt = $pdo->prepare("
    SELECT t.id as token_id, t.token, t.sent_at, t.expires_at, t.relance_count,
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
$pending_stmt->execute([$user]);
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

// Vérifier si un token est expiré
function is_token_expired(array $token): bool {
    return !empty($token['expires_at']) && strtotime($token['expires_at']) < time();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Mes validations — DREETS Workflow</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='15' fill='%23003189'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial'>D</text></svg>">
  <?php require_once __DIR__ . '/style.php'; ?>
  <style>
    .container { max-width: 1000px; }
    h1 { font-size: 1.5rem; margin-bottom: .25rem; }
    .subtitle { font-size: .85rem; color: #555; margin-bottom: 2rem; }

    .tab-bar { display: flex; gap: 0; margin-bottom: 2rem; border-bottom: 2px solid #ddd; }
    .tab { padding: .75rem 1.5rem; font-size: .9rem; font-weight: bold; color: #555; cursor: pointer; border: none; background: none; font-family: inherit; border-bottom: 3px solid transparent; margin-bottom: -2px; }
    .tab.active { color: #003189; border-bottom-color: #003189; }
    .tab:hover { color: #003189; }
    .tab .tab-count { background: #003189; color: #fff; font-size: .75rem; padding: .1rem .5rem; border-radius: 10px; margin-left: .5rem; }
    .tab-count.warn { background: #b45309; }

    .tab-content { display: none; }
    .tab-content.active { display: block; }

    .validation-card { background: #fff; border: 1px solid #ddd; border-radius: 6px; margin-bottom: 1rem; overflow: hidden; transition: box-shadow .15s; }
    .validation-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,.08); }
    .validation-card.expired { border-left: 4px solid #c0392b; opacity: .85; }
    .validation-card.pending { border-left: 4px solid #b45309; }
    .validation-card.done { border-left: 4px solid #1a6b3c; }

    .vc-header { padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: .5rem; }
    .vc-title { font-size: 1rem; font-weight: bold; color: #003189; }
    .vc-meta { font-size: .8rem; color: #888; margin-top: .25rem; }
    .vc-body { padding: 0 1.25rem 1rem; }

    .vc-data-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: .5rem; margin-bottom: 1rem; font-size: .85rem; }
    .vc-data-item { padding: .4rem 0; border-bottom: 1px solid #f5f5f5; }
    .vc-data-label { font-weight: bold; color: #555; font-size: .78rem; }
    .vc-data-value { color: #1e1e1e; }

    .vc-actions { padding: .75rem 1.25rem; border-top: 1px solid #eee; display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; }
    .vc-actions .btn { font-size: .8rem; padding: .4rem 1rem; }

    .expired-badge { background: #fde8e8; color: #c0392b; padding: .2rem .6rem; border-radius: 3px; font-size: .78rem; font-weight: bold; }

    .workflow-mini { display: flex; align-items: center; gap: .25rem; margin-bottom: .75rem; flex-wrap: wrap; }
    .wf-step-mini { font-size: .75rem; padding: .15rem .5rem; border-radius: 3px; white-space: nowrap; }
    .wf-step-done { background: #e8f5e9; color: #1a6b3c; }
    .wf-step-current { background: #fff3e0; color: #b45309; font-weight: bold; }
    .wf-step-upcoming { background: #f5f5f5; color: #888; }
    .wf-arrow { color: #aaa; font-size: .7rem; }

    .done-info { font-size: .85rem; color: #555; }
    .done-date { font-size: .8rem; color: #888; }
  </style>
</head>
<body>
<div class="bandeau">
  <strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités
  <span>Connecté en tant que : <strong><?= h($user) ?></strong></span>
  <span>
    <a href="my_submissions.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;">📋 Mes demandes</a>
    <a href="docs.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;margin-left:8px;">📖 Documentation</a>
    <?php if (is_admin_user()): ?>
    <a href="dashboard.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;margin-left:8px;">📊 Dashboard</a>
    <?php endif; ?>
  </span>
</div>
<div class="container">
  <h1>✅ Mes validations</h1>
  <p class="subtitle">Tâches de validation qui vous sont assignées et historique de vos validations</p>

  <div class="stats">
    <div class="stat warning"><strong><?= $pending_count ?></strong>En attente</div>
    <div class="stat success"><strong><?= $done_count ?></strong>Traitées</div>
  </div>

  <!-- Onglets -->
  <div class="tab-bar">
    <button class="tab active" onclick="showTab('pending')">⏳ En attente <?= $pending_count > 0 ? '<span class="tab-count warn">' . $pending_count . '</span>' : '' ?></button>
    <button class="tab" onclick="showTab('done')">✓ Historique (<?= $done_count ?>)</button>
  </div>

  <!-- Onglet : En attente -->
  <div id="tab-pending" class="tab-content active">
    <?php if (empty($pending_tokens)): ?>
      <div class="empty-state">
        <div class="empty-icon">🎉</div>
        <p>Aucune validation en attente — vous êtes à jour !</p>
      </div>
    <?php else: ?>
      <?php foreach ($pending_tokens as $tk):
          $data = json_decode($tk['data'], true) ?: [];
          $expired = is_token_expired($tk);
          $nom_agent = h(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? ''));

          // Mini workflow : quelles étapes sont faites / en cours / à venir
          $all_steps_stmt = $pdo->prepare("
              SELECT st.id, st.label, st.ordre,
                     GROUP_CONCAT(t2.done_at, '|') as dones
              FROM steps st
              LEFT JOIN tokens t2 ON t2.step_id = st.id AND t2.submission_id = ?
              WHERE st.form_id = (SELECT form_id FROM submissions WHERE id = ?) AND st.actif = 1
              GROUP BY st.id
              ORDER BY st.ordre
          ");
          $all_steps_stmt->execute([$tk['submission_id'], $tk['submission_id']]);
          $all_steps = $all_steps_stmt->fetchAll(PDO::FETCH_ASSOC);
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
            <span class="expired-badge">⏰ Expiré</span>
          <?php else: ?>
            <span class="badge badge-warn">⏳ En attente de votre validation</span>
          <?php endif; ?>
        </div>

        <!-- Mini workflow -->
        <div class="vc-body">
          <div class="workflow-mini">
            <?php foreach ($all_steps as $i => $as):
                $dones = array_filter(explode('|', $as['dones'] ?? ''));
                $all_done = !empty($dones) && count($dones) > 0 && !in_array('', $dones) && !in_array(null, $dones, true);
                $is_current = $as['id'] == $tk['step_id'] || (!$all_done && !empty($dones));
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
              <span class="wf-step-mini <?= $cls ?>"><?= $icon ?> <?= h($as['label']) ?></span>
            <?php endforeach; ?>
          </div>

          <!-- Données clés -->
          <div class="vc-data-grid">
            <?php foreach ($data as $k => $v):
                if (empty($v) || $v === '0' || $k === 'validations' || $k === 'csrf_token') continue;
                $label = ucfirst(str_replace('_', ' ', preg_replace('/^[a-z]+_/', '', $k)));
                $display = $v === '1' ? '✓ Oui' : h((string)$v);
            ?>
              <div class="vc-data-item">
                <div class="vc-data-label"><?= h($label) ?></div>
                <div class="vc-data-value"><?= $display ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="vc-actions">
          <?php if (!$expired): ?>
            <a href="validate.php?token=<?= urlencode($tk['token']) ?>" class="btn btn-primary">✓ Valider / Refuser</a>
          <?php else: ?>
            <span style="font-size:.85rem;color:#c0392b;">Token expiré — contactez un administrateur pour régénérer</span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Onglet : Historique -->
  <div id="tab-done" class="tab-content">
    <?php if (empty($done_tokens)): ?>
      <div class="empty-state">
        <div class="empty-icon">📋</div>
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
              // Vérifier si c'est ce validateur qui a refusé
              $refused_by_me = false;
              if (isset($data['validations'])) {
                  foreach ($data['validations'] as $v) {
                      if ($v['action'] === 'refuser') {
                          $refused_by_me = true;
                          break;
                      }
                  }
              }
              if ($refused_by_me) {
                  $action_label = 'Refusé';
                  $action_cls = 'badge-err';
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
          <div class="done-date">Délai de traitement : <?= h(round((strtotime($tk['done_at']) - strtotime($tk['sent_at'])) / 3600, 1)) ?>h</div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<script>
function showTab(name) {
  document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.tab').forEach(el => el.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  event.target.closest('.tab').classList.add('active');
}
</script>
<?= render_footer() ?>
</body>
</html>
