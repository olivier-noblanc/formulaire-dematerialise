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
            "UPDATE tokens SET expires_at = datetime('now') WHERE id = ?",
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
}
