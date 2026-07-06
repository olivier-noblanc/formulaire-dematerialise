<?php
declare(strict_types=1);

/**
 * Migration v16: Active l'envoi réel d'emails + ajoute l'admin manquant.
 *
 * Problème : en première installation, mail_dry_run=1 par défaut (les mails ne
 * partent pas) et l'admin par défaut est seedé avec get_admin_email() qui peut
 * être incorrect si le setting n'est pas encore en place. L'utilisateur se
 * retrouve bloqué : il ne peut pas devenir admin (le mail ne part pas) et il
 * ne peut pas accéder aux settings (il n'est pas admin).
 *
 * Cette migration :
 *   1. Met mail_dry_run = '0' (activer l'envoi réel)
 *   2. S'assure que l'admin_email en DB correspond à SETTINGS_DEFAULTS
 *   3. S'assure qu'il y a au moins un admin en table admins (sinon insère
 *      l'admin_email courant)
 *
 * @package Migrations
 */

function apply_migration_v16(PDO $pdo, int $current_version): int {
    // Forcer la migration même si schema_version >= 900 (ancien marqueur)
    $needs_v16 = ($current_version < 16) || ($current_version >= 900);
    if ($needs_v16) {
        try {
            // Vérifier si v16 a déjà été appliquée
            $v16_done = (int) $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 16")->fetchColumn();
            if ($v16_done > 0) {
                return max($current_version, 16);
            }

            // 1. Activer l'envoi réel d'emails (mail_dry_run = 0)
            $pdo->prepare("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES ('mail_dry_run', '0', datetime('now'))")->execute();

            // 2. S'assurer que admin_email en DB correspond à SETTINGS_DEFAULTS
            $default_admin_email = defined('SETTINGS_DEFAULTS') ? (SETTINGS_DEFAULTS['admin_email'] ?? '') : '';
            if ($default_admin_email !== '') {
                $current_admin_email = (string) $pdo->query("SELECT value FROM settings WHERE key = 'admin_email'")->fetchColumn();
                // Si admin_email est vide ou égal à l'ancienne valeur par défaut, le mettre à jour
                if ($current_admin_email === '' || $current_admin_email === 'admin@exemple.invalid') {
                    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES ('admin_email', ?, datetime('now'))")
                        ->execute([$default_admin_email]);
                }
            }

            // 3. S'assurer qu'il y a au moins un admin en table admins
            $admin_count = (int) $pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
            if ($admin_count === 0) {
                // Aucun admin — insérer l'admin_email courant
                $admin_email = (string) $pdo->query("SELECT value FROM settings WHERE key = 'admin_email'")->fetchColumn();
                if ($admin_email === '' && $default_admin_email !== '') {
                    $admin_email = $default_admin_email;
                }
                if ($admin_email !== '') {
                    $pdo->prepare("INSERT INTO admins (id, email, added_at) VALUES (?, ?, datetime('now'))")
                        ->execute([generate_uuid(), $admin_email]);
                }
            }

            // 4. Marquer la version
            $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([16]);
            return 16;
        } catch (PDOException $e) {
            error_log('[db_migrate] v16 FAILED: ' . $e->getMessage() . ' — retry au prochain appel');
        }
    }
    return $current_version;
}
