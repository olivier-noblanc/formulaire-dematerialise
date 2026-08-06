<?php
declare(strict_types=1);

/**
 * Migration v14: Audit trail + UNIQUE sur submission_validator_data.
 *
 * La v13 avait créé la table sans colonnes d'audit (step_id, step_label,
 * filled_by_email, token_id) et sans contrainte UNIQUE sur (submission_id,
 * field_name) → race condition possible si deux validateurs soumettent
 * simultanément la même donnée pour le même champ.
 *
 * La v14 ajoute (pour les bases déjà en v13) :
 *   - step_id TEXT         : UUID du step où la donnée a été saisie
 *   - step_label TEXT      : label du step (dénormalisé pour audit facile)
 *   - filled_by_email TEXT : email du validateur qui a rempli la donnée
 *   - token_id TEXT        : ID du token utilisé (lien vers tokens.id)
 *   - UNIQUE(submission_id, field_name) via l'index idx_svd_sub_field :
 *     un seul value par champ par soumission (UPSERT côté helpers.php).
 *
 * Note : sur les nouvelles installations, le CREATE TABLE de la v13
 * inclut déjà ces 4 colonnes + UNIQUE(submission_id, field_name). La v14
 * crée en plus l'index explicite idx_svd_sub_field (nommé pour le check
 * final) — légèrement redondant avec l'index implicite de la contrainte
 * UNIQUE de table, mais inoffensif et nécessaire pour un check déterministe.
 *
 * Pattern : même guard que v12/v13 — ne pas marquer la version tant que
 * les changements ne sont pas vérifiés (colonnes + index présents).
 *
 * @package Migrations
 */

function apply_migration_v14(PDO $pdo, int $current_version): int {
    if ($current_version < 14) {
        try {
            // Vérifier d'abord si la table existe (cas où v13 n'a pas tourné)
            $table_check = _dbm_q($pdo, "SELECT name FROM sqlite_master WHERE type='table' AND name='submission_validator_data'")->fetchColumn();
            if ($table_check === 'submission_validator_data') {
                // Colonnes à ajouter (idempotent — ALTER TABLE ADD COLUMN
                // échoue si la colonne existe déjà, d'où le check via PRAGMA)
                $cols_to_add = [
                    'step_id'         => 'TEXT',
                    'step_label'      => 'TEXT',
                    'filled_by_email' => 'TEXT',
                    'token_id'        => 'TEXT',
                ];
                $cols_check_stmt = $pdo->query("PRAGMA table_info(submission_validator_data)");
                if ($cols_check_stmt === false) {
                    throw new \RuntimeException('v14: PRAGMA table_info(submission_validator_data) failed');
                }
                $cols_check = $cols_check_stmt->fetchAll(PDO::FETCH_ASSOC);
                // CS-06 fix (audit 2026-07-26) : libérer le statement avant le prochain DDL
                $cols_check_stmt = null;
                $existing_cols = array_column($cols_check, 'name');
                foreach ($cols_to_add as $col_name => $col_type) {
                    if (!in_array($col_name, $existing_cols, true)) {
                        $pdo->exec("ALTER TABLE submission_validator_data ADD COLUMN $col_name $col_type");
                    }
                }

                // Index UNIQUE sur (submission_id, field_name).
                // SQLite ne permet pas ALTER TABLE ADD CONSTRAINT sur une table
                // existante : on crée un index UNIQUE explicite nommé idx_svd_sub_field.
                $idx_check_stmt = _dbm_q($pdo, "SELECT name FROM sqlite_master WHERE type='index' AND name='idx_svd_sub_field'");
                $idx_check = $idx_check_stmt->fetchColumn();
                $idx_check_stmt = null; // CS-06
                if ($idx_check !== 'idx_svd_sub_field') {
                    // Dédoubler avant de créer l'index UNIQUE : on garde le plus
                    // ancien (MIN(id)) par (submission_id, field_name) pour
                    // éviter que CREATE UNIQUE INDEX n'échoue sur des doublons
                    // préexistants (race condition de la v13).
                    $pdo->exec("DELETE FROM submission_validator_data WHERE id NOT IN (
                        SELECT MIN(id) FROM submission_validator_data
                        GROUP BY submission_id, field_name
                    )");
                    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_svd_sub_field ON submission_validator_data(submission_id, field_name)");
                }
            }

            // Vérification finale : 4 colonnes + index idx_svd_sub_field présents
            $final_cols_stmt = $pdo->query("PRAGMA table_info(submission_validator_data)");
            if ($final_cols_stmt === false) {
                throw new \RuntimeException('v14: PRAGMA table_info(submission_validator_data) failed');
            }
            $final_cols = $final_cols_stmt->fetchAll(PDO::FETCH_ASSOC);
            $final_cols_stmt = null; // CS-06
            $final_col_names = array_column($final_cols, 'name');
            $has_step_id         = in_array('step_id', $final_col_names, true);
            $has_step_label      = in_array('step_label', $final_col_names, true);
            $has_filled_by_email = in_array('filled_by_email', $final_col_names, true);
            $has_token_id        = in_array('token_id', $final_col_names, true);
            $final_idx_stmt = _dbm_q($pdo, "SELECT name FROM sqlite_master WHERE type='index' AND name='idx_svd_sub_field'");
            $final_idx = $final_idx_stmt->fetchColumn();
            $final_idx_stmt = null; // CS-06

            if ($has_step_id && $has_step_label && $has_filled_by_email && $has_token_id && $final_idx === 'idx_svd_sub_field') {
                $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([14]);
                return 14;
            } else {
                error_log('[db_migrate] v14 FAILED: colonnes ou index manquants, version NON marquée');
            }
        } catch (PDOException $e) {
            // @silent-ok: log-only — la migration sera retentée au prochain appel
            // Ne PAS marquer la version à 14 — la migration sera retentée au prochain appel.
            error_log('[db_migrate] v14 FAILED: ' . $e->getMessage() . ' — retry au prochain appel');
        }
    }
    return $current_version;
}
