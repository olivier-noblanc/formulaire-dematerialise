<?php
require_once __DIR__ . '/helpers.php';

// Traitement du POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim($_POST['token'] ?? '');
    $action = trim($_POST['action'] ?? '');
    $comment = trim($_POST['comment'] ?? '');
    
    if ($token && in_array($action, ['valider', 'refuser'])) {
        $result = validate_token($token, $action, $comment);
        
        if ($result['status'] === 'ok') {
            // Afficher un message de succès
            $success = true;
        } else {
            $error = $result['status'] === 'invalid' ? 'Lien invalide ou expiré.' :
                     ($result['status'] === 'already_done' ? 'Cette tâche a déjà été traitée.' :
                     ($result['status'] === 'closed' ? 'Le workflow est déjà terminé.' : 'Erreur inconnue.'));
        }
    } else {
        $error = 'Données invalides.';
    }
}

// Si GET, afficher le formulaire de validation/refus
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = trim($_GET['token'] ?? '');
    $result = $token ? validate_token($token) : ['status' => 'invalid'];
    $data = $result['data'] ?? [];
}

// Si POST et succès, on ne fait rien d'autre que l'affichage
if (!isset($success) && !isset($error) && $_SERVER['REQUEST_METHOD'] === 'GET' && $result['status'] === 'ok') {
    // Afficher le formulaire
} elseif (isset($success) || isset($error)) {
    // Afficher le message de succès ou d'erreur
} elseif (isset($error) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // Afficher l'erreur
}
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
    .card { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 2rem; }
    h1 { font-size: 1.3rem; color: #003189; margin-bottom: 1rem; }
    .info { font-size: .95rem; color: #444; line-height: 1.7; margin-bottom: 1.5rem; }
    .badge { display: inline-block; background: #003189; color: #fff; padding: .25rem .75rem; border-radius: 3px; font-size: .85rem; margin-bottom: 1rem; }
    .btn { background: #27ae60; color: #fff; border: none; padding: .75rem 2rem; font-size: 1rem; font-family: inherit; border-radius: 3px; cursor: pointer; margin: 0 .5rem; }
    .btn-refuser { background: #c0392b; }
    .ok   { color: #1a6b3c; font-size: 1.1rem; }
    .ok::before { content: "✓ "; font-weight: bold; }
    .err  { color: #c0392b; }
    .form-group { margin-bottom: 1.5rem; }
    textarea { width: 100%; padding: .75rem; border: 1px solid #ddd; border-radius: 3px; font-family: inherit; font-size: 1rem; resize: vertical; }
    .submit-buttons { display: flex; justify-content: center; margin-top: 2rem; }
    .validation-details { background: #f0f0f8; padding: 1.5rem; border-radius: 4px; margin-bottom: 1.5rem; }
    .validation-details h2 { font-size: 1.1rem; margin-bottom: 1rem; color: #003189; }
    .validation-details p { margin-bottom: .5rem; }
  </style>
</head>
<body>
<div class="bandeau"><strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités</div>
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
  <h1>Action requise</h1>
  
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
</body>
</html>
