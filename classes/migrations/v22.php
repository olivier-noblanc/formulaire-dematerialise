<?php
declare(strict_types=1);

/**
 * Migration v22: Ajoute la colonne `admin_comment` à la table `submissions`.
 *
 * Permet à l'admin ou au propriétaire du formulaire d'ajouter un commentaire
 * libre post-soumission (annotation indépendante des champs validator).
 *
 * Cas d'usage : annotation manuelle, note de suivi, contexte de clôture,
 * rappel métier — visible dans `submission_view.php` (zone éditable) et dans
 * `dashboard.php` (icône 💬 avec tooltip).
 *
 * La colonne est ajoutée de façon idempotente (ALTER TABLE + check
 * PRAGMA table_info). Rétrocompat : `admin_comment` absente ou null ou ''
 * = pas de commentaire.
 *
 * @package Migrations
 */

function apply_migration_v22(PDO $pdo, int $current_version): int {
    // Forcer la migration même si schema_version >= 900 (ancien marqueur)
    $needs_v22 = ($current_version < 22) || ($current_version >= 900);
    if ($needs_v22) {
        try {
            // Vérifier si v22 a déjà été appliquée
            $v22_done = (int) $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 22")->fetchColumn();
            if ($v22_done > 0) {
                return max($current_version, 22);
            }

            // Ajouter colonne `admin_comment` à submissions (idempotent)
            $cols = $pdo->query("PRAGMA table_info(submissions)")->fetchAll(PDO::FETCH_ASSOC);
            $has_admin_comment = false;
            if (is_array($cols)) {
                foreach ($cols as $c) {
                    if (is_array($c) && ($c['name'] ?? '') === 'admin_comment') {
                        $has_admin_comment = true;
                        break;
                    }
                }
            }
            if (!$has_admin_comment) {
                // TEXT DEFAULT '' — chaîne vide = pas de commentaire (rétrocompat).
                $pdo->exec("ALTER TABLE submissions ADD COLUMN admin_comment TEXT DEFAULT ''");
            }

            $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([22]);
            return 22;
        } catch (PDOException $e) {
            error_log('[db_migrate] v22 FAILED: ' . $e->getMessage() . ' — retry au prochain appel');
        }
    }
    return $current_version;
}
