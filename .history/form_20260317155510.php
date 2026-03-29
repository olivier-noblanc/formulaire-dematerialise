<?php
// form.php?f=onboarding — affiche et traite le formulaire d'un slug donné
require_once __DIR__ . '/helpers.php';

$pdo  = get_pdo();
$slug = trim($_GET['f'] ?? '');

$form = $pdo->prepare("SELECT * FROM forms WHERE slug = ? AND actif = 1");
$form->execute([$slug]);
$form = $form->fetch(PDO::FETCH_ASSOC);

if (!$form) { http_response_code(404); die('<p>Formulaire introuvable.</p>'); }

$submitted_by = $_SERVER['AUTH_USER'] ?? 'inconnu';
$current_user = get_auth_user();
$errors       = [];
$success      = false;

// Champs obligatoires hardcodés (identité agent — toujours présents)
$required = ['nom','prenom','date_naissance','corps_grade','affectation',
             'date_prise_poste','type_arrivee','quotite','type_poste','log_batiment_bureau'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($required as $f) {
        if (empty(trim($_POST[$f] ?? ''))) $errors[] = 'Champ obligatoire manquant : ' . $f;
    }

    if (empty($errors)) {
        $now  = date('Y-m-d H:i:s');
        $data = [];
        foreach ($_POST as $k => $v) {
            $data[htmlspecialchars($k)] = is_array($v) ? implode(', ', $v) : trim($v);
        }

        $pdo->prepare("INSERT INTO submissions (form_id, data, submitted_by, submitted_at) VALUES (?,?,?,?)")
            ->execute([$form['id'], json_encode($data, JSON_UNESCAPED_UNICODE), $submitted_by, $now]);

        advance_workflow((int)$pdo->lastInsertId());
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($form['label']) ?> — DREETS</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: "Marianne", Arial, sans-serif; background: #f5f5fe; color: #1e1e1e; padding: 2rem 1rem; }
    .bandeau { background: #003189; color: #fff; padding: .75rem 2rem; font-size: .85rem; margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center; }
    .container { max-width: 780px; margin: 0 auto; }
    h1 { font-size: 1.5rem; color: #003189; margin-bottom: .25rem; }
    .agent-info { font-size: .85rem; color: #555; margin-bottom: 2rem; }
    .card { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 1.5rem; margin-bottom: 1.5rem; }
    .card h2 { font-size: 1rem; color: #003189; border-bottom: 2px solid #003189; padding-bottom: .5rem; margin-bottom: 1.25rem; text-transform: uppercase; letter-spacing: .05em; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    .field { display: flex; flex-direction: column; gap: .35rem; }
    .field.full { grid-column: 1 / -1; }
    label { font-size: .85rem; font-weight: bold; color: #333; }
    .req { color: #c0392b; margin-left: 2px; }
    input[type="text"], input[type="date"], select, textarea {
      width: 100%; padding: .5rem .75rem; border: 1px solid #aaa;
      border-radius: 3px; font-size: .95rem; font-family: inherit; background: #fff; color: #1e1e1e;
    }
    input:focus, select:focus, textarea:focus { outline: 2px solid #003189; outline-offset: 1px; border-color: #003189; }
    textarea { resize: vertical; min-height: 80px; }
    .checkboxes { display: flex; flex-direction: column; gap: .5rem; margin-top: .25rem; }
    .checkbox-item { display: flex; align-items: center; gap: .5rem; font-size: .9rem; }
    input[type="checkbox"] { width: 18px; height: 18px; accent-color: #003189; cursor: pointer; flex-shrink: 0; }
    .errors { background: #fde8e8; border: 1px solid #c0392b; border-radius: 3px; padding: 1rem; margin-bottom: 1.5rem; color: #c0392b; }
    .success { background: #e8f5e9; border: 1px solid #27ae60; border-radius: 3px; padding: 1.5rem; color: #1a6b3c; text-align: center; }
    .success strong { display: block; font-size: 1.2rem; margin-bottom: .5rem; }
    .btn-submit { background: #003189; color: #fff; border: none; padding: .75rem 2.5rem; font-size: 1rem; font-family: inherit; border-radius: 3px; cursor: pointer; display: block; margin: 0 auto; }
    .btn-submit:hover { background: #002270; }
    @media (max-width: 600px) { .grid-2 { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<div class="bandeau"><strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités <span>Connecté en tant que : <strong><?= h(get_auth_user()) ?></strong></span> <a href="index.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;">⚙ Back office</a></div>
<div class="container">
  <h1><?= h($form['label']) ?></h1>
  <?php if ($form['description']): ?><p class="agent-info"><?= h($form['description']) ?></p><?php endif; ?>
  <p class="agent-info">Formulaire rempli par : <strong><?= h($submitted_by) ?></strong></p>

  <?php if ($success): ?>
    <div class="success">
      <strong>✓ Demande enregistrée</strong>
      Le workflow de validation a été déclenché automatiquement.
    </div>
  <?php else: ?>
    <?php if (!empty($errors)): ?>
      <div class="errors"><strong>Erreurs :</strong><ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form method="POST" novalidate>

      <div class="card">
        <h2>Identité de l'agent</h2>
        <div class="grid-2">
          <div class="field"><label>Nom <span class="req">*</span></label><input type="text" name="nom" required value="<?= h($_POST['nom'] ?? '') ?>"></div>
          <div class="field"><label>Prénom <span class="req">*</span></label><input type="text" name="prenom" required value="<?= h($_POST['prenom'] ?? '') ?>"></div>
          <div class="field"><label>Date de naissance <span class="req">*</span></label><input type="date" name="date_naissance" required value="<?= h($_POST['date_naissance'] ?? '') ?>"></div>
          <div class="field"><label>Date de prise de poste <span class="req">*</span></label><input type="date" name="date_prise_poste" required value="<?= h($_POST['date_prise_poste'] ?? '') ?>"></div>
          <div class="field">
            <label>Corps / Grade <span class="req">*</span></label>
            <select name="corps_grade" required>
              <option value="">— Sélectionner —</option>
              <?php foreach (['Attaché d\'administration','Secrétaire administratif','Adjoint administratif','Inspecteur du travail','Contrôleur du travail','Technicien','Ingénieur','Autre'] as $g): ?>
                <option value="<?= h($g) ?>" <?= (($_POST['corps_grade'] ?? '') === $g) ? 'selected' : '' ?>><?= h($g) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Type d'arrivée <span class="req">*</span></label>
            <select name="type_arrivee" required>
              <option value="">— Sélectionner —</option>
              <?php foreach (['Mutation','Primo-recrutement','Détachement','Stage','Alternance'] as $t): ?>
                <option value="<?= h($t) ?>" <?= (($_POST['type_arrivee'] ?? '') === $t) ? 'selected' : '' ?>><?= h($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Service / Affectation <span class="req">*</span></label><input type="text" name="affectation" required value="<?= h($_POST['affectation'] ?? '') ?>"></div>
          <div class="field">
            <label>Quotité <span class="req">*</span></label>
            <select name="quotite" required>
              <option value="">— Sélectionner —</option>
              <?php foreach (['100%','80%','50%'] as $q): ?>
                <option value="<?= h($q) ?>" <?= (($_POST['quotite'] ?? '') === $q) ? 'selected' : '' ?>><?= h($q) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="card">
        <h2>Informatique (IT)</h2>
        <div class="grid-2">
          <div class="field">
            <label>Type de poste <span class="req">*</span></label>
            <select name="type_poste" required>
              <option value="">— Sélectionner —</option>
              <?php foreach (['Fixe','Portable'] as $p): ?>
                <option value="<?= h($p) ?>" <?= (($_POST['type_poste'] ?? '') === $p) ? 'selected' : '' ?>><?= h($p) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Options</label>
            <div class="checkboxes">
              <label class="checkbox-item"><input type="checkbox" name="it_double_ecran" value="1" <?= isset($_POST['it_double_ecran']) ? 'checked' : '' ?>> Double écran</label>
              <label class="checkbox-item"><input type="checkbox" name="it_acces_rpvn" value="1" <?= isset($_POST['it_acces_rpvn']) ? 'checked' : '' ?>> Accès RPVN</label>
              <label class="checkbox-item"><input type="checkbox" name="it_telephone_pro" value="1" <?= isset($_POST['it_telephone_pro']) ? 'checked' : '' ?>> Téléphone professionnel</label>
            </div>
          </div>
          <div class="field full"><label>Applicatifs métier</label><textarea name="it_applicatifs" placeholder="Ex : SOLEN, APART..."><?= h($_POST['it_applicatifs'] ?? '') ?></textarea></div>
        </div>
      </div>

      <div class="card">
        <h2>Ressources Humaines</h2>
        <div class="checkboxes">
          <label class="checkbox-item"><input type="checkbox" name="rh_dossier_admin" value="1" <?= isset($_POST['rh_dossier_admin']) ? 'checked' : '' ?>> Dossier administratif à constituer</label>
          <label class="checkbox-item"><input type="checkbox" name="rh_mutuelle" value="1" <?= isset($_POST['rh_mutuelle']) ? 'checked' : '' ?>> Affiliation mutuelle MGEN</label>
          <label class="checkbox-item"><input type="checkbox" name="rh_visite_medicale" value="1" <?= isset($_POST['rh_visite_medicale']) ? 'checked' : '' ?>> Visite médicale à planifier</label>
          <label class="checkbox-item"><input type="checkbox" name="rh_habilitation" value="1" <?= isset($_POST['rh_habilitation']) ? 'checked' : '' ?>> Habilitation sécurité requise</label>
        </div>
      </div>

      <div class="card">
        <h2>Logistique</h2>
        <div class="grid-2">
          <div class="field full"><label>Bâtiment / Bureau <span class="req">*</span></label><input type="text" name="log_batiment_bureau" required placeholder="Ex : Bât. A — Bureau 214" value="<?= h($_POST['log_batiment_bureau'] ?? '') ?>"></div>
          <div class="field full">
            <div class="checkboxes">
              <label class="checkbox-item"><input type="checkbox" name="log_badge_acces" value="1" <?= isset($_POST['log_badge_acces']) ? 'checked' : '' ?>> Badge d'accès</label>
              <label class="checkbox-item"><input type="checkbox" name="log_vehicule_service" value="1" <?= isset($_POST['log_vehicule_service']) ? 'checked' : '' ?>> Véhicule de service</label>
              <label class="checkbox-item"><input type="checkbox" name="log_epi_requis" value="1" <?= isset($_POST['log_epi_requis']) ? 'checked' : '' ?>> EPI à préparer</label>
            </div>
          </div>
        </div>
      </div>

      <button type="submit" class="btn-submit">Envoyer la déclaration</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
