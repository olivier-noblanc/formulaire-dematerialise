<?php
declare(strict_types=1);

/**
 * Migration v11: admin_email en base (remplace le define ADMIN_EMAIL de config.php).
 *
 * Insère dans la table settings la valeur courante de admin_email (récupérée
 * via \App\Core\App::settings()->get()) afin de pouvoir la modifier sans toucher à config.php.
 *
 * @package Migrations
 */

function apply_migration_v11(PDO $pdo, int $current_version): int {
    if ($current_version < 11) {
        try {
            // Insérer l'admin_email actuel s'il n'existe pas déjà en base
            $admin_email_value = \App\Core\App::settings()->get('admin_email');
            $pdo->prepare("INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('admin_email', ?, datetime('now'))")->execute([$admin_email_value]);
            $pdo->prepare("INSERT INTO schema_version (version) VALUES (?)")->execute([11]);
            return 11;
        } catch (PDOException $e) {
            // @silent-ok: fallback — migration déjà appliquée, on marque la version
            try { $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([11]); } catch (PDOException $e2) { /* @silent-ok: fallback — ignore si déjà enregistré */ }
            return 11;
        }
    }
    return $current_version;
}
