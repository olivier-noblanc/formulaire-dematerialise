<?php

declare(strict_types=1);

namespace App\Repository\Traits;

/**
 * Trait regroupant les méthodes de statistiques et comptage de soumissions.
 *
 * Utilisé par SubmissionRepository.
 *
 * @method bool execute(string $sql, array<int, mixed> $params = [])
 * @method \PDO pdo()
 */
trait SubmissionStatsTrait
{
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
        /** @var array{avg_seconds: float|string|null}|null $result */
        $result = $this->fetchOne(
            "SELECT AVG(
                CAST(strftime('%s', s.closed_at) AS REAL) - CAST(strftime('%s', s.submitted_at) AS REAL)
            ) as avg_seconds
            FROM submissions s
            WHERE s.status = '" . \App\Enum\SubmissionStatus::Valide->value . "' AND s.closed_at IS NOT NULL"
        );
        return (float) ($result['avg_seconds'] ?? 0);
    }

    /**
     * @return array<int, array{day: string, cnt: int}>
     */
    public function getDailyCounts(int $days): array
    {
        /** @var array<int, array{day: string, cnt: int}> $result */
        $result = $this->fetchAll(
            "SELECT DATE(submitted_at) as day, COUNT(*) as cnt
             FROM submissions
             WHERE submitted_at >= datetime('now', '-' || ? || ' days')
             GROUP BY DATE(submitted_at)
             ORDER BY day DESC",
            [$days]
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
        /** @var list<array{period: string, total: int|string, valide: int|string, refuse: int|string, en_cours: int|string, avg_processing_seconds: float|string|null}> $result */
        $result = $this->fetchAll(
            "SELECT
                strftime(?, s.submitted_at) as period,
                COUNT(*) as total,
                SUM(CASE WHEN s.status = '" . \App\Enum\SubmissionStatus::Valide->value . "' THEN 1 ELSE 0 END) as valide,
                SUM(CASE WHEN s.status = '" . \App\Enum\SubmissionStatus::Refuse->value . "' THEN 1 ELSE 0 END) as refuse,
                SUM(CASE WHEN s.status = '" . \App\Enum\SubmissionStatus::EnCours->value . "' THEN 1 ELSE 0 END) as en_cours,
                AVG(CASE WHEN s.status = '" . \App\Enum\SubmissionStatus::Valide->value . "' AND s.closed_at IS NOT NULL
                    THEN CAST(strftime('%s', s.closed_at) AS REAL) - CAST(strftime('%s', s.submitted_at) AS REAL)
                    ELSE NULL END) as avg_processing_seconds
            FROM submissions s
            WHERE s.submitted_at >= datetime('now', ?)
            GROUP BY strftime(?, s.submitted_at)
            ORDER BY period DESC
            LIMIT ?",
            [$format, $interval, $format, $limit]
        );
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
        /** @var array{total: int|string, en_cours: int|string, valide: int|string, refuse: int|string, today: int|string, this_week: int|string, this_month: int|string}|null $result */
        $result = $this->fetchOne(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = '" . \App\Enum\SubmissionStatus::EnCours->value . "' THEN 1 ELSE 0 END) as en_cours,
                SUM(CASE WHEN status = '" . \App\Enum\SubmissionStatus::Valide->value . "' THEN 1 ELSE 0 END) as valide,
                SUM(CASE WHEN status = '" . \App\Enum\SubmissionStatus::Refuse->value . "' THEN 1 ELSE 0 END) as refuse,
                SUM(CASE WHEN DATE(submitted_at) = DATE('now') THEN 1 ELSE 0 END) as today,
                SUM(CASE WHEN submitted_at >= datetime('now', '-7 days') THEN 1 ELSE 0 END) as this_week,
                SUM(CASE WHEN submitted_at >= datetime('now', '-30 days') THEN 1 ELSE 0 END) as this_month
            FROM submissions"
        );
        return $result ?? ['total' => 0, \App\Enum\SubmissionStatus::EnCours->value => 0, \App\Enum\SubmissionStatus::Valide->value => 0, \App\Enum\SubmissionStatus::Refuse->value => 0, 'today' => 0, 'this_week' => 0, 'this_month' => 0];
    }

    /**
     * Avg processing time en secondes pour les soumissions validées.
     * Utilisé par StatsService::getGlobalStats().
     */
    public function getAvgProcessingSeconds(): float
    {
        /** @var array{avg: float|string|null}|null $result */
        $result = $this->fetchOne(
            "SELECT AVG(CAST(strftime('%s', closed_at) AS REAL) - CAST(strftime('%s', submitted_at) AS REAL)) as avg
            FROM submissions WHERE status = '" . \App\Enum\SubmissionStatus::Valide->value . "' AND closed_at IS NOT NULL"
        );
        return (float) ($result['avg'] ?? 0);
    }

    /**
     * Stats par formulaire (joins submissions) pour StatsService::getFormStats().
     *
     * @return list<array{label: string, slug: string, total: int|string, en_cours: int|string, valide: int|string, refuse: int|string, avg_seconds: float|string|null}>
     */
    public function getFormStats(): array
    {
        /** @var list<array{label: string, slug: string, total: int|string, en_cours: int|string, valide: int|string, refuse: int|string, avg_seconds: float|string|null}> $result */
        $result = $this->fetchAll(
            "SELECT f.label, f.slug, COUNT(s.id) as total,
                   SUM(CASE WHEN s.status = '" . \App\Enum\SubmissionStatus::EnCours->value . "' THEN 1 ELSE 0 END) as en_cours,
                   SUM(CASE WHEN s.status = '" . \App\Enum\SubmissionStatus::Valide->value . "' THEN 1 ELSE 0 END) as valide,
                   SUM(CASE WHEN s.status = '" . \App\Enum\SubmissionStatus::Refuse->value . "' THEN 1 ELSE 0 END) as refuse,
                   AVG(CASE WHEN s.status = '" . \App\Enum\SubmissionStatus::Valide->value . "' AND s.closed_at IS NOT NULL
                       THEN CAST(strftime('%s', s.closed_at) AS REAL) - CAST(strftime('%s', s.submitted_at) AS REAL)
                       ELSE NULL END) as avg_seconds
            FROM forms f
            LEFT JOIN submissions s ON s.form_id = f.id
            GROUP BY f.id
            ORDER BY total DESC"
        );
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
