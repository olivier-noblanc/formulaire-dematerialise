<?php
declare(strict_types=1);

/**
 * Migration v15: Corrige l'admin_email par défaut.
 *
 * La v11 avait inséré admin_email en DB avec la valeur de config.php à l'époque
 * (admin@dreets.gouv.fr). Cette adresse n'existe pas. La migration v15 met à jour
 * admin_email vers la nouvelle valeur par défaut (dreets-bfc.supportesic@dreets.gouv.fr)
 * SI ET SEULEMENT SI la valeur actuelle est encore l'ancienne valeur par défaut.
 *
 * Ne touche pas aux admin_email personnalisés (si l'admin a configuré une autre adresse).
 *
 * Aussi : met à jour la table admins pour remplacer l'ancien admin par défaut
 * (admin@dreets.gouv.fr) par le nouveau, et ajoute admin_email_cc.
 *
 * @package Migrations
 */

function apply_migration_v15(PDO $pdo, int $current_version): int {
    // ⚠️ Note: current_version peut être 900 (ancien marqueur auto-fix v9 supprimé).
    // Dans ce cas, on force la migration v15 car la base n'a jamais eu v10-v14 formellement.
    $needs_v15 = ($current_version < 15) || ($current_version >= 900);
    if ($needs_v15) {
        try {
            // Vérifier si v15 a déjà été appliquée (marqueur en base)
            $v15_done = (int) $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 15")->fetchColumn();
            if ($v15_done > 0) {
                return max($current_version, 15);
            }

            // Nouvelles valeurs par défaut
            $new_admin_email = 'dreets-bfc.supportesic@dreets.gouv.fr';
            $new_admin_email_cc = 'olivier.noblanc@dreets.gouv.fr';
            $old_default_admin_email = 'admin@dreets.gouv.fr';

            // 1. Mettre à jour admin_email SI c'est encore l'ancienne valeur par défaut
            $current_admin_email = (string) $pdo->query("SELECT value FROM settings WHERE key = 'admin_email'")->fetchColumn();
            if ($current_admin_email === $old_default_admin_email || $current_admin_email === '') {
                $pdo->prepare("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES ('admin_email', ?, datetime('now'))")
                    ->execute([$new_admin_email]);
            }

            // 2. Ajouter admin_email_cc s'il n'existe pas
            $pdo->prepare("INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('admin_email_cc', ?, datetime('now'))")
                ->execute([$new_admin_email_cc]);

            // 3. Mettre à jour la table admins : remplacer l'ancien admin par défaut
            $stmt = $pdo->prepare("SELECT id FROM admins WHERE email = ?");
            $stmt->execute([$old_default_admin_email]);
            $old_admin_id = $stmt->fetchColumn();
            if ($old_admin_id !== false) {
                // Vérifier qu'il n'y a pas déjà un admin avec le nouvel email
                $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE email = ?");
                $stmt2->execute([$new_admin_email]);
                if ((int) $stmt2->fetchColumn() === 0) {
                    // Mettre à jour l'ancien admin vers le nouvel email
                    $pdo->prepare("UPDATE admins SET email = ? WHERE id = ?")
                        ->execute([$new_admin_email, $old_admin_id]);
                } else {
                    // Un admin avec le nouvel email existe déjà → supprimer l'ancien
                    $pdo->prepare("DELETE FROM admins WHERE id = ?")
                        ->execute([$old_admin_id]);
                }
            }

            // 4. Vérification finale
            $final_email = (string) $pdo->query("SELECT value FROM settings WHERE key = 'admin_email'")->fetchColumn();
            $final_cc = (string) $pdo->query("SELECT value FROM settings WHERE key = 'admin_email_cc'")->fetchColumn();

            if ($final_email !== '' && $final_email !== $old_default_admin_email) {
                $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([15]);
                return 15;
            } else {
                error_log('[db_migrate] v15 FAILED: admin_email toujours à l\'ancienne valeur, version NON marquée');
            }
        } catch (PDOException $e) {
            error_log('[db_migrate] v15 FAILED: ' . $e->getMessage() . ' — retry au prochain appel');
        }
    }
    return $current_version;
}
