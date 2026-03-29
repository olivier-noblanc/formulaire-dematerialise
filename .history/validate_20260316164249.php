<?php
require_once __DIR__ . '/helpers.php';

$token  = trim($_GET['token'] ?? '');
$result = $token ? validate_token($token) : ['status' => 'invalid'];
$data   = $result['data'] ?? [];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Validation — DREETS</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: "Marianne", Arial, sans-serif; background: #f5f5fe; color: #1e1e1e; padding: 2rem 1rem; }
    .bandeau { background: #003189; color: #fff; padding: .75rem 2rem; font-size: .85rem; margin-bottom: 2rem; }
    .container { max-width: 560px; margin: 0 auto; }
    .card { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 2rem; text-align: center; }
    h1 { font-size: 1.3rem; color: #003189; margin-bottom: 1rem; }
    .info { font-size: .95rem; color: #444; line-height: 1.7; margin-bottom: 1.5rem; }
    .badge { display: inline-block; background: #003189; color: #fff; padding: .25rem .75rem; border-radius: 3px; font-size: .85rem; margin-bottom: 1rem; }
    .btn { background: #27ae60; color: #fff; border: none; padding: .75rem 2rem; font-size: 1rem; font-family: inherit; border-radius: 3px; cursor: pointer; }
    .ok   { color: #1a6b3c; font-size: 1.1rem; }
    .ok::before { content: "✓ "; font-weight: bold; }
    .err  { color: #c0392b; }
  </style>
</head>
<body>
<div class="bandeau"><strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités</div>
<div class="container"><div class="card">

<?php if ($result['status'] === 'invalid'): ?>
  <h1>Lien invalide</h1>
  <p class="err">Ce lien est introuvable ou expiré.</p>

<?php elseif ($result['status'] === 'already_done'): ?>
  <span class="badge"><?= h($data['step_label']) ?></span>
  <h1>Déjà validé</h1>
  <p class="info">Tâche validée le <?= h($data['done_at']) ?></p>

<?php elseif ($result['status'] === 'closed'): ?>
  <h1>Workflow terminé</h1>
  <p class="info">Ce dossier est déjà clôturé.</p>

<?php elseif ($result['status'] === 'ok'): ?>
  <?php
    $d   = json_decode($data['data'] ?? '{}', true);
    $nom = h(($d['prenom'] ?? '') . ' ' . ($d['nom'] ?? ''));
  ?>
  <span class="badge"><?= h($data['step_label']) ?></span>
  <h1>Tâche validée</h1>
  <p class="info">
    Dossier : <strong><?= $nom ?></strong><br>
    Étape : <strong><?= h($data['step_label']) ?></strong>
  </p>
  <p class="ok">Enregistré le <?= h($data['done_at']) ?></p>
<?php endif; ?>

</div></div>
</body>
</html>
