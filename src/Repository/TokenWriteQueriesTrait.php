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
     * Retourne le nombre de lignes affectées (0 si déjà traité/introuvable,
     * si le token a été invalidé, ou si la soumission n'est plus en_cours).
     *
     * R3 (audit 2026-09-14) — CAS : n'accepter la validation que si le token
     * est encore actif (done_at/invalidated_at NULL) ET que la soumission est
     * toujours en_cours. Un premier commit gagne ; une action concurrente
     * (cancel/déjà validée) fait échouer ce CAS (0 ligne) au lieu d'écrire sur
     * un dossier clôturé.
     */
    public function markDoneByTokenValue(string $token, string $doneAt): int
    {
        $stmt = $this->pdo()->prepare(
            "UPDATE tokens SET done_at = ?
             WHERE token = ? AND done_at IS NULL AND invalidated_at IS NULL
               AND EXISTS (SELECT 1 FROM submissions s WHERE s.id = tokens.submission_id AND s.status = ?)"
        );
        $stmt->execute([$doneAt, $token, \App\Enum\SubmissionStatus::EnCours->value]);
        return $stmt->rowCount();
    }

    /**
     * Marque un token comme traité + invalidé (done_at + invalidated_at),
     * uniquement s'il est encore actif (WHERE done_at IS NULL AND
     * invalidated_at IS NULL) et que la soumission est en_cours — même
     * protection race-condition que tryInvalidateForDelegation().
     * Utilisé par TokenService::regenerate().
     * Retourne true si le token a bien été marqué, false s'il était déjà
     * traité/invalidé, si la soumission n'est plus en_cours, ou introuvable.
     */
    public function markDoneAndInvalidatedById(string $tokenId, string $doneAt, string $invalidatedAt): bool
    {
        $stmt = $this->pdo()->prepare(
            "UPDATE tokens SET done_at = ?, invalidated_at = ?
             WHERE id = ? AND done_at IS NULL AND invalidated_at IS NULL
               AND EXISTS (SELECT 1 FROM submissions s WHERE s.id = tokens.submission_id AND s.status = ?)"
        );
        $stmt->execute([$doneAt, $invalidatedAt, $tokenId, \App\Enum\SubmissionStatus::EnCours->value]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Invalide+done atomique avec WHERE done_at IS NULL AND invalidated_at
     * IS NULL sur un token d'une soumission encore en_cours.
     * Utilisé par TokenService::delegate() pour gérer la race condition.
     * Retourne le nombre de lignes affectées.
     */
    public function tryInvalidateForDelegation(string $tokenId): int
    {
        $stmt = $this->pdo()->prepare(
            "UPDATE tokens SET done_at = datetime('now'), invalidated_at = datetime('now')
             WHERE id = ? AND done_at IS NULL AND invalidated_at IS NULL
               AND EXISTS (SELECT 1 FROM submissions s WHERE s.id = tokens.submission_id AND s.status = ?)"
        );
        $stmt->execute([$tokenId, \App\Enum\SubmissionStatus::EnCours->value]);
        return $stmt->rowCount();
    }

    /**
     * Revendique atomiquement (compare-and-swap) le créneau de relance suivant
     * d'un token, AVANT l'envoi SMTP.
     *
     * R2 (audit 2026-09-14) — remind.php : deux exécutions concurrentes
     * (Task Scheduler toutes les 12h + lazy_cron web) peuvent lire le même
     * relance_count et envoyer chacune un mail (relance_count écrasé, plafond
     * relance_max contourné). Le CAS sur relance_count garantit qu'une seule
     * remporte le créneau ; le filtre invalidated_at empêche de revendiquer un
     * token invalidé (délégation/régénération/RGPD) entre la lecture et la
     * revendication.
     *
     * Ceci évite un flock : avec le CAS, le perdant(s) s'abstiennent car
     * relance_count a changé (0 lignes affectées). SQLite sérialise de toute
     * façon les writers entre eux.
     *
     * @param int $expectedCount relance_count lu par l'appelant (valeur attendue)
     * @return int nombre de lignes affectées (1 = créneau revendiqué, 0 = course perdue)
     */
    public function tryClaimRelance(string $tokenId, int $expectedCount, string $relanceAt): int
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE tokens SET relance_count = COALESCE(relance_count, 0) + 1, relance_at = ?
             WHERE id = ? AND done_at IS NULL AND invalidated_at IS NULL
               AND COALESCE(relance_count, 0) = CAST(? AS INTEGER)'
        );
        $stmt->execute([$relanceAt, $tokenId, $expectedCount]);
        return $stmt->rowCount();
    }

    /**
     * Libère une revendication de relance dont l'envoi a échoué (CAS inverse).
     * Restaure relance_count et relance_at à leur valeur d'avant la
     * revendication pour ne pas compter une relance non envoyée ni bloquer la
     * suivante. Le CAS sur relance_count = $claimedCount évite de raboter une
     * relance ultérieure revendiquée manuellement entre-temps.
     *
     * @return int nombre de lignes affectées (1 = libéré, 0 = état déjà modifié)
     */
    public function releaseRelanceClaim(string $tokenId, int $claimedCount, int $previousCount, ?string $previousRelanceAt): int
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE tokens SET relance_count = ?, relance_at = ?
             WHERE id = ? AND COALESCE(relance_count, 0) = CAST(? AS INTEGER)'
        );
        $stmt->execute([$previousCount, $previousRelanceAt, $tokenId, $claimedCount]);
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
