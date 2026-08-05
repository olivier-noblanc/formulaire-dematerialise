<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\SubmissionStatus;

/**
 * Trait contenant les méthodes READ de vérification/comptage pour TokenRepository.
 *
 * Regroupe les requêtes de vérification (exists*, has*), comptage (count*)
 * et recherche de tokens bloqués (findBlocked).
 */
trait TokenReadCheckTrait
{
    public function existsForSubmissionAndEmail(string $submissionId, string $email): bool
    {
        $result = $this->fetchOne(
            'SELECT 1 FROM tokens WHERE submission_id = ? AND email = ?',
            [$submissionId, $email]
        );
        return $result !== null;
    }

    /**
     * Vérifie s'il existe un token pending (done_at IS NULL) pour le triplet
     * (submission_id, step_id, email).
     */
    public function hasPendingDuplicate(string $submissionId, string $stepId, string $email): bool
    {
        $result = $this->fetchOne(
            'SELECT 1 FROM tokens WHERE submission_id = ? AND step_id = ? AND email = ? AND done_at IS NULL',
            [$submissionId, $stepId, $email]
        );
        return $result !== null;
    }

    public function countExpired(): int
    {
        /** @var array{count: int}|null $result */
        $result = $this->fetchOne(
            "SELECT COUNT(*) as count FROM tokens t
             JOIN submissions s ON s.id = t.submission_id
             WHERE t.done_at IS NULL AND t.expires_at IS NOT NULL
               AND t.expires_at < datetime('now') AND s.status = '" . SubmissionStatus::EnCours->value . "'"
        );
        return (int) ($result['count'] ?? 0);
    }

    public function countPending(): int
    {
        /** @var array{cnt: int|string|null}|null $result */
        $result = $this->fetchOne('SELECT COUNT(*) as cnt FROM tokens WHERE done_at IS NULL');
        return (int) ($result['cnt'] ?? 0);
    }

    public function countActiveByStepId(string $stepId, string $submissionStatus): int
    {
        /** @var array{cnt: int|string|null}|null $result */
        $result = $this->fetchOne(
            'SELECT COUNT(*) as cnt FROM tokens t JOIN submissions s ON s.id = t.submission_id WHERE t.step_id = ? AND t.done_at IS NULL AND s.status = ?',
            [$stepId, $submissionStatus]
        );
        return (int) ($result['cnt'] ?? 0);
    }

    public function countPurgeableByCutoff(string $cutoff): int
    {
        /** @var array{cnt: int}|null $result */
        $result = $this->fetchOne(
            "SELECT COUNT(*) as cnt FROM tokens t
             JOIN submissions s ON s.id = t.submission_id
             WHERE s.status IN ('" . SubmissionStatus::Valide->value . "', '" . SubmissionStatus::Refuse->value . "') AND s.closed_at IS NOT NULL AND s.closed_at < ?",
            [$cutoff]
        );
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * @return array<int, array{id: string, email: string, sent_at: string|null, relance_count: int, expires_at: string|null, step_label: string, ordre: int, submission_id: string, submitted_by: string|null, submitted_at: string|null, form_label: string}>
     */
    public function findBlocked(int $hours, int $limit = 100): array
    {
        /** @var array<int, array{id: string, email: string, sent_at: string|null, relance_count: int, expires_at: string|null, step_label: string, ordre: int, submission_id: string, submitted_by: string|null, submitted_at: string|null, form_label: string}> $result */
        $result = $this->fetchAll(
            "SELECT t.id, t.email, t.sent_at, t.relance_count, t.expires_at,
                    st.label as step_label, st.ordre,
                    s.id as submission_id, s.submitted_by, s.submitted_at,
                    f.label as form_label
             FROM tokens t
             JOIN steps st ON st.id = t.step_id
             JOIN submissions s ON s.id = t.submission_id
             JOIN forms f ON f.id = s.form_id
             WHERE t.done_at IS NULL AND s.status = '" . SubmissionStatus::EnCours->value . "'
               AND CAST(strftime('%s', 'now') AS REAL) - CAST(strftime('%s', t.sent_at) AS REAL) > CAST(? AS REAL)
             ORDER BY t.sent_at ASC
             LIMIT ?",
            [$hours * 3600, $limit]
        );
        return $result;
    }
}
