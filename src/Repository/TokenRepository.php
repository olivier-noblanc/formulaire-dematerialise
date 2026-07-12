<?php
declare(strict_types=1);

namespace App\Repository;

final class TokenRepository extends BaseRepository
{
    public function findByValue(string $token): ?array
    {
        return $this->fetchOne("SELECT * FROM tokens WHERE token = ?", [$token]);
    }

    public function findById(string $tokenId): ?array
    {
        return $this->fetchOne("SELECT * FROM tokens WHERE id = ?", [$tokenId]);
    }

    public function findBySubmission(string $submissionId): array
    {
        return $this->fetchAll(
            "SELECT * FROM tokens WHERE submission_id = ? ORDER BY sent_at",
            [$submissionId]
        );
    }

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
        $result = $this->fetchOne(
            "SELECT COUNT(*) as count FROM tokens t JOIN submissions s ON s.id = t.submission_id WHERE s.form_id = ? AND t.done_at IS NULL AND t.expires_at > datetime('now')",
            [$formId]
        );
        return (int)($result['count'] ?? 0);
    }

    public function getActiveCountByStep(string $stepId): int
    {
        $result = $this->fetchOne(
            "SELECT COUNT(*) as count FROM tokens WHERE step_id = ? AND done_at IS NULL AND expires_at > datetime('now')",
            [$stepId]
        );
        return (int)($result['count'] ?? 0);
    }

    public function findWithStepsBySubmission(string $submissionId): array
    {
        return $this->fetchAll(
            "SELECT t.id, t.step_id, t.email, t.token, t.sent_at,
                    st.label as step_label, st.ordre
             FROM tokens t
             JOIN steps st ON st.id = t.step_id
             WHERE t.submission_id = ?
             ORDER BY st.ordre",
            [$submissionId]
        );
    }

    public function findDetailedWithStepsBySubmission(string $submissionId): array
    {
        return $this->fetchAll(
            "SELECT t.*, st.label as step_label, st.ordre
             FROM tokens t
             JOIN steps st ON st.id = t.step_id
             WHERE t.submission_id = ?
             ORDER BY st.ordre ASC, t.sent_at ASC",
            [$submissionId]
        );
    }

    public function findBySubmissionIds(array $submissionIds): array
    {
        if (empty($submissionIds)) return [];
        $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
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
            "SELECT 1 FROM tokens WHERE submission_id = ? AND email = ?",
            [$submissionId, $email]
        );
        return $result !== null;
    }

    public function findEmailAndStepLabelById(string $tokenId): ?array
    {
        return $this->fetchOne(
            "SELECT t.email, st.label as step_label FROM tokens t JOIN steps st ON st.id = t.step_id WHERE t.id = ?",
            [$tokenId]
        );
    }

    public function findPendingByEmail(string $email, string $search = ''): array
    {
        if ($search !== '') {
            return $this->fetchAll(
                "SELECT t.id as token_id, t.token, t.sent_at, t.expires_at, t.relance_count,
                        t.step_id, t.email,
                        st.label as step_label, st.ordre,
                        s.id as submission_id, s.data, s.submitted_at, s.status as sub_status,
                        f.label as form_label, f.slug as form_slug
                 FROM tokens t
                 JOIN steps st ON st.id = t.step_id
                 JOIN submissions s ON s.id = t.submission_id
                 JOIN forms f ON f.id = s.form_id
                 WHERE t.email = ? AND t.done_at IS NULL AND s.status = 'en_cours'
                   AND (f.label LIKE ? OR s.data LIKE ?)
                 ORDER BY t.sent_at DESC",
                [$email, '%' . $search . '%', '%' . $search . '%']
            );
        }
        return $this->fetchAll(
            "SELECT t.id as token_id, t.token, t.sent_at, t.expires_at, t.relance_count,
                    t.step_id, t.email,
                    st.label as step_label, st.ordre,
                    s.id as submission_id, s.data, s.submitted_at, s.status as sub_status,
                    f.label as form_label, f.slug as form_slug
             FROM tokens t
             JOIN steps st ON st.id = t.step_id
             JOIN submissions s ON s.id = t.submission_id
             JOIN forms f ON f.id = s.form_id
             WHERE t.email = ? AND t.done_at IS NULL AND s.status = 'en_cours'
             ORDER BY t.sent_at DESC",
            [$email]
        );
    }

    public function findDoneByEmail(string $email, int $limit = 50): array
    {
        return $this->fetchAll(
            "SELECT t.id as token_id, t.done_at, t.sent_at,
                    st.label as step_label, st.ordre,
                    s.id as submission_id, s.data, s.submitted_at, s.status as sub_status,
                    f.label as form_label, f.slug as form_slug
             FROM tokens t
             JOIN steps st ON st.id = t.step_id
             JOIN submissions s ON s.id = t.submission_id
             JOIN forms f ON f.id = s.form_id
             WHERE t.email = ? AND t.done_at IS NOT NULL
             ORDER BY t.done_at DESC
             LIMIT ?",
            [$email, $limit]
        );
    }

    public function findStepsBySubmissionIds(array $submissionIds): array
    {
        if (empty($submissionIds)) return [];
        $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
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
}
