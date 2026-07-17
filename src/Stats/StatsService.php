<?php

declare(strict_types=1);

namespace App\Stats;

use App\Core\Database;
use PDO;

/**
 * Service de statistiques et recherche plein texte.
 */
final readonly class StatsService
{
    public function __construct(private Database $database)
    {
    }

    /**
     * Recherche plein texte dans les soumissions.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array{
     *   id: string,
     *   form_id: string,
     *   data: string,
     *   submitted_by: string,
     *   submitted_at: string,
     *   closed_at: string|null,
     *   status: string,
     *   admin_comment: string,
     *   form_label: string,
     *   form_slug: string,
     *   deadline_field: string
     * }>
     */
    public function searchSubmissions(string $query, array $filters = []): array
    {
        $pdo = $this->database->getPdo();
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $query = mb_substr($query, 0, 200);

        $where = ['1=1'];
        $params = [];

        $where[] = '(s.submitted_by LIKE ? OR s.data LIKE ? OR f.label LIKE ?)';
        $searchTerm = '%' . $query . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;

        if (!empty($filters['status'])) {
            $where[] = 's.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['form_id'])) {
            $where[] = 's.form_id = ?';
            $params[] = $filters['form_id'];
        }

        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare("
            SELECT s.*, f.label as form_label, f.slug as form_slug, f.deadline_field
            FROM submissions s
            JOIN forms f ON f.id = s.form_id
            WHERE $whereSql
            ORDER BY s.submitted_at DESC
            LIMIT 100
        ");
        $stmt->execute($params);
        /** @var array<int, array{id: string, form_id: string, data: string, submitted_by: string, submitted_at: string, closed_at: string|null, status: string, admin_comment: string, form_label: string, form_slug: string, deadline_field: string}> $result */
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $result;
    }

    /**
     * Statistiques par période.
     *
     * @return array<int, array{
     *   period: string,
     *   total: int,
     *   valide: int,
     *   refuse: int,
     *   en_cours: int,
     *   avg_processing_seconds: float|null
     * }>
     */
    public function getStatsByPeriod(string $period = 'month', int $limit = 12): array
    {
        $pdo = $this->database->getPdo();

        switch ($period) {
            case 'week':
                $format = '%Y-W%W';
                $interval = '-12 weeks';
                break;
            case 'year':
                $format = '%Y';
                $interval = '-5 years';
                break;
            default: // month
                $format = '%Y-%m';
                $interval = '-12 months';
        }

        $stmt = $pdo->prepare("
            SELECT
                strftime(?, s.submitted_at) as period,
                COUNT(*) as total,
                SUM(CASE WHEN s.status = 'valide' THEN 1 ELSE 0 END) as valide,
                SUM(CASE WHEN s.status = 'refuse' THEN 1 ELSE 0 END) as refuse,
                SUM(CASE WHEN s.status = 'en_cours' THEN 1 ELSE 0 END) as en_cours,
                AVG(CASE WHEN s.status = 'valide' AND s.closed_at IS NOT NULL
                    THEN CAST(strftime('%s', s.closed_at) AS REAL) - CAST(strftime('%s', s.submitted_at) AS REAL)
                    ELSE NULL END) as avg_processing_seconds
            FROM submissions s
            WHERE s.submitted_at >= datetime('now', ?)
            GROUP BY strftime(?, s.submitted_at)
            ORDER BY period DESC
            LIMIT ?
        ");
        $stmt->execute([$format, $interval, $format, $limit]);
        /** @var array<int, array{period: string, total: int, valide: int, refuse: int, en_cours: int, avg_processing_seconds: float|null}> $result */
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $result;
    }

    /**
     * Statistiques globales pour le dashboard.
     *
     * @return array{
     *   total: int,
     *   en_cours: int,
     *   valide: int,
     *   refuse: int,
     *   today: int,
     *   this_week: int,
     *   this_month: int,
     *   avg_days: float,
     *   tokens_pending: int,
     *   attachments_count: int,
     *   attachments_size: int,
     *   taux_validation: float
     * }
     */
    public function getGlobalStats(): array
    {
        $pdo = $this->database->getPdo();

        $rowStmt = $pdo->query("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'en_cours' THEN 1 ELSE 0 END) as en_cours,
                SUM(CASE WHEN status = 'valide' THEN 1 ELSE 0 END) as valide,
                SUM(CASE WHEN status = 'refuse' THEN 1 ELSE 0 END) as refuse,
                SUM(CASE WHEN DATE(submitted_at) = DATE('now') THEN 1 ELSE 0 END) as today,
                SUM(CASE WHEN submitted_at >= datetime('now', '-7 days') THEN 1 ELSE 0 END) as this_week,
                SUM(CASE WHEN submitted_at >= datetime('now', '-30 days') THEN 1 ELSE 0 END) as this_month
            FROM submissions
        ");
        assert($rowStmt !== false);
        $row = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $tokensStmt = $pdo->query('SELECT COUNT(*) FROM tokens WHERE done_at IS NULL');
        assert($tokensStmt !== false);
        $attachmentsCountStmt = $pdo->query('SELECT COUNT(*) FROM attachments');
        assert($attachmentsCountStmt !== false);
        $attachmentsSizeStmt = $pdo->query('SELECT COALESCE(SUM(file_size), 0) FROM attachments');
        assert($attachmentsSizeStmt !== false);
        $stats = [
            'total' => (int) ($row['total'] ?? 0),
            'en_cours' => (int) ($row['en_cours'] ?? 0),
            'valide' => (int) ($row['valide'] ?? 0),
            'refuse' => (int) ($row['refuse'] ?? 0),
            'today' => (int) ($row['today'] ?? 0),
            'this_week' => (int) ($row['this_week'] ?? 0),
            'this_month' => (int) ($row['this_month'] ?? 0),
            'avg_days' => 0,
            'tokens_pending' => (int) $tokensStmt->fetchColumn(),
            'attachments_count' => (int) $attachmentsCountStmt->fetchColumn(),
            'attachments_size' => (int) $attachmentsSizeStmt->fetchColumn(),
        ];

        $avgStmt = $pdo->query("
            SELECT AVG(CAST(strftime('%s', closed_at) AS REAL) - CAST(strftime('%s', submitted_at) AS REAL))
            FROM submissions WHERE status = 'valide' AND closed_at IS NOT NULL
        ");
        assert($avgStmt !== false);
        $stats['avg_days'] = round((float) ($avgStmt->fetchColumn() ?: 0) / 86400, 1);

        $stats['taux_validation'] = $stats['total'] > 0
            ? round(($stats['valide'] / $stats['total']) * 100, 1)
            : 0;

        return $stats;
    }

    /**
     * @return array<int, array{
     *   label: string,
     *   slug: string,
     *   total: int,
     *   en_cours: int,
     *   valide: int,
     *   refuse: int,
     *   avg_seconds: float|null
     * }>
     */
    public function getFormStats(): array
    {
        $pdo = $this->database->getPdo();
        $stmt = $pdo->query("
            SELECT f.label, f.slug, COUNT(s.id) as total,
                   SUM(CASE WHEN s.status = 'en_cours' THEN 1 ELSE 0 END) as en_cours,
                   SUM(CASE WHEN s.status = 'valide' THEN 1 ELSE 0 END) as valide,
                   SUM(CASE WHEN s.status = 'refuse' THEN 1 ELSE 0 END) as refuse,
                   AVG(CASE WHEN s.status = 'valide' AND s.closed_at IS NOT NULL
                       THEN CAST(strftime('%s', s.closed_at) AS REAL) - CAST(strftime('%s', s.submitted_at) AS REAL)
                       ELSE NULL END) as avg_seconds
            FROM forms f
            LEFT JOIN submissions s ON s.form_id = f.id
            GROUP BY f.id
            ORDER BY total DESC
        ");
        assert($stmt !== false);
        /** @var array<int, array{label: string, slug: string, total: int, en_cours: int, valide: int, refuse: int, avg_seconds: float|null}> $result */
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $result;
    }

    /**
     * @return array<int, array{
     *   email: string,
     *   total: int,
     *   done: int,
     *   pending: int,
     *   avg_response_seconds: float|null
     * }>
     */
    public function getValidatorStats(): array
    {
        $pdo = $this->database->getPdo();
        $stmt = $pdo->query("
            SELECT t.email,
                   COUNT(t.id) as total,
                   SUM(CASE WHEN t.done_at IS NOT NULL THEN 1 ELSE 0 END) as done,
                   SUM(CASE WHEN t.done_at IS NULL THEN 1 ELSE 0 END) as pending,
                   AVG(CASE WHEN t.done_at IS NOT NULL
                       THEN CAST(strftime('%s', t.done_at) AS REAL) - CAST(strftime('%s', t.sent_at) AS REAL)
                       ELSE NULL END) as avg_response_seconds
            FROM tokens t
            JOIN submissions s ON s.id = t.submission_id
            WHERE s.status = 'en_cours' OR t.done_at IS NOT NULL
            GROUP BY t.email
            ORDER BY total DESC
            LIMIT 20
        ");
        assert($stmt !== false);
        /** @var array<int, array{email: string, total: int, done: int, pending: int, avg_response_seconds: float|null}> $result */
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $result;
    }
}
