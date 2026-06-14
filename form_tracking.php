<?php
// form_tracking.php — Tableau de suivi propriétaire
// Accessible uniquement par les owners du formulaire ou les administrateurs
require_once __DIR__ . '/helpers.php';

$user = get_auth_user();
$pdo = get_pdo();
$form_uuid = trim($_GET['f'] ?? '');

// Récupérer le formulaire par UUID (non devinable)
$form = null;
if (!empty($form_uuid)) {
    $form = get_form_by_uuid($form_uuid);
}

if (!$form) {
    render_error_page(404, 'Formulaire introuvable',
        'Le formulaire que vous cherchez n\'existe pas ou a été désactivé.',
        'Vérifiez l\'adresse dans votre navigateur.\nSi vous avez suivi un lien, contactez l\'expéditeur pour obtenir le bon lien.');
}

$form_id = $form['id'];

// Vérifier les droits : admin OU owner du formulaire
$is_admin = is_admin_user() || is_super_admin();
$is_owner = is_form_owner($form_id, $user);

if (!$is_admin && !$is_owner) {
    render_error_page(403, 'Accès refusé',
        'Vous n\'êtes pas propriétaire de ce formulaire. Seuls les propriétaires désignés et les administrateurs peuvent accéder au tableau de suivi.',
        'Si vous pensez que vous devriez avoir accès, contactez un administrateur pour vérifier vos droits de propriétaire sur ce formulaire.');
}

// Récupérer les champs du formulaire pour afficher les colonnes pertinentes
$form_fields = $pdo->prepare("SELECT * FROM form_fields WHERE form_id = ? ORDER BY ordre, id");
$form_fields->execute([$form_id]);
$fields = $form_fields->fetchAll(PDO::FETCH_ASSOC);

// Déterminer les champs clés à afficher dans le tableau de suivi
// On prend les premiers champs utiles (nom, prenom, email, type, date, etc.)
$key_fields = [];
$all_field_names = [];
foreach ($fields as $f) {
    $all_field_names[$f['field_name']] = $f['label'];
    // Sélectionner les champs clés pour les colonnes du tableau
    $fn = $f['field_name'];
    if (in_array($fn, ['nom', 'prenom', 'email', 'service', 'type_sortie', 'nature_depense',
        'montant', 'date_depense', 'type_materiel', 'nature_besoin', 'date_prescription',
        'urgence', 'date_sortie', 'heure_debut', 'heure_fin'])) {
        $key_fields[] = $f;
    }
}
// Si aucun champ clé trouvé, prendre les 4 premiers
if (empty($key_fields) && count($fields) >= 4) {
    $key_fields = array_slice($fields, 0, 4);
} elseif (empty($key_fields)) {
    $key_fields = $fields;
}

// Filtres
$filter_status = $_GET['status'] ?? '';
$filter_search = trim($_GET['q'] ?? '');

// Construction de la requête
$where = ["s.form_id = ?"];
$params = [$form_id];

if (!empty($filter_status)) {
    $where[] = "s.status = ?";
    $params[] = $filter_status;
}

$where_sql = implode(' AND ', $where);

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

// Compter le total
$count_sql = "SELECT COUNT(*) FROM submissions s WHERE $where_sql";
$total_stmt = $pdo->prepare($count_sql);
$total_stmt->execute($params);
$total = (int)$total_stmt->fetchColumn();
$total_pages = max(1, ceil($total / $per_page));

// Récupérer les soumissions
$sql = "SELECT s.* FROM submissions s WHERE $where_sql ORDER BY s.submitted_at DESC LIMIT $per_page OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Enrichir chaque soumission avec le nombre d'étapes validées/total
foreach ($submissions as &$sub) {
    $step_stmt = $pdo->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN t.done_at IS NOT NULL THEN 1 ELSE 0 END) as done
        FROM tokens t
        WHERE t.submission_id = ?
    ");
    $step_stmt->execute([$sub['id']]);
    $step_info = $step_stmt->fetch(PDO::FETCH_ASSOC);
    $sub['steps_done'] = (int)($step_info['done'] ?? 0);
    $sub['steps_total'] = (int)($step_info['total'] ?? 0);
    $sub['data'] = json_decode($sub['data'], true) ?? [];
}
unset($sub);

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Ré-exporter TOUTES les soumissions (pas juste la page courante)
    $export_sql = "SELECT s.* FROM submissions s WHERE $where_sql ORDER BY s.submitted_at DESC";
    $export_stmt = $pdo->prepare($export_sql);
    $export_stmt->execute($params);
    $export_rows = $export_stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="suivi_' . $form['slug'] . '_' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    // En-tête CSV
    $headers = ['Date soumission', 'Agent', 'Statut', 'Etapes validées'];
    foreach ($key_fields as $kf) {
        $headers[] = $kf['label'];
    }
    fputcsv($out, $headers, ';', '"', '\\');

    foreach ($export_rows as $row) {
        $data = json_decode($row['data'], true) ?? [];
        $step_stmt2 = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN done_at IS NOT NULL THEN 1 ELSE 0 END) as done FROM tokens WHERE submission_id = ?");
        $step_stmt2->execute([$row['id']]);
        $si = $step_stmt2->fetch(PDO::FETCH_ASSOC);

        $csv_row = [
            $row['submitted_at'],
            $row['submitted_by'],
            $row['status'],
            ($si['done'] ?? 0) . '/' . ($si['total'] ?? 0),
        ];
        foreach ($key_fields as $kf) {
            $csv_row[] = $data[$kf['field_name']] ?? '';
        }
        fputcsv($out, $csv_row, ';', '"', '\\');
    }
    fclose($out);
    exit;
}

