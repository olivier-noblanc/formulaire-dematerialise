<?php

declare(strict_types=1);

namespace App\Repository\Traits;

/**
 * Trait regroupant les méthodes de statistiques et comptage de soumissions.
 *
 * Utilisé par SubmissionRepository.
 *
 * Référentiels de temps (audit 2026-09-17) : dans `submissions`,
 * `submitted_at` est écrit par PHP `date()` sous Europe/Paris (voir
 * config.php) alors que `closed_at` est en UTC (SQLite `datetime('now')` /
 * PHP `gmdate()`). Toute soustraction ou comparaison directe entre ces deux
 * colonnes mélange donc deux référentiels et fausse les durées ainsi que les
 * compteurs today/week/month. Ce trait :
 *  - calcule les durées via un helper PHP qui ramène les deux bornes au même
 *    référentiel UTC, avec DateTimeZone('Europe/Paris') — donc correct été/hiver
 *    et indépendant du fuseau système de la machine (contrairement au
 *    modificateur SQLite `'utc'`, dépendant du fuseau de l'OS) ;
 *  - compare `submitted_at` à des bornes calculées dans le même référentiel
 *    local pour les compteurs de période.
 *
 * @method bool execute(string $sql, array<int, mixed> $params = [])
 * @method \PDO pdo()
 */
