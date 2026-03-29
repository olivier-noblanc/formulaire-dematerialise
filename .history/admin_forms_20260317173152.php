<?php
// admin_forms.php — Gestion des formulaires et des étapes
require_once __DIR__ . '/helpers.php';

// Vérification des droits d'accès
if (!is_admin_user() && !is_super_admin()) {
    header('Location: admin_access.php');
    exit;
}

// Traitement des actions POST
$action = $_POST['action'] ?? '';

if ($action === 'add_form') {
    $slug = trim($_POST['slug'] ?? '');
    $label = trim($_POST['label'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if (!empty($slug) && !empty($label)) {
        $pdo = get_pdo();
        try {
            $pdo->prepare("INSERT INTO forms (slug, label, description, actif, created_at) VALUES (?, ?, ?, 1, datetime('now'))")
                ->execute([$slug, $label, $description]);
            $success_msg = 'Formulaire ajouté avec succès.';
        } catch (PDOException $e) {
            $error_msg = 'Erreur lors de l\'ajout du formulaire : ' . $e->getMessage();
        }
    } else {
        $error_msg = 'Le slug et le libellé sont requis.';
    }
} elseif ($action === 'toggle_form') {
    $form_id = (int)($_POST['form_id'] ?? 0);
    if ($form_id > 0) {
        $pdo = get_pdo();
        $stmt = $pdo->prepare("SELECT actif FROM forms WHERE id = ?");
        $stmt->execute([$form_id]);
        $form = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($form) {
            $new_actif = $form['actif'] ? 0 : 1;
            $pdo->prepare("UPDATE forms SET actif = ? WHERE id = ?")
                ->execute([$new_actif, $form_id]);
            $success_msg = 'État du formulaire mis à jour.';
        }
    }
} elseif ($action === 'delete_form') {
    $form_id = (int)($_POST['form_id'] ?? 0);
    if ($form_id > 0) {
        $pdo = get_pdo();
        try {
            // Suppression des étapes associées
            $pdo->prepare("DELETE FROM steps WHERE form_id = ?")->execute([$form_id]);
            // Suppression du formulaire
            $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$form_id]);
            $success_msg = 'Formulaire supprimé avec succès.';
        } catch (PDOException $e) {
            $error_msg = 'Erreur lors de la suppression du formulaire : ' . $e->getMessage();
        }
    }
} elseif ($action === 'add_step') {
    $form_id = (int)($_POST['form_id'] ?? 0);
    $label = trim($_POST['label'] ?? '');
    $ordre = (int)($_POST['ordre'] ?? 0);
    
    if ($form_id > 0 && !empty($label) && $ordre > 0) {
        $pdo = get_pdo();
        try {
            $pdo->prepare("INSERT INTO steps (form_id, label, ordre, actif) VALUES (?, ?, ?, 1)")
                ->execute([$form_id, $label, $ordre]);
            $success_msg = 'Étape ajoutée avec succès.';
        } catch (PDOException $e) {
            $error_msg = 'Erreur lors de l\'ajout de l\'étape : ' . $e->getMessage();
        }
    } else {
        $error_msg = 'Les champs obligatoires ne sont pas remplis.';
    }
} elseif ($action === 'toggle_step') {
    $step_id = (int)($_POST['step_id'] ?? 0);
    if ($step_id > 0) {
        $pdo = get_pdo();
        $stmt = $pdo->prepare("SELECT actif FROM steps WHERE id = ?");
        $stmt->execute([$step_id]);
        $step = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($step) {
            $new_actif = $step['actif'] ? 0 : 1;
            $pdo->prepare("UPDATE steps SET actif = ? WHERE id = ?")
                ->execute([$new_actif, $step_id]);
            $success_msg = 'État de l\'étape mis à jour.';
        }
    }
} elseif ($action === 'delete_step') {
    $step_id = (int)($_POST['step_id'] ?? 0);
    if ($step_id > 0) {
        $pdo = get_pdo();
        try {
            // Suppression des destinataires associés
            $pdo->prepare("DELETE FROM step_recipients WHERE step_id = ?")->execute([$step_id]);
            // Suppression de l'étape
            $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$step_id]);
            $success_msg = 'Étape supprimée avec succès.';
        } catch (PDOException $e) {
            $error_msg = 'Erreur lors de la suppression de l\'étape : ' . $e->getMessage();
        }
    }
} elseif ($action === 'add_recipient') {
    $step_id = (int)($_POST['step_id'] ?? 0);
    $email = trim($_POST['email'] ?? '');
    
    if ($step_id > 0 && !empty($email)) {
        $pdo = get_pdo();
        try {
            $pdo->prepare("INSERT INTO step_recipients (step_id, email) VALUES (?, ?)")
                ->execute([$step_id, $email]);
            $success_msg = 'Destinataire ajouté avec succès.';
        } catch (PDOException $e) {
            $error_msg = 'Erreur lors de l\'ajout du destinataire : ' . $e->getMessage();
        }
    } else {
        $error_msg = 'L\'étape et l\'email sont requis.';
    }
} elseif ($action === 'delete_recipient') {
    $recipient_id = (int)($_POST['recipient_id'] ?? 0);
    if ($recipient_id > 0) {
        $pdo = get_pdo();
        try {
            $pdo->prepare("DELETE FROM step_recipients WHERE id = ?")->execute([$recipient_id]);
            $success_msg = 'Destinataire supprimé avec succès.';
        } catch (PDOException $e) {
            $error_msg = 'Erreur lors de la suppression du destinataire : ' . $e->getMessage();
        }
    }
}

