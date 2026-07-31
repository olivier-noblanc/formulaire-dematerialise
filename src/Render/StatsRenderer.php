<?php

declare(strict_types=1);

namespace App\Render;

use App\Core\App;
use App\Enum\SubmissionStatus;

/**
 * Rendu de la page Statistiques.
 */
final class StatsRenderer
{
    /**
     * @param array{total: int, valide: int, en_cours: int, refuse: int, taux_validation: float, avg_days: float, today: int, this_week: int, this_month: int, tokens_pending: int, attachments_count: int, attachments_size: int} $globalStats
     * @param array<int, array{period: string, total: int, valide: int, en_cours: int}> $periodStats
     * @param array<int, array{label: string, slug: string, total: int, en_cours: int, valide: int, refuse: int, avg_seconds: float|null}> $formStats
     * @param array<int, array{email: string, total: int, done: int, pending: int, avg_response_seconds: float|null}> $validatorStats
     */
    public static function content(
        string $period,
        array $globalStats,
        array $periodStats,
        array $formStats,
        array $validatorStats,
        string $periodLabel,
        int $dbSize
    ): string {
        $h = App::html()->escape(...);

        // Period tabs
        $weekActive  = $period === 'week' ? 'active' : '';
        $monthActive = $period === 'month' ? 'active' : '';
        $yearActive  = $period === 'year' ? 'active' : '';

        // Global stats values
        $total        = (int) ($globalStats['total'] ?? 0);
        $valide       = (int) ($globalStats[SubmissionStatus::Valide->value] ?? 0);
        $enCours      = (int) ($globalStats[SubmissionStatus::EnCours->value] ?? 0);
        $refuse       = (int) ($globalStats[SubmissionStatus::Refuse->value] ?? 0);
        $taux         = $h((string) ($globalStats['taux_validation'] ?? '0'));
        $avgDays      = $h((string) ($globalStats['avg_days'] ?? '—'));
        $today        = (int) ($globalStats['today'] ?? 0);
        $thisWeek     = (int) ($globalStats['this_week'] ?? 0);
        $thisMonth    = (int) ($globalStats['this_month'] ?? 0);
        $tokensPend   = (int) ($globalStats['tokens_pending'] ?? 0);
        $attachCount  = (int) ($globalStats['attachments_count'] ?? 0);
        $attachSize   = (int) ($globalStats['attachments_size'] ?? 0);

        $html = '<h1><span aria-hidden="true">📊</span> Statistiques</h1>';

        $html .= '<div class="period-tabs">';
        $html .= '<a href="index.php?p=stats&period=week" class="' . $weekActive . '">Par semaine</a>';
        $html .= '<a href="index.php?p=stats&period=month" class="' . $monthActive . '">Par mois</a>';
        $html .= '<a href="index.php?p=stats&period=year" class="' . $yearActive . '">Par année</a>';
        $html .= '</div>';

        $html .= '<div class="grid-3">';
        $html .= '<div class="stat-card"><div class="stat-value">' . $total . '</div><div class="stat-label">Soumissions totales</div></div>';
        $html .= '<div class="stat-card success"><div class="stat-value">' . $taux . '%</div><div class="stat-label">Taux de validation</div></div>';
        $html .= '<div class="stat-card"><div class="stat-value">' . $avgDays . ' j</div><div class="stat-label">Temps moyen de traitement</div></div>';
        $html .= '<div class="stat-card"><div class="stat-value">' . $today . '</div><div class="stat-label">Aujourd\'hui</div></div>';
        $html .= '<div class="stat-card"><div class="stat-value">' . $thisWeek . '</div><div class="stat-label">Cette semaine</div></div>';
        $html .= '<div class="stat-card"><div class="stat-value">' . $thisMonth . '</div><div class="stat-label">Ce mois</div></div>';
        $html .= '</div>';

        // Donut chart
        $html .= '<div class="card"><h2>Répartition des statuts</h2>';
        $html .= App::html()->renderDonutChart($total, $valide, $enCours, $refuse);
        $html .= '</div>';

        // Period chart
        $html .= '<div class="card"><h2>Évolution par ' . $periodLabel . '</h2>';
        if ($periodStats === []) {
            $html .= '<p class="empty-state">Aucune donnée pour cette période.</p>';
        } else {
            $column = array_column($periodStats, 'total');
            $maxTotal = $column !== [] ? (max($column) ?: 1) : 1;
            $periodStatsAsc = array_reverse($periodStats);
            $html .= '<div class="bar-chart">';
            foreach ($periodStatsAsc as $periodStatAsc) {
                $pct = round(($periodStatAsc['total'] / $maxTotal) * 100);
                $validePct  = $periodStatAsc['total'] > 0 ? round(($periodStatAsc[SubmissionStatus::Valide->value] / $periodStatAsc['total']) * 100) : 0;
                $enCoursPct = $periodStatAsc['total'] > 0 ? round(($periodStatAsc[SubmissionStatus::EnCours->value] / $periodStatAsc['total']) * 100) : 0;
                $refusePct  = max(0, 100 - $validePct - $enCoursPct);
                $barWidth   = max($pct, 3);
                $periodStr  = $h((string) $periodStatAsc['period']);
                $totalInt   = (int) $periodStatAsc['total'];
                $html .= '<div class="bar-row">';
                $html .= '<div class="bar-label">' . $periodStr . '</div>';
                // DynamicCssService : les largeurs sont calculées à l'exécution
                // depuis les données — impossibles en CSS statique.
                \App\Core\App::css()->rule('bar-w-' . (int) $barWidth, "width:{$barWidth}%;");
                \App\Core\App::css()->rule('seg-val-' . (int) $validePct, "width:{$validePct}%;");
                \App\Core\App::css()->rule('seg-enc-' . (int) $enCoursPct, "width:{$enCoursPct}%;");
                \App\Core\App::css()->rule('seg-ref-' . (int) $refusePct, "width:{$refusePct}%;");
                $html .= '<div class="bar-track"><div class="stacked-bar bar-w-' . (int) $barWidth . '">';
                $html .= '<div class="segment-valide seg-val-' . (int) $validePct . '"></div>';
                $html .= '<div class="segment-en_cours seg-enc-' . (int) $enCoursPct . '"></div>';
                $html .= '<div class="segment-refuse seg-ref-' . (int) $refusePct . '"></div>';
                $html .= '</div></div>';
                $html .= '<div class="bar-value">' . $totalInt . '</div>';
                $html .= '</div>';
            }
            $html .= '</div>';
            $html .= '<div class="chart-legend flex-row-gap15-mt">';
            $html .= '<div class="legend-item"><span class="legend-dot bg-success"></span>Validées</div>';
            $html .= '<div class="legend-item"><span class="legend-dot bg-warning"></span>En cours</div>';
            $html .= '<div class="legend-item"><span class="legend-dot bg-danger"></span>Refusées</div>';
            $html .= '</div>';
        }
        $html .= '</div>';

        // Form stats table
        $html .= '<div class="card"><h2>Performance par formulaire</h2>';
        if ($formStats === [] || (count($formStats) === 1 && ($formStats[0]['total'] ?? 0) === 0)) {
            $html .= '<p class="empty-state">Aucune soumission enregistrée.</p>';
        } else {
            $html .= '<table><thead><tr><th>Formulaire</th><th>Total</th><th>En cours</th><th>Validées</th><th>Refusées</th><th>Taux</th><th>Temps moyen</th></tr></thead><tbody>';
            foreach ($formStats as $formStat) {
                $fsTotal  = (int) $formStat['total'];
                $fsValide = (int) $formStat[SubmissionStatus::Valide->value];
                $fsRate   = $fsTotal > 0 ? round(($fsValide / $fsTotal) * 100, 1) : 0;
                $fsAvg    = empty($formStat['avg_seconds']) ? '—' : round((float) $formStat['avg_seconds'] / 86400, 1) . ' j';
                $fsLabel  = $h((string) $formStat['label']);
                $fsEnC    = (int) $formStat[SubmissionStatus::EnCours->value];
                $fsRef    = (int) $formStat[SubmissionStatus::Refuse->value];
                $html .= '<tr><td><strong>' . $fsLabel . '</strong></td><td>' . $fsTotal . '</td>';
                $html .= '<td><span class="badge badge-warn">' . $fsEnC . '</span></td>';
                $html .= '<td><span class="badge badge-ok">' . $fsValide . '</span></td>';
                $html .= '<td><span class="badge badge-err">' . $fsRef . '</span></td>';
                $html .= '<td><strong>' . $fsRate . '%</strong></td><td>' . $fsAvg . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        $html .= '</div>';

        // Validator stats table
        $html .= '<div class="card"><h2>Performance par validateur</h2>';
        if ($validatorStats === []) {
            $html .= '<p class="empty-state">Aucune donnée de validation.</p>';
        } else {
            $html .= '<table><thead><tr><th>Validateur</th><th>Total assigné</th><th>Traitées</th><th>En attente</th><th>Temps de réponse moyen</th></tr></thead><tbody>';
            foreach ($validatorStats as $validatorStat) {
                $vsAvg   = empty($validatorStat['avg_response_seconds']) ? '—' : round((float) $validatorStat['avg_response_seconds'] / 3600, 1) . ' h';
                $vsEmail = App::html()->displayUser((string) $validatorStat['email']);
                $vsTotal = (int) $validatorStat['total'];
                $vsDone  = (int) $validatorStat['done'];
                $vsPend  = (int) $validatorStat['pending'];
                $html .= '<tr><td>' . $vsEmail . '</td><td>' . $vsTotal . '</td>';
                $html .= '<td><span class="badge badge-ok">' . $vsDone . '</span></td>';
                $html .= '<td><span class="badge badge-warn">' . $vsPend . '</span></td>';
                $html .= '<td>' . $vsAvg . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        $html .= '</div>';

        // Data volume
        $dbSizeFormatted = App::html()->formatFileSize($dbSize);
        $attachSizeFormatted = App::html()->formatFileSize($attachSize);
        $html .= '<div class="card"><h2>Volume de données</h2><div class="grid-2">';
        $html .= '<div class="stat-card"><div class="stat-value">' . $tokensPend . '</div><div class="stat-label">Tokens en attente</div></div>';
        $html .= '<div class="stat-card"><div class="stat-value">' . $attachCount . '</div><div class="stat-label">Pièces jointes</div></div>';
        $html .= '<div class="stat-card"><div class="stat-value">' . $attachSizeFormatted . '</div><div class="stat-label">Volume pièces jointes</div></div>';
        $html .= '<div class="stat-card"><div class="stat-value">' . $dbSizeFormatted . '</div><div class="stat-label">Taille base de données</div></div>';

        return $html . '</div></div>';
    }
}
