<?php
declare(strict_types=1);

/**
 * Authentication & admin user management.
 *
 * Fonctions d'authentification (get_auth_user, is_admin_user, require_admin)
 * et de gestion des administrateurs (process_admin_request, approve/reject/remove).
 *
 * @package lib
 */

// ── UTILITAIRES (auth) ──────────────────────────────────────
function get_auth_user(): string {
    return \App\Core\App::auth()->getUser();
}

// Vérifie si l'utilisateur est administrateur
function is_admin_user(): bool {
    return \App\Core\App::auth()->isAdmin();
}

/**
 * v9.9.0 — "Effective admin" = admin réel ET pas de persona actif.
 *
 * Utiliser cette fonction pour l'AFFICHAGE (sidebar, pages admin, etc.).
 * Utiliser is_admin_user() pour la SÉCURITÉ (require_admin, accès directs).
 */
function is_admin_effective(): bool {
    return \App\Core\App::auth()->isAdminEffective();
}

// Vérifie si l'utilisateur est l'admin principal
function is_super_admin(): bool {
    return \App\Core\App::auth()->isSuperAdmin();
}

// Vérifie que l'utilisateur est admin ou super-admin, sinon redirige
function require_admin(): void {
    \App\Core\App::auth()->requireAdmin();
}

// Récupère l'email de l'admin principal (depuis settings DB, fallback SETTINGS_DEFAULTS)
function get_admin_email(): string {
    return \App\Core\App::auth()->getAdminEmail();
}

// Vérifie si l'utilisateur est propriétaire d'un formulaire donné
function is_form_owner(string $form_id, ?string $email = null): bool {
    if ($email === null) {
        $email = get_auth_user();
    }
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT 1 FROM form_owners WHERE form_id = ? AND email = ?");
    $stmt->execute([$form_id, $email]);
    return $stmt->fetch() !== false;
}

