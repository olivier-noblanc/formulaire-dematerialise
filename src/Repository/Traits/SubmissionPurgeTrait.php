<?php

declare(strict_types=1);

namespace App\Repository\Traits;

/**
 * Trait regroupant les méthodes de purge, workflow et RGPD.
 *
 * Utilisé par SubmissionRepository.
 *
 * @method bool execute(string $sql, array<int, mixed> $params = [])
 * @method \PDO pdo()
 */
trait SubmissionPurgeTrait
{
    /**
     * Supprime une soumission et toutes ses dépendances en transaction.
     */
    public function deleteCascade(string $id): bool
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $this->execute('DELETE FROM submission_validator_data WHERE submission_id = ?', [$id]);
            $this->execute('DELETE FROM alert_log WHERE submission_id = ?', [$id]);
            $this->execute('DELETE FROM tokens WHERE submission_id = ?', [$id]);
            $this->execute('DELETE FROM attachments WHERE submission_id = ?', [$id]);
            $result = $this->execute('DELETE FROM submissions WHERE id = ?', [$id]);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function countPurgeableByCutoff(string $cutoff): int
    {
        /** @var array{cnt: int|string|null}|null $result */
        $result = $this->fetchOne(
            "SELECT COUNT(*) as cnt FROM submissions
             WHERE status IN ('" . \App\Enum\SubmissionStatus::Valide->value . "', '" . \App\Enum\SubmissionStatus::Refuse->value . "') AND closed_at IS NOT NULL AND closed_at < ?",
            [$cutoff]
        );
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * @return list<string>
     */
    public function findPurgeableIds(string $cutoff): array
    {
        /** @var list<array{id: string}> $rows */
        $rows = $this->fetchAll(
            "SELECT id FROM submissions
             WHERE status IN ('" . \App\Enum\SubmissionStatus::Valide->value . "', '" . \App\Enum\SubmissionStatus::Refuse->value . "') AND closed_at IS NOT NULL AND closed_at < ?",
            [$cutoff]
        );
        return array_column($rows, 'id');
    }

    /**
     * @param array<int, string> $ids
     */
    public function deleteByIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo()->prepare("DELETE FROM submissions WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        return $stmt->rowCount();
    }

    /**
     * Clôture une soumission avec un statut donné (Valide/Refuse/Annule).
     */
    public function closeWithStatus(string $id, string $now, string $status): bool
    {
        return $this->execute(
            'UPDATE submissions SET closed_at = ?, status = ? WHERE id = ?',
            [$now, $status, $id]
        );
    }

    /**
     * Annule toutes les soumissions en_cours d'un demandeur (status=annule, closed_at=now).
     */
    public function cancelActiveBySubmitter(string $email, string $now): int
    {
        $stmt = $this->pdo()->prepare("UPDATE submissions SET closed_at = ?, status = '" . \App\Enum\SubmissionStatus::Annule->value . "' WHERE submitted_by = ? AND status = '" . \App\Enum\SubmissionStatus::EnCours->value . "' AND closed_at IS NULL");
        $stmt->execute([$now, $email]);
        return $stmt->rowCount();
    }

    /**
     * Récupère (id, data) pour toutes les soumissions d'un demandeur.
     *
     * @return list<array{id: string, data: string}>
     */
    public function findIdAndDataBySubmitter(string $email): array
    {
        /** @var list<array{id: string, data: string}> $result */
        $result = $this->fetchAll(
            'SELECT id, data FROM submissions WHERE submitted_by = ?',
            [$email]
        );
        return $result;
    }

    /**
     * Met à jour submitted_by et data pour une soumission (anonymisation RGPD).
     */
    public function updateSubmittedByAndData(string $id, string $submittedBy, string $data): bool
    {
        return $this->execute(
            'UPDATE submissions SET submitted_by = ?, data = ? WHERE id = ?',
            [$submittedBy, $data, $id]
        );
    }

    /**
     * Récupère les IDs des soumissions purgables (status != en_cours ET closed_at < cutoff).
     *
     * @return list<string>
     */
    public function findIdsPurgeableByCutoffForRgpd(string $cutoff): array
    {
        /** @var list<array{id: string}> $rows */
        $rows = $this->fetchAll(
            "SELECT id FROM submissions WHERE status != '" . \App\Enum\SubmissionStatus::EnCours->value . "' AND closed_at < ?",
            [$cutoff]
        );
        return array_values(array_map(static fn(array $r): string => (string) $r['id'], $rows));
    }

    /**
     * Récupère les soumissions d'un demandeur pour export RGPD (avec form_label).
     *
     * @return list<array{id: string, form_id: string, data: string, submitted_by: string, submitted_at: string|null, closed_at: string|null, status: string, admin_comment: string, rgpd_consent: int|null, form_label: string}>
     */
    public function findForRgpdExportByEmail(string $email): array
    {
        /** @var list<array{id: string, form_id: string, data: string, submitted_by: string, submitted_at: string|null, closed_at: string|null, status: string, admin_comment: string, rgpd_consent: int|null, form_label: string}> $result */
        $result = $this->fetchAll(
            'SELECT s.id, s.form_id, s.data, s.submitted_by, s.submitted_at, s.closed_at, s.status, s.admin_comment, s.rgpd_consent, f.label as form_label FROM submissions s JOIN forms f ON f.id = s.form_id WHERE s.submitted_by = ? ORDER BY s.submitted_at DESC',
            [$email]
        );
        return $result;
    }
}
