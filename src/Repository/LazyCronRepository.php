<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Repository pour la table `lazy_cron` (exécution différée des tâches planifiées).
 *
 * Encapsule l'accès à PDO pour le service CronService — notamment
 * les transactions `BEGIN EXCLUSIVE` qui ne peuvent pas être faites
 * hors de la couche Repository (règle disallowed-calls).
 */
final class LazyCronRepository extends BaseRepository
{
    /**
     * Retourne la valeur de last_run pour une tâche, ou null si la tâche n'existe pas.
     */
    public function findLastRun(string $key): ?string
    {
        $stmt = $this->pdo()->prepare('SELECT last_run FROM lazy_cron WHERE task_key = ?');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        if (in_array($val, [false, null, ''], true)) {
            return null;
        }
        return (string) $val;
    }

    /**
     * Tente de "claim" une tâche cron de façon atomique (BEGIN EXCLUSIVE).
     *
     * Étapes :
     *   1. BEGIN EXCLUSIVE (verrouille la DB en écriture)
     *   2. SELECT last_run actuel
     *   3. Si last_run existe et que l'intervalle n'est pas écoulé → COMMIT + retourner non-due
     *   4. Sinon : capturer prev_last_run (pour revert éventuel), UPSERT avec run_count+1, COMMIT
     *
     * @return array{claimed: bool, prev_last_run: string|null}
     */
    public function tryClaimTask(string $key, string $newLastRun, int $interval, int $nowTs): array
    {
        $pdo = $this->pdo();
        $pdo->exec('BEGIN EXCLUSIVE');
        try {
            $stmt = $pdo->prepare('SELECT last_run FROM lazy_cron WHERE task_key = ?');
            $stmt->execute([$key]);
            $lastRun = $stmt->fetchColumn();

            if (!in_array($lastRun, [false, null, ''], true)) {
                $ts = self::parseDbDatetime((string) $lastRun);
                if ($ts !== null && ($nowTs - $ts) < $interval) {
                    $pdo->exec('COMMIT');
                    return ['claimed' => false, 'prev_last_run' => null];
                }
            }

            $prev = (in_array($lastRun, [false, null, ''], true)) ? null : (string) $lastRun;

            $pdo->prepare('INSERT OR REPLACE INTO lazy_cron (task_key, last_run, run_count) VALUES (?, ?, COALESCE((SELECT run_count FROM lazy_cron WHERE task_key = ?), 0) + 1)')
                ->execute([$key, $newLastRun, $key]);

            $pdo->exec('COMMIT');
            return ['claimed' => true, 'prev_last_run' => $prev];
        } catch (\Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (\Throwable) {
                // @silent-ok: fallback cleanup — ignore rollback failure, original exception re-thrown
            }
            throw $e;
        }
    }

    public function deleteByKey(string $key): void
    {
        $this->execute('DELETE FROM lazy_cron WHERE task_key = ?', [$key]);
    }

    public function updateLastRun(string $key, string $lastRun): void
    {
        $this->execute('UPDATE lazy_cron SET last_run = ? WHERE task_key = ?', [$lastRun, $key]);
    }

    /**
     * Parse une datetime SQLite (format 'Y-m-d H:i:s') en timestamp Unix.
     * Contourne le bug PHP 8.4 où strtotime() retourne DateTimeImmutable.
     */
    public static function parseDbDatetime(string $datetime): ?int
    {
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $datetime, new \DateTimeZone('UTC'));
        if ($dt === false) {
            return null;
        }
        return $dt->getTimestamp();
    }
}
