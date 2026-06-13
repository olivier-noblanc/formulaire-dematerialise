<?php
// submission_view.php — Page de detail d'une soumission avec workflow visuel
require_once __DIR__ . '/helpers.php';

$pdo = get_pdo();
$sub_id = (int)($_GET['id'] ?? 0);

if ($sub_id <= 0) {
    header('Location: dashboard.php');
    exit;
}

// Récupérer la soumission
$stmt = $pdo->prepare("
    SELECT s.*, f.label as form_label, f.slug as form_slug, f.deadline_field
    FROM submissions s
    JOIN forms f ON f.id = s.form_id
    WHERE s.id = ?
");
$stmt->execute([$sub_id]);
$sub = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sub) {
    die('Soumission introuvable.');
}

$data = json_decode($sub['data'], true) ?: [];
$status = $sub['status'] ?? 'en_cours';
$user = get_auth_user();
$is_admin = is_admin_user();

// Vérifier l'accès : admin ou propriétaire
if (!$is_admin && $sub['submitted_by'] !== $user) {
    // Vérifier aussi si l'utilisateur est validateur sur cette soumission
    $validator_check = $pdo->prepare("SELECT 1 FROM tokens WHERE submission_id = ? AND email = ?");
    $validator_check->execute([$sub_id, $user]);
    if (!$validator_check->fetch()) {
        header('Location: dashboard.php');
        exit;
    }
}

// Récupérer toutes les étapes du workflow
$steps_stmt = $pdo->prepare("
    SELECT st.id as step_id, st.label as step_label, st.ordre, st.actif,
           GROUP_CONCAT(sr.email, '|') as recipient_emails
    FROM steps st
    LEFT JOIN step_recipients sr ON sr.step_id = st.id
    WHERE st.form_id = ?
    GROUP BY st.id
    ORDER BY st.ordre ASC, st.id ASC
");
$steps_stmt->execute([(int)$sub['form_id']]);
$workflow_steps = $steps_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les tokens pour cette soumission
$tokens_stmt = $pdo->prepare("
    SELECT t.id, t.step_id, t.email, t.token, t.done_at, t.sent_at, t.relance_count, t.expires_at,
           st.label as step_label, st.ordre
    FROM tokens t
    JOIN steps st ON st.id = t.step_id
    WHERE t.submission_id = ?
    ORDER BY st.ordre ASC, st.label ASC
");
$tokens_stmt->execute([$sub_id]);
$all_tokens = $tokens_stmt->fetchAll(PDO::FETCH_ASSOC);

// Grouper tokens par step_id
$tokens_by_step = [];
foreach ($all_tokens as $tok) {
    $tokens_by_step[$tok['step_id']][] = $tok;
}

// Déterminer le statut de chaque étape
foreach ($workflow_steps as &$ws) {
    $step_id = $ws['step_id'];
    if (!isset($tokens_by_step[$step_id]) || empty($tokens_by_step[$step_id])) {
        $ws['step_status'] = 'upcoming';
        $ws['tokens'] = [];
    } else {
        $ws['tokens'] = $tokens_by_step[$step_id];
        $all_done = true;
        foreach ($tokens_by_step[$step_id] as $tok) {
            if (empty($tok['done_at'])) $all_done = false;
        }
        $ws['step_status'] = $all_done ? 'validated' : 'current';
    }
}
unset($ws);

// Calculer la progression
$total_steps = count($workflow_steps);
$done_steps = count(array_filter($workflow_steps, fn($s) => $s['step_status'] === 'validated'));
$progress_pct = $total_steps > 0 ? round(($done_steps / $total_steps) * 100) : 0;

// Date limite
$deadline_field = $sub['deadline_field'] ?? '';
$deadline_val = $deadline_field ? ($data[$deadline_field] ?? '') : '';
$deadline_ts = null;
$days_remaining = null;
if (!empty($deadline_val)) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($deadline_val))) {
        $deadline_ts = strtotime(trim($deadline_val));
    } elseif (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', trim($deadline_val), $m)) {
        $deadline_ts = strtotime("{$m[3]}-{$m[2]}-{$m[1]}");
    }
    if ($deadline_ts) {
        $days_remaining = (int)(($deadline_ts - time()) / 86400);
    }
}

