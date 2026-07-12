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

        $period = $_GET['period'] ?? 'month';
        if (!in_array($period, ['week', 'month', 'year'])) {
            $period = 'month';
        }

        $statsService = App::getInstance()->get(\App\Stats\StatsService::class);
        $globalStats = $statsService->getGlobalStats();
        $periodStats = $statsService->getStatsByPeriod($period, 12);
        $formStats = $statsService->getFormStats();
        $validatorStats = $statsService->getValidatorStats();

        $periodLabel = $period === 'week' ? 'semaine' : ($period === 'year' ? 'année' : 'mois');
        $content = \App\Render\StatsRenderer::content($period, $globalStats, $periodStats, $formStats, $validatorStats, $periodLabel, (new \App\Webhook\WebhookService())->getDbSize());
        echo $this->renderPage('Statistiques', 'stats', '', $content);
    }
}
