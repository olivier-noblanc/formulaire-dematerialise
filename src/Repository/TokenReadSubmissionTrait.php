<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Trait contenant les méthodes READ groupées par submission pour TokenRepository.
 *
 * Regroupe les requêtes qui retournent des données par soumission
 * (find*, count* by submission_id).
 */
trait TokenReadSubmissionTrait
{
    /**
     * @return array<int, array{id: string, step_id: string, email: string, token: string, sent_at: string|null, step_label: string, ordre: int}>
     */
    public function findWithStepsBySubmission(string $submissionId): array
    {
        /** @var array<int, array{id: string, step_id: string, email: string, token: string, sent_at: string|null, step_label: string, ordre: int}> $result */
        $result = $this->fetchAll(
            'SELECT t.id, t.step_id, t.email, t.token, t.sent_at,
                    st.label as step_label, st.ordre
             FROM tokens t
             JOIN steps st ON st.id = t.step_id
             WHERE t.submission_id = ?
             ORDER BY st.ordre',
            [$submissionId]
        );
        return $result;
    }

    /**
     * @return array<int, array{id: string, submission_id: string, step_id: string, email: string, token: string, sent_at: string|null, done_at: string|null, relance_at: string|null, expires_at: string|null, relance_count: int, step_label: string, ordre: int}>
     */
    public function findDetailedWithStepsBySubmission(string $submissionId): array
    {
        /** @var array<int, array{id: string, submission_id: string, step_id: string, email: string, token: string, sent_at: string|null, done_at: string|null, relance_at: string|null, expires_at: string|null, relance_count: int, step_label: string, ordre: int}> $result */
        $result = $this->fetchAll(
            'SELECT t.id, t.submission_id, t.step_id, t.email, t.token, t.sent_at, t.done_at, t.relance_at, t.expires_at, t.relance_count, st.label as step_label, st.ordre
             FROM tokens t
             JOIN steps st ON st.id = t.step_id
             WHERE t.submission_id = ?
             ORDER BY st.ordre ASC, t.sent_at ASC',
            [$submissionId]
        );
        return $result;
    }

    /**
     * @param array<int, string> $submissionIds
     * @return array<string, list<array{submission_id: string, id: string, token: string, relance_count: int, expires_at: string|null, email: string, done_at: string|null, sent_at: string|null, step_id: string, label: string, step_label: string, ordre: int}>>
     */
    public function findBySubmissionIds(array $submissionIds): array
    {
        if ($submissionIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
        /** @var list<array{submission_id: string, id: string, token: string, relance_count: int, expires_at: string|null, email: string, done_at: string|null, sent_at: string|null, step_id: string, label: string, step_label: string, ordre: int}> $rows */
        $rows = $this->fetchAll(
            "SELECT t.submission_id, t.id, t.token, t.relance_count, t.expires_at,
                    t.email, t.done_at, t.sent_at, t.step_id,
                    st.label, st.label as step_label, st.ordre
             FROM tokens t
             JOIN steps st ON st.id = t.step_id
             WHERE t.submission_id IN ($placeholders)
             ORDER BY t.submission_id, st.ordre ASC, st.label ASC",
            $submissionIds
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['submission_id']][] = $row;
        }
        return $result;
    }

    /**
     * @param array<int, string> $submissionIds
     * @return array<string, list<array{submission_id: string, id: string, label: string, ordre: int, dones: string|null}>>
     */
    public function findStepsBySubmissionIds(array $submissionIds): array
    {
        if ($submissionIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
        /** @var list<array{submission_id: string, id: string, label: string, ordre: int, dones: string|null}> $rows */
        $rows = $this->fetchAll(
            "SELECT s.id as submission_id, st.id, st.label, st.ordre,
                    GROUP_CONCAT(t2.done_at, '|') as dones
             FROM submissions s
             JOIN steps st ON st.form_id = s.form_id AND st.actif = 1
             LEFT JOIN tokens t2 ON t2.step_id = st.id AND t2.submission_id = s.id
             WHERE s.id IN ($placeholders)
             GROUP BY s.id, st.id
             ORDER BY s.id, st.ordre",
            $submissionIds
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['submission_id']][] = $row;
        }
        return $result;
    }

    /**
     * @param array<int, string> $submissionIds
     * @return array<string, int>
     */
    public function countPendingBySubmissionIds(array $submissionIds): array
    {
        if ($submissionIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
        /** @var list<array{submission_id: string, cnt: int|string}> $rows */
        $rows = $this->fetchAll(
            "SELECT submission_id, COUNT(*) as cnt FROM tokens WHERE submission_id IN ($placeholders) AND done_at IS NULL GROUP BY submission_id",
            $submissionIds
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['submission_id']] = (int) $row['cnt'];
        }
        return $result;
    }

    /**
     * @return array<int, array{step_id: string, email: string, sent_at: string|null, done_at: string|null, expires_at: string|null}>
     */
    public function findForExport(string $submissionId): array
    {
        /** @var array<int, array{step_id: string, email: string, sent_at: string|null, done_at: string|null, expires_at: string|null}> $result */
        $result = $this->fetchAll(
            'SELECT step_id, email, sent_at, done_at, expires_at
             FROM tokens WHERE submission_id = ? ORDER BY sent_at',
            [$submissionId]
        );
        return $result;
    }

    /**
     * @return list<array{step_id: string, done_at: string|null}>
     */
    public function findStepIdsAndDonesBySubmission(string $submissionId): array
    {
        /** @var list<array{step_id: string, done_at: string|null}> $result */
        $result = $this->fetchAll(
            'SELECT step_id, done_at FROM tokens WHERE submission_id = ?',
            [$submissionId]
        );
        return $result;
    }
}
