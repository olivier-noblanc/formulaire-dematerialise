<?php

declare(strict_types=1);

namespace App\Repository;

final class TokenRepository extends BaseRepository
{
    /**
     * @return array{id: string, submission_id: string, step_id: string, email: string, token: string, sent_at: string|null, done_at: string|null, relance_at: string|null, expires_at: string|null, relance_count: int}|null
     */
    public function findByValue(string $token): ?array
    {
        /** @var array{id: string, submission_id: string, step_id: string, email: string, token: string, sent_at: string|null, done_at: string|null, relance_at: string|null, expires_at: string|null, relance_count: int}|null $result */
        $result = $this->fetchOne('SELECT id, submission_id, step_id, email, token, sent_at, done_at, relance_at, expires_at, relance_count FROM tokens WHERE token = ?', [$token]);
        return $result;
    }

    /**
     * @return array{id: string, submission_id: string, step_id: string, email: string, token: string, sent_at: string|null, done_at: string|null, relance_at: string|null, expires_at: string|null, relance_count: int}|null
     */
    public function findById(string $tokenId): ?array
    {
        /** @var array{id: string, submission_id: string, step_id: string, email: string, token: string, sent_at: string|null, done_at: string|null, relance_at: string|null, expires_at: string|null, relance_count: int}|null $result */
        $result = $this->fetchOne('SELECT id, submission_id, step_id, email, token, sent_at, done_at, relance_at, expires_at, relance_count FROM tokens WHERE id = ?', [$tokenId]);
        return $result;
    }

    /**
     * @return array<int, array{id: string, submission_id: string, step_id: string, email: string, token: string, sent_at: string|null, done_at: string|null, relance_at: string|null, expires_at: string|null, relance_count: int}>
     */
    public function findBySubmission(string $submissionId): array
    {
        /** @var array<int, array{id: string, submission_id: string, step_id: string, email: string, token: string, sent_at: string|null, done_at: string|null, relance_at: string|null, expires_at: string|null, relance_count: int}> $result */
        $result = $this->fetchAll(
            'SELECT id, submission_id, step_id, email, token, sent_at, done_at, relance_at, expires_at, relance_count FROM tokens WHERE submission_id = ? ORDER BY sent_at',
            [$submissionId]
        );
        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): string
    {
        $id = \generate_uuid();
        $this->execute(
            "INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), ?)",
            [$id, $data['submission_id'], $data['step_id'], $data['email'], $data['token'], $data['expires_at'] ?? null]
        );
        return $id;
    }

    public function markUsed(string $tokenId): bool
    {
        return $this->execute(
            "UPDATE tokens SET done_at = datetime('now') WHERE id = ?",
            [$tokenId]
        );
    }

    public function markExpired(string $tokenId): bool
    {
        return $this->execute(
            "UPDATE tokens SET expires_at = datetime('now', '-1 second') WHERE id = ?",
            [$tokenId]
        );
    }

    public function incrementRelance(string $tokenId): bool
    {
        return $this->execute(
            "UPDATE tokens SET relance_count = relance_count + 1, relance_at = datetime('now') WHERE id = ?",
            [$tokenId]
        );
    }

    public function getActiveCount(string $formId): int
    {
        /** @var array{count: int}|null $result */
        $result = $this->fetchOne(
            "SELECT COUNT(*) as count FROM tokens t JOIN submissions s ON s.id = t.submission_id WHERE s.form_id = ? AND t.done_at IS NULL AND t.expires_at > datetime('now')",
            [$formId]
        );
        return (int) ($result['count'] ?? 0);
    }

    public function getActiveCountByStep(string $stepId): int
    {
        /** @var array{count: int}|null $result */
        $result = $this->fetchOne(
            "SELECT COUNT(*) as count FROM tokens WHERE step_id = ? AND done_at IS NULL AND expires_at > datetime('now')",
            [$stepId]
        );
        return (int) ($result['count'] ?? 0);
    }

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
     */
    public function deleteBySubmissionIds(array $submissionIds): int
    {
        if ($submissionIds === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
        $stmt = $this->pdo()->prepare("DELETE FROM tokens WHERE submission_id IN ($placeholders)");
        $stmt->execute($submissionIds);
        return $stmt->rowCount();
    }

    public function countPurgeableByCutoff(string $cutoff): int
    {
        /** @var array{cnt: int}|null $result */
        $result = $this->fetchOne(
            "SELECT COUNT(*) as cnt FROM tokens t
             JOIN submissions s ON s.id = t.submission_id
             WHERE s.status IN ('valide', 'refuse') AND s.closed_at IS NOT NULL AND s.closed_at < ?",
            [$cutoff]
        );
        return (int) ($result['cnt'] ?? 0);
    }

    public function getActiveCountByEmail(string $email): int
    {
        /** @var array{count: int}|null $result */
        $result = $this->fetchOne(
            'SELECT COUNT(*) as count FROM tokens WHERE email = ? AND done_at IS NULL AND expires_at > datetime(\'now\')',
            [$email]
        );
        return (int) ($result['count'] ?? 0);
    }

    public function getBlockedCount(int $hours): int
    {
        /** @var array{count: int}|null $result */
        $result = $this->fetchOne(
            "SELECT COUNT(*) as count FROM tokens t
             JOIN submissions s ON s.id = t.submission_id
             WHERE t.done_at IS NULL AND s.status = 'en_cours'
               AND CAST(strftime('%s', 'now') AS REAL)
                   - CAST(strftime('%s', t.sent_at) AS REAL) > ?",
            [$hours * 3600]
        );
        return (int) ($result['count'] ?? 0);
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
             WHERE t.done_at IS NULL AND s.status = 'en_cours'
               AND CAST(strftime('%s', 'now') AS REAL) - CAST(strftime('%s', t.sent_at) AS REAL) > ?
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
               AND t.expires_at < datetime('now') AND s.status = 'en_cours'"
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
}
