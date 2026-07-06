<?php
// form_tracking.php — Tableau de suivi propriétaire
// Accessible uniquement par les owners du formulaire ou les administrateurs
require_once dirname(__DIR__) . '/helpers.php';

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
// Exclure les champs réservés aux validateurs (leurs valeurs sont dans submission_validator_data, pas dans s.data)
$fields = get_form_fields($form_id, 'demandeur');

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
if ($filter_status === 'tous') $filter_status = ''; // "tous" = pas de filtre
$filter_search = trim($_GET['q'] ?? '');

// Construction de la requête
// SQL Safety: $where[] conditions use only hardcoded column names and operators.
// User input is always passed via prepared statement parameters (?).
// NEVER add user-controlled column names to this array.
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
$total_pages = (int)max(1, ceil($total / $per_page));

// Récupérer les soumissions
$sql = "SELECT s.* FROM submissions s WHERE $where_sql ORDER BY s.submitted_at DESC LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge($params, [$per_page, $offset]));
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Enrichir chaque soumission avec le nombre d'étapes validées/total
// A-13: optimisé — était N+1 (1 requête COUNT par soumission)
// Batch: 1 seule requête GROUP BY submission_id pour toutes les soumissions de la page.
$steps_by_sub = [];
if (!empty($submissions)) {
    $sub_ids = array_column($submissions, 'id');
    $ph = implode(',', array_fill(0, count($sub_ids), '?'));
    $batch_steps_stmt = $pdo->prepare("
        SELECT submission_id,
               COUNT(*) as total,
               SUM(CASE WHEN done_at IS NOT NULL THEN 1 ELSE 0 END) as done
        FROM tokens
        WHERE submission_id IN ($ph)
        GROUP BY submission_id
    ");
    $batch_steps_stmt->execute($sub_ids);
    foreach ($batch_steps_stmt->fetchAll(PDO::FETCH_ASSOC) as $srow) {
        $steps_by_sub[$srow['submission_id']] = $srow;
    }
}
foreach ($submissions as &$sub) {
    $info = $steps_by_sub[$sub['id']] ?? ['done' => 0, 'total' => 0];
    $sub['steps_done'] = (int)($info['done'] ?? 0);
    $sub['steps_total'] = (int)($info['total'] ?? 0);
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

    // A-13: optimisé — était N+1 (1 requête COUNT par ligne exportée)
    // Batch: 1 seule requête GROUP BY submission_id pour toutes les lignes exportées.
    $steps_by_sub_export = [];
    if (!empty($export_rows)) {
        $export_ids = array_column($export_rows, 'id');
        $eph = implode(',', array_fill(0, count($export_ids), '?'));
        $batch_export_stmt = $pdo->prepare("
            SELECT submission_id,
                   COUNT(*) as total,
                   SUM(CASE WHEN done_at IS NOT NULL THEN 1 ELSE 0 END) as done
            FROM tokens
            WHERE submission_id IN ($eph)
            GROUP BY submission_id
        ");
        $batch_export_stmt->execute($export_ids);
        foreach ($batch_export_stmt->fetchAll(PDO::FETCH_ASSOC) as $erow) {
            $steps_by_sub_export[$erow['submission_id']] = $erow;
        }
    }

    $out = fopen('php://output', 'w');
    if ($out === false) { $out = null; }
    // En-tête CSV
    $headers = ['Date soumission', 'Agent', 'Statut', 'Etapes validées'];
    foreach ($key_fields as $kf) {
        $headers[] = $kf['label'];
    }
    if ($out !== null) { fputcsv($out, $headers, ';', '"', '\\'); }

    foreach ($export_rows as $row) {
        $data = json_decode($row['data'], true) ?? [];
        $si = $steps_by_sub_export[$row['id']] ?? ['done' => 0, 'total' => 0];

        $csv_row = [
            $row['submitted_at'],
            $row['submitted_by'],
            $row['status'],
            ($si['done'] ?? 0) . '/' . ($si['total'] ?? 0),
        ];
        foreach ($key_fields as $kf) {
            $csv_row[] = $data[$kf['field_name']] ?? '';
        }
        if ($out !== null) { fputcsv($out, $csv_row, ';', '"', '\\'); }
    }
    if ($out !== null) { fclose($out); }
    exit;
}

// Statistiques rapides pour ce formulaire
$stats = [
    'total' => 0,
    'en_cours' => 0,
    'valide' => 0,
    'refuse' => 0,
    'annule' => 0,
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
<?php
$page_css = '';
ob_start();
?>

  <h1><span aria-hidden="true">📊</span> Suivi : <?= h($form['label']) ?></h1>
  <p class="subtitle"><?= h($form['description']) ?></p>

  <?php if (!empty($owners)): ?>
  <div class="owners-list">
    Propriétaires :
    <?php foreach ($owners as $ow): ?>
      <span><?= display_user($ow['email']) ?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats-bar">
    <div class="stat-chip">
      <div class="sc-value"><?= $stats['total'] ?></div>
      <div class="sc-label">📊 Total</div>
    </div>
    <div class="stat-chip warning">
      <div class="sc-value"><?= $stats['en_cours'] ?></div>
      <div class="sc-label">⏳ En cours</div>
    </div>
    <div class="stat-chip success">
      <div class="sc-value"><?= $stats['valide'] ?></div>
      <div class="sc-label">✓ Validées</div>
    </div>
    <div class="stat-chip danger">
      <div class="sc-value"><?= $stats['refuse'] ?></div>
      <div class="sc-label">❌ Refusées</div>
    </div>
    <div class="stat-chip" style="background:#f3f4f6;color:#6b7280;">
      <div class="sc-value"><?= $stats['annule'] ?></div>
      <div class="sc-label">🗑 Annulées</div>
    </div>
  </div>

  <!-- Filtres -->
  <div class="filtres" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin-bottom:1.5rem;">
    <?= render_status_filter(empty($filter_status) ? 'tous' : $filter_status, 'index.php?p=form_tracking&f=' . $fuuid, 'status') ?>
    <a href="index.php?p=form_tracking&f=<?= $fuuid ?>&export=csv" style="margin-left:auto;border-color:#1a6b3c;color:#1a6b3c;padding:.4rem .75rem;border-radius:var(--r-sm);text-decoration:none;font-size:.85rem;">📥 Export CSV</a>
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
          <td><?= display_user($sub['submitted_by']) ?></td>
          <td>
            <?php
            $badge_class = 'badge-' . $sub['status'];
            $badge_label = $sub['status'] === 'en_cours' ? 'En cours' : ($sub['status'] === 'valide' ? 'Validé' : ($sub['status'] === 'annule' ? '🗑 Annulé' : 'Refusé'));
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
            <a href="index.php?p=submission_view&id=<?= h($sub['id']) ?>" style="color:var(--c-primary-dark);font-size:.85rem;">Voir →</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?= render_pagination($page, $total_pages, 'index.php?p=form_tracking&f=' . $fuuid . '&status=' . h($filter_status)) ?>
  <?php endif; ?>

<?php
$content = ob_get_clean();
if ($content === false) { $content = ''; }
echo render_page('Suivi : ' . h($form['label']), 'dashboard', $page_css, $content);
