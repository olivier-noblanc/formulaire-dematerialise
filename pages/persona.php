<?php
declare(strict_types=1);

/**
 * pages/persona.php — Route dédiée pour activer/désactiver un persona.
 *
 * v10.0.0 — Refonte token-based :
 *   - ?action=start&email=XXX → crée un token + redirect vers accueil avec ?persona_token=
 *   - ?action=stop             → révoque le token courant + redirect sans persona_token
 *
 * Sécurité : réservé aux admins (require_admin). Le persona ne fait que
 * downgrader (admin → user), jamais upgrade.
 */

require_once dirname(__DIR__) . '/helpers.php';

// Sécurité : réservé aux admins réels (pas effectif — un admin en persona
// doit pouvoir stopper son persona)
require_admin();

$action = $_GET['action'] ?? '';
$current_token = $_GET['persona_token'] ?? '';

// URL de redirection par défaut (sans persona_token)
$redirect_url = 'index.php';

if ($action === 'start') {
    $target_email = trim($_GET['email'] ?? '');
    if ($target_email === '') {
        http_response_code(400);
        render_error_page(400, 'Email manquant',
            'Le paramètre email est requis pour action=start.',
            '');
    }

    // Vérifier que l'email existe dans la DB (a déjà soumis une demande)
    try {
        $pdo = get_pdo();
        $check = $pdo->prepare("SELECT 1 FROM submissions WHERE submitted_by = ? LIMIT 1");
        $check->execute([$target_email]);
        if (!$check->fetchColumn()) {
            render_error_page(404, 'Utilisateur inconnu',
                'Aucune soumission trouvée pour ' . h($target_email) . '.',
                'Le persona ne peut être activé que pour un utilisateur existant.');
        }
    } catch (\Throwable $e) {
        render_error_page(500, 'Erreur DB', h($e->getMessage()), '');
    }

    // Créer le token — l'admin email = get_auth_user() (qui retourne l'user
    // réel puisqu'on n'a pas encore de persona_token actif ici)
    $admin_email = get_auth_user();
    $token = persona_create_token($admin_email, $target_email);
    if ($token === '') {
        render_error_page(500, 'Erreur création token',
            'Impossible de créer le token persona.', '');
    }

    // Rediriger vers l'accueil avec le token
    $redirect_url = 'index.php?persona_token=' . urlencode($token);
} elseif ($action === 'stop') {
    // Révoquer le token courant
    if ($current_token !== '') {
        persona_revoke($current_token);
    }
    // Rediriger sans persona_token
    $redirect_url = 'index.php';
} else {
    http_response_code(400);
    render_error_page(400, 'Action invalide',
        'Action non reconnue. Utilisez ?action=start&email=XXX ou ?action=stop.',
        '');
}

header('Location: ' . $redirect_url);
exit;
