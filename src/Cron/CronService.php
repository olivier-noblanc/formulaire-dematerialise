<?php

declare(strict_types=1);

namespace App\Cron;

use App\Core\App;
use App\Core\Database;
use App\Repository\LazyCronRepository;

/**
 * Service de cron différé — exécution de tâches planifiées + handler POST.
 *
 * Extrait de lib/lazy_cron.php — run_lazy_cron, parse_db_datetime, handle_post.
 * Les fonctions globales dans lib/lazy_cron.php délèguent maintenant ici.
 *
 * Le paramètre $database est conservé pour la compatibilité ascendante
 * (bootstrap, tests) mais n'est plus utilisé directement — tout accès DB
 * passe par le LazyCronRepository injecté.
 */
final class CronService
{
    private static bool $running = false;

    public LazyCronRepository $lazyCronRepository;

    public function __construct(
        Database $database,
        ?LazyCronRepository $lazyCronRepository = null
    ) {
        $app = App::getInstance();
        $this->lazyCronRepository = $lazyCronRepository ?? ($app->has(LazyCronRepository::class) ? $app->get(LazyCronRepository::class) : new LazyCronRepository($database));
    }

    /**
     * Exécute les tâches planifiées dont l'intervalle est écoulé.
     *
     * Deux passes séparées : d'abord tous les INSERT (sans exec de fichiers),
     * puis l'exécution des callbacks/fichiers. Cela évite que les fichiers
     * requis (remind.php, alert_check.php) ne verrouillent la DB au milieu
     * des INSERT, ce qui casserait les tâches suivantes.
     *
     * B9 fix (audit 2026-07-26) : si le callback d'une tâche lève une exception,
     * on réécrit last_run à sa valeur précédente (ou DELETE la ligne si c'était
     * la première exécution). La tâche sera ainsi réessayée à la prochaine requête
     * au lieu d'attendre un nouvel intervalle complet. run_count reste incrémenté
     * pour garder une trace de la tentative échouée (acceptable pour KISS).
     */
    public function runLazyCron(): void
    {
        if (self::$running) {
            return;
        }
        self::$running = true;

        $nowTs = time();
        $tasks = [
            'remind'      => ['interval' => 3600,  'file' => __DIR__ . '/../../remind.php'],
            'alert_check' => ['interval' => 86400, 'file' => __DIR__ . '/../../alert_check.php'],
            'rgpd_purge'  => ['interval' => 86400, 'callback' => function (): void {
                \App\Core\App::getInstance()->get(\App\Rgpd\RgpdService::class)->autoPurge();
            }],
        ];

        $due = [];
        /** @var array<string, string|null> $prevLastRun capture la valeur avant update pour revert en cas d'échec */
        $prevLastRun = [];

        // Pass 1 : déterminer les tâches à exécuter et enregistrer en DB.
        // La lecture initiale (hors transaction) est utilisée pour filtrer
        // rapidement les tâches non dues — le claim atomique est fait par
        // le repository (tryClaimTask) qui gère la race condition via
        // BEGIN EXCLUSIVE.
        try {
            foreach ($tasks as $key => $task) {
                $lastRun = $this->lazyCronRepository->findLastRun($key);

                $should_run = false;
                if ($lastRun === null) {
                    $should_run = true;
                } else {
                    $ts = self::parseDbDatetime($lastRun);
                    if ($ts === null) {
                        $should_run = true;
                    } elseif (($nowTs - $ts) >= $task['interval']) {
                        $should_run = true;
                    }
                }

                if (!$should_run) {
                    continue;
                }

                try {
                    $claim = $this->lazyCronRepository->tryClaimTask(
                        $key,
                        gmdate('Y-m-d H:i:s', $nowTs),
                        $task['interval'],
                        $nowTs
                    );
                    if (!$claim['claimed']) {
                        continue;
                    }
                    // B9 : capturer la valeur précédente pour revert en cas d'échec du callback
                    $prevLastRun[$key] = $claim['prev_last_run'];
                    $due[] = $key;
                } catch (\PDOException $e) {
                    // @silent-ok: log-only for background cron task
                    if (str_contains($e->getMessage(), 'busy')) {
                        continue;
                    }
                    if (str_contains($e->getMessage(), 'locked')) {
                        continue;
                    }
                    error_log("lazy_cron error for $key: " . $e->getMessage());
                    continue;
                }
            }
        } catch (\Throwable $e) {
            // @silent-ok: log-only for background cron task
            error_log('Lazy cron fatal (pass 1): ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }

        // Pass 2 : exécuter les callbacks/fichiers des tâches enregistrées
        foreach ($due as $key) {
            $task = $tasks[$key];
            $callbackFailed = false;
            try {
                $GLOBALS['_lazy_cron_running'] = true;
                ob_start();
                if (isset($task['callback']) && is_callable($task['callback'])) {
                    ($task['callback'])();
                } elseif (array_key_exists('file', $task)) {
                    require_once $task['file'];
                }
                ob_end_clean();
                $GLOBALS['_lazy_cron_running'] = false;
            } catch (\Throwable $e) {
                // @silent-ok: log-only for background cron callback failure
                ob_end_clean();
                $GLOBALS['_lazy_cron_running'] = false;
                $callbackFailed = true;
                error_log("Lazy cron error ({$key}): " . $e->getMessage());
            }

            // B9 fix : si le callback a échoué, revert last_run pour permettre
            // une nouvelle tentative à la prochaine requête au lieu d'attendre
            // un intervalle complet. run_count reste incrémenté (trace de tentative).
            if ($callbackFailed) {
                $prev = $prevLastRun[$key] ?? null;
                try {
                    if ($prev === null) {
                        // Première exécution qui a échoué → supprimer la ligne pour
                        // que should_run=true au prochain passage.
                        $this->lazyCronRepository->deleteByKey($key);
                    } else {
                        // Écrire l'ancienne valeur (assez ancienne pour que la tâche
                        // soit de nouveau considérée due au prochain passage).
                        $this->lazyCronRepository->updateLastRun($key, $prev);
                    }
                } catch (\Throwable $revertErr) {
                    // @silent-ok: log-only for background cron revert failure
                    error_log("Lazy cron revert error ({$key}): " . $revertErr->getMessage());
                }
            }
        }
    }

    /**
     * Parse une datetime SQLite (format 'Y-m-d H:i:s') en timestamp Unix.
     * Contourne le bug PHP 8.4 où strtotime() retourne DateTimeImmutable.
     */
    public static function parseDbDatetime(string $datetime): ?int
    {
        return LazyCronRepository::parseDbDatetime($datetime);
    }
}
