<?php
// my_submissions.php — Page "Mes demandes" pour l'agent connecté
require_once __DIR__ . '/helpers.php';

$user = get_auth_user();
$pdo  = get_pdo();
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['statut'] ?? 'tous';

// Récupérer toutes les soumissions de l'agent
$where = ['s.submitted_by = ?'];
$params = [$user];
if ($search) {
    $where[] = "(f.label LIKE ? OR s.data LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($status_filter === 'en_cours') { $where[] = "s.status = 'en_cours'"; }
elseif ($status_filter === 'valide') { $where[] = "s.status = 'valide'"; }
elseif ($status_filter === 'refuse') { $where[] = "s.status = 'refuse'"; }
$where_sql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT s.id, s.form_id, s.data, s.submitted_at, s.status, s.closed_at,
           f.label as form_label, f.slug as form_slug, f.description as form_description, f.deadline_field
    FROM submissions s
    JOIN forms f ON f.id = s.form_id
    WHERE $where_sql
    ORDER BY s.submitted_at DESC
");
$stmt->execute($params);
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pour chaque soumission, récupérer les étapes du workflow avec leur statut
foreach ($submissions as &$sub) {
    $sid = $sub['id'];

    $steps_stmt = $pdo->prepare("
        SELECT st.id as step_id, st.label as step_label, st.ordre,
               GROUP_CONCAT(sr.email, '|') as recipient_emails
        FROM steps st
        LEFT JOIN step_recipients sr ON sr.step_id = st.id
        WHERE st.form_id = ? AND st.actif = 1
        GROUP BY st.id
        ORDER BY st.ordre ASC, st.id ASC
    ");
    $steps_stmt->execute([$sub['form_id']]);
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
                    $detail_parts[] = h($tok['email']) . ' <span aria-hidden="true">✓</span>';
                } else {
                    $all_done = false;
                    $detail_parts[] = h($tok['email']) . ' <span aria-hidden="true">⏳</span>';
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
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><defs><linearGradient id='g' x1='0' y1='0' x2='1' y2='1'><stop offset='0%25' stop-color='%231E40AF'/><stop offset='100%25' stop-color='%233B82F6'/></linearGradient></defs><rect width='100' height='100' rx='20' fill='url(%23g)'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial' font-weight='bold'>D</text></svg>">
  <?php require_once __DIR__ . '/style.php'; ?>
  <style>
    body { padding: 0; }
    .container { max-width: 960px; }
    h1 { font-size: var(--text-2xl); margin-bottom: .25rem; }

    /* Page-specific */
    .subtitle { font-size: var(--text-sm); color: var(--c-text-secondary); margin-bottom: 2rem; }

    /* Stats */
    .stats { display: flex; gap: var(--sp-md); margin-bottom: 2rem; flex-wrap: wrap; }
    .stat {
      background: var(--c-surface);
      border: 1px solid var(--c-border-light);
      border-radius: var(--r-lg);
      padding: .85rem 1.25rem;
      min-width: 100px;
      text-align: center;
      box-shadow: var(--shadow-sm);
      position: relative;
      overflow: hidden;
    }
    .stat::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: var(--gradient-primary); }
    .stat strong { display: block; font-size: var(--text-3xl); color: var(--c-primary); font-weight: 800; letter-spacing: -.03em; }
    .stat.en-cours strong { color: var(--c-warning-dark); }
    .stat.en-cours::before { background: var(--c-warning); }
    .stat.valide strong { color: var(--c-success-dark); }
    .stat.valide::before { background: var(--c-success); }
    .stat.refuse strong { color: var(--c-danger-dark); }
    .stat.refuse::before { background: var(--c-danger); }
    .stat span { font-size: var(--text-xs); color: var(--c-text-tertiary); font-weight: 500; text-transform: uppercase; letter-spacing: .04em; }

    /* Card */
    .sub-card {
      background: var(--c-surface);
      border: 1px solid var(--c-border-light);
      border-radius: var(--r-lg);
      margin-bottom: 1.25rem;
      overflow: hidden;
      box-shadow: var(--shadow-sm);
      transition: box-shadow .25s var(--ease-out);
    }
    .sub-card:hover { box-shadow: var(--shadow-md); }
    .sub-card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 1rem 1.25rem;
      background: var(--c-bg-warm);
      border-bottom: 1px solid var(--c-border-light);
    }
    .sub-card-header:hover { background: var(--c-primary-50); }
    .sub-card-title { font-weight: 700; color: var(--c-primary-dark); font-size: var(--text-base); }
    .sub-card-date { font-size: var(--text-xs); color: var(--c-text-tertiary); margin-top: .15rem; }
    .sub-card-body { padding: 1.25rem; }

    /* Progress bar inline */
    .inline-progress { display: flex; align-items: center; gap: .75rem; margin-bottom: 1rem; }
    .inline-progress-bar { flex: 1; background: var(--c-border-light); border-radius: var(--r-full); height: 10px; overflow: hidden; }
    .inline-progress-fill { height: 100%; border-radius: var(--r-full); transition: width .6s var(--ease-out); }
    .inline-progress-fill.complete { background: var(--gradient-success); }
    .inline-progress-fill.in-progress { background: var(--gradient-cool); }
    .inline-progress-text { font-size: var(--text-xs); color: var(--c-text-tertiary); white-space: nowrap; min-width: 80px; text-align: right; }

    /* Timeline compact */
    .timeline-compact { display: flex; flex-direction: column; gap: .35rem; }
    .tl-step { display: flex; align-items: center; gap: .5rem; font-size: var(--text-sm); padding: .35rem .6rem; border-radius: var(--r-md); }
    .tl-step.done { background: var(--c-success-50); color: var(--c-success-dark); }
    .tl-step.active { background: var(--c-warning-50); color: var(--c-warning-dark); font-weight: 700; border: 1px dashed var(--c-warning); }
    .tl-step.waiting { background: var(--c-bg-warm); color: var(--c-text-tertiary); }
    .tl-icon { font-size: var(--text-sm); flex-shrink: 0; }
    .tl-label { flex: 1; }
    .tl-detail { font-size: var(--text-xs); color: var(--c-text-tertiary); }

    /* Refusal box */
    .refusal-box { background: var(--c-danger-50); border-radius: var(--r-md); padding: .75rem; font-size: var(--text-sm); margin-top: .75rem; }

    /* Deadline badge */
    .deadline-badge { display: inline-flex; align-items: center; gap: .35rem; font-size: var(--text-xs); padding: .2rem .6rem; border-radius: var(--r-full); margin-left: .5rem; font-weight: 600; }
    .deadline-badge.overdue { background: var(--c-danger-50); color: var(--c-danger-dark); }
    .deadline-badge.urgent { background: var(--c-warning-50); color: var(--c-warning-dark); }
    .deadline-badge.ok { background: var(--c-success-50); color: var(--c-success-dark); }

    .card-actions { margin-top: 1rem; padding-top: .75rem; border-top: 1px solid var(--c-border-light); display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; }
  </style>
</head>
<body>
<a href="#main-content" class="skip-link">Aller au contenu principal</a>
<?= render_nav('mes_demandes') ?>
<main class="container" id="main-content">
<?= render_breadcrumb([['Accueil', 'index.php'], ['Mes demandes']]) ?>
  <h1><span aria-hidden="true">📋</span> Mes demandes</h1>
  <p class="subtitle">Suivi de toutes vos demandes de workflow en tant qu'agent</p>

  <?php if ($total_count > 0): ?>
  <div class="stats">
    <div class="stat"><strong><?= $total_count ?></strong><span>Total</span></div>
    <div class="stat en-cours"><strong><?= $en_cours_count ?></strong><span>En cours</span></div>
    <div class="stat valide"><strong><?= $valide_count ?></strong><span>Validées</span></div>
    <div class="stat refuse"><strong><?= $refuse_count ?></strong><span>Refusées</span></div>
  </div>

  <!-- Barre de recherche et filtre -->
  <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:1.5rem;">
    <form method="GET" style="display:flex;gap:.5rem;align-items:center;">
      <input type="text" name="search" value="<?= h($search) ?>" placeholder="Rechercher..." style="padding:.4rem .75rem;border:1px solid #aaa;border-radius:3px;font-size:.85rem;font-family:inherit;width:250px;">
      <input type="hidden" name="statut" value="<?= h($status_filter) ?>">
      <button type="submit" class="btn btn-secondary" style="font-size:.8rem;padding:.4rem .75rem;">Rechercher</button>
      <?php if ($search): ?>
        <a href="?statut=<?= h($status_filter) ?>" class="btn btn-secondary" style="font-size:.8rem;padding:.4rem .75rem;">✕ Effacer</a>
      <?php endif; ?>
    </form>
    <div style="display:flex;gap:.35rem;">
      <a href="?statut=tous&search=<?= h($search) ?>" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;<?= $status_filter === 'tous' ? 'background:#003189;color:#fff;' : '' ?>">Tous</a>
      <a href="?statut=en_cours&search=<?= h($search) ?>" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;<?= $status_filter === 'en_cours' ? 'background:#b45309;color:#fff;' : '' ?>">En cours</a>
      <a href="?statut=valide&search=<?= h($search) ?>" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;<?= $status_filter === 'valide' ? 'background:#1a6b3c;color:#fff;' : '' ?>">Validées</a>
      <a href="?statut=refuse&search=<?= h($search) ?>" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;<?= $status_filter === 'refuse' ? 'background:#c0392b;color:#fff;' : '' ?>">Refusées</a>
    </div>
  </div>
  <?php endif; ?>

  <?php if (empty($submissions)): ?>
    <div class="empty-state">
      <div class="empty-icon" aria-hidden="true">📝</div>
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
                if ($dl_days < 0) $deadline_badge = '<span class="deadline-badge overdue"><span aria-hidden="true">🚨</span> J+' . abs($dl_days) . '</span>';
                elseif ($dl_days <= 2) $deadline_badge = '<span class="deadline-badge urgent"><span aria-hidden="true">⚠️</span> J-' . $dl_days . '</span>';
                elseif ($dl_days <= 5) $deadline_badge = '<span class="deadline-badge ok"><span aria-hidden="true">📅</span> J-' . $dl_days . '</span>';
            }
        }

        // Progression
        $pct = $sub['progress_pct'];
        $fill_cls = $pct === 100 ? 'complete' : ($pct > 0 ? 'in-progress' : 'in-progress');
    ?>
    <div class="sub-card">
      <a href="submission_view.php?id=<?= urlencode($sub['id']) ?>" style="text-decoration:none;color:inherit;">
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
              <span class="tl-icon" aria-hidden="true"><?= $icon ?></span>
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
          <a href="submission_view.php?id=<?= urlencode($sub['id']) ?>" class="btn btn-primary" style="font-size:.85rem;"><span aria-hidden="true">👁</span> Voir le détail</a>
          <a href="form.php?f=<?= h($sub['form_slug']) ?>" class="btn btn-secondary" style="font-size:.85rem;">Nouvelle demande</a>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</main>
<?= render_footer() ?>
</body>
</html>
