<?php
// my_validations.php — Dashboard validateur : tâches en attente + historique
require_once __DIR__ . '/helpers.php';

$user = get_auth_user();
$pdo  = get_pdo();
$search = trim($_GET['search'] ?? '');

// Traitement de la delegation
$delegation_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delegate_token') {
    if (!verify_csrf()) {
        render_error_page(403, 'Requête invalide', 'Le jeton de sécurité (CSRF) de votre session est invalide ou a expiré. Cela peut arriver si votre session a été inactive trop longtemps ou si la page est restée ouverte depuis longtemps.', 'Rechargez la page et réessayez. Si le problème persiste, fermez tous les onglets de l\'application et reconnectez-vous.');
    }
    $token_id = trim($_POST['token_id'] ?? '');
    $delegate_to = trim($_POST['delegate_to'] ?? '');
    $delegate_reason = trim($_POST['delegate_reason'] ?? '');
    $result = delegate_token($token_id, $delegate_to, $delegate_reason);
    $delegation_msg = $result['message'];
}

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
if ($search) {
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

// Vérifier si un token est expiré
function is_token_expired(array $token): bool {
    if (empty($token['expires_at'])) return false;
    $exp_ts = strtotime($token['expires_at']);
    return ($exp_ts !== false && $exp_ts < time());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Mes validations — <?= h(get_app_name()) ?></title>
  <?= render_favicon() ?>
  <?php require_once __DIR__ . '/style.php'; ?>
  <style>
    .container { max-width: 1000px; }
    h1 { font-size: var(--text-2xl); margin-bottom: .25rem; }
    .subtitle { font-size: var(--text-sm); color: var(--c-text-secondary); margin-bottom: 2rem; }

    .tab-bar { display: flex; gap: 0; margin-bottom: 2rem; border-bottom: 2px solid var(--c-border-light); }
    .tab {
      padding: .75rem 1.5rem;
      font-size: var(--text-sm);
      font-weight: 600;
      color: var(--c-text-tertiary);
      cursor: pointer;
      border: none;
      background: none;
      font-family: inherit;
      border-bottom: 3px solid transparent;
      margin-bottom: -2px;
      text-decoration: none;
      display: inline-block;
      transition: all var(--duration-fast) var(--ease-out);
    }
    .tab.active { color: var(--c-primary-dark); border-bottom-color: var(--c-primary); }
    .tab:hover { color: var(--c-primary-dark); text-decoration: none; }
    .tab .tab-count { background: var(--gradient-primary); color: #fff; font-size: .68rem; padding: .1rem .5rem; border-radius: var(--r-full); margin-left: .5rem; font-weight: 700; }
    .tab-count.warn { background: var(--c-warning); color: var(--c-text); }

    .validation-card {
      background: var(--c-surface);
      border: 1px solid var(--c-border-light);
      border-radius: var(--r-lg);
      margin-bottom: 1rem;
      overflow: hidden;
      box-shadow: var(--shadow-sm);
      transition: box-shadow .2s var(--ease-out);
    }
    .validation-card:hover { box-shadow: var(--shadow-md); }
    .validation-card.expired { border-left: 4px solid var(--c-danger); opacity: .85; }
    .validation-card.pending { border-left: 4px solid var(--c-warning); }
    .validation-card.done { border-left: 4px solid var(--c-success); }

    .vc-header { padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: .5rem; }
    .vc-title { font-size: var(--text-base); font-weight: 700; color: var(--c-primary-dark); }
    .vc-meta { font-size: var(--text-xs); color: var(--c-text-tertiary); margin-top: .25rem; }
    .vc-body { padding: 0 1.25rem 1rem; }

    .vc-data-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: .5rem; margin-bottom: 1rem; font-size: var(--text-sm); }
    .vc-data-item { padding: .4rem 0; border-bottom: 1px solid var(--c-border-light); }
    .vc-data-label { font-weight: 600; color: var(--c-text-secondary); font-size: var(--text-xs); }
    .vc-data-value { color: var(--c-text); }

    .vc-actions { padding: .75rem 1.25rem; border-top: 1px solid var(--c-border-light); display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; }
    .vc-actions .btn { font-size: var(--text-xs); padding: .4rem 1rem; }

    .expired-badge { background: var(--c-danger-50); color: var(--c-danger-dark); padding: .2rem .6rem; border-radius: var(--r-full); font-size: var(--text-xs); font-weight: 700; }

    .workflow-mini { display: flex; align-items: center; gap: .25rem; margin-bottom: .75rem; flex-wrap: wrap; }
    .wf-step-mini { font-size: var(--text-xs); padding: .15rem .5rem; border-radius: var(--r-full); white-space: nowrap; font-weight: 600; }
    .wf-step-done { background: var(--c-success-50); color: var(--c-success-dark); }
    .wf-step-current { background: var(--c-warning-50); color: var(--c-warning-dark); font-weight: 700; }
    .wf-step-upcoming { background: var(--c-bg-warm); color: var(--c-text-tertiary); }
    .wf-arrow { color: var(--c-text-tertiary); font-size: .7rem; }

    .done-info { font-size: var(--text-sm); color: var(--c-text-secondary); }
    .done-date { font-size: var(--text-xs); color: var(--c-text-tertiary); }
  </style>
</head>
<body>
<a href="#main-content" class="skip-link">Aller au contenu principal</a>
<?= render_nav('mes_validations') ?>
<main class="container" id="main-content">
<?= render_breadcrumb([['Accueil', 'index.php'], ['Mes validations']]) ?>
  <h1><span aria-hidden="true">✅</span> Mes validations</h1>
  <p class="subtitle">Tâches de validation qui vous sont assignées et historique de vos validations</p>

  <div class="stats">
    <div class="stat warning"><strong><?= $pending_count ?></strong>En attente</div>
    <div class="stat success"><strong><?= $done_count ?></strong>Traitées</div>
  </div>

  <!-- Barre de recherche -->
  <?php if ($delegation_msg): ?>
    <div class="msg-info"><?= h($delegation_msg) ?></div>
  <?php endif; ?>

  <form method="GET" style="display:flex;gap:.5rem;align-items:center;margin-bottom:1.5rem;">
    <input type="text" name="search" value="<?= h($search) ?>" placeholder="Rechercher un formulaire..." style="padding:.4rem .75rem;border:1px solid #aaa;border-radius:3px;font-size:.85rem;font-family:inherit;flex:1;max-width:350px;">
    <input type="hidden" name="tab" value="<?= h($active_tab) ?>">
    <button type="submit" class="btn btn-secondary" style="font-size:.8rem;padding:.4rem .75rem;">Rechercher</button>
    <?php if ($search): ?>
      <a href="?tab=<?= h($active_tab) ?>" class="btn btn-secondary" style="font-size:.8rem;padding:.4rem .75rem;">✕ Effacer</a>
    <?php endif; ?>
  </form>

  <!-- Onglets -->
  <div class="tab-bar">
    <a href="?tab=pending" class="tab <?= $active_tab === 'pending' ? 'active' : '' ?>"><span aria-hidden="true">⏳</span> En attente <?= $pending_count > 0 ? '<span class="tab-count warn">' . $pending_count . '</span>' : '' ?></a>
    <a href="?tab=done" class="tab <?= $active_tab === 'done' ? 'active' : '' ?>"><span aria-hidden="true">✓</span> Historique (<?= $done_count ?>)</a>
  </div>

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
              <span class="wf-step-mini <?= $cls ?>" aria-hidden="true"><?= $icon ?> <?= h($as['label']) ?></span>
            <?php endforeach; ?>
          </div>

          <!-- Données clés -->
          <div class="vc-data-grid">
            <?php foreach ($data as $k => $v):
                if (empty($v) || $v === '0' || $k === 'validations' || $k === 'csrf_token') continue;
                $label = ucfirst(str_replace('_', ' ', preg_replace('/^[a-z]+_/', '', $k)));
                $display = $v === '1' ? '<span aria-hidden="true">✓</span> Oui' : h((string)$v);
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
            <a href="validate.php?token=<?= urlencode($tk['token']) ?>" class="btn btn-primary"><span aria-hidden="true">✓</span> Valider / Refuser</a>
          <?php else: ?>
            <span style="font-size:.85rem;color:#c0392b;">Token expiré — contactez un administrateur pour régénérer</span>
          <?php endif; ?>
          <!-- Bouton delegation -->
          <details style="margin-left:.5rem;">
            <summary class="btn btn-secondary" style="font-size:.8rem;padding:.4rem .75rem;cursor:pointer;display:inline;"><span aria-hidden="true">🔄</span> Déléguer</summary>
            <form method="POST" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-top:.5rem;padding:.75rem;background:#f8f8fc;border-radius:4px;border:1px solid #ddd;">
              <?= csrf_field() ?>
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
          <div class="done-date">Délai de traitement : <?php
            $done_ts = strtotime($tk['done_at']); $sent_ts = strtotime($tk['sent_at']);
            echo h(($done_ts && $sent_ts) ? round(($done_ts - $sent_ts) / 3600, 1) : '?');
          ?>h</div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</main>
<?= render_footer() ?>
</body>
</html>
