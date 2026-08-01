<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Repository pour la table `persona_tokens` (tokens d'impersonation admin → user).
 *
 * Sépare l'accès à PDO du service PersonaService.
 */
final class PersonaTokenRepository extends BaseRepository
{
    /**
     * Crée un token persona.
     */
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
    public function findActiveByToken(string $token): ?array
    {
        /** @var array{target_email: string, expires_at: string, revoked_at: string|null, admin_email: string}|null $result */
        $result = $this->fetchOne(
            'SELECT pt.target_email, pt.expires_at, pt.revoked_at, pt.admin_email
             FROM persona_tokens pt
             WHERE pt.token = ?
             LIMIT 1',
            [$token]
        );
        return $result;
    }

    /**
     * Révoque un token persona (mark revoked_at = now).
     * Retourne le nombre de lignes affectées (0 si token introuvable ou déjà révoqué).
     */
    public function revokeByToken(string $token): int
    {
        $stmt = $this->pdo()->prepare("UPDATE persona_tokens SET revoked_at = datetime('now') WHERE token = ? AND revoked_at IS NULL");
        $stmt->execute([$token]);
        return $stmt->rowCount();
    }

    /**
     * Nettoie les tokens expirés ou révoqués depuis plus de 30 jours.
     * Retourne le nombre total de lignes supprimées.
     */
    public function cleanup(string $cutoff): int
    {
        $stmt1 = $this->pdo()->prepare('DELETE FROM persona_tokens WHERE revoked_at IS NOT NULL AND revoked_at < ?');
        $stmt1->execute([$cutoff]);
        $deleted = $stmt1->rowCount();

        $stmt2 = $this->pdo()->prepare('DELETE FROM persona_tokens WHERE revoked_at IS NULL AND expires_at < ?');
        $stmt2->execute([$cutoff]);
        $deleted += $stmt2->rowCount();

        return $deleted;
    }
}
