<?php

declare(strict_types=1);

namespace App\Repository;

final class LazyCronRepository extends BaseRepository
{
    /**
     * Get the last_run timestamp for a task.
     */
    public function getLastRun(string $taskKey): ?string
    {
        $result = $this->fetchOne('SELECT last_run FROM lazy_cron WHERE task_key = ?', [$taskKey]);
        if ($result === null || $result['last_run'] === null || $result['last_run'] === '') {
            return null;
        }
        return (string) $result['last_run'];
    }

    /**
     * Record a cron run: INSERT OR REPLACE with incremented run_count.
     */
    public function recordRun(string $taskKey, string $now): void
    {
        $this->execute(
            'INSERT OR REPLACE INTO lazy_cron (task_key, last_run, run_count) VALUES (?, ?, COALESCE((SELECT run_count FROM lazy_cron WHERE task_key = ?), 0) + 1)',
            [$taskKey, $now, $taskKey]
        );
    }

    /**
     * Begin an EXCLUSIVE transaction (SQLite-specific).
     */
    public function beginExclusive(): void
    {
        $this->pdo()->exec('BEGIN EXCLUSIVE');
    }

    /**
     * Commit the current transaction.
     */
    public function commit(): void
    {
        $this->pdo()->exec('COMMIT');
    }

    /**
     * Roll back the current transaction.
     */
    public function rollback(): void
    {
        $this->pdo()->exec('ROLLBACK');
    }
}
