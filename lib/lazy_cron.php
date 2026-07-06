<?php
declare(strict_types=1);

/**
 * Lazy cron — deferred task execution + POST handler hook.
 *
 * @package lib
 */

function run_lazy_cron(PDO $pdo): void {
    static $running = false;
    if ($running) return;
    $running = true;

    $now = time();
    $tasks = [
        'remind'      => ['interval' => 3600,  'file' => __DIR__ . '/../remind.php'],
        'alert_check' => ['interval' => 86400, 'file' => __DIR__ . '/../alert_check.php'],
        // v10.0.9 — Purge RGPD automatique (toutes les 24h)
        // Supprime/anonymise les soumissions de plus de N mois (configurable
        // via setting retention_months, défaut 24). Conformité RGPD article 5.1.e
        'rgpd_purge'  => ['interval' => 86400, 'callback' => 'rgpd_auto_purge'],
    ];

    try {
        foreach ($tasks as $key => $task) {
            $stmt = $pdo->prepare("SELECT last_run FROM lazy_cron WHERE task_key = ?");
            $stmt->execute([$key]);
            $last_run = $stmt->fetchColumn();

            $should_run = false;
            if ($last_run === false || $last_run === null || $last_run === '') {
                $should_run = true;
            } else {
                // Parser manuellement au lieu de strtotime (bug PHP 8.4)
                $ts = parse_db_datetime((string)$last_run);
                if ($ts === null) {
                    $should_run = true;
                } elseif (($now - $ts) >= $task['interval']) {
                    $should_run = true;
                }
            }

            if (!$should_run) continue;

            try {
                $pdo->exec('BEGIN EXCLUSIVE');
                $stmt2 = $pdo->prepare("SELECT last_run FROM lazy_cron WHERE task_key = ?");
                $stmt2->execute([$key]);
                $last_run2 = $stmt2->fetchColumn();
                if ($last_run2 !== false && $last_run2 !== null && $last_run2 !== '') {
                    $ts2 = parse_db_datetime((string)$last_run2);
                    if ($ts2 !== null && ($now - $ts2) < $task['interval']) {
                        $pdo->exec('COMMIT');
                        continue;
                    }
                }
                $pdo->prepare("INSERT OR REPLACE INTO lazy_cron (task_key, last_run, run_count) VALUES (?, ?, COALESCE((SELECT run_count FROM lazy_cron WHERE task_key = ?), 0) + 1)")
                    ->execute([$key, gmdate('Y-m-d H:i:s', $now), $key]);
                $pdo->exec('COMMIT');
            } catch (PDOException $e) {
                try { $pdo->exec('ROLLBACK'); } catch (\Throwable $re) {}
                if (strpos($e->getMessage(), 'busy') !== false || strpos($e->getMessage(), 'locked') !== false) continue;
                error_log("lazy_cron error for $key: " . $e->getMessage());
                continue;
            }

            try {
                $GLOBALS['_lazy_cron_running'] = true;
                ob_start();
                if (isset($task['callback']) && is_callable($task['callback'])) {
                    // v10.0.9 — Callback (fonction PHP directe, pas un fichier)
                    ($task['callback'])();
                } elseif (array_key_exists('file', $task)) {
                    require $task['file'];
                }
                ob_end_clean();
                $GLOBALS['_lazy_cron_running'] = false;
            } catch (\Throwable $e) {
                $GLOBALS['_lazy_cron_running'] = false;
                error_log("Lazy cron error ({$key}): " . $e->getMessage());
            }
        }
    } catch (\Throwable $e) {
        error_log("Lazy cron fatal: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    }
}

/**
 * Parse une datetime SQLite (format 'Y-m-d H:i:s') en timestamp Unix.
 * Contourne le bug PHP 8.4 où strtotime() retourne DateTimeImmutable.
 */
function parse_db_datetime(string $datetime): ?int {
    $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $datetime, new \DateTimeZone('UTC'));
    if ($dt === false) return null;
    return $dt->getTimestamp();
}

function handle_post(): ?string {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return null;
    if (!rate_limit_check('handle_post', 30, 60)) {
        render_error_page(429, 'Trop de requêtes', 'Vous avez effectué trop de requêtes en peu de temps.');
    }
    require_csrf();
    return $_POST['action'] ?? null;
}
