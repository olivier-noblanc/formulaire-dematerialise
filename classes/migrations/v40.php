<?php
declare(strict_types=1);

/**
 * Migration v40 : normalisation des horodatages historiques Paris → UTC.
 *
 * P2-E (2026-09-18) : les colonnes `submissions.submitted_at` et les réglages
 * `settings.last_alert_check`/`last_remind_run` étaient écrits par PHP
 * `date()` sous Europe/Paris, alors que le reste de la base (tokens, mail_log,
 * closed_at…) est en UTC (SQLite `datetime('now')` / PHP `gmdate()`). Ce
 * double référentiel faussait durées, compteurs et dédoublonnages.
 *
 * Les writers passent désormais à `gmdate()` ; la présente migration convertit
 * l'historique déjà stocké en heure locale de Paris vers UTC. La conversion se
 * fait en PHP avec `DateTimeZone('Europe/Paris')` → `DateTimeZone('UTC')`,
 * donc correcte été/hiver (DST) — jamais un offset fixe.
 *
 * Atomicité : les UPDATE et le marquage `schema_version` sont dans une même
 * transaction, annulée (rollback) sur toute `Throwable`.
 * Idempotence / self-healing : si la version 40 est déjà marquée, aucun travail
 * n'est rejoué ; les chaînes non reconnues comme date SQL sont laissées telles
 * quelles (aucune donnée écrasée à l'aveugle).
 *
 * @package Migrations
 */

/**
 * Convertit une chaîne SQL 'Y-m-d H:i:s' interprétée en Europe/Paris vers UTC.
 *
 * Retourne null si la valeur n'est pas une date SQL reconnue : l'appelant la
 * laisse alors inchangée (une valeur invalide ne doit ni bloquer la migration
 * ni être silencieusement remplacée).
 */
function _migration_v40_paris_to_utc(string $value): ?string
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $value) !== 1) {
        return null;
    }
    try {
        return new \DateTimeImmutable($value, new \DateTimeZone('Europe/Paris'))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    } catch (\Exception) {
        return null;
    }
}

function apply_migration_v40(PDO $pdo, int $current_version): int {
    $needs_v40 = ($current_version < 40) || ($current_version >= 900);
    if (!$needs_v40) {
        return $current_version;
    }

    $ownsTransaction = !$pdo->inTransaction();
    try {
        $v40_stmt = $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 40");
        if ($v40_stmt === false) {
            throw new \RuntimeException('v40: COUNT query failed');
        }
        $v40_done = (int) $v40_stmt->fetchColumn();
        // Libérer le statement avant les écritures (règle SQLITE_LOCKED).
        $v40_stmt = null;
        if ($v40_done > 0) {
            return max($current_version, 40);
        }

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        // ── submissions.submitted_at : Paris → UTC ──────────────────────
        $subs_stmt = $pdo->query("SELECT id, submitted_at FROM submissions
                                  WHERE submitted_at IS NOT NULL AND submitted_at <> ''");
        if ($subs_stmt === false) {
            throw new \RuntimeException('v40: SELECT submissions failed');
        }
        /** @var list<array{id: string, submitted_at: string}> $subs */
        $subs = $subs_stmt->fetchAll(PDO::FETCH_ASSOC);
        $subs_stmt = null;

        $update_sub = $pdo->prepare('UPDATE submissions SET submitted_at = ? WHERE id = ?');
        foreach ($subs as $row) {
            $converted = _migration_v40_paris_to_utc((string) $row['submitted_at']);
            if ($converted !== null && $converted !== $row['submitted_at']) {
                $update_sub->execute([$converted, (string) $row['id']]);
            }
        }
        $update_sub = null;

        // ── settings.last_alert_check / last_remind_run : Paris → UTC ──
        $set_stmt = $pdo->query("SELECT key, value FROM settings
                                 WHERE key IN ('last_alert_check', 'last_remind_run')
                                   AND value IS NOT NULL AND value <> ''");
        if ($set_stmt === false) {
            throw new \RuntimeException('v40: SELECT settings failed');
        }
        /** @var list<array{key: string, value: string}> $settings */
        $settings = $set_stmt->fetchAll(PDO::FETCH_ASSOC);
        $set_stmt = null;

        $update_setting = $pdo->prepare('UPDATE settings SET value = ? WHERE key = ?');
        foreach ($settings as $row) {
            $converted = _migration_v40_paris_to_utc((string) $row['value']);
            if ($converted !== null && $converted !== $row['value']) {
                $update_setting->execute([$converted, (string) $row['key']]);
            }
        }
        $update_setting = null;

        $pdo->exec("INSERT INTO schema_version (version, applied_at) VALUES (40, datetime('now'))");

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return 40;
    } catch (\Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // @silent-ok: log-only — la migration sera retentée au prochain appel.
        error_log("Migration v40 failed: " . $e->getMessage());
        return $current_version;
    }
}