// Traitement des actions POST
$action_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('Token CSRF invalide.');

    $action = $_POST['action'] ?? '';

    if ($action === 'regenerate_token' && $is_admin) {
        $token_id = (int)($_POST['token_id'] ?? 0);
        $result = regenerate_token($token_id);
        $action_msg = $result['message'];
    }
    elseif ($action === 'cancel_submission') {
        if ($is_admin || $sub['submitted_by'] === $user) {
            $result = cancel_submission($sub_id, $user);
            $action_msg = $result['message'];
            // Rafraîchir les données
            header('Location: submission_view.php?id=' . $sub_id);
            exit;
        }
    }
}

// Nom de l'agent
$nom_agent = h(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? ''));
$status_label = $status === 'valide' ? 'Validée' : ($status === 'refuse' ? 'Refusée / Annulée' : 'En cours');
$status_cls = $status === 'valide' ? 'badge-valide' : ($status === 'refuse' ? 'badge-refuse' : 'badge-en-cours');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Soumission #<?= $sub_id ?> — DREETS</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='15' fill='%23003189'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial'>D</text></svg>">
  <?php require_once __DIR__ . '/style.php'; ?>
  <style>
    .container { max-width: 1000px; }
    .back-link { display: inline-block; margin-bottom: 1.5rem; font-size: .85rem; color: #003189; text-decoration: none; }
    .back-link:hover { text-decoration: underline; }

    /* Header */
    .sub-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem; }
    .sub-title { font-size: 1.4rem; color: #003189; margin-bottom: .25rem; }
    .sub-meta { font-size: .85rem; color: #888; line-height: 1.8; }

    /* Progress bar */
    .progress-section { margin-bottom: 2rem; }
    .progress-bar-container { background: #e0e0e0; border-radius: 12px; height: 28px; overflow: hidden; position: relative; margin-bottom: .5rem; }
    .progress-bar-fill { height: 100%; border-radius: 12px; transition: width .3s; display: flex; align-items: center; justify-content: center; color: #fff; font-size: .8rem; font-weight: bold; }
    .progress-bar-fill.complete { background: #1a6b3c; }
    .progress-bar-fill.in-progress { background: linear-gradient(90deg, #1a6b3c <?= $progress_pct ?>%, #b45309 <?= $progress_pct ?>%); }
    .progress-bar-fill.not-started { background: #ccc; color: #666; }
    .progress-label { font-size: .85rem; color: #555; text-align: center; }

    /* Deadline */
    .deadline-card { background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: 1rem 1.5rem; margin-bottom: 2rem; display: flex; align-items: center; gap: 1rem; }
    .deadline-card.overdue { border-left: 5px solid #c0392b; background: #fff5f5; }
    .deadline-card.urgent { border-left: 5px solid #b45309; background: #fff8f0; }
    .deadline-card.ok { border-left: 5px solid #1a6b3c; }
    .deadline-card .dl-icon { font-size: 2rem; }
    .deadline-card .dl-text { flex: 1; }
    .deadline-card .dl-date { font-size: 1.3rem; font-weight: bold; color: #003189; }
    .deadline-card .dl-remaining { font-size: .9rem; font-weight: bold; }
    .deadline-card .dl-remaining.overdue { color: #c0392b; }
    .deadline-card .dl-remaining.urgent { color: #b45309; }
    .deadline-card .dl-remaining.ok { color: #1a6b3c; }

    /* Workflow diagram */
    .workflow-diagram { margin-bottom: 2rem; }
    .wf-flow { display: flex; align-items: flex-start; gap: 0; overflow-x: auto; padding: 1rem 0; }
    .wf-step-group { display: flex; flex-direction: column; gap: .5rem; align-items: center; }
    .wf-step { border-radius: 10px; padding: 1rem 1.25rem; min-width: 180px; max-width: 220px; text-align: center; position: relative; }
    .wf-step.validated { background: #e8f5e9; border: 2px solid #1a6b3c; }
    .wf-step.current { background: #fff3e0; border: 2px dashed #b45309; }
    .wf-step.upcoming { background: #f5f5f5; border: 2px solid #ddd; }
    .wf-step.refused { background: #fde8e8; border: 2px solid #c0392b; }
    .wf-step .wf-ordre { font-size: .7rem; font-weight: bold; text-transform: uppercase; letter-spacing: .05em; margin-bottom: .25rem; }
    .wf-step.validated .wf-ordre { color: #1a6b3c; }
    .wf-step.current .wf-ordre { color: #b45309; }
    .wf-step.upcoming .wf-ordre { color: #999; }
    .wf-step .wf-label { font-weight: bold; font-size: .95rem; margin-bottom: .5rem; }
    .wf-step.validated .wf-label { color: #1a6b3c; }
    .wf-step.current .wf-label { color: #b45309; }
    .wf-step.upcoming .wf-label { color: #888; }
    .wf-step .wf-validators { font-size: .75rem; line-height: 1.5; }
    .wf-step .wf-validator-item { display: flex; align-items: center; justify-content: center; gap: .25rem; margin-bottom: .15rem; }
    .wf-step .wf-check { color: #1a6b3c; font-weight: bold; }
    .wf-step .wf-pending { color: #b45309; }
    .wf-step .wf-waiting { color: #999; }
    .wf-connector { display: flex; align-items: center; padding: 0 .25rem; flex-shrink: 0; }
    .wf-connector .arrow { color: #003189; font-size: 1.5rem; font-weight: bold; }

    /* Data cards */
    .data-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem 1.5rem; }
    .data-item { padding: .5rem 0; border-bottom: 1px solid #f0f0f0; }
    .data-item .data-label { font-size: .8rem; color: #888; font-weight: bold; }
    .data-item .data-value { font-size: .9rem; color: #333; margin-top: .1rem; }
    .data-group-title { grid-column: 1 / -1; font-size: .95rem; color: #003189; font-weight: bold; border-bottom: 2px solid #003189; padding-bottom: .5rem; margin-top: .75rem; }

    /* Validation history */
    .val-item { display: flex; gap: 1rem; padding: .75rem 0; border-bottom: 1px solid #f0f0f0; }
    .val-item:last-child { border-bottom: none; }
    .val-icon { font-size: 1.2rem; flex-shrink: 0; margin-top: .1rem; }
    .val-content { flex: 1; }
    .val-header { font-weight: bold; font-size: .9rem; }
    .val-detail { font-size: .8rem; color: #888; margin-top: .2rem; }
    .val-comment { font-size: .85rem; color: #555; background: #f5f5fe; padding: .5rem; border-radius: 3px; margin-top: .35rem; font-style: italic; }

    /* Actions */
    .actions-bar { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #eee; }

    @media (max-width: 768px) {
      .data-grid { grid-template-columns: 1fr; }
      .wf-flow { flex-direction: column; align-items: stretch; }
      .wf-connector { justify-content: center; padding: .25rem 0; }
      .wf-connector .arrow { transform: rotate(90deg); }
      .wf-step { min-width: auto; max-width: none; }
    }
  </style>
</head>
<body>
<div class="bandeau">
  <strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités
  <span>Connecté en tant que : <strong><?= h($user) ?></strong></span>
  <span>
    <?php if ($is_admin): ?>
      <a href="dashboard.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;">📊 Dashboard</a>
    <?php endif; ?>
    <a href="my_submissions.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;<?= $is_admin ? 'margin-left:8px;' : '' ?>">📋 Mes demandes</a>
    <a href="docs.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;margin-left:8px;">📖 Documentation</a>
  </span>
</div>
<div class="container">

  <?php if ($is_admin): ?>
    <a href="dashboard.php" class="back-link">← Retour au dashboard</a>
  <?php else: ?>
    <a href="my_submissions.php" class="back-link">← Retour à mes demandes</a>
  <?php endif; ?>

  <?php if ($action_msg): ?>
    <div class="msg-info"><?= h($action_msg) ?></div>
  <?php endif; ?>

  <!-- Header -->
  <div class="sub-header">
    <div>
      <div class="sub-title">Soumission #<?= $sub_id ?> — <?= h($sub['form_label']) ?></div>
      <div class="sub-meta">
        Agent : <strong><?= $nom_agent ?: h($sub['submitted_by']) ?></strong><br>
        Soumis le : <strong><?= h(date('d/m/Y à H:i', strtotime($sub['submitted_at']))) ?></strong>
        <?php if (!empty($sub['closed_at'])): ?>
          <br>Clôturé le : <strong><?= h(date('d/m/Y à H:i', strtotime($sub['closed_at']))) ?></strong>
        <?php endif; ?>
      </div>
    </div>
    <span class="badge <?= $status_cls ?>" style="font-size:1rem;padding:.5rem 1.25rem;"><?= $status_label ?></span>
  </div>

  <!-- Progression -->
  <div class="progress-section">
    <div class="progress-bar-container">
      <div class="progress-bar-fill <?= $progress_pct === 100 ? 'complete' : ($progress_pct > 0 ? 'in-progress' : 'not-started') ?>" style="width:<?= max($progress_pct, 8) ?>%;">
        <?= $progress_pct ?>%
      </div>
    </div>
    <div class="progress-label"><?= $done_steps ?> / <?= $total_steps ?> étapes validées</div>
  </div>

  <!-- Deadline -->
  <?php if ($deadline_ts && $status === 'en_cours'): ?>
    <?php
      $dl_cls = $days_remaining < 0 ? 'overdue' : ($days_remaining <= 2 ? 'urgent' : 'ok');
      $dl_icon = $days_remaining < 0 ? '🚨' : ($days_remaining <= 2 ? '⚠️' : '📅');
      $dl_text = $days_remaining < 0 ? 'Date dépassée de ' . abs($days_remaining) . ' jour(s)' : ($days_remaining === 0 ? "C'est aujourd'hui !" : "Plus que {$days_remaining} jour(s)");
    ?>
    <div class="deadline-card <?= $dl_cls ?>">
      <div class="dl-icon"><?= $dl_icon ?></div>
      <div class="dl-text">
        <div class="dl-date">Date cible : <?= h(date('d/m/Y', $deadline_ts)) ?></div>
        <div class="dl-remaining <?= $dl_cls ?>"><?= $dl_text ?></div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Workflow diagram -->
  <div class="card">
    <h2>🔀 Circuit de validation</h2>
    <div class="workflow-diagram">
      <div class="wf-flow">
        <?php foreach ($workflow_steps as $i => $ws):
          $step_cls = $ws['step_status'];
          // Si la soumission est refusee, les étapes en cours deviennent "refused"
          if ($status === 'refuse' && $ws['step_status'] === 'current') $step_cls = 'refused';
        ?>
          <?php if ($i > 0): ?>
            <div class="wf-connector"><span class="arrow">→</span></div>
          <?php endif; ?>
          <div class="wf-step <?= $step_cls ?>">
            <div class="wf-ordre">Étape <?= (int)$ws['ordre'] ?></div>
            <div class="wf-label"><?= h($ws['step_label']) ?></div>
            <div class="wf-validators">
              <?php if (!empty($ws['tokens'])): ?>
                <?php foreach ($ws['tokens'] as $tok): ?>
                  <div class="wf-validator-item">
                    <?php if (!empty($tok['done_at'])): ?>
                      <span class="wf-check">✓</span>
                    <?php elseif ($ws['step_status'] === 'current'): ?>
                      <span class="wf-pending">⏳</span>
                    <?php else: ?>
                      <span class="wf-waiting">○</span>
                    <?php endif; ?>
                    <span><?= h($tok['email']) ?></span>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="wf-waiting">En attente de démarrage</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($is_admin && $status === 'en_cours'): ?>
    <div class="actions-bar">
      <?php foreach ($all_tokens as $tok): ?>
        <?php if (empty($tok['done_at'])): ?>
          <form method="POST" style="display:inline;" onsubmit="return confirm('Régénérer le lien pour <?= h($tok['email']) ?> ?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="regenerate_token">
            <input type="hidden" name="token_id" value="<?= (int)$tok['id'] ?>">
            <button type="submit" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;">🔄 Relancer <?= h($tok['email']) ?></button>
          </form>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Données du formulaire -->
  <div class="card">
    <h2>📋 Données du formulaire</h2>
    <div class="data-grid">
      <?php
        // Regrouper les données par card_group si on a les infos
        $field_info = [];
        $fields_stmt2 = $pdo->prepare("SELECT field_name, label, card_group, field_type FROM form_fields WHERE form_id = ? ORDER BY ordre");
        $fields_stmt2->execute([(int)$sub['form_id']]);
        foreach ($fields_stmt2->fetchAll(PDO::FETCH_ASSOC) as $fi) {
            $field_info[$fi['field_name']] = $fi;
        }

        $current_group = '';
        foreach ($data as $k => $v):
          if ($k === 'validations') continue;
          if (empty($v) && $v !== '0') continue;

          $group = isset($field_info[$k]) ? $field_info[$k]['card_group'] : '';
          $label = isset($field_info[$k]) ? $field_info[$k]['label'] : ucfirst(str_replace('_', ' ', preg_replace('/^[a-z]+_/', '', $k)));
          $display_val = $v === '1' ? '✓ Oui' : ($v === '0' ? 'Non' : h((string)$v));

          if ($group !== $current_group && !empty($group)):
            $current_group = $group;
      ?>
        <div class="data-group-title"><?= h($group) ?></div>
      <?php endif; ?>
        <div class="data-item">
          <div class="data-label"><?= h($label) ?></div>
          <div class="data-value"><?= $display_val ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Historique des validations -->
  <?php if (isset($data['validations']) && is_array($data['validations']) && !empty($data['validations'])): ?>
  <div class="card">
    <h2>📝 Historique des validations</h2>
    <?php foreach ($data['validations'] as $v):
      $is_valid = $v['action'] === 'valider';
      $icon = $is_valid ? '✅' : '❌';
    ?>
    <div class="val-item">
      <div class="val-icon"><?= $icon ?></div>
      <div class="val-content">
        <div class="val-header">
          <?= h($v['step_label']) ?> — <?= h($v['email']) ?>
          <span style="color:<?= $is_valid ? '#1a6b3c' : '#c0392b' ?>;">
            <?= $is_valid ? 'Validé' : 'Refusé' ?>
          </span>
        </div>
        <div class="val-detail"><?= h($v['date']) ?></div>
        <?php if (!empty($v['commentaire'])): ?>
          <div class="val-comment">💬 <?= h($v['commentaire']) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Actions -->
  <?php if ($status === 'en_cours' && ($is_admin || $sub['submitted_by'] === $user)): ?>
  <div class="card">
    <h2>⚙ Actions</h2>
    <form method="POST" onsubmit="return confirm('Annuler cette soumission ? Cette action est irréversible.');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="cancel_submission">
      <button type="submit" class="btn btn-danger">🗑 Annuler la soumission</button>
    </form>
  </div>
  <?php endif; ?>

</div>
<?= render_footer() ?>
</body>
</html>
