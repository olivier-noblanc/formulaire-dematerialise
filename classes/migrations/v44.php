<?php
declare(strict_types=1);

/** Make form field assignment and visibility strict and non-nullable. */
function apply_migration_v44(PDO $pdo, int $current_version): int
{
    if ($current_version >= 44 && $current_version < 900) {
        return $current_version;
    }

    $done = $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 44');
    if ($done === false) {
        throw new RuntimeException('v44: version query failed');
    }
    $already = (int) $done->fetchColumn();
    $done = null;
    if ($already > 0) {
        return max($current_version, 44);
    }

    $badQuery = $pdo->query("SELECT id FROM form_fields WHERE filled_by IS NULL OR filled_by NOT IN ('demandeur','validator') OR visibility IS NULL OR visibility NOT IN ('all','owner_only') LIMIT 10");
    if ($badQuery === false) {
        throw new RuntimeException('v44: preflight query failed');
    }
    $bad = $badQuery->fetchAll(PDO::FETCH_COLUMN);
    $badQuery = null;
    if ($bad !== []) {
        throw new RuntimeException('v44 blocked: invalid form_fields.filled_by or visibility values');
    }

    $fkQuery = $pdo->query('PRAGMA foreign_keys');
    if ($fkQuery === false) {
        throw new RuntimeException('v44: foreign key query failed');
    }
    $foreignKeys = (int) $fkQuery->fetchColumn() === 1;
    $fkQuery = null;
    $pdo->exec('PRAGMA foreign_keys = OFF');
    try {
        $pdo->exec('BEGIN IMMEDIATE');
        $sqlQuery = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='form_fields'");
        if ($sqlQuery === false) {
            throw new RuntimeException('v44: schema query failed');
        }
        $sql = (string) $sqlQuery->fetchColumn();
        $sqlQuery = null;
        if ($sql === '') {
            throw new RuntimeException('v44: missing form_fields table');
        }
        $sql = "CREATE TABLE form_fields_new (id TEXT PRIMARY KEY NOT NULL, form_id TEXT NOT NULL, label TEXT NOT NULL, field_type TEXT NOT NULL DEFAULT 'text' CHECK (field_type IN ('text','email','date','select','checkbox','textarea','file')), field_name TEXT NOT NULL, options TEXT, hint TEXT DEFAULT '', required INTEGER NOT NULL DEFAULT 0 CHECK (required IN (0,1)), ordre INTEGER DEFAULT 0, card_group TEXT DEFAULT 'Général', filled_by TEXT NOT NULL DEFAULT 'demandeur' CHECK (filled_by IN ('demandeur','validator')), validator_step TEXT DEFAULT '', visibility TEXT NOT NULL DEFAULT 'all' CHECK (visibility IN ('all','owner_only')), `condition` TEXT DEFAULT '', FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE)";
        $pdo->exec('DROP TABLE IF EXISTS form_fields_new');
        $pdo->exec($sql);
        // Mapping par NOM de colonne, jamais positionnel : un `SELECT *`
        // copierait les valeurs dans l'ordre physique de la table source, qui
        // peut différer de l'ordre de `form_fields_new` et permuter
        // silencieusement required/ordre/card_group/hint (cf. v47).
        $columns = 'id, form_id, label, field_type, field_name, options, hint, '
            . 'required, ordre, card_group, filled_by, validator_step, visibility, `condition`';
        $pdo->exec("INSERT INTO form_fields_new ({$columns}) SELECT {$columns} FROM form_fields");
        $pdo->exec('DROP TABLE form_fields');
        $pdo->exec('ALTER TABLE form_fields_new RENAME TO form_fields');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ff_form ON form_fields(form_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ff_filled_by ON form_fields(form_id,filled_by)');
        $pdo->exec("INSERT INTO schema_version (version, applied_at) VALUES (44, datetime('now'))");
        $pdo->exec('COMMIT');
        return 44;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK');
        }
        throw $e;
    } finally {
        if ($foreignKeys) {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }
}
