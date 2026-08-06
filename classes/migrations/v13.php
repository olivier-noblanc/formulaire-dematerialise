<?php
declare(strict_types=1);

/**
 * Migration v13: Validator-only fields (filled_by).
 *
 * Permet de marquer certains champs comme remplissables uniquement par
 * des validateurs (ex : "Avis médecin", "Décision SG", "Matériel remis").
 * Ces champs ne sont pas affichés au demandeur mais apparaissent lors
 * des étapes de validation du workflow.
 *
 * Colonnes ajoutées à form_fields :
 *   - filled_by TEXT DEFAULT 'demandeur'  ('demandeur' | 'validator')
 *   - validator_step TEXT DEFAULT ''       (lien vers step label/ID)
 *
 * Nouvelle table submission_validator_data :
 *   Stocke les valeurs remplies par les validateurs, séparément des
 *   données du demandeur (submissions.data). Colonnes d'audit (step_id,
 *   step_label, filled_by_email, token_id) + UNIQUE(submission_id,
 *   field_name) ajoutées en v14 — présentes dès la création pour que les
 *   nouvelles installations disposent du schéma v14 directement.
 *
 * Pattern : suivre le même guard que v9/v10/v11 — ne pas marquer la
 * version tant que les changements n'existent pas vraiment (table
 * vérifiée, colonnes ajoutées).
 *
 * @package Migrations
 */

function apply_migration_v13(PDO $pdo, int $current_version): int {
    if ($current_version < 13) {
        try {
            // A-13a : colonnes `filled_by` et `validator_step` dans form_fields
            try { $pdo->exec("ALTER TABLE form_fields ADD COLUMN filled_by TEXT DEFAULT 'demandeur'"); } catch (PDOException $e) { /* @silent-ok: fallback — colonne déjà existante */ }
            try { $pdo->exec("ALTER TABLE form_fields ADD COLUMN validator_step TEXT DEFAULT ''"); } catch (PDOException $e) { /* @silent-ok: fallback — colonne déjà existante */ }

            // A-13b : table submission_validator_data
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS submission_validator_data (
                    id TEXT PRIMARY KEY NOT NULL,
                    submission_id TEXT NOT NULL,
                    field_name TEXT NOT NULL,
                    field_label TEXT NOT NULL,
                    field_type TEXT NOT NULL,
                    value TEXT,
                    filled_by TEXT NOT NULL,
                    filled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    step_id TEXT,
                    step_label TEXT,
                    filled_by_email TEXT,
                    token_id TEXT,
                    UNIQUE(submission_id, field_name),
                    FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
                )
            ");
            // Index pour récupérer rapidement les données par soumission
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_svds_submission ON submission_validator_data(submission_id)");

            // Vérifier que les changements existent RÉELLEMENT avant de marquer la version
            $cols_check_stmt = $pdo->query("PRAGMA table_info(form_fields)");
            if ($cols_check_stmt === false) {
                throw new \RuntimeException('v13: PRAGMA table_info(form_fields) failed');
            }
            $cols_check = $cols_check_stmt->fetchAll(PDO::FETCH_ASSOC);
            // CS-06 fix (audit 2026-07-26) : libérer le statement avant le _dbm_q suivant
            // (règle SQLITE_LOCKED intra-processus, voir AGENTS.md).
            $cols_check_stmt = null;
            $has_filled_by = false;
            $has_validator_step = false;
            foreach ($cols_check as $c) {
                if ($c['name'] === 'filled_by')    $has_filled_by    = true;
                if ($c['name'] === 'validator_step') $has_validator_step = true;
            }
            $table_exists_stmt = _dbm_q($pdo, "SELECT name FROM sqlite_master WHERE type='table' AND name='submission_validator_data'");
            $table_exists = $table_exists_stmt->fetchColumn();
            $table_exists_stmt = null; // CS-06 : libérer avant le prochain DDL

            if ($has_filled_by && $has_validator_step && $table_exists === 'submission_validator_data') {
                $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([13]);
                return 13;
            } else {
                error_log('[db_migrate] v13 FAILED: colonnes/form_fields ou table submission_validator_data manquantes, version NON marquée');
            }
        } catch (PDOException $e) {
            // @silent-ok: log-only — la migration sera retentée au prochain appel
            // Ne PAS marquer la version à 13 — la migration sera retentée au prochain appel.
            error_log('[db_migrate] v13 FAILED: ' . $e->getMessage() . ' — retry au prochain appel');
        }
    }
    return $current_version;
}
