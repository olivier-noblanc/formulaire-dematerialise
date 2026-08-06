<?php
declare(strict_types=1);

/**
 * Migration v18: Ajoute la colonne `visibility` à la table `form_fields`.
 *
 * Permet de configurer la visibilité des pièces jointes (champs de type
 * `file`) :
 *   - 'all'        : visible par tous les validateurs + l'owner (comportement
 *                    historique, par défaut).
 *   - 'owner_only' : visible uniquement par l'owner du formulaire (l'admin
 *                    qui a créé le formulaire). Caché des validateurs des
 *                    étapes de workflow.
 *
 * Cas d'usage : un agent upload un « CV détaillé » — l'admin RH veut le
 * voir, mais le manager direct ne devrait pas y avoir accès. Le champ file
 * est alors marqué `visibility = 'owner_only'`.
 *
 * La colonne est ajoutée de façon idempotente (ALTER TABLE + check PRAGMA
 * table_info). Rétrocompat : `visibility` absente ou null = 'all'.
 *
 * @package Migrations
 */

function apply_migration_v18(PDO $pdo, int $current_version): int {
    // Forcer la migration même si schema_version >= 900 (ancien marqueur)
    $needs_v18 = ($current_version < 18) || ($current_version >= 900);
    if ($needs_v18) {
        try {
            // Vérifier si v18 a déjà été appliquée
            $v18_stmt = $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 18");
            if ($v18_stmt === false) {
                throw new \RuntimeException('v18: COUNT query failed');
            }
            $v18_done = (int) $v18_stmt->fetchColumn();
            if ($v18_done > 0) {
                return max($current_version, 18);
            }

            // Ajouter colonne visibility à form_fields (idempotent)
            $cols_stmt = $pdo->query("PRAGMA table_info(form_fields)");
            if ($cols_stmt === false) {
                throw new \RuntimeException('v18: PRAGMA table_info(form_fields) failed');
            }
            $cols = $cols_stmt->fetchAll(PDO::FETCH_ASSOC);
            $has_visibility = false;
            if (is_array($cols)) {
                foreach ($cols as $c) {
                    if (is_array($c) && ($c['name'] ?? '') === 'visibility') {
                        $has_visibility = true;
                        break;
                    }
                }
            }
            if (!$has_visibility) {
                $pdo->exec("ALTER TABLE form_fields ADD COLUMN visibility TEXT DEFAULT 'all'");
                // 'all'        = visible par tous (validateurs + owner) — comportement actuel
                // 'owner_only' = visible uniquement par l'owner du formulaire
            }

            $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([18]);
            return 18;
        } catch (PDOException $e) {
            // @silent-ok: log-only — la migration sera retentée au prochain appel
            error_log('[db_migrate] v18 FAILED: ' . $e->getMessage() . ' — retry au prochain appel');
        }
    }
    return $current_version;
}
