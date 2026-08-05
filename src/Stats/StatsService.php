<?php

declare(strict_types=1);

namespace App\Stats;

use App\Core\App;
use App\Enum\SubmissionStatus;
use App\Repository\AttachmentRepository;
use App\Repository\SubmissionRepository;
use App\Repository\TokenRepository;

/**
 * Service de statistiques et recherche plein texte.
 *
 * Tout accès DB passe par les repositories injectés ou résolus via App.
 */
final readonly class StatsService
{
    public SubmissionRepository $submissionRepository;
    public TokenRepository $tokenRepository;
    public AttachmentRepository $attachmentRepository;

    public function __construct(
        ?SubmissionRepository $submissionRepository = null,
        ?TokenRepository $tokenRepository = null,
        ?AttachmentRepository $attachmentRepository = null
    ) {
        $app = App::getInstance();
        $this->submissionRepository = $submissionRepository ?? $app->get(SubmissionRepository::class);
        $this->tokenRepository = $tokenRepository ?? $app->get(TokenRepository::class);
        $this->attachmentRepository = $attachmentRepository ?? $app->get(AttachmentRepository::class);
    }

    /**
     * Statistiques par période.
     *
     * @return list<array{
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

        $rows = $this->submissionRepository->getStatsByPeriod($format, $interval, $limit);
        // Normaliser les types (SQLite retourne int|string selon le contexte)
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'period' => (string) $row['period'],
                'total' => (int) $row['total'],
                SubmissionStatus::Valide->value => (int) $row[SubmissionStatus::Valide->value],
                SubmissionStatus::Refuse->value => (int) $row[SubmissionStatus::Refuse->value],
                SubmissionStatus::EnCours->value => (int) $row[SubmissionStatus::EnCours->value],
                'avg_processing_seconds' => $row['avg_processing_seconds'] !== null ? (float) $row['avg_processing_seconds'] : null,
            ];
        }
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
        $row = $this->submissionRepository->getGlobalStatsCounts();

        $tokensPending = $this->tokenRepository->countPending();
        $attachmentsCount = $this->attachmentRepository->countAll();
        $attachmentsSize = $this->attachmentRepository->getTotalFileSize();

        $stats = [
            'total' => (int) ($row['total'] ?? 0),
            SubmissionStatus::EnCours->value => (int) ($row[SubmissionStatus::EnCours->value] ?? 0),
            SubmissionStatus::Valide->value => (int) ($row[SubmissionStatus::Valide->value] ?? 0),
            SubmissionStatus::Refuse->value => (int) ($row[SubmissionStatus::Refuse->value] ?? 0),
            'today' => (int) ($row['today'] ?? 0),
            'this_week' => (int) ($row['this_week'] ?? 0),
            'this_month' => (int) ($row['this_month'] ?? 0),
            'avg_days' => 0.0,
            'tokens_pending' => $tokensPending,
            'attachments_count' => $attachmentsCount,
            'attachments_size' => $attachmentsSize,
        ];

        $avgSeconds = $this->submissionRepository->getAvgProcessingSeconds();
        $stats['avg_days'] = round($avgSeconds / 86400, 1);

        $stats['taux_validation'] = $stats['total'] > 0
            ? round(($stats[SubmissionStatus::Valide->value] / $stats['total']) * 100, 1)
            : 0;

        return $stats;
    }

    /**
     * @return list<array{
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
        $rows = $this->submissionRepository->getFormStats();
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'label' => (string) $row['label'],
                'slug' => (string) $row['slug'],
                'total' => (int) $row['total'],
                SubmissionStatus::EnCours->value => (int) $row[SubmissionStatus::EnCours->value],
                SubmissionStatus::Valide->value => (int) $row[SubmissionStatus::Valide->value],
                SubmissionStatus::Refuse->value => (int) $row[SubmissionStatus::Refuse->value],
                'avg_seconds' => $row['avg_seconds'] !== null ? (float) $row['avg_seconds'] : null,
            ];
        }
        return $result;
    }

    /**
     * @return list<array{
     *   email: string,
     *   total: int,
     *   done: int,
     *   pending: int,
     *   avg_response_seconds: float|null
     * }>
     */
    public function getValidatorStats(): array
    {
        $rows = $this->tokenRepository->getValidatorStats(SubmissionStatus::EnCours->value);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'email' => (string) $row['email'],
                'total' => (int) $row['total'],
                'done' => (int) $row['done'],
                'pending' => (int) $row['pending'],
                'avg_response_seconds' => $row['avg_response_seconds'] !== null ? (float) $row['avg_response_seconds'] : null,
            ];
        }
        return $result;
    }
}
