<?php
require_once __DIR__ . '/helpers.php';

// Traitement du POST — exécute l'action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérification CSRF
    if (!verify_csrf()) {
        die('Token CSRF invalide. Veuillez réessayer.');
    }

    $token = trim($_POST['token'] ?? '');
    $action = trim($_POST['action'] ?? '');
    $comment = trim($_POST['comment'] ?? '');
    
    if ($token && in_array($action, ['valider', 'refuser'])) {
        $result = validate_token($token, $action, $comment);
        
        if ($result['status'] === 'ok') {
            $success = true;
        } else {
            $error = $result['status'] === 'invalid' ? 'Lien invalide ou expiré.' :
                     ($result['status'] === 'already_done' ? 'Cette tâche a déjà été traitée.' :
                     ($result['status'] === 'closed' ? 'Le workflow est déjà terminé.' :
                     ($result['status'] === 'expired' ? 'Ce lien a expiré.' : 'Erreur inconnue.')));
        }
    } else {
        $error = 'Données invalides.';
    }
}

// GET request — affichage uniquement (pas d'effet de bord)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = trim($_GET['token'] ?? '');
    
    if ($token) {
        // Recherche le token dans la base de données sans appeler validate_token()
        $pdo = get_pdo();
        $row = $pdo->prepare("
            SELECT t.*, st.label as step_label, s.form_id,
                   f.label as form_label, s.data, s.closed_at, s.status
            FROM tokens t
            JOIN steps st ON st.id = t.step_id
            JOIN submissions s ON s.id = t.submission_id
            JOIN forms f ON f.id = s.form_id
            WHERE t.token = ?
        ");
        $row->execute([$token]);
        $data = $row->fetch(PDO::FETCH_ASSOC);

        if (!$data) {
            $result = ['status' => 'invalid'];
        } elseif ($data['done_at']) {
            $result = ['status' => 'already_done', 'data' => $data];
        } elseif ($data['closed_at']) {
            $result = ['status' => 'closed', 'data' => $data];
        } elseif (!empty($data['expires_at']) && strtotime($data['expires_at']) < time()) {
            $result = ['status' => 'expired', 'data' => $data];
        } else {
            $result = ['status' => 'ok', 'data' => $data];
        }
    } else {
        $result = ['status' => 'invalid'];
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Validation — DREETS</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='15' fill='%23003189'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial'>D</text></svg>">
  <?php require_once __DIR__ . '/style.php'; ?>
  <style>
    /* Overrides */
    body { padding: 2rem 1rem; }
    .container { max-width: 560px; padding: 0; }
    .card { padding: 2rem; }
    h1 { font-size: 1.3rem; margin-bottom: 1rem; }

    /* Page-specific */
    .info { font-size: .95rem; color: #444; line-height: 1.7; margin-bottom: 1.5rem; }
    .badge { display: inline-block; background: #003189; color: #fff; padding: .25rem .75rem; border-radius: 3px; font-size: .85rem; margin-bottom: 1rem; }
    .btn { background: #27ae60; color: #fff; border: none; padding: .75rem 2rem; font-size: 1rem; font-family: inherit; border-radius: 3px; cursor: pointer; margin: 0 .5rem; }
    .btn-refuser { background: #c0392b; }
    .ok   { color: #1a6b3c; font-size: 1.1rem; }
    .ok::before { content: "✓ "; font-weight: bold; }
    .err  { color: #c0392b; }
    .submit-buttons { display: flex; justify-content: center; margin-top: 2rem; }
    .validation-details { background: #f0f0f8; padding: 1.5rem; border-radius: 4px; margin-bottom: 1.5rem; }
    .validation-details h2 { font-size: 1.1rem; margin-bottom: 1rem; color: #003189; }
    .validation-details p { margin-bottom: .5rem; }

    /* Workflow progression */
    .wf-progression { margin-bottom: 1.5rem; }
    .wf-progression h3 { font-size: .9rem; color: #003189; margin-bottom: .75rem; }
    .wf-steps { display: flex; flex-direction: column; gap: .35rem; }
    .wf-prog-step { display: flex; align-items: center; gap: .5rem; font-size: .85rem; padding: .4rem .6rem; border-radius: 3px; }
    .wf-prog-done { background: #e8f5e9; color: #1a6b3c; }
    .wf-prog-current { background: #fff3e0; color: #b45309; font-weight: bold; border: 1px dashed #b45309; }
    .wf-prog-upcoming { background: #f5f5f5; color: #999; }
    .wf-prog-icon { font-size: .9rem; flex-shrink: 0; }

    .back-link { display: inline-block; margin-bottom: 1rem; font-size: .85rem; color: #003189; text-decoration: none; }
    .back-link:hover { text-decoration: underline; }
  </style>
</head>
<body>
<div class="bandeau">
    <strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités
    <span><a href="docs.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;">📖 Documentation</a></span>
</div>
<div class="container"><div class="card">

<?php if (isset($success)): ?>
  <h1>Validation effectuée</h1>
  <p class="ok">Action effectuée avec succès.</p>

<?php elseif (isset($error)): ?>
  <h1>Erreur</h1>
  <p class="err"><?= h($error) ?></p>

<?php elseif ($result['status'] === 'invalid'): ?>
  <h1>Lien invalide</h1>
  <p class="err">Ce lien est introuvable ou expiré.</p>

<?php elseif ($result['status'] === 'already_done'): ?>
  <?php $data = $result['data'] ?? []; ?>
  <span class="badge"><?= h($data['step_label']) ?></span>
  <h1>Déjà validé</h1>
  <p class="info">Tâche validée le <?= h($data['done_at']) ?></p>

<?php elseif ($result['status'] === 'closed'): ?>
  <h1>Workflow terminé</h1>
  <p class="info">Ce dossier est déjà clôturé.</p>

<?php elseif ($result['status'] === 'expired'): ?>
  <h1>Lien expiré</h1>
  <p class="err">Ce lien de validation a expiré. Veuillez contacter l'expéditeur pour obtenir un nouveau lien.</p>

<?php elseif ($result['status'] === 'ok'): ?>
  <?php
    $data = $result['data'] ?? [];
    $d   = json_decode($data['data'] ?? '{}', true);
    $nom = h(($d['prenom'] ?? '') . ' ' . ($d['nom'] ?? ''));

    // Récupérer toutes les étapes du workflow pour afficher la progression
    $wf_steps = $pdo->prepare("
        SELECT st.id, st.label, st.ordre,
               GROUP_CONCAT(t2.done_at, '|') as dones,
               GROUP_CONCAT(t2.email, '|') as emails
        FROM steps st
        LEFT JOIN tokens t2 ON t2.step_id = st.id AND t2.submission_id = ?
        WHERE st.form_id = ? AND st.actif = 1
        GROUP BY st.id
        ORDER BY st.ordre, st.id
    ");
    $wf_steps->execute([$data['submission_id'] ?? 0, $data['form_id'] ?? 0]);
    $all_wf_steps = $wf_steps->fetchAll(PDO::FETCH_ASSOC);
  ?>
  <a href="my_validations.php" class="back-link">← Mes validations</a>
  <span class="badge"><?= h($data['step_label']) ?></span>
  <h1>Action requise</h1>
  
  <!-- Progression du workflow -->
  <?php if (!empty($all_wf_steps)): ?>
  <div class="wf-progression">
    <h3>Progression du circuit</h3>
    <div class="wf-steps">
      <?php foreach ($all_wf_steps as $ws):
          $dones_arr = array_filter(explode('|', $ws['dones'] ?? ''), fn($x) => !empty($x));
          $all_done = count($dones_arr) > 0 && count(array_filter(explode('|', $ws['dones'] ?? ''))) === count(array_filter(explode('|', $ws['emails'] ?? '')));
          $is_current = ($ws['id'] == ($data['step_id'] ?? 0));

          if ($all_done) { $cls = 'wf-prog-done'; $icon = '✓'; }
          elseif ($is_current) { $cls = 'wf-prog-current'; $icon = '⏳'; }
          else { $cls = 'wf-prog-upcoming'; $icon = '○'; }
      ?>
        <div class="wf-prog-step <?= $cls ?>">
          <span class="wf-prog-icon"><?= $icon ?></span>
          <span>Étape <?= (int)$ws['ordre'] ?> — <?= h($ws['label']) ?><?= $is_current ? ' (votre tour)' : '' ?><?= $all_done ? ' — validée' : '' ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Affichage des détails du formulaire -->
  <div class="validation-details">
    <h2>Détails du formulaire</h2>
    <p><strong>Dossier :</strong> <?= $nom ?></p>
    <p><strong>Étape :</strong> <?= h($data['step_label']) ?></p>
    <?php foreach ($d as $k => $v): if (empty($v) || $v === '0') continue; ?>
      <p><strong><?= h(ucfirst(str_replace('_', ' ', preg_replace('/^[a-z]+_/', '', $k)))) ?> :</strong> <?= $v === '1' ? '✓' : h((string)$v) ?></p>
    <?php endforeach; ?>
  </div>
  
  <!-- Formulaire de validation/refus -->
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= h($token) ?>">
    
    <div class="form-group">
      <label for="comment">Commentaire (facultatif)</label>
      <textarea id="comment" name="comment" rows="4" placeholder="Ajoutez un commentaire si nécessaire..."></textarea>
    </div>
    
    <div class="submit-buttons">
      <button type="submit" name="action" value="valider" class="btn">✅ Valider</button>
      <button type="submit" name="action" value="refuser" class="btn btn-refuser">❌ Refuser</button>
    </div>
  </form>
<?php endif; ?>

</div></div>
<?= render_footer() ?>
</body>
</html>
