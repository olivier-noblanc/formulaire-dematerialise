<?php
declare(strict_types=1);

/** Migration v41: make form_fields.required strict and non-nullable. */
function apply_migration_v41(PDO $pdo, int $current_version): int
{
    if ($current_version >= 41 && $current_version < 900) {
        return $current_version;
    }

    $done = $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 41');
    if ($done === false) {
        throw new \RuntimeException('v41: schema_version preflight failed');
    }
    $alreadyApplied = (int) $done->fetchColumn();
    $done = null;
    if ($alreadyApplied > 0) {
        return max($current_version, 41);
    }

    // This must remain before every DROP/ALTER/RENAME/UPDATE.
    $preflight = $pdo->query("SELECT id, required, typeof(required) AS required_type,
            field_name, form_id, label
        FROM form_fields
        WHERE required IS NULL OR required NOT IN (0, 1)
        ORDER BY id LIMIT 10");
    if ($preflight === false) {
        throw new \RuntimeException('v41: required preflight query failed');
    }
    $invalidRows = $preflight->fetchAll(PDO::FETCH_ASSOC);
    $preflight = null;
    if ($invalidRows !== []) {
        $sample = array_map(static function (array $row): string {
            $required = $row['required'] === null ? 'NULL' : var_export($row['required'], true);
            $label = array_key_exists('label', $row) && $row['label'] !== null
                ? ', label=' . var_export($row['label'], true)
                : '';

            return 'id=' . var_export($row['id'], true)
                . ', required=' . $required
                . ', typeof(required)=' . var_export($row['required_type'], true)
                . ', field_name=' . var_export($row['field_name'], true)
                . ', form_id=' . var_export($row['form_id'], true)
                . $label;
        }, $invalidRows);
        throw new \RuntimeException('v41 blocked: invalid form_fields.required values (' . implode(', ', $sample) . ')');
    }

    $ownsTransaction = !$pdo->inTransaction();
    $foreignKeysStmt = $pdo->query('PRAGMA foreign_keys');
    if ($foreignKeysStmt === false) {
        throw new \RuntimeException('v41: foreign_keys state query failed');
    }
    $foreignKeysInitiallyEnabled = ((int) $foreignKeysStmt->fetchColumn()) === 1;
    $foreignKeysStmt = null;
    $foreignKeysChanged = false;
    try {
        // SQLite ignores PRAGMA foreign_keys changes inside a transaction. Set
        // it before BEGIN, and restore it only after COMMIT/ROLLBACK.
        if (!$ownsTransaction && $foreignKeysInitiallyEnabled) {
            throw new \RuntimeException('v41: cannot rebuild while an outer transaction has foreign_keys enabled');
        }
        if ($ownsTransaction && $foreignKeysInitiallyEnabled) {
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $foreignKeysChanged = true;
        }
        if ($ownsTransaction) {
            $pdo->exec('BEGIN IMMEDIATE');
        }
        $pdo->exec('DROP TABLE IF EXISTS form_fields_new');
        $pdo->exec("CREATE TABLE form_fields_new (
            id TEXT PRIMARY KEY NOT NULL,
            form_id TEXT NOT NULL,
            label TEXT NOT NULL,
            field_type TEXT NOT NULL DEFAULT 'text' CHECK (field_type IN ('text', 'email', 'date', 'select', 'checkbox', 'textarea', 'file')),
            field_name TEXT NOT NULL,
            options TEXT,
            hint TEXT DEFAULT '',
            required INTEGER NOT NULL DEFAULT 0 CHECK (required IN (0, 1)),
            ordre INTEGER DEFAULT 0,
            card_group TEXT DEFAULT 'Général',
            filled_by TEXT DEFAULT 'demandeur' CHECK (filled_by IN ('demandeur', 'validator')),
            validator_step TEXT DEFAULT '',
            visibility TEXT DEFAULT 'all' CHECK (visibility IN ('all', 'owner_only')),
            `condition` TEXT DEFAULT '',
            FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        )");
        $pdo->exec('INSERT INTO form_fields_new (id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group, filled_by, validator_step, visibility, `condition`) SELECT id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group, filled_by, validator_step, visibility, `condition` FROM form_fields');
        $pdo->exec('DROP TABLE form_fields');
        $pdo->exec('ALTER TABLE form_fields_new RENAME TO form_fields');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ff_form ON form_fields(form_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ff_filled_by ON form_fields(form_id, filled_by)');
        $fkCheck = $pdo->query('PRAGMA foreign_key_check');
        if ($fkCheck === false) {
            throw new \RuntimeException('v41: foreign_key_check failed');
        }
        $fkErrors = $fkCheck->fetchAll(PDO::FETCH_ASSOC);
        $fkCheck = null;
        if ($fkErrors !== []) {
            throw new \RuntimeException('v41: foreign_key_check reported violations');
        }
        $pdo->exec("INSERT INTO schema_version (version, applied_at) VALUES (41, datetime('now'))");
        if ($ownsTransaction) {
            $pdo->exec('COMMIT');
        }
        return 41;
    } catch (\Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK');
        }
        throw $e;
    } finally {
        if ($foreignKeysChanged && !$pdo->inTransaction()) {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }
}
