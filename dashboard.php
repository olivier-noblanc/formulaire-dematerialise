<?php
require_once __DIR__ . '/helpers.php';

$pdo    = get_pdo();
$filtre = $_GET['statut'] ?? 'tous';
$form_f = $_GET['form']   ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $options = [];
    if ($form_f) {
        $f_stmt = $pdo->prepare("SELECT id FROM forms WHERE slug = ?");
        $f_stmt->execute([$form_f]);
        $fid = $f_stmt->fetchColumn();
        if ($fid) $options['form_id'] = (int)$fid;
    }
    if ($filtre !== 'tous') {
        $options['status'] = $filtre === 'en_cours' ? 'en_cours' : ($filtre === 'complet' ? 'valide' : '');
    }
    app_log('export_csv', '', 'Export CSV des soumissions');
    export_csv($pdo, $options);
}

// Régénération de token (admin)
$regen_msg = '';
if (isset($_POST['action']) && $_POST['action'] === 'regenerate_token' && is_admin_user()) {
    if (!verify_csrf()) {
        if (TEST_MODE) { test_json_response(['error' => 'CSRF invalide']); }
        die('Token CSRF invalide.');
    }
    $token_id = (int)($_POST['token_id'] ?? 0);
    $result = regenerate_token($token_id);
    $regen_msg = $result['message'];
    if (TEST_MODE) { test_json_response(['action' => 'regenerate_token', 'result' => $result]); }
}

// Annulation de soumission (admin ou agent)
$cancel_msg = '';
if (isset($_POST['action']) && $_POST['action'] === 'cancel_submission') {
    if (!verify_csrf()) {
        if (TEST_MODE) { test_json_response(['error' => 'CSRF invalide']); }
        die('Token CSRF invalide.');
    }
    $sub_id = (int)($_POST['submission_id'] ?? 0);
    $actor = get_auth_user();
    // Vérifier que l'utilisateur est admin ou le propriétaire de la soumission
    $sub_stmt = $pdo->prepare("SELECT submitted_by FROM submissions WHERE id = ?");
    $sub_stmt->execute([$sub_id]);
    $sub_owner = $sub_stmt->fetchColumn();
    if (is_admin_user() || $sub_owner === $actor) {
        $result = cancel_submission($sub_id, $actor);
        $cancel_msg = $result['message'];
        if (TEST_MODE) { test_json_response(['action' => 'cancel_submission', 'result' => $result, 'submission_id' => $sub_id]); }
    }
}

$where = ['1=1'];
$params = [];
if ($filtre === 'en_cours') { $where[] = 's.status = ?'; $params[] = 'en_cours'; }
if ($filtre === 'complet')  { $where[] = 's.status != ?'; $params[] = 'en_cours'; }
if ($form_f) { $where[] = "f.slug = ?"; $params[] = $form_f; }
$where = implode(' AND ', $where);

// Count total matching rows for pagination
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions s JOIN forms f ON f.id = s.form_id WHERE $where");
$count_stmt->execute($params);
$total_rows = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

$stmt = $pdo->prepare("SELECT s.*, f.label as form_label, f.slug as form_slug, f.deadline_field FROM submissions s JOIN forms f ON f.id = s.form_id WHERE $where ORDER BY s.submitted_at DESC LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$forms   = $pdo->query("SELECT * FROM forms WHERE actif=1 ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);
$total   = $pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
$complet = $pdo->query("SELECT COUNT(*) FROM submissions WHERE status != 'en_cours'")->fetchColumn();

