<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Trait contenant les méthodes READ de TokenRepository.
 * Regroupe les requêtes de lecture par email et les requêtes misc.
 */
trait TokenReadQueriesTrait
{
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
                 WHERE t.email = ? AND t.done_at IS NULL AND t.expires_at > datetime('now') AND s.status = 'en_cours'
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
             WHERE t.email = ? AND t.done_at IS NULL AND t.expires_at > datetime('now') AND s.status = 'en_cours'
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
}
