<?php
// admin_forms.php — Gestion des formulaires et des étapes
require_once __DIR__ . '/helpers.php';

// Vérification des droits d'accès
if (!is_admin_user() && !is_super_admin()) {
    header('Location: admin_access.php');
    exit;
}

// Récupération des formulaires pour le sélecteur
$forms = get_pdo()->query("SELECT id, label FROM forms ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);

// Récupération de l'ID du formulaire sélectionné
$form_id = (int)($_GET['form_id'] ?? 0);

// Récupération de l'ID de l'étape à modifier
$edit_step_id = (int)($_GET['edit_step'] ?? 0);

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
            // Redirection pour éviter les doubles soumissions
            header('Location: admin_forms.php?form_id=' . $pdo->lastInsertId());
            exit;
        } catch (PDOException $e) {
            $error_msg = 'Erreur lors de l\'ajout du formulaire : ' . $e->getMessage();
        }
    } else {
        $error_msg = 'Le slug et le libellé sont requis.';
    }
} elseif ($action === 'update_form') {
    $form_id = (int)($_POST['form_id'] ?? 0);
    $slug = trim($_POST['slug'] ?? '');
    $label = trim($_POST['label'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $actif = isset($_POST['actif']) ? 1 : 0;
    
    if ($form_id > 0 && !empty($slug) && !empty($label)) {
        $pdo = get_pdo();
        try {
            $pdo->prepare("UPDATE forms SET slug = ?, label = ?, description = ?, actif = ? WHERE id = ?")
                ->execute([$slug, $label, $description, $actif, $form_id]);
            $success_msg = 'Formulaire mis à jour avec succès.';
            // Redirection pour éviter les doubles soumissions
            header('Location: admin_forms.php?form_id=' . $form_id);
            exit;
        } catch (PDOException $e) {
            $error_msg = 'Erreur lors de la mise à jour du formulaire : ' . $e->getMessage();
        }
    } else {
        $error_msg = 'Le slug et le libellé sont requis.';
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
            // Redirection pour éviter les doubles soumissions
            header('Location: admin_forms.php');
            exit;
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
            // Redirection pour éviter les doubles soumissions
            header('Location: admin_forms.php?form_id=' . $form_id);
            exit;
        } catch (PDOException $e) {
            $error_msg = 'Erreur lors de l\'ajout de l\'étape : ' . $e->getMessage();
        }
    } else {
        $error_msg = 'Les champs obligatoires ne sont pas remplis.';
    }
} elseif ($action === 'update_step') {
    $step_id = (int)($_POST['step_id'] ?? 0);
    $label = trim($_POST['label'] ?? '');
    $ordre = (int)($_POST['ordre'] ?? 0);
    $actif = isset($_POST['actif']) ? 1 : 0;
    
    if ($step_id > 0 && !empty($label) && $ordre > 0) {
        $pdo = get_pdo();
        try {
            $pdo->prepare("UPDATE steps SET label = ?, ordre = ?, actif = ? WHERE id = ?")
                ->execute([$label, $ordre, $actif, $step_id]);
            $success_msg = 'Étape mise à jour avec succès.';
            // Redirection pour éviter les doubles soumissions
            header('Location: admin_forms.php?form_id=' . $form_id);
            exit;
        } catch (PDOException $e) {
            $error_msg = 'Erreur lors de la mise à jour de l\'étape : ' . $e->getMessage();
        }
    } else {
        $error_msg = 'Les champs obligatoires ne sont pas remplis.';
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
            // Redirection pour éviter les doubles soumissions
            header('Location: admin_forms.php?form_id=' . $form_id);
            exit;
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
            // Redirection pour éviter les doubles soumissions
            header('Location: admin_forms.php?form_id=' . $form_id);
            exit;
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
            // Redirection pour éviter les doubles soumissions
            header('Location: admin_forms.php?form_id=' . $form_id);
            exit;
        } catch (PDOException $e) {
            $error_msg = 'Erreur lors de la suppression du destinataire : ' . $e->getMessage();
        }
    }
}

// Récupération des informations du formulaire sélectionné
$form = null;
$steps = [];
$recipients = [];

