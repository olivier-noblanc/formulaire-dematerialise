<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Repository pour la table `delegations` (traçabilité des délégations de tokens).
 *
 * Sépare l'accès à PDO du service TokenService (delegate) et RgpdService
 * (anonymisation RGPD des délégations).
 */
final class DelegationRepository extends BaseRepository
{
    /**
     * Insère une entrée de délégation (audit trail d'un token délégué).
     */
    public function insertDelegation(
        string $id,
        string $tokenId,
        string $fromEmail,
        string $toEmail,
        string $reason,
        string $delegatedAt,
        string $newTokenId
    ): bool {
        return $this->execute(
            'INSERT INTO delegations (id, token_id, from_email, to_email, reason, delegated_at, new_token_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $tokenId, $fromEmail, $toEmail, $reason, $delegatedAt, $newTokenId]
        );
    }

    /**
     * Supprime les délégations dont le token appartient à une soumission donnée.
     * Retourne le nombre de lignes supprimées.
     */
    public function deleteBySubmissionId(string $submissionId): int
    {
        $stmt = $this->pdo()->prepare('DELETE FROM delegations WHERE token_id IN (SELECT id FROM tokens WHERE submission_id = ?)');
        $stmt->execute([$submissionId]);
        return $stmt->rowCount();
    }

    /**
     * Anonymise le champ from_email pour toutes les délégations d'un email donné.
     */
    public function anonymizeFromEmail(string $oldEmail, string $newEmail): int
    {
        $stmt = $this->pdo()->prepare('UPDATE delegations SET from_email = ? WHERE from_email = ?');
        $stmt->execute([$newEmail, $oldEmail]);
        return $stmt->rowCount();
    }

    /**
     * Anonymise le champ to_email pour toutes les délégations d'un email donné.
     */
    public function anonymizeToEmail(string $oldEmail, string $newEmail): int
    {
        $stmt = $this->pdo()->prepare('UPDATE delegations SET to_email = ? WHERE to_email = ?');
        $stmt->execute([$newEmail, $oldEmail]);
        return $stmt->rowCount();
    }
}
