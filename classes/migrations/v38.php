<?php
declare(strict_types=1);

use App\Enum\MailStatus;

/**
 * Migration v38: Outbox SMTP write-ahead durable.
 *
 * mail_log devient une vraie file d'attente durable : le corps HTML complet est
 * écrit AVANT l'appel SMTP (write-ahead), ce qui permet au worker de rejeu de
 * renvoyer le message si le process meurt pendant (ou juste après) l'envoi.
 *
 * SQLite ne supporte pas ALTER TABLE ADD COLUMN avec contrainte CHECK ni
 * l'élargissement d'un CHECK existant → rebuild de table, comme v31.
 *
 * Colonnes ajoutées :
 *   - body_html     TEXT NULL   — corps HTML complet (RGPD : purgé/anonymisé)
 *   - attempts      INTEGER NOT NULL DEFAULT 0 — nombre d'essais d'envoi
 *   - next_retry_at TEXT NULL   — prochaine tentative planifiée (UTC)
 *   - status élargi : CHECK (status IN ('pending','sent','error','failed','blocked','dry_run'))
 *     (liste de référence unique : App\Enum\MailStatus::values())
 *
 * IMPORTANT : libérer TOUS les PDOStatement avant le DDL de rebuild (AGENTS.md —
 * SQLITE_LOCKED intra-processus), et ne marquer schema_version QU'APRÈS la
 * validation FK (leçon P0-7 / v36).
 *
 * @package Migrations
 */

function apply_migration_v38(PDO $pdo, int $current_version): int {
    $needs_v38 = ($current_version < 38) || ($current_version >= 900);
    if (!$needs_v38) {
        return $current_version;
    }

    try {
        $v38_stmt = $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 38");
        if ($v38_stmt === false) {
            throw new \RuntimeException('v38: COUNT query failed');
        }
        $v38_done = (int) $v38_stmt->fetchColumn();
        // Libérer le statement avant le prochain DDL (règle SQLITE_LOCKED).
        $v38_stmt = null;
        if ($v38_done > 0) {
            return max($current_version, 38);
        }

        $dbListStmt = $pdo->query('PRAGMA database_list');
        if ($dbListStmt === false) {
            throw new \RuntimeException('v38: PRAGMA database_list failed');
        }
        $dbPathRaw = $dbListStmt->fetchColumn(2);
        $dbListStmt = null;
        if ($dbPathRaw === false || $dbPathRaw === null) {
            throw new \RuntimeException('v38: PRAGMA database_list returned no path');
        }
        $dbPath = (string) $dbPathRaw;

        $rebuild = new PDO('sqlite:' . $dbPath);
        $rebuild->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $rebuild->exec('PRAGMA busy_timeout = 10000');
        $rebuild->exec('PRAGMA foreign_keys = OFF');

        // ── Self-healing : nettoyer mail_log_new orpheline d'une panne précédente ──
        $hasOldStmt = $rebuild->query("SELECT name FROM sqlite_master WHERE type='table' AND name='mail_log'");
        $hasOld = $hasOldStmt !== false && $hasOldStmt->fetchColumn() !== false;
        $hasOldStmt = null;

        $hasNewStmt = $rebuild->query("SELECT name FROM sqlite_master WHERE type='table' AND name='mail_log_new'");
        $hasNew = $hasNewStmt !== false && $hasNewStmt->fetchColumn() !== false;
        $hasNewStmt = null;

        if (!$hasOld && $hasNew) {
            $rebuild->exec('ALTER TABLE mail_log_new RENAME TO mail_log');
        } elseif ($hasOld && $hasNew) {
            $rebuild->exec('DROP TABLE mail_log_new');
        }

        // Liste fermée des statuts : source unique App\Enum\MailStatus.
        $statusList = implode(', ', array_map(
            static fn(string $s): string => "'" . $s . "'",
            MailStatus::values()
        ));

        // ── Rebuild mail_log : nouvelles colonnes + CHECK élargi ──
        $rebuild->exec('DROP TABLE IF EXISTS mail_log_new');
        $rebuild->exec("CREATE TABLE mail_log_new (
            id TEXT PRIMARY KEY,
            created_at TEXT NOT NULL,
            recipient TEXT NOT NULL,
            subject TEXT NOT NULL,
            body_html TEXT,
            status TEXT NOT NULL CHECK (status IN ({$statusList})),
            error_message TEXT DEFAULT '',
            smtp_log TEXT DEFAULT '',
            attempts INTEGER NOT NULL DEFAULT 0,
            next_retry_at TEXT,
            actor TEXT DEFAULT '',
            ip TEXT DEFAULT ''
        )");
        // Les colonnes nouvelles ont des DEFAULT : on liste explicitement les
        // colonnes communes (l'ancienne table n'a ni body_html, ni attempts,
        // ni next_retry_at).
        $rebuild->exec('INSERT INTO mail_log_new (id, created_at, recipient, subject, status, error_message, smtp_log, actor, ip)
                        SELECT id, created_at, recipient, subject, status, error_message, smtp_log, actor, ip FROM mail_log');
        $rebuild->exec('DROP TABLE mail_log');
        $rebuild->exec('ALTER TABLE mail_log_new RENAME TO mail_log');
        $rebuild->exec('CREATE INDEX IF NOT EXISTS idx_mail_log_created_at ON mail_log(created_at DESC)');
        $rebuild->exec('CREATE INDEX IF NOT EXISTS idx_mail_log_recipient ON mail_log(recipient)');
        $rebuild->exec('CREATE INDEX IF NOT EXISTS idx_mail_log_status ON mail_log(status)');
        $rebuild->exec('CREATE INDEX IF NOT EXISTS idx_mail_log_next_retry ON mail_log(status, next_retry_at)');

        $rebuild->exec('PRAGMA foreign_keys = ON');
        $fkStmt = $rebuild->query('PRAGMA foreign_key_check');
        $fkErrors = $fkStmt !== false ? $fkStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $fkStmt = null;

        $rebuild = null;

        if ($fkErrors !== []) {
            throw new \RuntimeException('v38: FK integrity broken: ' . json_encode($fkErrors));
        }

        // P0-7 : marquer la version APRÈS validation FK (jamais avant).
        $pdo->exec("INSERT INTO schema_version (version, applied_at) VALUES (38, datetime('now'))");

        return 38;
    } catch (\PDOException $e) {
        // @silent-ok: log-only — la migration sera retentée au prochain appel
        error_log("Migration v38 failed: " . $e->getMessage());
        return $current_version;
    }
}