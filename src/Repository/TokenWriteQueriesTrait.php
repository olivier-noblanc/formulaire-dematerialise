<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Trait contenant les méthodes WRITE (insert, update, delete) de TokenRepository.
 */
trait TokenWriteQueriesTrait
{
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

    /**
     * Insère un nouveau token.
     */
    public function insertToken(
        string $id,
        string $submissionId,
        string $stepId,
        string $email,
        string $token,
        string $sentAt,
        string $expiresAt
    ): bool {
        return $this->execute(
            'INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $submissionId, $stepId, $email, $token, $sentAt, $expiresAt]
        );
    }

    /**
     * Marque un token comme traité (done_at) par sa valeur de token.
     * Retourne le nombre de lignes affectées (0 si déjà traité ou introuvable).
     */
    public function markDoneByTokenValue(string $token, string $doneAt): int
    {
        $stmt = $this->pdo()->prepare('UPDATE tokens SET done_at = ? WHERE token = ? AND done_at IS NULL');
        $stmt->execute([$doneAt, $token]);
        return $stmt->rowCount();
    }

    /**
     * Marque un token comme traité + invalidé (done_at + invalidated_at).
     * Utilisé par TokenService::regenerate() et TokenService::delegate().
     * Retourne le nombre de lignes affectées.
     */
    public function markDoneAndInvalidatedById(string $tokenId, string $doneAt, string $invalidatedAt): bool
    {
        return $this->execute(
            'UPDATE tokens SET done_at = ?, invalidated_at = ? WHERE id = ?',
            [$doneAt, $invalidatedAt, $tokenId]
        );
    }

    /**
     * Invalide+done atomique avec WHERE done_at IS NULL AND invalidated_at IS NULL.
     * Utilisé par TokenService::delegate() pour gérer la race condition.
     * Retourne le nombre de lignes affectées.
     */
    public function tryInvalidateForDelegation(string $tokenId): int
    {
        $stmt = $this->pdo()->prepare("UPDATE tokens SET done_at = datetime('now'), invalidated_at = datetime('now') WHERE id = ? AND done_at IS NULL AND invalidated_at IS NULL");
        $stmt->execute([$tokenId]);
        return $stmt->rowCount();
    }

    /**
     * Met à jour relance_count et relance_at uniquement si le token est
     * encore pending (done_at IS NULL). Utilisé par TokenService::remind()
     * pour gérer la race condition validation/refus concurrente.
     * Retourne le nombre de lignes affectées.
     */
    public function updateRelanceCountIfPending(string $tokenId, int $newCount, string $relanceAt): int
    {
        $stmt = $this->pdo()->prepare('UPDATE tokens SET relance_count = ?, relance_at = ? WHERE id = ? AND done_at IS NULL');
        $stmt->execute([$newCount, $relanceAt, $tokenId]);
        return $stmt->rowCount();
    }

    /**
     * Invalide tous les tokens actifs (done_at IS NULL, invalidated_at IS NULL)
     * d'un email donné. Utilisé par RgpdService::deleteUserData() avant
     * l'anonymisation de l'agent.
     * Retourne le nombre de lignes affectées.
     */
    public function invalidateActiveByEmail(string $email, string $now): int
    {
        $stmt = $this->pdo()->prepare('UPDATE tokens SET invalidated_at = ? WHERE email = ? AND done_at IS NULL AND invalidated_at IS NULL');
        $stmt->execute([$now, $email]);
        return $stmt->rowCount();
    }

    /**
     * Invalide tous les tokens actifs d'une soumission donnée.
     * Utilisé par TokenService::cancel().
     */
    public function invalidateActiveBySubmission(string $submissionId, string $now): int
    {
        $stmt = $this->pdo()->prepare('UPDATE tokens SET invalidated_at = ? WHERE submission_id = ? AND done_at IS NULL AND invalidated_at IS NULL');
        $stmt->execute([$now, $submissionId]);
        return $stmt->rowCount();
    }

    /**
     * Met à jour l'email d'un token (anonymisation RGPD).
     * Utilisé par RgpdService::deleteUserData().
     */
    public function updateEmailByOldEmail(string $oldEmail, string $newEmail): int
    {
        $stmt = $this->pdo()->prepare('UPDATE tokens SET email = ? WHERE email = ?');
        $stmt->execute([$newEmail, $oldEmail]);
        return $stmt->rowCount();
    }

    /**
     * Supprime tous les tokens d'une soumission (mono-id).
     * Utilisé par RgpdService::autoPurge() (parcours soumission par soumission).
     */
    public function deleteBySubmissionId(string $submissionId): int
    {
        $stmt = $this->pdo()->prepare('DELETE FROM tokens WHERE submission_id = ?');
        $stmt->execute([$submissionId]);
        return $stmt->rowCount();
    }
}
