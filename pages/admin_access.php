<?php
// admin_access.php — Page d'accès au back office avec demande d'accès admin
require_once dirname(__DIR__) . '/helpers.php';
use App\Core\App;

// Traitement des actions POST uniquement (securite : plus d'actions modifiant la DB en GET)
$confirm_data = null; // Pour afficher la page de confirmation si on clique sur un lien email

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérification CSRF
    App::security()->requireCsrf();

    $action = $_POST['action'] ?? '';
    
    if ($action === 'request_access') {
        $email = get_auth_user();
        $result = process_admin_request($email);
        if ($result['success']) {
            if ($result['reason'] === 'already_admin') {
                $success_msg = 'Vous êtes déjà administrateur.';
            } elseif ($result['reason'] === 'dry_run') {
                $warning_msg = 'Votre demande a été enregistrée, mais l\'envoi d\'email est désactivé (mail_dry_run=1). Contactez directement l\'administrateur principal : ' . h(get_admin_email());
            } else {
                $success_msg = 'Votre demande d\'accès admin a été envoyée. Vous recevrez un email lorsque l\'administrateur principal aura pris une décision.';
            }
        } else {
            if ($result['reason'] === 'pending') {
                $error_msg = 'Vous avez déjà une demande d\'accès admin en attente. L\'administrateur principal doit l\'approuver. Contactez-le directement : ' . h(get_admin_email());
            } elseif ($result['reason'] === 'mail_failed') {
                $error_msg = 'Votre demande a été enregistrée mais l\'email n\'a pas pu être envoyé. Contactez directement l\'administrateur : ' . h(get_admin_email());
            } elseif ($result['reason'] === 'exception') {
                $error_msg = 'Erreur lors de la demande : ' . h($result['error'] ?? 'erreur inconnue');
            } else {
                $error_msg = 'Une erreur est survenue. Contactez l\'administrateur : ' . h(get_admin_email());
            }
        }
    }
    elseif ($action === 'approve' && is_super_admin()) {
        $token = $_POST['token'] ?? '';
        $pdo = App::db()->getPdo();
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
        $pdo = App::db()->getPdo();
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
    $pdo = App::db()->getPdo();
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
    $pdo = App::db()->getPdo();
    $stmt = $pdo->prepare("SELECT * FROM admin_requests WHERE status = 'pending' ORDER BY requested_at DESC");
    $stmt->execute();
    $admin_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Récupération des admins
$admins = [];
if (is_super_admin() || is_admin_user()) {
    $pdo = App::db()->getPdo();
    $stmt = $pdo->prepare("SELECT * FROM admins ORDER BY email");
    $stmt->execute();
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<?php
$page_css = '';
ob_start();
?>
    <h1>Accès au back office</h1>
    
    <?= render_messages(['success'=>$success_msg ?? '', 'error'=>$error_msg ?? '', 'warning'=>$warning_msg ?? '']) ?>

    <?php if ($confirm_data): ?>
        <!-- Page de confirmation pour les liens email (securite : GET n'a plus d'effet de bord) -->
        <div class="card" style="border:2px solid <?= $confirm_data['action'] === 'approve' ? '#1a6b3c' : '#c0392b' ?>;">
            <h2 style="color:<?= $confirm_data['action'] === 'approve' ? '#1a6b3c' : '#c0392b' ?>;">
                <?= $confirm_data['action'] === 'approve' ? '<span aria-hidden="true">✅</span> Approuver' : '<span aria-hidden="true">❌</span> Refuser' ?> la demande d'accès
            </h2>
            <p style="margin-bottom:1rem;">
                <strong><?= h($confirm_data['email']) ?></strong> a demandé l'accès admin le <?= h(date('d/m/Y à H:i', strtotime($confirm_data['requested_at']))) ?>.
            </p>
            <p style="margin-bottom:1rem;color:#555;">
                Confirmez-vous cette action ?
            </p>
            <form method="POST" style="display:flex;gap:.5rem;">
                <?= App::security()->csrfField() ?>
                <input type="hidden" name="action" value="<?= h($confirm_data['action']) ?>">
                <input type="hidden" name="token" value="<?= h($confirm_data['token']) ?>">
                <button type="submit" class="btn" style="background:<?= $confirm_data['action'] === 'approve' ? '#1a6b3c' : '#c0392b' ?>;color:#fff;">
                    <?= $confirm_data['action'] === 'approve' ? 'Oui, approuver' : 'Oui, refuser' ?>
                </button>
                <a href="index.php?p=admin_access" class="btn btn-secondary">Annuler</a>
            </form>
        </div>
    <?php elseif (is_admin_user()): ?>
        <div class="card">
            <h2>Accès autorisé</h2>
            <p>Bienvenue dans le back office ! Vous avez les droits d'administration.</p>
            <a href="index.php?p=dashboard" class="btn btn-primary">Accéder au back office</a>
        </div>
    <?php elseif (is_super_admin()): ?>
        <div class="card">
            <h2>Administration principale</h2>
            <p>Vous êtes l'administrateur principal. Vous pouvez gérer les accès admin.</p>
            <a href="index.php?p=dashboard" class="btn btn-primary">Accéder au back office</a>
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
                                <td><?= h(date('d/m/Y à H:i', strtotime((string)$request['requested_at']))) ?></td>
                                <td class="actions">
                                    <form method="POST" style="display:inline;">
                                        <?= App::security()->csrfField() ?>
                                        <input type="hidden" name="action" value="approve_request">
                                        <input type="hidden" name="email" value="<?= h($request['email']) ?>">
                                        <button type="submit" class="action-btn approve-btn">Approuver</button>
                                    </form>
                                    <form method="POST" style="display:inline;">
                                        <?= App::security()->csrfField() ?>
                                        <input type="hidden" name="action" value="reject_request">
                                        <input type="hidden" name="email" value="<?= h($request['email']) ?>">
                                        <button type="submit" class="action-btn reject-btn" onclick="return confirm('Refuser cette demande d\'accès ?');">Refuser</button>
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
                            <td><?= h(date('d/m/Y à H:i', strtotime((string)$admin['added_at']))) ?></td>
                            <td>
                                <?php if ($admin['email'] !== get_admin_email()): ?>
                                    <form method="POST" style="display:inline;">
                                        <?= App::security()->csrfField() ?>
                                        <input type="hidden" name="action" value="remove_admin">
                                        <input type="hidden" name="email" value="<?= h($admin['email']) ?>">
                                        <button type="submit" class="action-btn reject-btn" onclick="return confirm('Supprimer cet administrateur ? Cette action est irréversible.');">Supprimer</button>
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
                <?= App::security()->csrfField() ?>
                <input type="hidden" name="action" value="request_access">
                <button type="submit" class="btn btn-primary">Demander l'accès admin</button>
            </form>
        </div>
        
        <div class="card">
            <h2>Informations</h2>
            <p><strong>Administrateur principal :</strong> <?= h(get_admin_email()) ?></p>
            <p>Cette page affiche l'email de l'administrateur principal. Pour obtenir l'accès au back office, vous devez demander l'autorisation à cet administrateur.</p>
        </div>
    <?php endif; ?>
<?php
$content = ob_get_clean();
if ($content === false) { $content = ''; }
echo render_page('Accès au back office', 'access', $page_css, $content);
