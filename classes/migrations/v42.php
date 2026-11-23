<?php
declare(strict_types=1);

/** Strict, non-nullable 0/1 flags for forms, steps and alert rules. */
function apply_migration_v42(PDO $pdo, int $current_version): int
{
    if ($current_version >= 42 && $current_version < 900) return $current_version;
    $done = $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 42');
    if ($done === false) throw new RuntimeException('v42: version query failed');
    $already = (int) $done->fetchColumn(); $done = null;
    if ($already > 0) return max($current_version, 42);

    foreach (['forms', 'steps', 'alert_rules'] as $table) {
        $q = $pdo->query("SELECT id, typeof(actif) AS actif_type, actif FROM {$table} WHERE actif IS NULL OR actif NOT IN (0, 1) LIMIT 10");
        if ($q === false) throw new RuntimeException('v42: preflight query failed for ' . $table);
        $bad = $q->fetchAll(PDO::FETCH_ASSOC); $q = null;
        if ($bad !== []) {
            throw new RuntimeException('v42 blocked: invalid ' . $table . '.actif values');
        }
    }
    $fkQuery = $pdo->query('PRAGMA foreign_keys');
    if ($fkQuery === false) throw new RuntimeException('v42: foreign key query failed');
    $fk = (int) $fkQuery->fetchColumn() === 1; $fkQuery = null;
    $pdo->exec('PRAGMA foreign_keys = OFF');
    try {
        $pdo->exec('BEGIN IMMEDIATE');
        foreach (['forms', 'steps', 'alert_rules'] as $table) {
            $sqlQuery = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$table}'");
            if ($sqlQuery === false) throw new RuntimeException('v42: schema query failed for ' . $table);
            $sql = (string) $sqlQuery->fetchColumn(); $sqlQuery = null;
            if ($sql === '') throw new RuntimeException('v42: missing table ' . $table);
            $new = $table . '_new';
            $strict = preg_replace('/actif\s+INTEGER\s+DEFAULT\s+1(?:\s+CHECK\s*\(actif\s+IN\s*\(0,\s*1\)\))?/i', 'actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1))', $sql);
            if (!is_string($strict)) throw new RuntimeException('v42: cannot rewrite ' . $table);
            $strict = preg_replace('/\b' . preg_quote($table, '/') . '\b/', $new, $strict, 1);
            if (!is_string($strict)) throw new RuntimeException('v42: cannot rename ' . $table);
            $pdo->exec('DROP TABLE IF EXISTS ' . $new);
            $pdo->exec($strict);
            $pdo->exec('INSERT INTO ' . $new . ' SELECT * FROM ' . $table);
            $pdo->exec('DROP TABLE ' . $table);
            $pdo->exec('ALTER TABLE ' . $new . ' RENAME TO ' . $table);
        }
        $fkCheckQuery = $pdo->query('PRAGMA foreign_key_check');
        if ($fkCheckQuery === false) throw new RuntimeException('v42: foreign key check failed');
        $fkCheck = $fkCheckQuery->fetchAll(PDO::FETCH_ASSOC); $fkCheckQuery = null;
        if ($fkCheck !== []) throw new RuntimeException('v42: foreign key check failed');
        $pdo->exec("INSERT INTO schema_version (version, applied_at) VALUES (42, datetime('now'))");
        $pdo->exec('COMMIT');
        return 42;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->exec('ROLLBACK');
        throw $e;
    } finally {
        if ($fk) $pdo->exec('PRAGMA foreign_keys = ON');
    }
}
