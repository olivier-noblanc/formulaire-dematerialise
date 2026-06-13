<?php
// my_submissions.php — Page "Mes demandes" pour l'agent connecté
require_once __DIR__ . '/helpers.php';

$user = get_auth_user();
$pdo  = get_pdo();

// Récupérer toutes les soumissions de l'agent
$stmt = $pdo->prepare("
    SELECT s.id, s.form_id, s.data, s.submitted_at, s.status, s.closed_at,
           f.label as form_label, f.slug as form_slug, f.description as form_description
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

    // Récupérer toutes les étapes actives du formulaire, ordonnées
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

    // Récupérer les tokens pour cette soumission
    $tokens_stmt = $pdo->prepare("
        SELECT t.step_id, t.email, t.done_at, t.sent_at, st.label as step_label, st.ordre
        FROM tokens t
        JOIN steps st ON st.id = t.step_id
        WHERE t.submission_id = ?
        ORDER BY st.ordre ASC, st.label ASC
    ");
    $tokens_stmt->execute([$sid]);
    $sub['tokens'] = $tokens_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Déterminer le statut de chaque étape du workflow
    // Regrouper les tokens par step_id
    $tokens_by_step = [];
    foreach ($sub['tokens'] as $tok) {
        $tokens_by_step[$tok['step_id']][] = $tok;
    }

    // Pour chaque étape du workflow, déterminer son statut
    foreach ($sub['workflow_steps'] as &$ws) {
        $step_id = $ws['step_id'];
        if (!isset($tokens_by_step[$step_id]) || empty($tokens_by_step[$step_id])) {
            // Pas de tokens = étape à venir (upcoming)
            $ws['step_status'] = 'upcoming';
            $ws['step_detail'] = '';
        } else {
            $all_done = true;
            $any_done = false;
            $detail_parts = [];
            foreach ($tokens_by_step[$step_id] as $tok) {
                if (!empty($tok['done_at'])) {
                    $any_done = true;
                    $detail_parts[] = h($tok['email']) . ' ✓';
                } else {
                    $all_done = false;
                    $detail_parts[] = h($tok['email']) . ' ⏳';
                }
            }
            if ($all_done) {
                $ws['step_status'] = 'validated';
            } else {
                $ws['step_status'] = 'current';
            }
            $ws['step_detail'] = implode('<br>', $detail_parts);
        }
    }
    unset($ws);
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
    /* Overrides */
    body { padding: 0; }
    .container { max-width: 900px; }
    h1 { font-size: 1.5rem; margin-bottom: .25rem; }

    /* Page-specific */
    .subtitle { font-size: .85rem; color: #555; margin-bottom: 2rem; }
    .card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; flex-wrap: wrap; gap: .5rem; }
    .card-title { font-size: 1.1rem; font-weight: bold; color: #003189; }
    .card-date { font-size: .8rem; color: #888; margin-top: .25rem; }
    .card-actions { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #eee; display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; }
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
    <div class="stat"><strong><?= $total_count ?></strong>Total</div>
    <div class="stat en-cours"><strong><?= $en_cours_count ?></strong>En cours</div>
    <div class="stat valide"><strong><?= $valide_count ?></strong>Validées</div>
    <div class="stat refuse"><strong><?= $refuse_count ?></strong>Refusées</div>
  </div>
  <?php endif; ?>

  <?php if (empty($submissions)): ?>
    <div class="empty-state">
      <div class="empty-icon">📝</div>
      <p>Vous n'avez encore soumis aucune demande.</p>
      <?php
        // Afficher les formulaires actifs disponibles
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
    ?>
    <div class="card">
      <div class="card-header">
        <div>
          <div class="card-title"><?= h($sub['form_label']) ?></div>
          <div class="card-date">Soumis le <?= h(date('d/m/Y à H:i', strtotime($sub['submitted_at']))) ?></div>
          <?php if (!empty($sub['closed_at'])): ?>
            <div class="card-date">Clôturé le <?= h(date('d/m/Y à H:i', strtotime($sub['closed_at']))) ?></div>
          <?php endif; ?>
          <?php
            $nom_agent = h(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? ''));
            $affectation = h($data['affectation'] ?? '');
            if ($nom_agent || $affectation):
          ?>
            <div class="card-date" style="margin-top:.15rem;"><?= $nom_agent ?><?= $affectation ? ' — ' . $affectation : '' ?></div>
          <?php endif; ?>
        </div>
        <span class="badge <?= $badge_cls ?>"><?= $status_label ?></span>
      </div>

      <?php if (!empty($sub['workflow_steps'])): ?>
      <div class="timeline">
        <?php foreach ($sub['workflow_steps'] as $ws):
            $step_cls = 'step-upcoming';
            $step_icon = '○';
            if ($ws['step_status'] === 'validated') {
                $step_cls = 'step-validated';
                $step_icon = '✓';
            } elseif ($ws['step_status'] === 'current') {
                $step_cls = 'step-current';
                $step_icon = '⏳';
            }
        ?>
          <div class="step-item <?= $step_cls ?>">
            <div class="step-icon"><?= $step_icon ?></div>
            <div class="step-label"><?= h($ws['step_label']) ?></div>
            <?php if (!empty($ws['step_detail'])): ?>
              <div class="step-detail"><?= $ws['step_detail'] ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($status === 'refuse' && isset($data['validations'])): ?>
        <div style="margin-top:1rem;padding:.75rem;background:#fde8e8;border-radius:3px;font-size:.85rem;">
          <?php
            // Trouver le refus dans les validations
            foreach ($data['validations'] as $v) {
                if ($v['action'] === 'refuser') {
                    echo '<strong>Refusé par :</strong> ' . h($v['email']) . ' (étape ' . h($v['step_label']) . ')';
                    if (!empty($v['commentaire'])) {
                        echo '<br><strong>Motif :</strong> ' . h($v['commentaire']);
                    }
                    break;
                }
            }
          ?>
        </div>
      <?php endif; ?>

      <div class="card-actions">
        <a href="form.php?f=<?= h($sub['form_slug']) ?>" class="btn btn-primary">Nouvelle demande</a>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?= render_footer() ?>
</body>
</html>
