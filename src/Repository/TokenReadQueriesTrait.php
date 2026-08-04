<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\SubmissionStatus;

/**
 * Trait contenant les méthodes READ de TokenRepository.
 * Regroupe les requêtes de lecture (find*, count*, exists*).
 */
trait TokenReadQueriesTrait
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

    public function existsForSubmissionAndEmail(string $submissionId, string $email): bool
    {
        $result = $this->fetchOne(
            'SELECT 1 FROM tokens WHERE submission_id = ? AND email = ?',
            [$submissionId, $email]
        );
        return $result !== null;
    }

    /**
     * @return array{email: string, step_label: string}|null
     */
    public function findEmailAndStepLabelById(string $tokenId): ?array
    {
        /** @var array{email: string, step_label: string}|null $result */
        $result = $this->fetchOne(
            'SELECT t.email, st.label as step_label FROM tokens t JOIN steps st ON st.id = t.step_id WHERE t.id = ?',
            [$tokenId]
        );
        return $result;
    }

    /**
     * @return array<int, array{token_id: string, token: string, sent_at: string|null, expires_at: string|null, relance_count: int, step_id: string, email: string, step_label: string, ordre: int, submission_id: string, data: string|null, submitted_at: string|null, sub_status: string, form_label: string, form_slug: string}>
     */
    public function findPendingByEmail(string $email, string $search = ''): array
    {
        if ($search !== '') {
            /** @var array<int, array{token_id: string, token: string, sent_at: string|null, expires_at: string|null, relance_count: int, step_id: string, email: string, step_label: string, ordre: int, submission_id: string, data: string|null, submitted_at: string|null, sub_status: string, form_label: string, form_slug: string}> $result */
            $result = $this->fetchAll(
                "SELECT t.id as token_id, t.token, t.sent_at, t.expires_at, t.relance_count,
                        t.step_id, t.email,
                        st.label as step_label, st.ordre,
                        s.id as submission_id, s.data, s.submitted_at, s.status as sub_status,
                        f.label as form_label, f.slug as form_slug
                 FROM tokens t
                 JOIN steps st ON st.id = t.step_id
                 JOIN submissions s ON s.id = t.submission_id
                 JOIN forms f ON f.id = s.form_id
                 WHERE t.email = ? AND t.done_at IS NULL AND t.expires_at > datetime('now') AND s.status = '" . SubmissionStatus::EnCours->value . "'
                   AND (f.label LIKE ? OR s.data LIKE ?)
                 ORDER BY t.sent_at DESC",
                [$email, '%' . $search . '%', '%' . $search . '%']
            );
            return $result;
        }
        /** @var array<int, array{token_id: string, token: string, sent_at: string|null, expires_at: string|null, relance_count: int, step_id: string, email: string, step_label: string, ordre: int, submission_id: string, data: string|null, submitted_at: string|null, sub_status: string, form_label: string, form_slug: string}> $result */
        $result = $this->fetchAll(
            "SELECT t.id as token_id, t.token, t.sent_at, t.expires_at, t.relance_count,
                    t.step_id, t.email,
                    st.label as step_label, st.ordre,
                    s.id as submission_id, s.data, s.submitted_at, s.status as sub_status,
                    f.label as form_label, f.slug as form_slug
             FROM tokens t
             JOIN steps st ON st.id = t.step_id
             JOIN submissions s ON s.id = t.submission_id
             JOIN forms f ON f.id = s.form_id
             WHERE t.email = ? AND t.done_at IS NULL AND t.expires_at > datetime('now') AND s.status = '" . SubmissionStatus::EnCours->value . "'
             ORDER BY t.sent_at DESC",
            [$email]
        );
        return $result;
    }

    /**
     * @return array<int, array{token_id: string, done_at: string|null, sent_at: string|null, step_label: string, ordre: int, submission_id: string, data: string|null, submitted_at: string|null, sub_status: string, form_label: string, form_slug: string}>
     */
    public function findDoneByEmail(string $email, int $limit = 50): array
    {
        /** @var array<int, array{token_id: string, done_at: string|null, sent_at: string|null, step_label: string, ordre: int, submission_id: string, data: string|null, submitted_at: string|null, sub_status: string, form_label: string, form_slug: string}> $result */
        $result = $this->fetchAll(
            'SELECT t.id as token_id, t.done_at, t.sent_at,
                    st.label as step_label, st.ordre,
                    s.id as submission_id, s.data, s.submitted_at, s.status as sub_status,
                    f.label as form_label, f.slug as form_slug
             FROM tokens t
             JOIN steps st ON st.id = t.step_id
             JOIN submissions s ON s.id = t.submission_id
             JOIN forms f ON f.id = s.form_id
             WHERE t.email = ? AND t.done_at IS NOT NULL AND t.invalidated_at IS NULL
             ORDER BY t.done_at DESC
             LIMIT ?',
            [$email, $limit]
        );
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
     * Nombre de tokens en attente pour un utilisateur donné (pas encore traités, pas expirés).
     */
    public function countPendingForEmail(string $email): int
    {
        /** @var array{cnt: int}|null $result */
        $result = $this->fetchOne(
            "SELECT COUNT(*) as cnt FROM tokens t
             JOIN submissions s ON s.id = t.submission_id
             WHERE t.email = ? AND t.done_at IS NULL
               AND (t.expires_at IS NULL OR t.expires_at > datetime('now'))
               AND s.closed_at IS NULL",
            [$email]
        );
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * Récupère un token avec tout son contexte (submission, form, step label).
     * Utilisé par WorkflowEngine::getTokenWithContext() / getTokenByIdWithContext().
     *
     * @param list<string> $params
     * @return array{
     *   id: string,
     *   submission_id: string,
     *   step_id: string,
     *   email: string,
     *   token: string,
     *   sent_at: string,
     *   done_at: string|null,
     *   relance_at: string|null,
     *   expires_at: string|null,
     *   relance_count: int,
     *   invalidated_at: string|null,
     *   action: string|null,
     *   step_label: string,
     *   form_id: string,
     *   form_label: string,
     *   data: string,
     *   closed_at: string|null,
     *   status: string,
     *   submitted_by: string
     * }|null
     */
    public function findTokenWithContextByCondition(string $whereClause, array $params): ?array
    {
        /** @var array{
         *   id: string,
         *   submission_id: string,
         *   step_id: string,
         *   email: string,
         *   token: string,
         *   sent_at: string,
         *   done_at: string|null,
         *   relance_at: string|null,
         *   expires_at: string|null,
         *   relance_count: int,
         *   invalidated_at: string|null,
         *   action: string|null,
         *   step_label: string,
         *   form_id: string,
         *   form_label: string,
         *   data: string,
         *   closed_at: string|null,
         *   status: string,
         *   submitted_by: string
         * }|null $result
         */
        $result = $this->fetchOne(
            "SELECT t.id, t.submission_id, t.step_id, t.email, t.token, t.sent_at,
                    t.done_at, t.relance_at, t.expires_at, t.relance_count, t.invalidated_at, t.action,
                    st.label as step_label, s.form_id,
                    f.label as form_label, s.data, s.closed_at, s.status,
                    s.submitted_by
             FROM tokens t
             JOIN steps st ON st.id = t.step_id
             JOIN submissions s ON s.id = t.submission_id
             JOIN forms f ON f.id = s.form_id
             WHERE {$whereClause}",
            $params
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

    public function countActiveByStepId(string $stepId, string $submissionStatus): int
    {
        /** @var array{cnt: int|string|null}|null $result */
        $result = $this->fetchOne(
            'SELECT COUNT(*) as cnt FROM tokens t JOIN submissions s ON s.id = t.submission_id WHERE t.step_id = ? AND t.done_at IS NULL AND s.status = ?',
            [$stepId, $submissionStatus]
        );
        return (int) ($result['cnt'] ?? 0);
    }

    public function countPending(): int
    {
        /** @var array{cnt: int|string|null}|null $result */
        $result = $this->fetchOne('SELECT COUNT(*) as cnt FROM tokens WHERE done_at IS NULL');
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

    public function findStepLabelByStepId(string $stepId): ?string
    {
        $result = $this->fetchOne('SELECT label FROM steps WHERE id = ?', [$stepId]);
        return $result !== null ? (string) $result['label'] : null;
    }

    /**
     * @return array{id: string, submission_id: string, step_id: string, email: string, token: string, sent_at: string, done_at: string|null, relance_at: string|null, expires_at: string, relance_count: int, sub_status: string}|null
     */
    public function findForRegenerate(string $tokenId): ?array
    {
        /** @var array{id: string, submission_id: string, step_id: string, email: string, token: string, sent_at: string, done_at: string|null, relance_at: string|null, expires_at: string, relance_count: int, sub_status: string}|null $result */
        $result = $this->fetchOne(
            'SELECT t.id, t.submission_id, t.step_id, t.email, t.token, t.sent_at,
                    t.done_at, t.relance_at, t.expires_at, t.relance_count,
                    s.status as sub_status
             FROM tokens t
             JOIN submissions s ON s.id = t.submission_id
             WHERE t.id = ?',
            [$tokenId]
        );
        return $result;
    }

    /**
     * @return list<array{id: string, submission_id: string, step_id: string, email: string, token: string, sent_at: string, done_at: string|null, relance_at: string|null, expires_at: string|null, relance_count: int, step_label: string, form_label: string}>
     */
    public function findDoneValidationsByEmail(string $email): array
    {
        /** @var list<array{id: string, submission_id: string, step_id: string, email: string, token: string, sent_at: string, done_at: string|null, relance_at: string|null, expires_at: string|null, relance_count: int, step_label: string, form_label: string}> $result */
        $result = $this->fetchAll(
            'SELECT t.id, t.submission_id, t.step_id, t.email, t.token, t.sent_at, t.done_at, t.relance_at, t.expires_at, t.relance_count, st.label as step_label, f.label as form_label FROM tokens t JOIN steps st ON st.id = t.step_id JOIN submissions s ON s.id = t.submission_id JOIN forms f ON f.id = s.form_id WHERE t.email = ? AND t.done_at IS NOT NULL AND t.invalidated_at IS NULL ORDER BY t.done_at DESC',
            [$email]
        );
        return $result;
    }

    /**
     * @return list<array{email: string, total: int|string, done: int|string, pending: int|string, avg_response_seconds: float|string|null}>
     */
    public function getValidatorStats(string $submissionStatus): array
    {
        /** @var list<array{email: string, total: int|string, done: int|string, pending: int|string, avg_response_seconds: float|string|null}> $result */
        $result = $this->fetchAll(
            "SELECT t.email,
                    COUNT(t.id) as total,
                    SUM(CASE WHEN t.done_at IS NOT NULL AND t.invalidated_at IS NULL THEN 1 ELSE 0 END) as done,
                    SUM(CASE WHEN t.done_at IS NULL THEN 1 ELSE 0 END) as pending,
                    AVG(CASE WHEN t.done_at IS NOT NULL AND t.invalidated_at IS NULL
                        THEN CAST(strftime('%s', t.done_at) AS REAL) - CAST(strftime('%s', t.sent_at) AS REAL)
                        ELSE NULL END) as avg_response_seconds
             FROM tokens t
             JOIN submissions s ON s.id = t.submission_id
             WHERE s.status = ? OR (t.done_at IS NOT NULL AND t.invalidated_at IS NULL)
             GROUP BY t.email
             ORDER BY total DESC
             LIMIT 20",
            [$submissionStatus]
        );
        return $result;
    }
}
