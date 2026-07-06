<?php
declare(strict_types=1);

/**
 * Migration v19: Ajoute la colonne `condition` à la table `steps`.
 *
 * Permet de configurer des branches conditionnelles dans les circuits de
 * validation. Une étape peut ne s'exécuter que si la valeur d'un champ
 * validateur (rempli lors d'une étape précédente) respecte une condition
 * (égalité, différence, contenance, présence...).
 *
 * Format stocké (JSON encodé en TEXT) :
 *   {"field": "decision_sg", "op": "equals", "value": "Acceptée"}
 *
 * Opérateurs supportés par evaluate_step_condition() :
 *   - 'equals'       : valeur exacte
 *   - 'not_equals'   : différente
 *   - 'contains'     : contient la valeur
 *   - 'not_empty'    : non vide
 *   - 'empty'        : vide
 *
 * Si `condition` est vide ou null → l'étape s'exécute toujours
 * (comportement historique, rétrocompat assuré).
 *
 * Cas d'usage : un formulaire "Adaptation poste matériel" où l'étape 1
 * (owner) remplit `decision_sg`. Si "Refusée" → les étapes Logistique
 * et DSI (ordre 2) sont skippées, la soumission est clôturée
 * directement. Si "Acceptée" → Logistique + DSI s'exécutent.
 *
 * La colonne est ajoutée de façon idempotente (ALTER TABLE + check
 * PRAGMA table_info). Rétrocompat : `condition` absente ou null ou ''
 * = toujours exécuter.
 *
 * @package Migrations
 */

function apply_migration_v19(PDO $pdo, int $current_version): int {
    // Forcer la migration même si schema_version >= 900 (ancien marqueur)
    $needs_v19 = ($current_version < 19) || ($current_version >= 900);
    if ($needs_v19) {
        try {
            // Vérifier si v19 a déjà été appliquée
            $v19_done = (int) $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 19")->fetchColumn();
            if ($v19_done > 0) {
                return max($current_version, 19);
            }

            // Ajouter colonne `condition` à steps (idempotent)
            $cols = $pdo->query("PRAGMA table_info(steps)")->fetchAll(PDO::FETCH_ASSOC);
            $has_condition = false;
            if (is_array($cols)) {
                foreach ($cols as $c) {
                    if (is_array($c) && ($c['name'] ?? '') === 'condition') {
                        $has_condition = true;
                        break;
                    }
                }
            }
            if (!$has_condition) {
                // `condition` est un mot-clé SQL réservé, mais SQLite accepte
                // un ALTER TABLE ADD COLUMN `condition` (le mot-clé est réservé
                // comme type affinité mais pas comme nom de colonne quand il est
                // correctement échappé dans les requêtes utilisant des
                // identificateurs entre guillemets). On utilise des backticks
                // pour SQLite (compatible) — les requêtes SELECT utilisent
                // toujours `st.condition` ce qui fonctionne sans backticks car
                // le parser SQLite tolère `condition` en position de colonne.
                $pdo->exec("ALTER TABLE steps ADD COLUMN `condition` TEXT DEFAULT ''");
                // '' (chaîne vide) = pas de condition = exécuter toujours
            }

            $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([19]);
            return 19;
        } catch (PDOException $e) {
            error_log('[db_migrate] v19 FAILED: ' . $e->getMessage() . ' — retry au prochain appel');
        }
    }
    return $current_version;
}
