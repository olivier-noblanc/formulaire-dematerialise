<?php

declare(strict_types=1);

namespace App\Repository;

final class PersonaRepository extends BaseRepository
{
    public function createToken(string $id, string $token, string $adminEmail, string $targetEmail, string $expiresAt): bool
    {
        return $this->execute(
            'INSERT INTO persona_tokens (id, token, admin_email, target_email, expires_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $token, $adminEmail, $targetEmail, $expiresAt]
        );
    }

    /**
     * @return array{target_email: string, expires_at: string, revoked_at: string|null, admin_email: string}|null
     */
    public function findByToken(string $token): ?array
    {
        /** @var array{target_email: string, expires_at: string, revoked_at: string|null, admin_email: string}|null */
        return $this->fetchOne(
            'SELECT target_email, expires_at, revoked_at, admin_email FROM persona_tokens WHERE token = ? LIMIT 1',
            [$token]
        );
    }

    public function revoke(string $token): bool
    {
        $stmt = $this->pdo()->prepare(
            "UPDATE persona_tokens SET revoked_at = datetime('now') WHERE token = ? AND revoked_at IS NULL"
        );
        $stmt->execute([$token]);
        return $stmt->rowCount() > 0;
    }

    public function deleteRevokedBefore(string $cutoff): int
    {
        $stmt = $this->pdo()->prepare(
            'DELETE FROM persona_tokens WHERE revoked_at IS NOT NULL AND revoked_at < ?'
        );
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }

    public function deleteExpiredBefore(string $cutoff): int
    {
        $stmt = $this->pdo()->prepare(
            'DELETE FROM persona_tokens WHERE revoked_at IS NULL AND expires_at < ?'
        );
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }
}
