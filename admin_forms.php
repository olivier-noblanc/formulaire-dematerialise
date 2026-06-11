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

// Récupération de l'ID du champ à modifier
$edit_field_id = (int)($_GET['edit_field'] ?? 0);

// Traitement des actions POST
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf()) {
    die('Token CSRF invalide. Veuillez réessayer.');
}

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
        // Vérifier s'il y a des soumissions actives
        $active_count = has_active_submissions($form_id);
        if ($active_count > 0) {
            $error_msg = 'Impossible de supprimer ce formulaire : ' . $active_count . ' soumission(s) en cours y sont rattachée(s). Veuillez attendre que ces demandes soient clôturées ou les annuler avant de supprimer le formulaire.';
        } else {
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
        // Vérifier s'il y a des soumissions actives utilisant cette étape
        $active_count = has_active_step_submissions($step_id);
        if ($active_count > 0) {
            $error_msg = 'Impossible de supprimer cette étape : ' . $active_count . ' soumission(s) en cours y sont rattachée(s). Veuillez attendre que ces demandes soient clôturées ou les annuler avant de supprimer l\'étape.';
        } else {
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
            header('Location: admin_forms.php?form_id=' . $form_id);
            exit;
        } catch (PDOException $e) {
            $error_msg = 'Erreur lors de la suppression du destinataire : ' . $e->getMessage();
        }
    }
} elseif ($action === 'add_field') {
    $form_id = (int)($_POST['form_id'] ?? 0);
    $ff_label = trim($_POST['ff_label'] ?? '');
    $ff_field_name = trim($_POST['ff_field_name'] ?? '');
    $ff_field_type = trim($_POST['ff_field_type'] ?? 'text');
    $ff_options = trim($_POST['ff_options'] ?? '');
    $ff_required = isset($_POST['ff_required']) ? 1 : 0;
    $ff_ordre = (int)($_POST['ff_ordre'] ?? 0);
    $ff_card_group = trim($_POST['ff_card_group'] ?? 'Général');

    if ($form_id > 0 && !empty($ff_label) && !empty($ff_field_name)) {
        $pdo = get_pdo();
        try {
            // Validate options JSON if provided
            $options_json = null;
            if ($ff_field_type === 'select' && !empty($ff_options)) {
                $decoded = json_decode($ff_options, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                    throw new RuntimeException('Les options doivent être un JSON valide, ex : ["Option A","Option B"]');
                }
                $options_json = $ff_options;
            }
            $pdo->prepare("INSERT INTO form_fields (form_id, label, field_type, field_name, options, required, ordre, card_group) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$form_id, $ff_label, $ff_field_type, $ff_field_name, $options_json, $ff_required, $ff_ordre, $ff_card_group]);
            $success_msg = 'Champ ajouté avec succès.';
            header('Location: admin_forms.php?form_id=' . $form_id . '#fields');
            exit;
        } catch (PDOException|RuntimeException $e) {
            $error_msg = 'Erreur lors de l\'ajout du champ : ' . $e->getMessage();
        }
    } else {
        $error_msg = 'Le libellé et le nom technique du champ sont requis.';
    }
} elseif ($action === 'update_field') {
    $field_id = (int)($_POST['field_id'] ?? 0);
    $form_id = (int)($_POST['form_id'] ?? 0);
    $ff_label = trim($_POST['ff_label'] ?? '');
    $ff_field_name = trim($_POST['ff_field_name'] ?? '');
    $ff_field_type = trim($_POST['ff_field_type'] ?? 'text');
    $ff_options = trim($_POST['ff_options'] ?? '');
    $ff_required = isset($_POST['ff_required']) ? 1 : 0;
    $ff_ordre = (int)($_POST['ff_ordre'] ?? 0);
    $ff_card_group = trim($_POST['ff_card_group'] ?? 'Général');

    if ($field_id > 0 && !empty($ff_label) && !empty($ff_field_name)) {
        $pdo = get_pdo();
        try {
            $options_json = null;
            if ($ff_field_type === 'select' && !empty($ff_options)) {
                $decoded = json_decode($ff_options, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                    throw new RuntimeException('Les options doivent être un JSON valide, ex : ["Option A","Option B"]');
                }
                $options_json = $ff_options;
            }
            $pdo->prepare("UPDATE form_fields SET label = ?, field_type = ?, field_name = ?, options = ?, required = ?, ordre = ?, card_group = ? WHERE id = ?")
                ->execute([$ff_label, $ff_field_type, $ff_field_name, $options_json, $ff_required, $ff_ordre, $ff_card_group, $field_id]);
            $success_msg = 'Champ mis à jour avec succès.';
            header('Location: admin_forms.php?form_id=' . $form_id . '#fields');
            exit;
        } catch (PDOException|RuntimeException $e) {
            $error_msg = 'Erreur lors de la mise à jour du champ : ' . $e->getMessage();
        }
    } else {
        $error_msg = 'Le libellé et le nom technique du champ sont requis.';
    }
} elseif ($action === 'delete_field') {
    $field_id = (int)($_POST['field_id'] ?? 0);
    $form_id = (int)($_POST['form_id'] ?? 0);
    if ($field_id > 0) {
        $pdo = get_pdo();
        try {
            $pdo->prepare("DELETE FROM form_fields WHERE id = ?")->execute([$field_id]);
            $success_msg = 'Champ supprimé avec succès.';
            header('Location: admin_forms.php?form_id=' . $form_id . '#fields');
            exit;
        } catch (PDOException $e) {
            $error_msg = 'Erreur lors de la suppression du champ : ' . $e->getMessage();
        }
    }
}

// Récupération des informations du formulaire sélectionné
$form = null;
$steps = [];
$recipients = [];
$form_fields = [];

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

        // Récupération des champs du formulaire
        $stmt = $pdo->prepare("SELECT * FROM form_fields WHERE form_id = ? ORDER BY ordre, id");
        $stmt->execute([$form_id]);
        $form_fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestion des formulaires — DREETS Workflow</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='15' fill='%23003189'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial'>D</text></svg>">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: "Marianne", Arial, sans-serif; background: #f5f5fe; color: #1e1e1e; }
        .bandeau { background: #003189; color: #fff; padding: .75rem 2rem; font-size: .85rem; margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .5rem; }
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
    </style>
</head>
<body>
<div class="bandeau">
    <strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités
    <span>Connecté en tant que : <strong><?= h(get_auth_user()) ?></strong></span>
    <span><a href="docs.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;">📖 Documentation</a> <a href="admin_settings.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;margin-left:8px;">⚙ Paramètres</a></span>
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
                <?= csrf_field() ?>
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
                    <?= csrf_field() ?>
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
                        <?= csrf_field() ?>
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
                    <?= csrf_field() ?>
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
                                        <?= csrf_field() ?>
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
                                            <?= csrf_field() ?>
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
                            <?= csrf_field() ?>
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
                                                <?= csrf_field() ?>
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

        <!-- Section D - Champs du formulaire -->
        <div class="card" id="fields">
            <div class="form-section-header">
                <h2 class="section-title">Champs du formulaire</h2>
            </div>
            <p style="font-size:.85rem;color:#666;margin-bottom:1rem;">Ces champs définissent le formulaire que les agents rempliront. Modifiez-les pour personnaliser la collecte d'informations selon le type de formulaire.</p>

            <?php if (!empty($form_fields)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Ordre</th>
                            <th>Groupe (carte)</th>
                            <th>Libellé</th>
                            <th>Nom technique</th>
                            <th>Type</th>
                            <th>Oblig.</th>
                            <th>Options</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($form_fields as $ff): ?>
                            <?php if ($edit_field_id === (int)$ff['id']): ?>
                            <tr>
                                <td colspan="8" style="background:#f0f4ff;">
                                    <div class="edit-form">
                                        <h4>Modifier le champ</h4>
                                        <form method="POST">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_field">
                                            <input type="hidden" name="field_id" value="<?= $ff['id'] ?>">
                                            <input type="hidden" name="form_id" value="<?= $form_id ?>">
                                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
                                                <div class="field">
                                                    <label>Libellé</label>
                                                    <input type="text" name="ff_label" value="<?= h($ff['label']) ?>" required>
                                                </div>
                                                <div class="field">
                                                    <label>Nom technique (clé POST)</label>
                                                    <input type="text" name="ff_field_name" value="<?= h($ff['field_name']) ?>" required placeholder="ex: nom, date_debut">
                                                </div>
                                                <div class="field">
                                                    <label>Type de champ</label>
                                                    <select name="ff_field_type">
                                                        <?php foreach (['text'=>'Texte','date'=>'Date','select'=>'Sélecteur','checkbox'=>'Case à cocher','textarea'=>'Zone de texte'] as $val => $lbl): ?>
                                                            <option value="<?= $val ?>" <?= $ff['field_type'] === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="field">
                                                    <label>Groupe (carte)</label>
                                                    <input type="text" name="ff_card_group" value="<?= h($ff['card_group']) ?>" placeholder="ex: Identité de l'agent">
                                                </div>
                                                <div class="field">
                                                    <label>Options (JSON, pour sélecteur)</label>
                                                    <input type="text" name="ff_options" value="<?= h($ff['options'] ?? '') ?>" placeholder='["Option A","Option B"]'>
                                                </div>
                                                <div class="field">
                                                    <label>Ordre</label>
                                                    <input type="number" name="ff_ordre" value="<?= $ff['ordre'] ?>" min="0">
                                                </div>
                                            </div>
                                            <div class="field" style="margin-top:.5rem;">
                                                <label><input type="checkbox" name="ff_required" <?= $ff['required'] ? 'checked' : '' ?>> Champ obligatoire</label>
                                            </div>
                                            <button type="submit" class="btn btn-primary" style="margin-top:.5rem;">Enregistrer</button>
                                            <a href="?form_id=<?= $form_id ?>#fields" class="btn btn-secondary" style="margin-top:.5rem;">Annuler</a>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                            <tr>
                                <td><?= h($ff['ordre']) ?></td>
                                <td><?= h($ff['card_group']) ?></td>
                                <td><?= h($ff['label']) ?></td>
                                <td><code><?= h($ff['field_name']) ?></code></td>
                                <td><?= h($ff['field_type']) ?></td>
                                <td><?= $ff['required'] ? '<strong style="color:#c0392b;">Oui</strong>' : 'Non' ?></td>
                                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($ff['options'] ?? '') ?>"><?= h($ff['options'] ?? '—') ?></td>
                                <td class="actions">
                                    <a href="?form_id=<?= $form_id ?>&edit_field=<?= $ff['id'] ?>#fields" class="btn action-btn btn-secondary">Modifier</a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Supprimer ce champ ?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_field">
                                        <input type="hidden" name="field_id" value="<?= $ff['id'] ?>">
                                        <input type="hidden" name="form_id" value="<?= $form_id ?>">
                                        <button type="submit" class="btn delete-btn">Supprimer</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Aucun champ défini pour ce formulaire. Ajoutez-en ci-dessous.</p>
            <?php endif; ?>

            <!-- Formulaire d'ajout de champ -->
            <div class="card" style="margin-top:1.5rem;">
                <h3>Ajouter un champ</h3>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_field">
                    <input type="hidden" name="form_id" value="<?= $form_id ?>">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
                        <div class="field">
                            <label>Libellé</label>
                            <input type="text" name="ff_label" required placeholder="ex: Nom, Date de début">
                        </div>
                        <div class="field">
                            <label>Nom technique (clé POST)</label>
                            <input type="text" name="ff_field_name" required placeholder="ex: nom, date_debut">
                        </div>
                        <div class="field">
                            <label>Type de champ</label>
                            <select name="ff_field_type">
                                <?php foreach (['text'=>'Texte','date'=>'Date','select'=>'Sélecteur','checkbox'=>'Case à cocher','textarea'=>'Zone de texte'] as $val => $lbl): ?>
                                    <option value="<?= $val ?>"><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Groupe (carte)</label>
                            <input type="text" name="ff_card_group" value="Général" placeholder="ex: Identité de l'agent">
                        </div>
                        <div class="field">
                            <label>Options (JSON, pour sélecteur)</label>
                            <input type="text" name="ff_options" placeholder='["Option A","Option B"]'>
                        </div>
                        <div class="field">
                            <label>Ordre</label>
                            <input type="number" name="ff_ordre" min="0" value="<?= count($form_fields ?? []) + 1 ?>">
                        </div>
                    </div>
                    <div class="field" style="margin-top:.5rem;">
                        <label><input type="checkbox" name="ff_required"> Champ obligatoire</label>
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin-top:.5rem;">Ajouter le champ</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>