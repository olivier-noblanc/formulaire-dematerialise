<?php
// my_submissions.php — Page "Mes demandes" pour l'agent connecté
require_once __DIR__ . '/helpers.php';

$user = get_auth_user();
$pdo  = get_pdo();

// Récupérer toutes les soumissions de l'agent
$stmt = $pdo->prepare("
    SELECT s.id, s.form_id, s.data, s.submitted_at, s.status, s.closed_at,
           f.label as form_label, f.slug as form_slug, f.description as form_description, f.deadline_field
    FROM submissions s
    JOIN forms f ON f.id = s.form_id
    WHERE s.submitted_by = ?
    ORDER BY s.submitted_at DESC
");
$stmt->execute([$user]);
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pour chaque soumission, récupérer les étapes du workflow avec leur statut
foreach ($submissions as &$sub) {
    $sid = (int)$sub['id'];

    $steps_stmt = $pdo->prepare("
        SELECT st.id as step_id, st.label as step_label, st.ordre,
               GROUP_CONCAT(sr.email, '|') as recipient_emails
        FROM steps st
        LEFT JOIN step_recipients sr ON sr.step_id = st.id
        WHERE st.form_id = ? AND st.actif = 1
        GROUP BY st.id
        ORDER BY st.ordre ASC, st.id ASC
    ");
    $steps_stmt->execute([(int)$sub['form_id']]);
    $sub['workflow_steps'] = $steps_stmt->fetchAll(PDO::FETCH_ASSOC);

    $tokens_stmt = $pdo->prepare("
        SELECT t.step_id, t.email, t.done_at, t.sent_at, st.label as step_label, st.ordre
        FROM tokens t
        JOIN steps st ON st.id = t.step_id
        WHERE t.submission_id = ?
        ORDER BY st.ordre ASC, st.label ASC
    ");
    $tokens_stmt->execute([$sid]);
    $sub['tokens'] = $tokens_stmt->fetchAll(PDO::FETCH_ASSOC);

    $tokens_by_step = [];
    foreach ($sub['tokens'] as $tok) {
        $tokens_by_step[$tok['step_id']][] = $tok;
    }

    foreach ($sub['workflow_steps'] as &$ws) {
        $step_id = $ws['step_id'];
        if (!isset($tokens_by_step[$step_id]) || empty($tokens_by_step[$step_id])) {
            $ws['step_status'] = 'upcoming';
            $ws['step_detail'] = '';
        } else {
            $all_done = true;
            $detail_parts = [];
            foreach ($tokens_by_step[$step_id] as $tok) {
                if (!empty($tok['done_at'])) {
                    $detail_parts[] = h($tok['email']) . ' ✓';
                } else {
                    $all_done = false;
                    $detail_parts[] = h($tok['email']) . ' ⏳';
                }
            }
            $ws['step_status'] = $all_done ? 'validated' : 'current';
            $ws['step_detail'] = implode('<br>', $detail_parts);
        }
    }
    unset($ws);

    // Calculer progression
    $total = count($sub['workflow_steps']);
    $done = count(array_filter($sub['workflow_steps'], fn($s) => $s['step_status'] === 'validated'));
    $sub['progress_pct'] = $total > 0 ? round(($done / $total) * 100) : 0;
    $sub['progress_done'] = $done;
    $sub['progress_total'] = $total;
}
unset($sub);

// Compteurs pour le résumé
$total_count = count($submissions);
$en_cours_count = 0;
$valide_count = 0;
$refuse_count = 0;
foreach ($submissions as $s) {
    if ($s['status'] === 'valide') $valide_count++;
    elseif ($s['status'] === 'refuse') $refuse_count++;
    else $en_cours_count++;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Mes demandes — DREETS</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='15' fill='%23003189'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial'>D</text></svg>">
  <?php require_once __DIR__ . '/style.php'; ?>
  <style>
    body { padding: 0; }
    .container { max-width: 900px; }
    h1 { font-size: 1.5rem; margin-bottom: .25rem; }

    /* Page-specific */
    .subtitle { font-size: .85rem; color: #555; margin-bottom: 2rem; }

    /* Stats */
    .stats { display: flex; gap: 1rem; margin-bottom: 2rem; flex-wrap: wrap; }
    .stat { background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: .75rem 1.25rem; min-width: 100px; text-align: center; }
    .stat strong { display: block; font-size: 1.8rem; color: #003189; }
    .stat.en-cours strong { color: #b45309; }
    .stat.valide strong { color: #1a6b3c; }
    .stat.refuse strong { color: #c0392b; }
    .stat span { font-size: .8rem; color: #888; }

    /* Card */
    .sub-card { background: #fff; border: 1px solid #ddd; border-radius: 6px; margin-bottom: 1.25rem; overflow: hidden; }
    .sub-card-header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem; background: #f8f8fc; border-bottom: 1px solid #eee; cursor: pointer; }
    .sub-card-header:hover { background: #f0f0f8; }
    .sub-card-title { font-weight: bold; color: #003189; font-size: 1rem; }
    .sub-card-date { font-size: .8rem; color: #888; margin-top: .15rem; }
    .sub-card-body { padding: 1.25rem; }

    /* Progress bar inline */
    .inline-progress { display: flex; align-items: center; gap: .75rem; margin-bottom: 1rem; }
    .inline-progress-bar { flex: 1; background: #e0e0e0; border-radius: 8px; height: 14px; overflow: hidden; }
    .inline-progress-fill { height: 100%; border-radius: 8px; }
    .inline-progress-fill.complete { background: #1a6b3c; }
    .inline-progress-fill.in-progress { background: linear-gradient(90deg, #1a6b3c, #b45309); }
    .inline-progress-text { font-size: .8rem; color: #555; white-space: nowrap; min-width: 80px; text-align: right; }

    /* Timeline compact */
    .timeline-compact { display: flex; flex-direction: column; gap: .35rem; }
    .tl-step { display: flex; align-items: center; gap: .5rem; font-size: .85rem; padding: .35rem .6rem; border-radius: 4px; }
    .tl-step.done { background: #e8f5e9; color: #1a6b3c; }
    .tl-step.active { background: #fff3e0; color: #b45309; font-weight: bold; border: 1px dashed #b45309; }
    .tl-step.waiting { background: #f5f5f5; color: #999; }
    .tl-icon { font-size: .85rem; flex-shrink: 0; }
    .tl-label { flex: 1; }
    .tl-detail { font-size: .75rem; color: #888; }

    /* Refusal box */
    .refusal-box { background: #fde8e8; border-radius: 4px; padding: .75rem; font-size: .85rem; margin-top: .75rem; }

    /* Deadline badge */
    .deadline-badge { display: inline-flex; align-items: center; gap: .35rem; font-size: .8rem; padding: .2rem .6rem; border-radius: 3px; margin-left: .5rem; }
    .deadline-badge.overdue { background: #fde8e8; color: #c0392b; font-weight: bold; }
    .deadline-badge.urgent { background: #fff3e0; color: #b45309; font-weight: bold; }
    .deadline-badge.ok { background: #e8f5e9; color: #1a6b3c; }

    .card-actions { margin-top: 1rem; padding-top: .75rem; border-top: 1px solid #eee; display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; }
  </style>
</head>
<body>
<div class="bandeau">
  <strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités
  <span>Connecté en tant que : <strong><?= h($user) ?></strong></span>
  <span>
    <a href="my_validations.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;">✅ Mes validations</a>
    <a href="docs.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;margin-left:8px;">📖 Documentation</a>
    <?php if (is_admin_user()): ?>
    <a href="admin_settings.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;margin-left:8px;">⚙ Paramètres</a>
    <?php endif; ?>
  </span>
</div>
<div class="container">
  <h1>📋 Mes demandes</h1>
  <p class="subtitle">Suivi de toutes vos demandes de workflow en tant qu'agent</p>

  <?php if ($total_count > 0): ?>
  <div class="stats">
    <div class="stat"><strong><?= $total_count ?></strong><span>Total</span></div>
    <div class="stat en-cours"><strong><?= $en_cours_count ?></strong><span>En cours</span></div>
    <div class="stat valide"><strong><?= $valide_count ?></strong><span>Validées</span></div>
    <div class="stat refuse"><strong><?= $refuse_count ?></strong><span>Refusées</span></div>
  </div>
  <?php endif; ?>

  <?php if (empty($submissions)): ?>
    <div class="empty-state">
      <div class="empty-icon">📝</div>
      <p>Vous n'avez encore soumis aucune demande.</p>
      <?php
        $active_forms = $pdo->query("SELECT slug, label FROM forms WHERE actif = 1 ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($active_forms)):
      ?>
        <p style="font-size:.9rem;color:#555;margin-bottom:.5rem;">Formulaires disponibles :</p>
        <?php foreach ($active_forms as $af): ?>
          <a href="form.php?f=<?= h($af['slug']) ?>" class="btn btn-primary" style="margin:.25rem;"><?= h($af['label']) ?></a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <?php foreach ($submissions as $sub):
        $data = json_decode($sub['data'], true);
        $status = $sub['status'] ?? 'en_cours';
        $status_label = $status === 'valide' ? 'Validée' : ($status === 'refuse' ? 'Refusée' : 'En cours');
        $badge_cls = $status === 'valide' ? 'badge-valide' : ($status === 'refuse' ? 'badge-refuse' : 'badge-en-cours');

        // Deadline
        $deadline_field = $sub['deadline_field'] ?? '';
        $deadline_val = $deadline_field ? ($data[$deadline_field] ?? '') : '';
        $deadline_badge = '';
        if (!empty($deadline_val) && $status === 'en_cours') {
            $deadline_ts = null;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($deadline_val))) {
                $deadline_ts = strtotime(trim($deadline_val));
            } elseif (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', trim($deadline_val), $m)) {
                $deadline_ts = strtotime("{$m[3]}-{$m[2]}-{$m[1]}");
            }
            if ($deadline_ts) {
                $dl_days = (int)(($deadline_ts - time()) / 86400);
                if ($dl_days < 0) $deadline_badge = '<span class="deadline-badge overdue">🚨 J+' . abs($dl_days) . '</span>';
                elseif ($dl_days <= 2) $deadline_badge = '<span class="deadline-badge urgent">⚠️ J-' . $dl_days . '</span>';
                elseif ($dl_days <= 5) $deadline_badge = '<span class="deadline-badge ok">📅 J-' . $dl_days . '</span>';
            }
        }

        // Progression
        $pct = $sub['progress_pct'];
        $fill_cls = $pct === 100 ? 'complete' : ($pct > 0 ? 'in-progress' : 'in-progress');
    ?>
    <div class="sub-card">
      <a href="submission_view.php?id=<?= (int)$sub['id'] ?>" style="text-decoration:none;color:inherit;">
      <div class="sub-card-header">
        <div>
          <div class="sub-card-title"><?= h($sub['form_label']) ?> <?= $deadline_badge ?></div>
          <div class="sub-card-date">Soumis le <?= h(date('d/m/Y à H:i', strtotime($sub['submitted_at']))) ?> — <?= h(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? '')) ?></div>
        </div>
        <span class="badge <?= $badge_cls ?>"><?= $status_label ?></span>
      </div>
      </a>
      <div class="sub-card-body">
        <!-- Progression bar -->
        <div class="inline-progress">
          <div class="inline-progress-bar">
            <div class="inline-progress-fill <?= $fill_cls ?>" style="width:<?= max($pct, 3) ?>%;"></div>
          </div>
          <div class="inline-progress-text"><?= $sub['progress_done'] ?>/<?= $sub['progress_total'] ?> étapes (<?= $pct ?>%)</div>
        </div>

        <!-- Timeline compact -->
        <div class="timeline-compact">
          <?php foreach ($sub['workflow_steps'] as $ws):
            $cls = $ws['step_status'] === 'validated' ? 'done' : ($ws['step_status'] === 'current' ? 'active' : 'waiting');
            $icon = $ws['step_status'] === 'validated' ? '✓' : ($ws['step_status'] === 'current' ? '⏳' : '○');
          ?>
            <div class="tl-step <?= $cls ?>">
              <span class="tl-icon"><?= $icon ?></span>
              <span class="tl-label"><?= h($ws['step_label']) ?></span>
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
                      echo '<strong>Refusé par :</strong> ' . h($v['email']) . ' (' . h($v['step_label']) . ')';
                      if (!empty($v['commentaire'])) echo '<br><strong>Motif :</strong> ' . h($v['commentaire']);
                      break;
                  }
              }
            ?>
          </div>
        <?php endif; ?>

        <div class="card-actions">
          <a href="submission_view.php?id=<?= (int)$sub['id'] ?>" class="btn btn-primary" style="font-size:.85rem;">👁 Voir le détail</a>
          <a href="form.php?f=<?= h($sub['form_slug']) ?>" class="btn btn-secondary" style="font-size:.85rem;">Nouvelle demande</a>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?= render_footer() ?>
</body>
</html>
