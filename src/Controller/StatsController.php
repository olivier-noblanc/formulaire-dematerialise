<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Contrôleur de la page Statistiques.
 */
final class StatsController extends BaseController
{
    public function handle(): void
    {
        App::auth()->requireAdmin();

        $pdo = $this->db->getPdo();
        $period = $_GET['period'] ?? 'month';
        if (!in_array($period, ['week', 'month', 'year'])) $period = 'month';

        $globalStats = App::getInstance()->get(\App\Stats\StatsService::class)->getGlobalStats();
        $periodStats = App::getInstance()->get(\App\Stats\StatsService::class)->getStatsByPeriod($period, 12);

        $formStats = $pdo->query("
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
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $validatorStats = $pdo->query("
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
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $periodLabel = $period === 'week' ? 'semaine' : ($period === 'year' ? 'année' : 'mois');

        $navExtra = [
            'stats'     => ['href' => 'index.php?p=stats',        'label' => 'Statistiques', 'icon' => '📈'],
            'monitoring'=> ['href' => 'index.php?p=monitoring',    'label' => 'Surveillance', 'icon' => '🖥'],
        ];
        $content = \App\Render\StatsRenderer::content($period, $globalStats, $periodStats, $formStats, $validatorStats, $periodLabel, (new \App\Webhook\WebhookService($this->db))->getDbSize());
        echo $this->renderPage('Statistiques', 'stats', '', $content, ['nav_extra' => $navExtra]);
    }
}
