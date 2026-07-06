<?php
declare(strict_types=1);

/**
 * Audit & security logging.
 *
 * app_log() — journal des actions admin (table audit_log)
 * security_log() — journal des événements de sécurité (table security_log)
 * get_audit_logs() — récupération filtrée des logs
 *
 * @package lib
 */

// ── AUDIT LOG ────────────────────────────────────────────────

/**
 * Enregistre une action dans le journal d'audit
 *
 * @param string $action  Type d'action (ex: 'form_create', 'admin_remove', 'settings_update')
 * @param string $target  Cible de l'action (ex: 'form:3', 'submission:42')
 * @param string $detail  Description lisible de l'action
 * @param string $actor   Acteur (email), si vide = utilisateur connecté
 */
function app_log(string $action, string $target = '', string $detail = '', string $actor = ''): void {
    try {
        $pdo = get_pdo();
        if (empty($actor)) {
            $actor = get_auth_user();
        }
        // Sécurité : ne pas utiliser X-Forwarded-FOR (falsifiable par le client)
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';

        // Sécurité (S-18) : masquer les données sensibles dans les logs
        // Remplacer les adresses email par leur début + ***
        $log_detail = $detail;
        $log_target = $target;
        // Ne pas masquer en mode CLI (administration système) ni pour les logs de sécurité critiques
        if (php_sapi_name() !== 'cli' && !in_array($action, ['access_denied', 'rate_limit', 'file_upload_blocked', 'admin_request', 'admin_approve', 'admin_reject', 'admin_remove', 'rgpd_delete', 'rgpd_purge', 'security_event'])) {
            // Masquer partiellement les emails dans les détails de log
            $log_detail = preg_replace('/([a-zA-Z0-9._%+\-]{2})[a-zA-Z0-9._%+\-]*@/', '$1***@', $log_detail);
        }

        $pdo->prepare("INSERT INTO audit_log (id, action, target, detail, actor, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, datetime('now'))")
            ->execute([generate_uuid(), $action, $log_target, $log_detail, $actor, $ip]);
    } catch (Exception $e) {
        error_log('Audit log error: ' . $e->getMessage());
    }
}

/**
 * Journalise un événement de sécurité (A-10).
 * Variante d'app_log() pour les événements liés à la sécurité
 * (tentatives d'accès non autorisé, injections, etc.).
 * Ces événements sont TOUJOURS loggés avec le détail complet.
 */
function security_log(string $event, string $detail = '', string $actor = ''): void {
    if (empty($actor)) {
        $actor = get_auth_user();
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
    // Sécurité (A-10) : toujours journaliser les événements de sécurité avec détail complet
    app_log('security_event', $event, $detail, $actor);
    // Double écriture dans error_log pour alerter les administrateurs système
    error_log("[SECURITY] {$event} — IP: {$ip} — Actor: {$actor} — {$detail}");
}

/**
 * Récupère les entrées du journal d'audit
 * @return array<string, mixed>
 */
function get_audit_logs(int $limit = 100, string $action_filter = ''): array {
    $pdo = get_pdo();
    if ($action_filter) {
        $stmt = $pdo->prepare("SELECT * FROM audit_log WHERE action = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$action_filter, $limit]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$limit]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
