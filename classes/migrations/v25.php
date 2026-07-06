<?php
declare(strict_types=1);

/**
 * Migration v25: Crée la table persona_tokens pour la refonte du persona admin.
 *
 * Architecture (v10.0.0) :
 *   - L'admin génère un token aléatoire stocké en DB (lié à son email + target)
 *   - Le token est propagé dans toutes les URLs via ?persona_token=XXX
 *   - AuthService::getUser() lit le token depuis $_GET et retourne le target_email
 *   - Le persona downgrade seulement (admin → user), jamais upgrade
 *
 * Sécurité :
 *   - Le token expire après 8h (configurable via setting persona_token_ttl)
 *   - Le token est révocable individuellement
 *   - Un token ne peut être créé que par un admin (vérifié dans lib/persona.php)
 *   - Même si le token fuite dans les logs, l'attaquant ne fait que downgrader
 *
 * @package Migrations
 */

function apply_migration_v25(PDO $pdo, int $current_version): int {
    $needs_v25 = ($current_version < 25) || ($current_version >= 900);
    if (!$needs_v25) {
        return $current_version;
    }

    try {
        $v25_done = (int) $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 25")->fetchColumn();
        if ($v25_done > 0) {
            return max($current_version, 25);
        }

        // Créer la table persona_tokens
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS persona_tokens (
                id TEXT PRIMARY KEY,
                token TEXT NOT NULL UNIQUE,
                admin_email TEXT NOT NULL,
                target_email TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                expires_at TEXT NOT NULL,
                revoked_at TEXT
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_persona_tokens_token ON persona_tokens(token)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_persona_tokens_admin ON persona_tokens(admin_email)");

        // Enregistrer la version
        $stmt = $pdo->prepare("INSERT INTO schema_version (version, applied_at) VALUES (25, datetime('now'))");
        $stmt->execute();

        // Nettoyer l'ancien mécanisme session-based (v9.7.0 → v9.9.0)
        // Les personas stockés en session seront perdus — c'est attendu,
        // l'utilisateur devra réactiver un persona via la user card.
        // (Pas d'action DB ici — la session est nettoyée au prochain chargement)

        return 25;
    } catch (PDOException $e) {
        error_log("Migration v25 failed: " . $e->getMessage());
        return $current_version;
    }
}
