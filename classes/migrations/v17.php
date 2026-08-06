<?php
declare(strict_types=1);

/**
 * Migration v17: Corrige l'inversion admin_email / admin_email_cc.
 *
 * Les migrations v15 et v16 avaient mis:
 *   admin_email    = service.support@exemple.invalid
 *   admin_email_cc = admin.local@exemple.invalid
 *
 * Mais c'est l'inverse: l'admin est l'admin principal, et
 * service.support est en CC.
 *
 * Cette migration:
 *   1. Inverse admin_email et admin_email_cc en DB
 *   2. Met à jour la table admins pour refletter le changement
 *      (supprime l'ancien admin, ajoute admin.local si absent)
 *
 * @package Migrations
 */

function apply_migration_v17(PDO $pdo, int $current_version): int {
    $needs_v17 = ($current_version < 17) || ($current_version >= 900);
    if ($needs_v17) {
        try {
            // Vérifier si v17 a déjà été appliquée
            $v17_stmt = $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 17");
            if ($v17_stmt === false) {
                throw new \RuntimeException('v17: COUNT query failed');
            }
            $v17_done = (int) $v17_stmt->fetchColumn();
            if ($v17_done > 0) {
                return max($current_version, 17);
            }

            // Nouvelles valeurs correctes
            $correct_admin_email    = 'admin.local@exemple.invalid';
            $correct_admin_email_cc = 'service.support@exemple.invalid';
            $wrong_admin_email      = 'service.support@exemple.invalid';
            $wrong_admin_email_cc   = 'admin.local@exemple.invalid';

            // 1. Lire les valeurs actuelles en DB
            $email_stmt = $pdo->query("SELECT value FROM settings WHERE key = 'admin_email'");
            if ($email_stmt === false) {
                throw new \RuntimeException('v17: SELECT admin_email failed');
            }
            $current_admin_email = (string) $email_stmt->fetchColumn();
            $cc_stmt = $pdo->query("SELECT value FROM settings WHERE key = 'admin_email_cc'");
            if ($cc_stmt === false) {
                throw new \RuntimeException('v17: SELECT admin_email_cc failed');
            }
            $current_admin_email_cc = (string) $cc_stmt->fetchColumn();

            // 2. Corriger admin_email si c'est l'ancienne valeur inversée
            if ($current_admin_email === $wrong_admin_email) {
                $pdo->prepare("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES ('admin_email', ?, datetime('now'))")
                    ->execute([$correct_admin_email]);
            }

            // 3. Corriger admin_email_cc si c'est l'ancienne valeur inversée
            if ($current_admin_email_cc === $wrong_admin_email_cc) {
                $pdo->prepare("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES ('admin_email_cc', ?, datetime('now'))")
                    ->execute([$correct_admin_email_cc]);
            } elseif ($current_admin_email_cc === '') {
                // Si admin_email_cc n'existe pas, l'insérer avec la bonne valeur
                $pdo->prepare("INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('admin_email_cc', ?, datetime('now'))")
                    ->execute([$correct_admin_email_cc]);
            }

            // 4. Mettre à jour la table admins
            // Si l'ancien admin (service.support) existe, le supprimer
            $stmt = $pdo->prepare("SELECT id FROM admins WHERE email = ?");
            $stmt->execute([$wrong_admin_email]);
            $wrong_admin_id = $stmt->fetchColumn();
            if ($wrong_admin_id !== false) {
                $pdo->prepare("DELETE FROM admins WHERE id = ?")->execute([$wrong_admin_id]);
            }

            // S'assurer que admin.local est dans admins
            $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE email = ?");
            $stmt2->execute([$correct_admin_email]);
            if ((int) $stmt2->fetchColumn() === 0) {
                $pdo->prepare("INSERT INTO admins (id, email, added_at) VALUES (?, ?, datetime('now'))")
                    ->execute([generate_uuid(), $correct_admin_email]);
            }

            // 5. Vérification finale
            // v17 a pour but d'inverser les valeurs erronées (v15/v16). Une fois l'inversion
            // faite (ou si l'admin a configuré un autre email volontairement), la migration
            // est considérée comme appliquée. Le seul cas d'échec légitime est une BDD vide
            // sans aucun admin_email — mais c'est géré par v15/v16 qui insèrent une valeur.
            //
            // AVANT (bug) : on exigeait $final_email === 'admin.local@exemple.invalid'
            // ce qui échouait systématiquement si l'admin avait configuré un autre email
            // (via SETTINGS_DEFAULTS ou admin_settings.php). La migration n'était jamais
            // marquée appliquée → log pollué + retry infini.
            $final_stmt = $pdo->query("SELECT value FROM settings WHERE key = 'admin_email'");
            if ($final_stmt === false) {
                throw new \RuntimeException('v17: SELECT admin_email failed');
            }
            $final_email = (string) $final_stmt->fetchColumn();
            if ($final_email !== '' && $final_email !== $wrong_admin_email) {
                // admin_email est non vide ET n'est plus l'ancienne valeur erronée → OK
                $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([17]);
                return 17;
            } else {
                error_log('[db_migrate] v17 FAILED: admin_email toujours à l\'ancienne valeur, version NON marquée');
            }
        } catch (PDOException $e) {
            // @silent-ok: log-only — la migration sera retentée au prochain appel
            error_log('[db_migrate] v17 FAILED: ' . $e->getMessage() . ' — retry au prochain appel');
        }
    }
    return $current_version;
}
