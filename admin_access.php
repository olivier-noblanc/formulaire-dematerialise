<?php
// admin_access.php — Page d'accès au back office avec demande d'accès admin
require_once __DIR__ . '/helpers.php';

// Traitement des actions POST uniquement (securite : plus d'actions modifiant la DB en GET)
$confirm_data = null; // Pour afficher la page de confirmation si on clique sur un lien email

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérification CSRF
    if (!verify_csrf()) {
        die('Token CSRF invalide. Veuillez réessayer.');
    }

    $action = $_POST['action'] ?? '';
    
    if ($action === 'request_access') {
        $email = get_auth_user();
        if (process_admin_request($email)) {
            $success_msg = 'Votre demande d\'accès admin a été envoyée. Vous recevrez un email lorsque l\'administrateur principal aura pris une décision.';
        } else {
            $error_msg = 'Une erreur est survenue lors de votre demande. Vous avez peut-être déjà une demande en attente.';
        }
    }
    elseif ($action === 'approve' && is_super_admin()) {
        $token = $_POST['token'] ?? '';
        $pdo = get_pdo();
        $stmt = $pdo->prepare("SELECT email FROM admin_requests WHERE token = ? AND status = 'pending'");
        $stmt->execute([$token]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($request) {
            if (approve_admin_request($request['email'])) {
                $success_msg = 'Demande d\'accès approuvée pour ' . h($request['email']) . '.';
            } else {
                $error_msg = 'Erreur lors de l\'approbation de la demande.';
            }
        } else {
            $error_msg = 'Demande invalide ou déjà traitée.';
        }
    }
    elseif ($action === 'reject' && is_super_admin()) {
        $token = $_POST['token'] ?? '';
        $pdo = get_pdo();
        $stmt = $pdo->prepare("SELECT email FROM admin_requests WHERE token = ? AND status = 'pending'");
        $stmt->execute([$token]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($request) {
            if (reject_admin_request($request['email'])) {
                $success_msg = 'Demande d\'accès refusée pour ' . h($request['email']) . '.';
            } else {
                $error_msg = 'Erreur lors du refus de la demande.';
            }
        } else {
            $error_msg = 'Demande invalide ou déjà traitée.';
        }
    }
    elseif ($action === 'approve_request' && is_super_admin()) {
        $email = $_POST['email'] ?? '';
        if (approve_admin_request($email)) {
            $success_msg = 'Demande d\'accès approuvée.';
        } else {
            $error_msg = 'Erreur lors de l\'approbation de la demande.';
        }
    }
    elseif ($action === 'reject_request' && is_super_admin()) {
        $email = $_POST['email'] ?? '';
        if (reject_admin_request($email)) {
            $success_msg = 'Demande d\'accès refusée.';
        } else {
            $error_msg = 'Erreur lors du refus de la demande.';
        }
    }
    elseif ($action === 'remove_admin' && is_super_admin()) {
        $email = $_POST['email'] ?? '';
        if (remove_admin($email)) {
            $success_msg = 'Administrateur supprimé.';
        } else {
            $error_msg = 'Erreur lors de la suppression de l\'administrateur.';
        }
    }
}

// Lien email GET : afficher une page de confirmation (pas d'effet de bord au GET)
$get_action = $_GET['action'] ?? '';
$get_token = $_GET['token'] ?? '';
if (($get_action === 'approve' || $get_action === 'reject') && !empty($get_token) && is_super_admin()) {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT email, requested_at FROM admin_requests WHERE token = ? AND status = 'pending'");
    $stmt->execute([$get_token]);
    $confirm_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($confirm_data) {
        $confirm_data['action'] = $get_action;
        $confirm_data['token'] = $get_token;
    }
}

// Récupération des demandes d'accès pour l'admin principal
$admin_requests = [];
if (is_super_admin()) {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT * FROM admin_requests WHERE status = 'pending' ORDER BY requested_at DESC");
    $stmt->execute();
    $admin_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Récupération des admins
$admins = [];
if (is_super_admin() || is_admin_user()) {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT * FROM admins ORDER BY email");
    $stmt->execute();
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Accès au back office — DREETS Workflow</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='15' fill='%23003189'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial'>D</text></svg>">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: "Marianne", Arial, sans-serif; background: #f5f5fe; color: #1e1e1e; }
        .bandeau { background: #003189; color: #fff; padding: .75rem 2rem; font-size: .85rem; margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .5rem; }
        .container { max-width: 800px; margin: 0 auto; padding: 0 1rem 2rem; }
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
        input[type="text"], input[type="email"], textarea {
            padding: .6rem; border: 1px solid #aaa; border-radius: 3px;
            font-size: .9rem; font-family: inherit; background: #fff; color: #1e1e1e;
        }
        input:focus, textarea:focus { outline: 2px solid #003189; border-color: #003189; }
        table { width: 100%; border-collapse: collapse; background: #fff; margin-top: 1rem; }
        th, td { padding: .75rem; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #003189; color: #fff; font-weight: normal; }
        tr:nth-child(even) { background: #f7f7fb; }
        .status-pending { color: #b45309; }
        .status-approved { color: #1a6b3c; }
        .status-rejected { color: #c0392b; }
        .actions { display: flex; gap: .5rem; }
        .action-btn { padding: .3rem .6rem; border: none; border-radius: 3px; font-size: .8rem; cursor: pointer; }
        .approve-btn { background: #1a6b3c; color: #fff; }
        .reject-btn { background: #c0392b; color: #fff; }
    </style>
</head>
<body>
<div class="bandeau">
    <strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités
    <span>Connecté en tant que : <strong><?= h(get_auth_user()) ?></strong></span>
    <span><a href="docs.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;">📖 Documentation</a> <a href="admin_settings.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;margin-left:8px;">⚙ Paramètres</a></span>
</div>
<div class="container">
    <h1>Accès au back office</h1>
    
    <?php if (isset($success_msg)): ?>
        <div class="msg-success"><?= h($success_msg) ?></div>
    <?php endif; ?>
    
    <?php if (isset($error_msg)): ?>
        <div class="msg-error"><?= h($error_msg) ?></div>
    <?php endif; ?>

    <?php if ($confirm_data): ?>
        <!-- Page de confirmation pour les liens email (securite : GET n'a plus d'effet de bord) -->
        <div class="card" style="border:2px solid <?= $confirm_data['action'] === 'approve' ? '#1a6b3c' : '#c0392b' ?>;">
            <h2 style="color:<?= $confirm_data['action'] === 'approve' ? '#1a6b3c' : '#c0392b' ?>;">
                <?= $confirm_data['action'] === 'approve' ? '✅ Approuver' : '❌ Refuser' ?> la demande d'accès
            </h2>
            <p style="margin-bottom:1rem;">
                <strong><?= h($confirm_data['email']) ?></strong> a demandé l'accès admin le <?= h(date('d/m/Y à H:i', strtotime($confirm_data['requested_at']))) ?>.
            </p>
            <p style="margin-bottom:1rem;color:#555;">
                Confirmez-vous cette action ?
            </p>
            <form method="POST" style="display:flex;gap:.5rem;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= h($confirm_data['action']) ?>">
                <input type="hidden" name="token" value="<?= h($confirm_data['token']) ?>">
                <button type="submit" class="btn" style="background:<?= $confirm_data['action'] === 'approve' ? '#1a6b3c' : '#c0392b' ?>;color:#fff;">
                    <?= $confirm_data['action'] === 'approve' ? 'Oui, approuver' : 'Oui, refuser' ?>
                </button>
                <a href="admin_access.php" class="btn btn-secondary">Annuler</a>
            </form>
        </div>
    <?php elseif (is_admin_user()): ?>
        <div class="card">
            <h2>Accès autorisé</h2>
            <p>Bienvenue dans le back office ! Vous avez les droits d'administration.</p>
            <a href="dashboard.php" class="btn btn-primary">Accéder au back office</a>
        </div>
    <?php elseif (is_super_admin()): ?>
        <div class="card">
            <h2>Administration principale</h2>
            <p>Vous êtes l'administrateur principal. Vous pouvez gérer les accès admin.</p>
            <a href="dashboard.php" class="btn btn-primary">Accéder au back office</a>
        </div>
        
        <?php if (!empty($admin_requests)): ?>
            <div class="card">
                <h2>Demandes d'accès en attente</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($admin_requests as $request): ?>
                            <tr>
                                <td><?= h($request['email']) ?></td>
                                <td><?= h($request['requested_at']) ?></td>
                                <td class="actions">
                                    <form method="POST" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="approve_request">
                                        <input type="hidden" name="email" value="<?= h($request['email']) ?>">
                                        <button type="submit" class="action-btn approve-btn">Approuver</button>
                                    </form>
                                    <form method="POST" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reject_request">
                                        <input type="hidden" name="email" value="<?= h($request['email']) ?>">
                                        <button type="submit" class="action-btn reject-btn">Refuser</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <h2>Liste des administrateurs</h2>
            <table>
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Date d'ajout</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admins as $admin): ?>
                        <tr>
                            <td><?= h($admin['email']) ?></td>
                            <td><?= h($admin['added_at']) ?></td>
                            <td>
                                <?php if ($admin['email'] !== ADMIN_EMAIL): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cet administrateur ?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="remove_admin">
                                        <input type="hidden" name="email" value="<?= h($admin['email']) ?>">
                                        <button type="submit" class="action-btn reject-btn">Supprimer</button>
                                    </form>
                                <?php else: ?>
                                    <em>Administrateur principal</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="card">
            <h2>Demande d'accès admin</h2>
            <p>Vous souhaitez accéder au back office ? Veuillez demander l'accès administrateur ci-dessous.</p>
            <p>Une fois votre demande approuvée par l'administrateur principal, vous pourrez accéder au back office.</p>
            
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="request_access">
                <button type="submit" class="btn btn-primary">Demander l'accès admin</button>
            </form>
        </div>
        
        <div class="card">
            <h2>Informations</h2>
            <p><strong>Administrateur principal :</strong> <?= h(ADMIN_EMAIL) ?></p>
            <p>Cette page affiche l'email de l'administrateur principal. Pour obtenir l'accès au back office, vous devez demander l'autorisation à cet administrateur.</p>
        </div>
    <?php endif; ?>
</div>
<?= render_footer() ?>
</body>
</html>