if ($form_id > 0) {
    $pdo = get_pdo();
    
    // Récupération du formulaire
    $stmt = $pdo->prepare("SELECT * FROM forms WHERE id = ?");
    $stmt->execute([$form_id]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($form) {
        // Récupération des étapes
        $stmt = $pdo->prepare("
            SELECT s.*,
                   (SELECT COUNT(*) FROM step_recipients sr WHERE sr.step_id = s.id) as recipient_count
            FROM steps s
            WHERE s.form_id = ?
            ORDER BY s.ordre, s.label
        ");
        $stmt->execute([$form_id]);
        $steps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Récupération des destinataires pour chaque étape
        foreach ($steps as &$step) {
            $stmt = $pdo->prepare("
                SELECT * FROM step_recipients
                WHERE step_id = ?
                ORDER BY email
            ");
            $stmt->execute([$step['id']]);
            $step['recipients'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
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
        .section-title { font-size: 1.2rem; color: #003189; margin-bottom: 1rem; padding-bottom: .5rem; border-bottom: 2px solid #003189; }
        .form-selector { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; }
        .form-selector select { padding: .6rem; border: 1px solid #aaa; border-radius: 3px; font-size: 1rem; font-family: inherit; }
        .form-selector button { padding: .6rem 1.2rem; border: none; border-radius: 3px; background: #003189; color: #fff; cursor: pointer; }
        .form-selector button:hover { background: #002270; }
        .form-section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .form-section-header h2 { margin: 0; }
        .form-section-header a { text-decoration: none; }
        .step-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: .5rem; }
        .step-row div { flex: 1; }
        .step-row .step-label { font-weight: bold; }
        .step-row .step-order { width: 80px; text-align: center; }
        .step-row .step-actif { width: 80px; text-align: center; }
        .step-row .step-actions { width: 150px; text-align: right; }
        .recipient-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: .25rem; }
        .recipient-row div { flex: 1; }
        .recipient-row .recipient-email { flex: 2; }
        .recipient-row .recipient-actions { width: 100px; text-align: right; }
        .edit-form { display: none; margin-top: 1rem; padding: 1rem; background: #f0f8ff; border: 1px solid #b0d0ff; border-radius: 4px; }
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
    
    <?php if (!empty($success_msg)): ?>
        <div class="msg-success"><?= h($success_msg) ?></div>
    <?php endif; ?>
    
    <?php if (!empty($error_msg)): ?>
        <div class="msg-error"><?= h($error_msg) ?></div>
    <?php endif; ?>
    
    <!-- Sélecteur de formulaire -->
    <div class="form-selector">
        <select name="form_id" onchange="window.location.href='admin_forms.php?form_id='+this.value">
            <option value="">Sélectionner un formulaire</option>
            <?php foreach ($forms as $f): ?>
                <option value="<?= $f['id'] ?>" <?= $form_id == $f['id'] ? 'selected' : '' ?>>
                    <?= h($f['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <a href="admin_forms.php" class="btn btn-primary">Nouveau formulaire</a>
    </div>
    
    <!-- Formulaire de création si aucun formulaire sélectionné -->
    <?php if ($form_id <= 0): ?>
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
    <?php else: ?>
        <!-- Section A - Informations du formulaire -->
        <div class="card">
            <div class="form-section-header">
                <h2 class="section-title">Informations du formulaire</h2>
                <a href="dashboard.php" class="btn btn-secondary">Retour au tableau de bord</a>
            </div>
            
            <?php if ($form): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="update_form">
                    <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                    <div class="field">
                        <label>Slug (identifiant technique)</label>
                        <input type="text" name="slug" value="<?= h($form['slug']) ?>" required>
                    </div>
                    <div class="field">
                        <label>Libellé (affiché dans l'interface)</label>
                        <input type="text" name="label" value="<?= h($form['label']) ?>" required>
                    </div>
                    <div class="field">
                        <label>Description</label>
                        <textarea name="description" placeholder="Description du formulaire"><?= h($form['description']) ?></textarea>
                    </div>
                    <div class="field">
                        <label>
                            <input type="checkbox" name="actif" <?= $form['actif'] ? 'checked' : '' ?>> Actif
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                </form>
                
                <div class="form-actions" style="margin-top: 1rem;">
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce formulaire ?');">
                        <input type="hidden" name="action" value="delete_form">
                        <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                        <button type="submit" class="btn btn-danger">Supprimer le formulaire</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Section B - Étapes -->
        <div class="card">
            <div class="form-section-header">
                <h2 class="section-title">Étapes</h2>
            </div>
            
            <!-- Formulaire d'ajout d'étape -->
            <div class="card">
                <h3>Ajouter une étape</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="add_step">
                    <input type="hidden" name="form_id" value="<?= $form_id ?>">
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
            
            <!-- Liste des étapes -->
            <?php if (!empty($steps)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Ordre</th>
                            <th>Label</th>
                            <th>Actif</th>
                            <th>Destinataires</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($steps as $step): ?>
                            <tr>
                                <td><?= h($step['ordre']) ?></td>
                                <td><?= h($step['label']) ?></td>
                                <td><?= $step['actif'] ? 'Oui' : 'Non' ?></td>
                                <td><?= h($step['recipient_count']) ?></td>
                                <td class="actions">
                                    <a href="?form_id=<?= $form_id ?>&edit_step=<?= $step['id'] ?>" class="btn action-btn btn-secondary">Modifier</a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cette étape ?');">
                                        <input type="hidden" name="action" value="delete_step">
                                        <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
                                        <button type="submit" class="btn delete-btn">Supprimer</button>
                                    </form>
                                </td>
                            </tr>
                            <!-- Formulaire d'édition inline -->
                            <?php if ($edit_step_id === (int)$step['id']): ?>
                            <tr>
                                <td colspan="5">
                                    <div class="edit-form">
                                        <h4>Modifier l'étape</h4>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="update_step">
                                            <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
                                            <input type="hidden" name="form_id" value="<?= $form_id ?>">
                                            <div class="field">
                                                <label>Libellé de l'étape</label>
                                                <input type="text" name="label" value="<?= h($step['label']) ?>" required>
                                            </div>
                                            <div class="field">
                                                <label>Ordre (numéro de l'étape)</label>
                                                <input type="number" name="ordre" value="<?= $step['ordre'] ?>" required min="1">
                                            </div>
                                            <div class="field">
                                                <label>
                                                    <input type="checkbox" name="actif" <?= $step['actif'] ? 'checked' : '' ?>> Actif
                                                </label>
                                            </div>
                                            <button type="submit" class="btn btn-primary">Enregistrer</button>
                                            <a href="?form_id=<?= $form_id ?>" class="btn btn-secondary">Annuler</a>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Aucune étape définie pour ce formulaire.</p>
            <?php endif; ?>
        </div>
        
        <!-- Section C - Destinataires de l'étape -->
        <div class="card">
            <div class="form-section-header">
                <h2 class="section-title">Destinataires de l'étape</h2>
            </div>
            
            <!-- Sélecteur d'étape -->
            <div class="field">
                <label>Sélectionner une étape</label>
                <select name="step_id" onchange="window.location.href='admin_forms.php?form_id=<?= $form_id ?>&step_id='+this.value">
                    <option value="">Sélectionner une étape</option>
                    <?php foreach ($steps as $step): ?>
                        <option value="<?= $step['id'] ?>" <?= (isset($_GET['step_id']) && $_GET['step_id'] == $step['id']) ? 'selected' : '' ?>>
                            <?= h($step['label']) ?> (Ordre <?= h($step['ordre']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <?php if (isset($_GET['step_id']) && !empty($_GET['step_id'])): ?>
                <?php 
                $selected_step = null;
                foreach ($steps as $step) {
                    if ($step['id'] == $_GET['step_id']) {
                        $selected_step = $step;
                        break;
                    }
                }
                ?>
                
                <?php if ($selected_step): ?>
                    <h3><?= h($selected_step['label']) ?> (Ordre <?= h($selected_step['ordre']) ?>)</h3>
                    
                    <!-- Formulaire d'ajout de destinataire -->
                    <div class="card">
                        <h4>Ajouter un destinataire</h4>
                        <form method="POST">
                            <input type="hidden" name="action" value="add_recipient">
                            <input type="hidden" name="step_id" value="<?= $selected_step['id'] ?>">
                            <div class="field">
                                <label>Email du destinataire</label>
                                <input type="email" name="email" required placeholder="ex: rh@dreets.gouv.fr">
                            </div>
                            <button type="submit" class="btn btn-primary">Ajouter le destinataire</button>
                        </form>
                    </div>
                    
                    <!-- Liste des destinataires -->
                    <?php if (!empty($selected_step['recipients'])): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Email</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($selected_step['recipients'] as $recipient): ?>
                                    <tr>
                                        <td><?= h($recipient['email']) ?></td>
                                        <td class="actions">
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce destinataire ?');">
                                                <input type="hidden" name="action" value="delete_recipient">
                                                <input type="hidden" name="recipient_id" value="<?= $recipient['id'] ?>">
                                                <button type="submit" class="btn delete-btn">Supprimer</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>Aucun destinataire défini pour cette étape.</p>
                    <?php endif; ?>
                <?php endif; ?>
            <?php else: ?>
                <p>Sélectionnez une étape pour gérer ses destinataires.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<script>
    function toggleEdit(id) {
        const element = document.getElementById('edit-step-' + id);
        if (element.style.display === 'none') {
            element.style.display = 'table-row';
        } else {
            element.style.display = 'none';
        }
    }
</script>
</body>
</html>