// Statuts des tokens par soumission
function get_tokens_status(int $sub_id): array {
    $rows = get_pdo()->prepare("
        SELECT t.email, t.done_at, st.label, st.ordre
        FROM tokens t
        JOIN steps st ON st.id = t.step_id
        WHERE t.submission_id = ?
        ORDER BY st.ordre, st.label
    ");
    $rows->execute([$sub_id]);
    return $rows->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Supervision workflow — DREETS</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='15' fill='%23003189'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial'>D</text></svg>">
  <?php require_once __DIR__ . '/style.php'; ?>
  <style>
    /* Overrides */
    .container { max-width: 1200px; }
    .toolbar { gap: 1rem; margin-bottom: 1.5rem; }

    /* Page-specific */
    .filtres { display: flex; gap: .5rem; }
    .filtres a, .btn-admin { padding: .4rem 1rem; border: 1px solid #003189; border-radius: 3px; text-decoration: none; font-size: .85rem; color: #003189; }
    .filtres a.actif, .btn-admin { background: #003189; color: #fff; }
    tbody tr:nth-child(4n+1), tbody tr:nth-child(4n+2) { background: #f7f7fb; }
    .token-grid { display: flex; flex-wrap: wrap; gap: .35rem; }
    .token-badge { font-size: .75rem; padding: .2rem .5rem; border-radius: 3px; white-space: nowrap; }
    .token-ok   { background: #e8f5e9; color: #1a6b3c; }
    .token-wait { background: #fff3e0; color: #b45309; }
    .token-pend { background: #f5f5f5; color: #888; }
    .detail-btn { cursor: pointer; color: #003189; font-size: .8rem; text-decoration: underline; background: none; border: none; font-family: inherit; }
    .detail-row { display: none; }
    .detail-row.open { display: table-row; }
    .detail-content { padding: 1rem; background: #f0f0f8; font-size: .82rem; line-height: 1.9; }
    .ordre-label { font-size: .7rem; background: #003189; color: #fff; padding: .1rem .4rem; border-radius: 2px; margin-right: .25rem; }
  </style>
</head>
<body>
<div class="bandeau"><strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités <span>Connecté en tant que : <strong><?= h(get_auth_user()) ?></strong></span> <span><a href="my_validations.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;">✅ Mes validations</a> <a href="docs.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;margin-left:8px;">📖 Documentation</a> <a href="admin_alerts.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;margin-left:8px;">🔔 Alertes</a> <a href="admin_settings.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;margin-left:8px;">⚙ Paramètres</a></span></div>
<div class="container">
  <h1>Supervision — Workflows en cours</h1>

  <?php if ($regen_msg): ?><div class="msg-info"><?= h($regen_msg) ?></div><?php endif; ?>
  <?php if ($cancel_msg): ?><div class="msg-info"><?= h($cancel_msg) ?></div><?php endif; ?>

  <div class="stats">
    <div class="stat"><strong><?= $total ?></strong>Total</div>
    <div class="stat"><strong style="color:#c0392b;"><?= $total - $complet ?></strong>En cours</div>
    <div class="stat"><strong style="color:#1a6b3c;"><?= $complet ?></strong>Clôturés</div>
  </div>

  <div class="toolbar">
    <div class="filtres">
      <a href="?statut=tous&form=<?= h($form_f) ?>"     class="<?= $filtre==='tous'     ? 'actif':'' ?>">Tous</a>
      <a href="?statut=en_cours&form=<?= h($form_f) ?>" class="<?= $filtre==='en_cours' ? 'actif':'' ?>">En cours</a>
      <a href="?statut=complet&form=<?= h($form_f) ?>"  class="<?= $filtre==='complet'  ? 'actif':'' ?>">Clôturés</a>
    </div>
    <select class="form-filter" onchange="location='?statut=<?= h($filtre) ?>&form='+this.value">
      <option value="">Tous les formulaires</option>
      <?php foreach ($forms as $f): ?>
        <option value="<?= h($f['slug']) ?>" <?= $form_f===$f['slug']?'selected':'' ?>><?= h($f['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <a href="monitoring.php" class="btn-admin">🖥 Monitoring</a>
    <a href="admin_alerts.php" class="btn-admin" style="background:#b45309;">🔔 Alertes</a>
    <a href="admin_forms.php" class="btn-admin">⚙ Gestion formulaires</a>
    <a href="?export=csv&statut=<?= h($filtre) ?>&form=<?= h($form_f) ?>" class="btn-admin" style="background:#1a6b3c;">📥 Export CSV</a>
  </div>

  <table>
    <thead>
      <tr>
        <th>Formulaire</th>
        <th>Agent</th>
        <th>Date cible</th>
        <th>Workflow</th>
        <th>Soumis le</th>
        <th>Statut</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($rows)): ?>
      <tr><td colspan="7" style="text-align:center;padding:2rem;color:#888;">Aucune soumission.</td></tr>
    <?php else: ?>
      <?php foreach ($rows as $i => $row):
        $d      = json_decode($row['data'], true);
        $tokens = get_tokens_status((int)$row['id']);
        $nom    = h(($d['prenom'] ?? '') . ' ' . ($d['nom'] ?? ''));
        $status = $row['status'] ?? 'en_cours';
        $deadline_field = $row['deadline_field'] ?? '';
        $deadline_val = $deadline_field ? ($d[$deadline_field] ?? '') : ($d['date_prise_poste'] ?? $d['date_depart'] ?? '');
        // Calculer l'urgence si on a une date cible
        $deadline_urgency = '';
        if (!empty($deadline_val) && $status === 'en_cours') {
            $deadline_ts = null;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($deadline_val))) {
                $deadline_ts = strtotime(trim($deadline_val));
            } elseif (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', trim($deadline_val), $m)) {
                $deadline_ts = strtotime("{$m[3]}-{$m[2]}-{$m[1]}");
            }
            if ($deadline_ts) {
                $days_left = (int)(($deadline_ts - time()) / 86400);
                if ($days_left < 0) $deadline_urgency = 'color:#c0392b;font-weight:bold;';
                elseif ($days_left <= 2) $deadline_urgency = 'color:#c0392b;font-weight:bold;';
                elseif ($days_left <= 5) $deadline_urgency = 'color:#b45309;font-weight:bold;';
            }
        }
      ?>
      <tr>
        <td><span style="font-size:.8rem;background:#e8eaf6;color:#003189;padding:.2rem .5rem;border-radius:3px;"><?= h($row['form_label']) ?></span></td>
        <td><strong><?= $nom ?></strong></td>
        <td style="white-space:nowrap;<?= $deadline_urgency ?>"><?= h($deadline_val) ?></td>
        <td>
          <div class="token-grid">
            <?php foreach ($tokens as $t):
              if ($t['done_at']) $cls = 'token-ok';
              elseif ($t['ordre'] == min(array_column(array_filter($tokens, fn($x) => !$x['done_at']), 'ordre') ?: [0])) $cls = 'token-wait';
              else $cls = 'token-pend';
            ?>
              <span class="token-badge <?= $cls ?>">
                <span class="ordre-label"><?= (int)$t['ordre'] ?></span><?= h($t['label']) ?>
                <?= $t['done_at'] ? ' ✓' : '' ?>
              </span>
            <?php endforeach; ?>
          </div>
        </td>
        <td style="white-space:nowrap;"><?= h(substr($row['submitted_at'],0,10)) ?></td>
        <td><?php
          if ($status === 'refuse') {
              echo '<span style="color:#c0392b;font-weight:bold;">❌ Refusé</span>';
          } elseif ($status === 'valide') {
              echo '<span style="color:#1a6b3c;font-weight:bold;">✓ Validé</span>';
          } else {
              echo '<span style="color:#b45309;">En cours</span>';
          }
        ?></td>
        <td><button class="detail-btn" onclick="toggle(<?= $i ?>)">détail</button> <a href="submission_view.php?id=<?= (int)$row['id'] ?>" style="font-size:.8rem;color:#003189;text-decoration:underline;">voir</a></td>
      </tr>
      <tr class="detail-row" id="det-<?= $i ?>">
        <td colspan="7">
          <div class="detail-content">
            <?php if (isset($d['validations']) && is_array($d['validations'])): ?>
              <h3 style="margin-top:0;margin-bottom:1rem;">Historique des validations</h3>
              <?php foreach ($d['validations'] as $validation): ?>
                <div style="border-left:3px solid #003189;padding-left:1rem;margin-bottom:1rem;">
                  <strong><?= h($validation['step_label']) ?></strong> -
                  <?= h($validation['email']) ?> -
                  <span style="<?= $validation['action'] === 'valider' ? 'color:#1a6b3c;' : 'color:#c0392b;' ?>">
                    <?= $validation['action'] === 'valider' ? '✅ Validé' : '❌ Refusé' ?>
                  </span>
                  <?php if (!empty($validation['commentaire'])): ?>
                    <br><em>Commentaire :</em> <?= h($validation['commentaire']) ?>
                  <?php endif; ?>
                  <br><small><?= h($validation['date']) ?></small>
                </div>
              <?php endforeach; ?>
              <hr style="margin:1rem 0;">
            <?php endif; ?>
            
            <?php foreach ($d as $k => $v): if (empty($v)||$v==='0') continue; ?>
              <?php if ($k === 'validations') continue; // Ne pas afficher les validations dans les détails ?>
              <strong><?= h(ucfirst(str_replace('_',' ',preg_replace('/^[a-z]+_/','',$k)))) ?> :</strong>
              <?= $v==='1'?'✓':h($v) ?> &nbsp;
            <?php endforeach; ?>

            <?php if ($status === 'en_cours'): ?>
              <hr style="margin:1rem 0;">
              <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-start;">
                <?php if (is_admin_user()): ?>
                  <?php foreach ($tokens as $t): ?>
                    <?php if (!$t['done_at']): ?>
                      <form method="POST" style="display:inline;" onsubmit="return confirm('Régénérer le lien de validation pour <?= h($t['email']) ?> ?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="regenerate_token">
                        <input type="hidden" name="token_id" value="<?= (int)$t['id'] ?>">
                        <button type="submit" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;">🔄 Relancer <?= h($t['email']) ?></button>
                      </form>
                    <?php endif; ?>
                  <?php endforeach; ?>
                <?php endif; ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Annuler cette soumission ? Cette action est irréversible.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="cancel_submission">
                  <input type="hidden" name="submission_id" value="<?= (int)$row['id'] ?>">
                  <button type="submit" class="btn btn-danger" style="font-size:.75rem;padding:.3rem .6rem;">🗑 Annuler la soumission</button>
                </form>
              </div>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>

  <?php if ($total_pages > 1): ?>
  <div class="pagination">
    <?php
      $qs = http_build_query(['statut' => $filtre, 'form' => $form_f, 'page' => '']);
      $base_url = '?' . $qs;
    ?>
    <?php if ($page > 1): ?>
      <a href="<?= $base_url . ($page - 1) ?>">← Précédent</a>
    <?php else: ?>
      <span class="disabled">← Précédent</span>
    <?php endif; ?>

    <span class="current">Page <?= $page ?> / <?= $total_pages ?></span>

    <?php if ($page < $total_pages): ?>
      <a href="<?= $base_url . ($page + 1) ?>">Suivant →</a>
    <?php else: ?>
      <span class="disabled">Suivant →</span>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>
<script>
function toggle(i){document.getElementById('det-'+i).classList.toggle('open');}
</script>
<?= render_footer() ?>
</body>
</html>
