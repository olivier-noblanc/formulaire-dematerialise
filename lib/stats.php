<?php
declare(strict_types=1);

/**
 * Statistics & full-text search.
 *
 * search_submissions() — recherche plein texte dans les soumissions
 * get_stats_by_period() — statistiques par période (semaine/mois/année)
 * get_global_stats() — statistiques globales pour le dashboard
 *
 * @package lib
 */

// ── FULL-TEXT SEARCH ────────────────────────────────────────

/**
 * Recherche plein texte dans les soumissions
 * Cherche dans : submitted_by, data JSON, form_label
 * @param array<string, mixed> $filters
 * @return array<string, mixed>
 */
function search_submissions(string $query, array $filters = []): array {
    // Sécurité (S-16) : limiter le nombre de recherches par IP
    if (!rate_limit_check('search', 30, 60)) {
        return [];
    }
    $pdo = get_pdo();
    $query = trim($query);
    if (empty($query)) return [];
    // Sécurité (A-01) : valider et sanitisser le terme de recherche
    $query = mb_substr($query, 0, 200); // Limiter la longueur

    $where = ['1=1'];
    $params = [];

    // Full-text search across multiple fields
    $where[] = "(s.submitted_by LIKE ? OR s.data LIKE ? OR f.label LIKE ?)";
    $search_term = '%' . $query . '%';
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;

    // Apply filters
    if (!empty($filters['status'])) {
        $where[] = 's.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['form_id'])) {
        $where[] = 's.form_id = ?';
        $params[] = $filters['form_id'];
    }

    $where_sql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT s.*, f.label as form_label, f.slug as form_slug, f.deadline_field
        FROM submissions s
        JOIN forms f ON f.id = s.form_id
        WHERE $where_sql
        ORDER BY s.submitted_at DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── STATISTICS ──────────────────────────────────────────────

/**
 * Statistiques par période
 * @return array<string, mixed>
 */
function get_stats_by_period(string $period = 'month', int $limit = 12): array {
    $pdo = get_pdo();

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
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Statistiques globales pour le dashboard
 * @return array<string, mixed>
 */
function get_global_stats(): array {
    $pdo = get_pdo();

    $stats = [
        'total' => (int)_dbm_q($pdo, "SELECT COUNT(*) FROM submissions")->fetchColumn(),
        'en_cours' => (int)_dbm_q($pdo, "SELECT COUNT(*) FROM submissions WHERE status = 'en_cours'")->fetchColumn(),
        'valide' => (int)_dbm_q($pdo, "SELECT COUNT(*) FROM submissions WHERE status = 'valide'")->fetchColumn(),
        'refuse' => (int)_dbm_q($pdo, "SELECT COUNT(*) FROM submissions WHERE status = 'refuse'")->fetchColumn(),
        'avg_days' => 0,
        'today' => (int)_dbm_q($pdo, "SELECT COUNT(*) FROM submissions WHERE DATE(submitted_at) = DATE('now')")->fetchColumn(),
        'this_week' => (int)_dbm_q($pdo, "SELECT COUNT(*) FROM submissions WHERE submitted_at >= datetime('now', '-7 days')")->fetchColumn(),
        'this_month' => (int)_dbm_q($pdo, "SELECT COUNT(*) FROM submissions WHERE submitted_at >= datetime('now', '-30 days')")->fetchColumn(),
        'tokens_pending' => (int)_dbm_q($pdo, "SELECT COUNT(*) FROM tokens WHERE done_at IS NULL")->fetchColumn(),
        'attachments_count' => (int)_dbm_q($pdo, "SELECT COUNT(*) FROM attachments")->fetchColumn(),
        'attachments_size' => (int)_dbm_q($pdo, "SELECT COALESCE(SUM(file_size), 0) FROM attachments")->fetchColumn(),
    ];

    // Average processing time
    $avg_stmt = _dbm_q($pdo, "
        SELECT AVG(CAST(strftime('%s', closed_at) AS REAL) - CAST(strftime('%s', submitted_at) AS REAL))
        FROM submissions WHERE status = 'valide' AND closed_at IS NOT NULL
    ");
    $stats['avg_days'] = round((float)($avg_stmt->fetchColumn() ?: 0) / 86400, 1);

    $stats['taux_validation'] = $stats['total'] > 0 ? round(($stats['valide'] / $stats['total']) * 100, 1) : 0;

    return $stats;
}
