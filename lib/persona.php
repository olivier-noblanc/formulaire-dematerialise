<?php
declare(strict_types=1);

/**
 * lib/persona.php — Gestion des tokens persona (refonte v10.0.0).
 *
 * Architecture :
 *   - Un admin génère un token aléatoire lié à (admin_email, target_email)
 *   - Le token est stocké en DB (table persona_tokens)
 *   - Le token est propagé dans toutes les URLs via ?persona_token=XXX
 *   - AuthService::getUser() lit le token et retourne le target_email
 *   - Le token expire après 8h (configurable)
 *   - Le token est révocable individuellement
 *
 * Sécurité :
 *   - Downgrade uniquement (admin → user simple), jamais upgrade
 *   - Même si le token fuite, l'attaquant ne fait que visualiser en user
 *   - Un token ne peut être créé que par un admin (vérifié par l'appelant)
 *
 * @package lib
 */

/**
 * Durée de vie par défaut d'un token persona (en secondes).
 * 8h = 28800s — assez pour une journée de travail.
 */
const PERSONA_TOKEN_TTL = 28800;

/**
 * Crée un token persona pour visualiser en tant que target_email.
 *
 * @param string $admin_email  Email de l'admin qui crée le token (user réel)
 * @param string $target_email Email du user à impersonner
 * @return string Le token généré (32 hex chars), ou '' si échec
 */
function persona_create_token(string $admin_email, string $target_email): string {
    if ($admin_email === '' || $target_email === '') return '';

    try {
        $pdo = get_pdo();
        $token = bin2hex(random_bytes(16));  // 32 hex chars
        $id = generate_uuid();
        $expires_at = gmdate('Y-m-d H:i:s', time() + PERSONA_TOKEN_TTL);

        $stmt = $pdo->prepare("
            INSERT INTO persona_tokens (id, token, admin_email, target_email, expires_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$id, $token, $admin_email, $target_email, $expires_at]);

        app_log('persona_create', 'admin:' . $admin_email, "Persona créé pour $target_email (expire $expires_at)");
        return $token;
    } catch (\Throwable $e) {
        error_log('persona_create_token error: ' . $e->getMessage());
        return '';
    }
}

/**
 * Lookup un token persona → retourne le target_email si valide, '' sinon.
 *
 * Vérifications :
 *   - Token existe en DB
 *   - Token pas expiré (expires_at > now)
 *   - Token pas révoqué (revoked_at IS NULL)
 *   - Token créé par un admin (admin_email est dans la table admins)
 *
 * @param string $token Le token à vérifier
 * @return string target_email si valide, '' sinon
 */
function persona_lookup(string $token): string {
    if ($token === '') return '';

    try {
        $pdo = get_pdo();
        $stmt = $pdo->prepare("
            SELECT pt.target_email, pt.expires_at, pt.revoked_at, pt.admin_email
            FROM persona_tokens pt
            WHERE pt.token = ?
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return '';

        // Vérifier révocabilité
        if (!empty($row['revoked_at'])) return '';

        // Vérifier expiration
        $now = gmdate('Y-m-d H:i:s');
        if ($row['expires_at'] <= $now) return '';

        // Vérifier que l'admin est encore admin (sécurité : si l'admin a été
        // rétrogradé entre-temps, le token devient inactif)
        $admin_check = $pdo->prepare("SELECT 1 FROM admins WHERE email = ?");
        $admin_check->execute([$row['admin_email']]);
        if (!$admin_check->fetchColumn()) return '';

        return (string)$row['target_email'];
    } catch (\Throwable $e) {
        error_log('persona_lookup error: ' . $e->getMessage());
        return '';
    }
}

/**
 * Révoque un token persona (mark revoked_at = now).
 *
 * @param string $token Le token à révoquer
 * @return bool True si révoqué, false si non trouvé
 */
function persona_revoke(string $token): bool {
    if ($token === '') return false;
    try {
        $pdo = get_pdo();
        $stmt = $pdo->prepare("
            UPDATE persona_tokens
            SET revoked_at = datetime('now')
            WHERE token = ? AND revoked_at IS NULL
        ");
        $stmt->execute([$token]);
        $revoked = $stmt->rowCount() > 0;
        if ($revoked) {
            app_log('persona_revoke', 'token:' . substr($token, 0, 8) . '…', "Persona révoqué");
        }
        return $revoked;
    } catch (\Throwable $e) {
        error_log('persona_revoke error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Nettoie les tokens expirés ou révoqués depuis > 30 jours.
 * À appeler périodiquement (par exemple dans alert_check.php cron).
 *
 * @return int Nombre de tokens supprimés
 */
function persona_cleanup(): int {
    try {
        $pdo = get_pdo();
        // Supprimer les tokens révoqués depuis > 30 jours
        $cutoff = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $stmt = $pdo->prepare("
            DELETE FROM persona_tokens
            WHERE revoked_at IS NOT NULL AND revoked_at < ?
        ");
        $stmt->execute([$cutoff]);
        $deleted = $stmt->rowCount();

        // Supprimer aussi les tokens expirés non révoqués depuis > 30 jours
        $stmt2 = $pdo->prepare("
            DELETE FROM persona_tokens
            WHERE revoked_at IS NULL AND expires_at < ?
        ");
        $stmt2->execute([$cutoff]);
        $deleted += $stmt2->rowCount();

        return $deleted;
    } catch (\Throwable $e) {
        error_log('persona_cleanup error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Retourne le token persona actif depuis $_GET, ou '' si aucun.
 *
 * Utilisé par AuthService::getUser() pour déterminer si un persona est actif.
 *
 * @return string
 */
function persona_current_token(): string {
    return isset($_GET['persona_token']) ? (string)$_GET['persona_token'] : '';
}

/**
 * Retourne l'email du persona actif (target_email), ou '' si aucun.
 *
 * @return string
 */
function persona_current_target(): string {
    $token = persona_current_token();
    if ($token === '') return '';
    return persona_lookup($token);
}
