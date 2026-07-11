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
}