// Récupère la liste des propriétaires d'un formulaire
/** @return array<string, mixed> */
function get_form_owners(string $form_id): array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT id, email, added_at FROM form_owners WHERE form_id = ? ORDER BY email");
    $stmt->execute([$form_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Récupère les formulaires dont l'utilisateur est propriétaire
/** @return array<int, array<string, mixed>> */
function get_owned_forms(?string $email = null): array {
    if ($email === null) {
        $email = get_auth_user();
    }
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT f.id, f.slug, f.label, f.description FROM forms f INNER JOIN form_owners fo ON fo.form_id = f.id WHERE fo.email = ? AND f.actif = 1 ORDER BY f.label");
    $stmt->execute([$email]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── ADMIN FUNCTIONS ───────────────────────────────────────────

/**
 * Traite une demande d'accès admin.
 *
 * @param string $email Email de l'utilisateur qui demande l'accès
 * @return array{success: bool, reason: string} Résultat détaillé :
 *   - 'already_admin' : l'utilisateur est déjà admin
 *   - 'pending'       : une demande est déjà en attente
 *   - 'sent'          : demande créée + mail envoyé
 *   - 'dry_run'       : demande créée mais mail intercepté (mail_dry_run=1)
 *   - 'mail_failed'   : demande créée mais mail non envoyé
 *   - 'exception'     : erreur inattendue
 */
function process_admin_request(string $email): array {
    try {
        $pdo = get_pdo();

        // Vérifie si l'utilisateur est déjà admin
        if (is_admin_user()) {
            return ['success' => true, 'reason' => 'already_admin'];
        }

        // Vérifie si une demande est déjà en attente
        $stmt = $pdo->prepare("SELECT 1 FROM admin_requests WHERE email = ? AND status = 'pending'");
        $stmt->execute([$email]);
        if ($stmt->fetch() !== false) {
            return ['success' => false, 'reason' => 'pending'];
        }

        // Génère un token pour la demande
        $token = bin2hex(random_bytes(32));

        // Insère la demande dans la base de données
        $ar_id = generate_uuid();
        $stmt = $pdo->prepare("INSERT INTO admin_requests (id, email, requested_at, status, token) VALUES (?, ?, ?, 'pending', ?)");
        $stmt->execute([$ar_id, $email, gmdate('Y-m-d H:i:s'), $token]);

        app_log('admin_request', 'admin:' . $email, 'Demande d\'accès admin', $email);

        // Envoie un email à l'admin principal pour approbation
        $approve_url = resolve_base_url() . '/index.php?p=admin_access&action=approve&token=' . $token;
        $reject_url = resolve_base_url() . '/index.php?p=admin_access&action=reject&token=' . $token;
        $subject = 'Demande d\'accès admin - ' . get_app_name();
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body>
    <h2>Demande d\'accès admin</h2>
    <p>Un utilisateur a demandé l\'accès admin au back office du workflow :</p>
    <p><strong>Utilisateur :</strong> ' . h($email) . '</p>
    <p><strong>Date :</strong> ' . gmdate('d/m/Y H:i:s') . ' UTC</p>
    <p><a href="' . $approve_url . '" style="background:#1a6b3c;color:#fff;padding:10px 15px;text-decoration:none;border-radius:4px;display:inline-block;margin-right:10px;">Approuver</a>
    <a href="' . $reject_url . '" style="background:#c0392b;color:#fff;padding:10px 15px;text-decoration:none;border-radius:4px;display:inline-block;">Refuser</a></p>
</body>
</html>';

        // Envoyer à l'admin + CC si configuré
        $cc_email = get_setting('admin_email_cc', '');
        $mail_sent = send_mail(get_admin_email(), $subject, $body);
        if ($cc_email !== '' && $cc_email !== get_admin_email()) {
            send_mail($cc_email, '[CC] ' . $subject, $body);
        }

        // Vérifier si les mails étaient en dry-run (non envoyés réellement)
        $dry_run = get_setting('mail_dry_run', '0') === '1';
        if ($dry_run) {
            return ['success' => true, 'reason' => 'dry_run'];
        }
        if (!$mail_sent) {
            return ['success' => false, 'reason' => 'mail_failed'];
        }

        return ['success' => true, 'reason' => 'sent'];
    } catch (Exception $e) {
        error_log('Erreur lors de la demande d\'accès admin : ' . $e->getMessage());
        return ['success' => false, 'reason' => 'exception', 'error' => $e->getMessage()];
    }
}

/**
 * Approve an admin request
 */
function approve_admin_request(string $email): bool {
    $pdo = get_pdo();
    
    try {
        // Met à jour la demande
        $stmt = $pdo->prepare("UPDATE admin_requests SET status = 'approved' WHERE email = ?");
        $stmt->execute([$email]);
        
        // Ajoute l'utilisateur comme administrateur
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO admins (id, email, added_at) VALUES (?, ?, ?)");
        $stmt->execute([generate_uuid(), $email, gmdate('Y-m-d H:i:s')]);
        
        // Envoie un email de confirmation
        $subject = 'Accès admin approuvé - ' . get_app_name();
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body>
    <h2>Accès admin approuvé</h2>
    <p>Votre demande d\'accès admin au back office du workflow a été approuvée.</p>
    <p>Vous pouvez maintenant accéder au back office en cliquant sur le lien ci-dessous :</p>
    <p><a href="' . resolve_base_url() . '/index.php?p=admin_access">Accéder au back office</a></p>
</body>
</html>';
        
        send_mail($email, $subject, $body);
        app_log('admin_approve', 'admin:' . $email, 'Accès admin approuvé');
        return true;
    } catch (Exception $e) {
        error_log('Erreur lors de l\'approbation de la demande admin : ' . $e->getMessage());
        return false;
    }
}

/**
 * Reject an admin request
 */
function reject_admin_request(string $email): bool {
    $pdo = get_pdo();
    
    try {
        // Met à jour la demande
        $stmt = $pdo->prepare("UPDATE admin_requests SET status = 'rejected' WHERE email = ?");
        $stmt->execute([$email]);
        
        // Envoie un email de refus
        $subject = 'Demande d\'accès admin refusée - ' . get_app_name();
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body>
    <h2>Demande d\'accès admin refusée</h2>
    <p>Votre demande d\'accès admin au back office du workflow a été refusée.</p>
</body>
</html>';
        
        send_mail($email, $subject, $body);
        app_log('admin_reject', 'admin:' . $email, 'Accès admin refusé');
        return true;
    } catch (Exception $e) {
        error_log('Erreur lors du refus de la demande admin : ' . $e->getMessage());
        return false;
    }
}

/**
 * Remove an admin
 */
function remove_admin(string $email): bool {
    $pdo = get_pdo();
    
    // Ne peut pas supprimer l'admin principal
    if ($email === get_admin_email()) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("DELETE FROM admins WHERE email = ?");
        $stmt->execute([$email]);
        app_log('admin_remove', 'admin:' . $email, 'Admin supprimé', $email);
        return true;
    } catch (Exception $e) {
        error_log('Erreur lors de la suppression d\'un admin : ' . $e->getMessage());
        return false;
    }
}
