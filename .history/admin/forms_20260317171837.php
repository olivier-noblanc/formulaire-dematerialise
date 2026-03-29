<?php
require_once __DIR__ . '/../helpers.php';

// Vérifier les permissions
if (!is_admin_user() && !is_super_admin()) {
    header('Location: ../admin_access.php');
    exit;
}

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_form' && is_super_admin()) {
        $slug = $_POST['slug'] ?? '';
        $label = $_POST['label'] ?? '';
        $description = $_POST['description'] ?? '';
        
        if (!empty($slug) && !empty($label)) {
            $pdo = get_pdo();
            $stmt = $pdo->prepare("INSERT INTO forms (slug, label, description, actif, created_at) VALUES (?, ?, ?, 1, datetime('now'))");
            if ($stmt->execute([$slug, $label, $description])) {
                $success_msg = 'Formulaire créé avec succès.';
            } else {
                $error_msg = 'Erreur lors de la création du formulaire.';
            }
        } else {
            $error_msg = 'Veuillez remplir tous les champs requis.';
        }
    }
    elseif ($action === 'update_form' && is_super_admin()) {
        $id = $_POST['id'] ?? 0;
        $slug = $_POST['slug'] ?? '';
        $label = $_POST['label'] ?? '';
        $description = $_POST['description'] ?? '';
        $actif = isset($_POST['actif']) ? 1 : 0;
        
        if ($id > 0 && !empty($slug) && !empty($label)) {
            $pdo = get_pdo();
            $stmt = $pdo->prepare("UPDATE forms SET slug = ?, label = ?, description = ?, actif = ? WHERE id = ?");
            if ($stmt->execute([$slug, $label, $description, $actif, $id])) {
                $success_msg = 'Formulaire mis à jour avec succès.';
            } else {
                $error_msg = 'Erreur lors de la mise à jour du formulaire.';
            }
        } else {
            $error_msg = 'Données invalides.';
        }
    }
    elseif ($action === 'delete_form' && is_super_admin()) {
        $id = $_POST['id'] ?? 0;
        if ($id > 0) {
            $pdo = get_pdo();
            // Supprimer d'abord les étapes associées
            $stmt = $pdo->prepare("DELETE FROM steps WHERE form_id = ?");
            $stmt->execute([$id]);
            
            // Puis supprimer le formulaire
            $stmt = $pdo->prepare("DELETE FROM forms WHERE id = ?");
            if ($stmt->execute([$id])) {
                $success_msg = 'Formulaire supprimé avec succès.';
            } else {
                $error_msg = 'Erreur lors de la suppression du formulaire.';
            }
        } else {
            $error_msg = 'ID invalide.';
        }
    }
}

// Récupérer tous les formulaires
$pdo = get_pdo();
$forms = $pdo->query("SELECT * FROM forms ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Récupérer le formulaire à éditer s'il y en a un
$edit_form = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM forms WHERE id = ?");
    $stmt->execute([$id]);
    $edit_form = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Paramétrage des formulaires — DREETS Workflow</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: "Marianne", Arial, sans-serif; background: #f5f5fe; color: #1e1e1e; }
        .bandeau { background: #003189; color: #fff; padding: .75rem 2rem; font-size: .85rem; margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center; }
        .container { max-width: 1000px; margin: 0 auto; padding: 0 1rem 2rem; }
        h1 { font-size: 1.4rem; color: #003189; margin-bottom: 1.25rem; }
        .card { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 1.5rem; margin-bottom: 1.5rem; }
        .btn { padding: .6rem 1.2rem; border: none; border-radius: 3px; font-size: .9rem; font-family: inherit; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #003189; color: #fff; }
        .btn-primary:hover { background: #002270; }
        .btn-secondary { background: #f0f0f0; color: #333; }
        .btn-secondary:hover { background: #e0e0e0; }
        .msg-success { background: #e8f5e9; border: 1px solid #27ae60; border-radius: 3px; padding: .75rem 1rem; margin-bottom: 1rem; color: #1a6b3c; }
        .msg-error { background: #ffebee; border: 1px solid #c0392b; border-radius: 3px; padding: .75rem 1rem; margin-bottom: 1rem; color: #c0392b; }
        .field { display: flex; flex-direction: column; gap: .3rem; margin-bottom: 1rem; }
        label { font-size: .82rem; font-weight: bold; color: #444; }
        input[type="text"], input[type="email"], textarea, select {
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
        .edit-btn { background: #3498db; color: #fff; }
        .delete-btn { background: #c0392b; color: #fff; }
        .back-link { margin-bottom: 1rem; }
    </style>
</head>
<body>
<div class="bandeau">
    <strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités
    <span>Connecté en tant que : <strong><?= h(get_auth_user()) ?></strong></span>
</div>
<div class="container">
    <h1>Paramétrage des formulaires</h1>
    
    <div class="back-link">
        <a href="admin_access.php" class="btn btn-secondary">← Retour à la gestion des accès</a>
    </div>
    
    <?php if (isset($success_msg)): ?>
        <div class="msg-success"><?= h($success_msg) ?></div>
    <?php endif; ?>
    
    <?php if (isset($error_msg)): ?>
        <div class="msg-error"><?= h($error_msg) ?></div>
    <?php endif; ?>
    
    <div class="card">
        <h2><?= $edit_form ? 'Modifier un formulaire' : 'Créer un nouveau formulaire' ?></h2>
        
        <form method="POST">
            <input type="hidden" name="action" value="<?= $edit_form ? 'update_form' : 'create_form' ?>">
            <?php if ($edit_form): ?>
                <input type="hidden" name="id" value="<?= h($edit_form['id']) ?>">
            <?php endif; ?>
            
            <div class="field">
                <label for="slug">Slug *</label>
                <input type="text" id="slug" name="slug" value="<?= h($edit_form['slug'] ?? '') ?>" required>
            </div>
            
            <div class="field">
                <label for="label">Libellé *</label>
                <input type="text" id="label" name="label" value="<?= h($edit_form['label'] ?? '') ?>" required>
            </div>
            
            <div class="field">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="3"><?= h($edit_form['description'] ?? '') ?></textarea>
            </div>
            
            <?php if ($edit_form): ?>
                <div class="field">
                    <label>
                        <input type="checkbox" name="actif" <?= $edit_form['actif'] ? 'checked' : '' ?>> Actif
                    </label>
                </div>
            <?php endif; ?>
            
            <button type="submit" class="btn btn-primary">
                <?= $edit_form ? 'Mettre à jour' : 'Créer' ?>
            </button>
        </form>
    </div>
    
    <div class="card">
        <h2>Liste des formulaires</h2>
        
        <?php if (empty($forms)): ?>
            <p>Aucun formulaire disponible.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Slug</th>
                        <th>Libellé</th>
                        <th>Description</th>
                        <th>Actif</th>
                        <th>Date de création</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($forms as $form): ?>
                        <tr>
                            <td><?= h($form['slug']) ?></td>
                            <td><?= h($form['label']) ?></td>
                            <td><?= h($form['description']) ?></td>
                            <td><?= $form['actif'] ? 'Oui' : 'Non' ?></td>
                            <td><?= h($form['created_at']) ?></td>
                            <td class="actions">
                                <a href="?edit=<?= h($form['id']) ?>" class="action-btn edit-btn">Modifier</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce formulaire ?');">
                                    <input type="hidden" name="action" value="delete_form">
                                    <input type="hidden" name="id" value="<?= h($form['id']) ?>">
                                    <button type="submit" class="action-btn delete-btn">Supprimer</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>