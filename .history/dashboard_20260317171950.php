<?php
require_once __DIR__ . '/helpers.php';

$pdo    = get_pdo();
$filtre = $_GET['statut'] ?? 'tous';
$form_f = $_GET['form']   ?? '';
$user   = get_current_user();

$where = ['1=1'];
if ($filtre === 'en_cours') $where[] = 's.closed_at IS NULL';
if ($filtre === 'complet')  $where[] = 's.closed_at IS NOT NULL';
if ($form_f)                $where[] = "f.slug = " . $pdo->quote($form_f);
$where = implode(' AND ', $where);

$rows = $pdo->query("
    SELECT s.*, f.label as form_label, f.slug as form_slug
    FROM submissions s
    JOIN forms f ON f.id = s.form_id
    WHERE $where
    ORDER BY s.submitted_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$forms   = $pdo->query("SELECT * FROM forms WHERE actif=1 ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);
$total   = $pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
$complet = $pdo->query("SELECT COUNT(*) FROM submissions WHERE closed_at IS NOT NULL")->fetchColumn();

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
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: "Marianne", Arial, sans-serif; background: #f5f5fe; color: #1e1e1e; }
    .bandeau { background: #003189; color: #fff; padding: .75rem 2rem; font-size: .85rem; margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center; }
    .container { max-width: 1200px; margin: 0 auto; padding: 0 1rem 2rem; }
    h1 { font-size: 1.4rem; color: #003189; margin-bottom: 1.25rem; }
    .toolbar { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; margin-bottom: 1.5rem; }
    .filtres { display: flex; gap: .5rem; }
    .filtres a, .btn-admin { padding: .4rem 1rem; border: 1px solid #003189; border-radius: 3px; text-decoration: none; font-size: .85rem; color: #003189; }
    .filtres a.actif, .btn-admin { background: #003189; color: #fff; }
    select.form-filter { padding: .4rem .75rem; border: 1px solid #aaa; border-radius: 3px; font-size: .85rem; font-family: inherit; }
    .stats { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
    .stat { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: .75rem 1.25rem; min-width: 130px; font-size: .9rem; }
    .stat strong { display: block; font-size: 1.8rem; color: #003189; }
    table { width: 100%; border-collapse: collapse; background: #fff; font-size: .875rem; }
    thead { background: #003189; color: #fff; }
    thead th { padding: .65rem .75rem; text-align: left; font-weight: normal; white-space: nowrap; }
    tbody tr:nth-child(4n+1), tbody tr:nth-child(4n+2) { background: #f7f7fb; }
    tbody td { padding: .55rem .75rem; vertical-align: middle; border-bottom: 1px solid #eee; }
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
<div class="bandeau"><strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités <span>Connecté en tant que : <strong><?= h(get_auth_user()) ?></strong></span> <a href="admin_forms.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;">⚙ Gestion formulaires</a></div>
<div class="container">
  <h1>Supervision — Workflows en cours</h1>

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
    <a href="admin_forms.php" class="btn-admin">⚙ Gestion formulaires</a>
  </div>

  <table>
    <thead>
      <tr>
        <th>Formulaire</th>
        <th>Agent</th>
        <th>Prise de poste</th>
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
      ?>
      <tr>
        <td><span style="font-size:.8rem;background:#e8eaf6;color:#003189;padding:.2rem .5rem;border-radius:3px;"><?= h($row['form_label']) ?></span></td>
        <td><strong><?= $nom ?></strong></td>
        <td><?= h($d['date_prise_poste'] ?? '') ?></td>
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
        <td><?= $row['closed_at']
          ? '<span style="color:#1a6b3c;font-weight:bold;">✓ Clôturé</span>'
          : '<span style="color:#b45309;">En cours</span>' ?>
        </td>
        <td><button class="detail-btn" onclick="toggle(<?= $i ?>)">détail</button></td>
      </tr>
      <tr class="detail-row" id="det-<?= $i ?>">
        <td colspan="7">
          <div class="detail-content">
            <?php foreach ($d as $k => $v): if (empty($v)||$v==='0') continue; ?>
              <strong><?= h(ucfirst(str_replace('_',' ',preg_replace('/^[a-z]+_/','',$k)))) ?> :</strong>
              <?= $v==='1'?'✓':h($v) ?> &nbsp;
            <?php endforeach; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<script>
function toggle(i){document.getElementById('det-'+i).classList.toggle('open');}
</script>
</body>
</html>