// Statistiques rapides pour ce formulaire
$stats = [
    'total' => 0,
    'en_cours' => 0,
    'valide' => 0,
    'refuse' => 0,
];
$stats_stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM submissions WHERE form_id = ? GROUP BY status");
$stats_stmt->execute([$form_id]);
foreach ($stats_stmt->fetchAll(PDO::FETCH_ASSOC) as $sr) {
    $stats['total'] += (int)$sr['cnt'];
    if (isset($stats[$sr['status']])) {
        $stats[$sr['status']] = (int)$sr['cnt'];
    }
}

// Owners du formulaire
$owners = get_form_owners($form_id);
$fuuid = h($form['id']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Suivi : <?= h($form['label']) ?> — DREETS</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='15' fill='%23003189'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial'>D</text></svg>">
  <?php require_once __DIR__ . '/style.php'; ?>
  <style>
    .container { max-width: 1200px; }
    h1 { font-size: 1.4rem; margin-bottom: .25rem; }
    .subtitle { color: #666; font-size: .9rem; margin-bottom: 1.5rem; }

    /* Stats bar */
    .stats-bar { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: .75rem; margin-bottom: 1.5rem; }
    .stat-chip { background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: .75rem; text-align: center; }
    .stat-chip .sc-value { font-size: 1.6rem; font-weight: bold; color: #003189; }
    .stat-chip .sc-label { font-size: .75rem; color: #595959; margin-top: .15rem; }
    .stat-chip.warning .sc-value { color: #b45309; }
    .stat-chip.success .sc-value { color: #1a6b3c; }
    .stat-chip.danger .sc-value { color: #c0392b; }

    /* Filters */
    .filters { display: flex; gap: .75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1.5rem; }
    .filters a, .filters span { font-size: .85rem; padding: .4rem .75rem; border-radius: 4px; text-decoration: none; color: #666; border: 1px solid #ddd; }
    .filters a:hover { background: #f0f4ff; border-color: #003189; color: #003189; }
    .filters a.active { background: #003189; color: #fff; border-color: #003189; }

    /* Table */
    .tracking-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
    .tracking-table th { background: #f8f9fa; padding: .6rem .75rem; text-align: left; border-bottom: 2px solid #ddd; font-weight: 600; color: #333; white-space: nowrap; }
    .tracking-table td { padding: .5rem .75rem; border-bottom: 1px solid #eee; vertical-align: top; }
    .tracking-table tr:hover { background: #f0f4ff; }

    /* Badges */
    .badge { display: inline-block; padding: .15rem .5rem; border-radius: 3px; font-size: .75rem; font-weight: bold; }
    .badge-en_cours { background: #fff3e0; color: #b45309; }
    .badge-valide { background: #e8f5e9; color: #1a6b3c; }
    .badge-refuse { background: #fde8e8; color: #c0392b; }

    /* Progress mini */
    .progress-mini { display: flex; align-items: center; gap: .4rem; }
    .progress-mini-bar { width: 60px; height: 6px; background: #eee; border-radius: 3px; overflow: hidden; }
    .progress-mini-fill { height: 100%; border-radius: 3px; transition: width .3s; }
    .progress-mini-text { font-size: .75rem; color: #595959; }

    /* Owners list */
    .owners-list { margin-bottom: 1rem; font-size: .8rem; color: #666; }
    .owners-list span { display: inline-block; background: #f0f4ff; border: 1px solid #b3c8f0; border-radius: 3px; padding: .15rem .4rem; margin: .1rem .2rem; }

    /* Pagination */
    .pagination { display: flex; gap: .5rem; justify-content: center; margin-top: 1.5rem; }
    .pagination a, .pagination span { padding: .4rem .75rem; border: 1px solid #ddd; border-radius: 4px; font-size: .85rem; text-decoration: none; color: #003189; }
    .pagination span.current { background: #003189; color: #fff; border-color: #003189; }
    .pagination a:hover { background: #f0f4ff; }
  </style>
</head>
<body>
<a href="#main-content" class="skip-link">Aller au contenu principal</a>
<?= render_nav('dashboard') ?>
<?= render_breadcrumb([['Accueil', 'index.php'], ['Suivi : ' . $form['label']]]) ?>
<main class="container" id="main-content">

  <h1><span aria-hidden="true">📊</span> Suivi : <?= h($form['label']) ?></h1>
  <p class="subtitle"><?= h($form['description']) ?></p>

  <?php if (!empty($owners)): ?>
  <div class="owners-list">
    Propriétaires :
    <?php foreach ($owners as $ow): ?>
      <span><?= h($ow['email']) ?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats-bar">
    <div class="stat-chip">
      <div class="sc-value"><?= $stats['total'] ?></div>
      <div class="sc-label">Total</div>
    </div>
    <div class="stat-chip warning">
      <div class="sc-value"><?= $stats['en_cours'] ?></div>
      <div class="sc-label">En cours</div>
    </div>
    <div class="stat-chip success">
      <div class="sc-value"><?= $stats['valide'] ?></div>
      <div class="sc-label">Validées</div>
    </div>
    <div class="stat-chip danger">
      <div class="sc-value"><?= $stats['refuse'] ?></div>
      <div class="sc-label">Refusées</div>
    </div>
  </div>

  <!-- Filtres -->
  <div class="filters">
    <span>Filtrer :</span>
    <a href="form_tracking.php?f=<?= $fuuid ?>" class="<?= empty($filter_status) ? 'active' : '' ?>">Toutes</a>
    <a href="form_tracking.php?f=<?= $fuuid ?>&status=en_cours" class="<?= $filter_status === 'en_cours' ? 'active' : '' ?>">En cours</a>
    <a href="form_tracking.php?f=<?= $fuuid ?>&status=valide" class="<?= $filter_status === 'valide' ? 'active' : '' ?>">Validées</a>
    <a href="form_tracking.php?f=<?= $fuuid ?>&status=refuse" class="<?= $filter_status === 'refuse' ? 'active' : '' ?>">Refusées</a>
    <a href="form_tracking.php?f=<?= $fuuid ?>&export=csv" style="margin-left:auto;border-color:#1a6b3c;color:#1a6b3c;">📥 Export CSV</a>
  </div>

  <!-- Tableau -->
  <?php if (empty($submissions)): ?>
    <p style="color:#595959;font-style:italic;">Aucune soumission pour ce formulaire.</p>
  <?php else: ?>
    <table class="tracking-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Agent</th>
          <th>Statut</th>
          <th>Avancement</th>
          <?php foreach ($key_fields as $kf): ?>
          <th><?= h($kf['label']) ?></th>
          <?php endforeach; ?>
          <th>Détail</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($submissions as $sub): ?>
        <tr>
          <td style="white-space:nowrap;"><?= h(substr($sub['submitted_at'], 0, 10)) ?></td>
          <td><?= h($sub['submitted_by']) ?></td>
          <td>
            <?php
            $badge_class = 'badge-' . $sub['status'];
            $badge_label = $sub['status'] === 'en_cours' ? 'En cours' : ($sub['status'] === 'valide' ? 'Validé' : 'Refusé');
            ?>
            <span class="badge <?= $badge_class ?>"><?= $badge_label ?></span>
          </td>
          <td>
            <div class="progress-mini">
              <?php
              $pct = $sub['steps_total'] > 0 ? round(($sub['steps_done'] / $sub['steps_total']) * 100) : 0;
              $fill_color = $pct >= 100 ? '#1a6b3c' : ($pct >= 50 ? '#b45309' : '#c0392b');
              ?>
              <div class="progress-mini-bar">
                <div class="progress-mini-fill" style="width:<?= $pct ?>%;background:<?= $fill_color ?>;"></div>
              </div>
              <span class="progress-mini-text"><?= $sub['steps_done'] ?>/<?= $sub['steps_total'] ?></span>
            </div>
          </td>
          <?php foreach ($key_fields as $kf): ?>
          <td><?= h($sub['data'][$kf['field_name']] ?? '') ?></td>
          <?php endforeach; ?>
          <td>
            <a href="submission_view.php?id=<?= h($sub['id']) ?>" style="color:#003189;font-size:.85rem;">Voir →</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
      <?php if ($page > 1): ?>
        <a href="form_tracking.php?f=<?= $fuuid ?>&status=<?= h($filter_status) ?>&page=<?= $page - 1 ?>">← Précédent</a>
      <?php endif; ?>
      <?php for ($p = max(1, $page - 3); $p <= min($total_pages, $page + 3); $p++): ?>
        <?php if ($p === $page): ?>
          <span class="current"><?= $p ?></span>
        <?php else: ?>
          <a href="form_tracking.php?f=<?= $fuuid ?>&status=<?= h($filter_status) ?>&page=<?= $p ?>"><?= $p ?></a>
        <?php endif; ?>
      <?php endfor; ?>
      <?php if ($page < $total_pages): ?>
        <a href="form_tracking.php?f=<?= $fuuid ?>&status=<?= h($filter_status) ?>&page=<?= $page + 1 ?>">Suivant →</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>

</main>
<?= render_footer() ?>
</body>
</html>