trait SubmissionStatsTrait
{
    /**
     * Durée de traitement en secondes.
     *
     * `submitted_at` est en heure locale de Paris (PHP date() sous
     * Europe/Paris), `closed_at` en UTC (SQLite datetime('now') / PHP gmdate()).
     * Les deux bornes sont ramenées au même référentiel avant soustraction —
     * sans quoi la durée est sous-estimée de 1 à 2 h selon la saison.
     * Retourne null si une borne est absente, vide ou invalide (la ligne est
     * alors ignorée des moyennes au lieu de les fausser).
     */
    private static function processingSeconds(?string $submittedAt, ?string $closedAt): ?float
    {
        if ($submittedAt === null || $submittedAt === '' || $closedAt === null || $closedAt === '') {
            return null;
        }
        try {
            $start = new \DateTimeImmutable($submittedAt, new \DateTimeZone('Europe/Paris'));
            $end = new \DateTimeImmutable($closedAt, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
        return (float) ($end->getTimestamp() - $start->getTimestamp());
    }

    /**
     * Moyenne des durées de traitement, groupée par la clé SQL `grp`.
     *
     * @param list<array{grp: string, submitted_at: string, closed_at: string}> $rows
     * @return array<string, float> clé de groupe => moyenne en secondes
     */
    private static function averageProcessingByGroup(array $rows): array
    {
        $sums = [];
        $counts = [];
        foreach ($rows as $row) {
            $seconds = self::processingSeconds($row['submitted_at'], $row['closed_at']);
            if ($seconds === null) {
                continue;
            }
            $grp = $row['grp'];
            $sums[$grp] = ($sums[$grp] ?? 0.0) + $seconds;
            $counts[$grp] = ($counts[$grp] ?? 0) + 1;
        }
        $result = [];
        foreach ($sums as $grp => $sum) {
            $result[$grp] = $sum / (float) $counts[$grp];
        }
        return $result;
    }

    /**
     * @return array<int, array{status: string, cnt: int}>
     */
    public function getStatusCountsByForm(string $formId): array
    {
        /** @var array<int, array{status: string, cnt: int}> $result */
        $result = $this->fetchAll(
            'SELECT status, COUNT(*) as cnt FROM submissions WHERE form_id = ? GROUP BY status',
            [$formId]
        );
        return $result;
    }

    /**
     * @return array<int, array{status: string, cnt: int}>
     */
    public function getStatusCountsBySubmitter(string $email): array
    {
        /** @var array<int, array{status: string, cnt: int}> $result */
        $result = $this->fetchAll(
            'SELECT status, COUNT(*) as cnt FROM submissions WHERE submitted_by = ? GROUP BY status',
            [$email]
        );
        return $result;
    }

    public function countByForm(string $formId): int
    {
        /** @var array{cnt: int|string|null}|null $result */
        $result = $this->fetchOne('SELECT COUNT(*) as cnt FROM submissions WHERE form_id = ?', [$formId]);
        return (int) ($result['cnt'] ?? 0);
    }

    public function countAll(): int
    {
        /** @var array{cnt: int|string|null}|null $result */
        $result = $this->fetchOne('SELECT COUNT(*) as cnt FROM submissions');
        return (int) ($result['cnt'] ?? 0);
    }

    public function getAvgProcessingTime(): float
    {
        // Alias historique de getAvgProcessingSeconds() (MonitoringController) :
        // même calcul, une seule implémentation.
        return $this->getAvgProcessingSeconds();
    }

    /**
     * @return array<int, array{day: string, cnt: int}>
     */
    public function getDailyCounts(int $days): array
    {
        // submitted_at est en heure locale (Paris) → borne calculée dans le
        // même référentiel. `datetime('now')` (UTC) décalait la fenêtre de 1-2 h.
        $daysTs = strtotime("-{$days} days");
        $since = date('Y-m-d H:i:s', $daysTs !== false ? $daysTs : time());
        /** @var array<int, array{day: string, cnt: int}> $result */
        $result = $this->fetchAll(
            'SELECT DATE(submitted_at) as day, COUNT(*) as cnt
             FROM submissions
             WHERE submitted_at >= ?
             GROUP BY DATE(submitted_at)
             ORDER BY day DESC',
            [$since]
        );
        return $result;
    }

    public function countOldByRetention(int $retentionMonths): int
    {
        /** @var array{cnt: int|string|null}|null $result */
        $result = $this->fetchOne(
            "SELECT COUNT(*) as cnt FROM submissions WHERE status != '" . \App\Enum\SubmissionStatus::EnCours->value . "' AND closed_at < datetime('now', '-' || ? || ' months')",
            [$retentionMonths]
        );
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * @return array<string, int>
     */
    public function countByStatusForSubmitter(string $email): array
    {
        /** @var list<array{status: string, cnt: int|string}> $rows */
        $rows = $this->fetchAll(
            'SELECT status, COUNT(*) as cnt FROM submissions WHERE submitted_by = ? GROUP BY status',
            [$email]
        );
        $result = ['total' => 0, \App\Enum\SubmissionStatus::EnCours->value => 0, \App\Enum\SubmissionStatus::Valide->value => 0];
        foreach ($rows as $row) {
            $result['total'] += (int) $row['cnt'];
            if ($row['status'] === \App\Enum\SubmissionStatus::EnCours->value) {
                $result[\App\Enum\SubmissionStatus::EnCours->value] = (int) $row['cnt'];
            } elseif ($row['status'] === \App\Enum\SubmissionStatus::Valide->value) {
                $result[\App\Enum\SubmissionStatus::Valide->value] = (int) $row['cnt'];
            }
        }
        return $result;
    }

    /**
     * Nombre de soumissions en cours pour un demandeur donné.
     */
    public function countEnCoursBySubmitter(string $email): int
    {
        /** @var array{cnt: int}|null $result */
        $result = $this->fetchOne(
            "SELECT COUNT(*) as cnt FROM submissions
             WHERE submitted_by = ? AND status = '" . \App\Enum\SubmissionStatus::EnCours->value . "' AND closed_at IS NULL",
            [$email]
        );
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * Compte les soumissions actives (status = ?) pour un formulaire.
     */
    public function countActiveByFormAndStatus(string $formId, string $status): int
    {
        /** @var array{cnt: int|string|null}|null $result */
        $result = $this->fetchOne(
            'SELECT COUNT(*) as cnt FROM submissions WHERE form_id = ? AND status = ?',
            [$formId, $status]
        );
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * Stats agrégées par période (week/month/year) pour StatsService::getStatsByPeriod().
     *
     * @return list<array{period: string, total: int|string, valide: int|string, refuse: int|string, en_cours: int|string, avg_processing_seconds: float|string|null}>
     */
    public function getStatsByPeriod(string $format, string $interval, int $limit): array
    {
        // submitted_at est en heure locale (Paris) → borne dans le même
        // référentiel ; `datetime('now', ?)` (UTC) décalait la fenêtre.
        $sinceTs = strtotime($interval);
        $since = date('Y-m-d H:i:s', $sinceTs !== false ? $sinceTs : time());
        /** @var list<array{period: string, total: int|string, valide: int|string, refuse: int|string, en_cours: int|string}> $rows */
        $rows = $this->fetchAll(
            "SELECT
                strftime(?, s.submitted_at) as period,
                COUNT(*) as total,
                SUM(CASE WHEN s.status = '" . \App\Enum\SubmissionStatus::Valide->value . "' THEN 1 ELSE 0 END) as valide,
                SUM(CASE WHEN s.status = '" . \App\Enum\SubmissionStatus::Refuse->value . "' THEN 1 ELSE 0 END) as refuse,
                SUM(CASE WHEN s.status = '" . \App\Enum\SubmissionStatus::EnCours->value . "' THEN 1 ELSE 0 END) as en_cours
            FROM submissions s
            WHERE s.submitted_at >= ?
            GROUP BY strftime(?, s.submitted_at)
            ORDER BY period DESC
            LIMIT ?",
            [$format, $since, $format, $limit]
        );

        /** @var list<array{grp: string, submitted_at: string, closed_at: string}> $durationRows */
        $durationRows = $this->fetchAll(
            "SELECT strftime(?, s.submitted_at) as grp, s.submitted_at, s.closed_at
             FROM submissions s
             WHERE s.status = '" . \App\Enum\SubmissionStatus::Valide->value . "'
               AND s.closed_at IS NOT NULL AND s.submitted_at IS NOT NULL
               AND s.submitted_at >= ?",
            [$format, $since]
        );
        $avgByGroup = self::averageProcessingByGroup($durationRows);

        $result = [];
        foreach ($rows as $row) {
            $period = (string) $row['period'];
            $result[] = [
                'period' => $period,
                'total' => $row['total'],
                \App\Enum\SubmissionStatus::Valide->value => $row[\App\Enum\SubmissionStatus::Valide->value],
                \App\Enum\SubmissionStatus::Refuse->value => $row[\App\Enum\SubmissionStatus::Refuse->value],
                \App\Enum\SubmissionStatus::EnCours->value => $row[\App\Enum\SubmissionStatus::EnCours->value],
                'avg_processing_seconds' => $avgByGroup[$period] ?? null,
            ];
        }
        return $result;
    }

    /**
     * Stats globales agrégées (total, en_cours, valide, refuse, today, this_week, this_month)
     * pour StatsService::getGlobalStats(). Une seule requête.
     *
     * @return array{total: int|string, en_cours: int|string, valide: int|string, refuse: int|string, today: int|string, this_week: int|string, this_month: int|string}
     */
    public function getGlobalStatsCounts(): array
    {
        // submitted_at est en heure locale (Paris) : les bornes today/week/month
        // sont calculées dans ce référentiel. Les comparer à `datetime('now')`
        // (UTC) décalait les compteurs de 1-2 h (bascule de date autour de
        // minuit, fenêtre de 7/30 jours décalée).
        $today = date('Y-m-d');
        $weekAgoTs = strtotime('-7 days');
        $monthAgoTs = strtotime('-30 days');
        $weekAgo = date('Y-m-d H:i:s', $weekAgoTs !== false ? $weekAgoTs : time());
        $monthAgo = date('Y-m-d H:i:s', $monthAgoTs !== false ? $monthAgoTs : time());
        /** @var array{total: int|string, en_cours: int|string, valide: int|string, refuse: int|string, today: int|string, this_week: int|string, this_month: int|string}|null $result */
        $result = $this->fetchOne(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = '" . \App\Enum\SubmissionStatus::EnCours->value . "' THEN 1 ELSE 0 END) as en_cours,
                SUM(CASE WHEN status = '" . \App\Enum\SubmissionStatus::Valide->value . "' THEN 1 ELSE 0 END) as valide,
                SUM(CASE WHEN status = '" . \App\Enum\SubmissionStatus::Refuse->value . "' THEN 1 ELSE 0 END) as refuse,
                SUM(CASE WHEN DATE(submitted_at) = ? THEN 1 ELSE 0 END) as today,
                SUM(CASE WHEN submitted_at >= ? THEN 1 ELSE 0 END) as this_week,
                SUM(CASE WHEN submitted_at >= ? THEN 1 ELSE 0 END) as this_month
            FROM submissions",
            [$today, $weekAgo, $monthAgo]
        );
        return $result ?? ['total' => 0, \App\Enum\SubmissionStatus::EnCours->value => 0, \App\Enum\SubmissionStatus::Valide->value => 0, \App\Enum\SubmissionStatus::Refuse->value => 0, 'today' => 0, 'this_week' => 0, 'this_month' => 0];
    }

    /**
     * Avg processing time en secondes pour les soumissions validées.
     * Utilisé par StatsService::getGlobalStats().
     */
    public function getAvgProcessingSeconds(): float
    {
        /** @var list<array{submitted_at: string, closed_at: string}> $rows */
        $rows = $this->fetchAll(
            "SELECT submitted_at, closed_at FROM submissions
             WHERE status = '" . \App\Enum\SubmissionStatus::Valide->value . "'
               AND closed_at IS NOT NULL AND submitted_at IS NOT NULL"
        );
        $total = 0.0;
        $count = 0;
        foreach ($rows as $row) {
            $seconds = self::processingSeconds($row['submitted_at'], $row['closed_at']);
            if ($seconds === null) {
                continue;
            }
            $total += $seconds;
            $count++;
        }
        return $count > 0 ? $total / $count : 0.0;
    }

    /**
     * Stats par formulaire (joins submissions) pour StatsService::getFormStats().
     *
     * @return list<array{label: string, slug: string, total: int|string, en_cours: int|string, valide: int|string, refuse: int|string, avg_seconds: float|string|null}>
     */
    public function getFormStats(): array
    {
        /** @var list<array{id: string, label: string, slug: string, total: int|string, en_cours: int|string, valide: int|string, refuse: int|string}> $rows */
        $rows = $this->fetchAll(
            "SELECT f.id, f.label, f.slug, COUNT(s.id) as total,
                   SUM(CASE WHEN s.status = '" . \App\Enum\SubmissionStatus::EnCours->value . "' THEN 1 ELSE 0 END) as en_cours,
                   SUM(CASE WHEN s.status = '" . \App\Enum\SubmissionStatus::Valide->value . "' THEN 1 ELSE 0 END) as valide,
                   SUM(CASE WHEN s.status = '" . \App\Enum\SubmissionStatus::Refuse->value . "' THEN 1 ELSE 0 END) as refuse
            FROM forms f
            LEFT JOIN submissions s ON s.form_id = f.id
            GROUP BY f.id
            ORDER BY total DESC"
        );

        /** @var list<array{grp: string, submitted_at: string, closed_at: string}> $durationRows */
        $durationRows = $this->fetchAll(
            "SELECT s.form_id as grp, s.submitted_at, s.closed_at
             FROM submissions s
             WHERE s.status = '" . \App\Enum\SubmissionStatus::Valide->value . "'
               AND s.closed_at IS NOT NULL AND s.submitted_at IS NOT NULL"
        );
        $avgByGroup = self::averageProcessingByGroup($durationRows);

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'label' => (string) $row['label'],
                'slug' => (string) $row['slug'],
                'total' => $row['total'],
                \App\Enum\SubmissionStatus::EnCours->value => $row[\App\Enum\SubmissionStatus::EnCours->value],
                \App\Enum\SubmissionStatus::Valide->value => $row[\App\Enum\SubmissionStatus::Valide->value],
                \App\Enum\SubmissionStatus::Refuse->value => $row[\App\Enum\SubmissionStatus::Refuse->value],
                'avg_seconds' => $avgByGroup[(string) $row['id']] ?? null,
            ];
        }
        return $result;
    }

    public function getOldestSubmittedAt(): ?string
    {
        /** @var array{val: string|null}|null $result */
        $result = $this->fetchOne('SELECT MIN(submitted_at) as val FROM submissions');
        return $result !== null && $result['val'] !== null ? (string) $result['val'] : null;
    }

    public function getNewestSubmittedAt(): ?string
    {
        /** @var array{val: string|null}|null $result */
        $result = $this->fetchOne('SELECT MAX(submitted_at) as val FROM submissions');
        return $result !== null && $result['val'] !== null ? (string) $result['val'] : null;
    }
}