// Récupération des formulaires
$forms = get_pdo()->query("SELECT * FROM forms ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);

// Récupération des étapes et destinataires pour chaque formulaire
$forms_with_steps = [];
foreach ($forms as $form) {
    $form_id = $form['id'];
    $stmt = get_pdo()->prepare("
        SELECT s.*,
               (SELECT COUNT(*) FROM step_recipients sr WHERE sr.step_id = s.id) as recipient_count
        FROM steps s
        WHERE s.form_id = ?
        ORDER BY s.ordre, s.label
    ");
    $stmt->execute([$form_id]);
    $steps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $forms_with_steps[] = [
        'form' => $form,
        'steps' => $steps
    ];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestion des formulaires — DREETS Workflow</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: "Marianne", Arial, sans-serif; background: #f5f5fe; color: #1e1e1e; }
        .bandeau { background: #003189; color: #fff; padding: .75rem 2rem; font-size: .85rem; margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center; }
        .container { max-width: 1200px; margin: 0 auto; padding: 0 1rem 2rem; }
        h1 { font-size: 1.4rem; color: #003189; margin-bottom: 1.25rem; }
        .card { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 1.5rem; margin-bottom: 1.5rem; }
        .btn { padding: .6rem 1.2rem; border: none; border-radius: 3px; font-size: .9rem; font-family: inherit; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #003189; color: #fff; }
        .btn-primary:hover { background: #002270; }
        .btn-secondary { background: #f0f0f0; color: #333; }
        .btn-secondary:hover { background: #e0e0e0; }
        .btn-danger { background: #c0392b; color: #fff; }
        .btn-danger:hover { background: #a93226; }
        .msg-success { background: #e8f5e9; border: 1px solid #27ae60; border-radius: 3px; padding: .75rem 1rem; margin-bottom: 1rem; color: #1a6b3c; }
        .msg-error { background: #ffebee; border: 1px solid #c0392b; border-radius: 3px; padding: .75rem 1rem; margin-bottom: 1rem; color: #c0392b; }
        .field { display: flex; flex-direction: column; gap: .3rem; margin-bottom: 1rem; }
        label { font-size: .82rem; font-weight: bold; color: #444; }
        input[type="text"], input[type="email"], input[type="number"], textarea, select {
            padding: .6rem; border: 1px solid #aaa; border-radius: 3px;
            font-size: .9rem; font-family: inherit; background: #fff; color: #1e1e1e;
        }
        input:focus, textarea:focus, select:focus { outline: 2px solid #003189; border-color: #003189; }
        table { width: 100%; border-collapse: collapse; background: #fff; margin-top: 1rem; }
        th, td { padding: .75rem; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #003189; color: #fff; font-weight: normal; }
        tr:nth-child(even) { background: #f7f7fb; }
        .actions { display: flex; gap: .5rem; }
        .action-btn { padding: .3rem .6rem; border: none; border-radius: 3px; font-size: .8rem; cursor: pointer; }
        .toggle-btn { background: #1a6b3c; color: #fff; }
        .delete-btn { background: #c0392b; color: #fff; }
        .form-section { margin-bottom: 2rem; }
        .step-list { margin-top: 1rem; }
        .step-item { padding: .75rem; border: 1px solid #ddd; border-radius: 4px; margin-bottom: .5rem; background: #f9f9ff; }
        .recipient-list { margin-top: .5rem; }
        .recipient-item { padding: .25rem 0; border-bottom: 1px dashed #ccc; }
        .recipient-item:last-child { border-bottom: none; }
        .form-actions { display: flex; gap: .5rem; margin-top: .5rem; }
        .form-actions a { text-decoration: none; }
    </style>
</head>
<body>
<div class="bandeau">
    <strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités
    <span>Connecté en tant que : <strong><?= h(get_auth_user()) ?></strong></span>
    <a href="admin_access.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;">⚙ Gestion accès</a>
</div>
<div class="container">
    <h1>Gestion des formulaires</h1>
    
    <?php if (isset($success_msg)): ?>
        <div class="msg-success"><?= h($success_msg) ?></div>
    <?php endif; ?>
    
    <?php if (isset($error_msg)): ?>
        <div class="msg-error"><?= h($error_msg) ?></div>
    <?php endif; ?>
    
    <div class="card">
        <h2>Ajouter un nouveau formulaire</h2>
        <form method="POST">
            <input type="hidden" name="action" value="add_form">
            <div class="field">
                <label>Slug (identifiant technique)</label>
                <input type="text" name="slug" required placeholder="ex: onboarding">
            </div>
            <div class="field">
                <label>Libellé (affiché dans l'interface)</label>
                <input type="text" name="label" required placeholder="ex: Onboarding agent">
            </div>
            <div class="field">
                <label>Description</label>
                <textarea name="description" placeholder="Description du formulaire"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Ajouter le formulaire</button>
        </form>
    </div>
    
    <?php foreach ($forms_with_steps as $item): ?>
        <?php $form = $item['form']; ?>
        <div class="card form-section">
            <h2><?= h($form['label']) ?></h2>
            <p><strong>Slug:</strong> <?= h($form['slug']) ?></p>
            <p><strong>Description:</strong> <?= h($form['description']) ?></p>
            
            <div class="form-actions">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="toggle_form">
                    <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                    <button type="submit" class="btn action-btn <?= $form['actif'] ? 'toggle-btn' : 'btn-secondary' ?>">
                        <?= $form['actif'] ? 'Désactiver' : 'Activer' ?>
                    </button>
                </form>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce formulaire ?');">
                    <input type="hidden" name="action" value="delete_form">
                    <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                    <button type="submit" class="btn delete-btn">Supprimer</button>
                </form>
                <a href="admin_access.php" class="btn btn-secondary">Retour</a>
            </div>
            
            <div class="step-list">
                <h3>Étapes du formulaire</h3>
                
                <div class="card">
                    <h4>Ajouter une étape</h4>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_step">
                        <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                        <div class="field">
                            <label>Libellé de l'étape</label>
                            <input type="text" name="label" required placeholder="ex: Validation RH">
                        </div>
                        <div class="field">
                            <label>Ordre (numéro de l'étape)</label>
                            <input type="number" name="ordre" required min="1" value="1">
                        </div>
                        <button type="submit" class="btn btn-primary">Ajouter l'étape</button>
                    </form>
                </div>
                
                <?php foreach ($item['steps'] as $step): ?>
                    <div class="step-item">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <div>
                                <strong>Ordre <?= $step['ordre'] ?>:</strong> <?= h($step['label']) ?>
                                <br>
                                <small><?= $step['recipient_count'] ?> destinataire(s)</small>
                            </div>
                            <div class="actions">
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_step">
                                    <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
                                    <button type="submit" class="btn action-btn <?= $step['actif'] ? 'toggle-btn' : 'btn-secondary' ?>">
                                        <?= $step['actif'] ? 'Désactiver' : 'Activer' ?>
                                    </button>
                                </form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cette étape ?');">
                                    <input type="hidden" name="action" value="delete_step">
                                    <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
                                    <button type="submit" class="btn delete-btn">Supprimer</button>
                                </form>
                            </div>
                        </div>
                        
                        <div class="recipient-list">
                            <h4>Destinataires</h4>
                            
                            <div class="card">
                                <h5>Ajouter un destinataire</h5>
                                <form method="POST">
                                    <input type="hidden" name="action" value="add_recipient">
                                    <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
                                    <div class="field">
                                        <label>Email du destinataire</label>
                                        <input type="email" name="email" required placeholder="ex: rh@dreets.gouv.fr">
                                    </div>
                                    <button type="submit" class="btn btn-primary">Ajouter le destinataire</button>
                                </form>
                            </div>
                            
                            <?php
                            $stmt = get_pdo()->prepare("
                                SELECT * FROM step_recipients
                                WHERE step_id = ?
                                ORDER BY email
                            ");
                            $stmt->execute([$step['id']]);
                            $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            
                            <?php if (!empty($recipients)): ?>
                                <?php foreach ($recipients as $recipient): ?>
                                    <div class="recipient-item">
                                        <?= h($recipient['email']) ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce destinataire ?');">
                                            <input type="hidden" name="action" value="delete_recipient">
                                            <input type="hidden" name="recipient_id" value="<?= $recipient['id'] ?>">
                                            <button type="submit" class="btn delete-btn" style="padding: .1rem .3rem; font-size: .7rem;">Supprimer</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>Aucun destinataire défini.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
</body>
</html>