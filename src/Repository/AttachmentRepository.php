<?php

declare(strict_types=1);

namespace App\Repository;

final class AttachmentRepository extends BaseRepository
{
    /**
     * @return array{id: string, submission_id: string, field_name: string, original_name: string, stored_name: string, mime_type: string, file_size: int, file_data: string|null, uploaded_at: string}|null
     */
    public function findById(string $id): ?array
    {
        /** @var array{id: string, submission_id: string, field_name: string, original_name: string, stored_name: string, mime_type: string, file_size: int, file_data: string|null, uploaded_at: string}|null $result */
        $result = $this->fetchOne('SELECT id, submission_id, field_name, original_name, stored_name, mime_type, file_size, file_data, uploaded_at FROM attachments WHERE id = ?', [$id]);
        return $result;
    }

    /**
     * @return array<int, array{id: string, submission_id: string, field_name: string, original_name: string, stored_name: string, mime_type: string, file_size: int, file_data: string|null, uploaded_at: string}>
     */
    public function findBySubmission(string $submissionId): array
    {
        /** @var array<int, array{id: string, submission_id: string, field_name: string, original_name: string, stored_name: string, mime_type: string, file_size: int, file_data: string|null, uploaded_at: string}> $result */
        $result = $this->fetchAll(
            'SELECT id, submission_id, field_name, original_name, stored_name, mime_type, file_size, file_data, uploaded_at FROM attachments WHERE submission_id = ? ORDER BY uploaded_at ASC',
            [$submissionId]
        );
        return $result;
    }

    /**
     * @param array{submission_id: string, field_name: string, original_name: string, mime_type: string, file_size: int, file_data: string|null} $data
     */
    public function create(array $data): string
    {
        $id = \generate_uuid();
        $this->execute(
            "INSERT INTO attachments (id, submission_id, field_name, original_name, stored_name, mime_type, file_size, file_data, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))",
            [$id, $data['submission_id'], $data['field_name'], $data['original_name'], $data['original_name'], $data['mime_type'], $data['file_size'], $data['file_data']]
        );
        return $id;
    }

    /**
     * @return array<int, array{id: string, field_name: string, original_name: string, mime_type: string, file_size: int, uploaded_at: string}>
     */
    public function findForExport(string $submissionId): array
    {
        /** @var array<int, array{id: string, field_name: string, original_name: string, mime_type: string, file_size: int, uploaded_at: string}> $result */
        $result = $this->fetchAll(
            'SELECT id, field_name, original_name, mime_type, file_size, uploaded_at
             FROM attachments WHERE submission_id = ? ORDER BY uploaded_at',
            [$submissionId]
        );
        return $result;
    }

    public function countAll(): int
    {
        /** @var array{cnt: int}|null $result */
        $result = $this->fetchOne('SELECT COUNT(*) as cnt FROM attachments');
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * Supprime toutes les pièces jointes d'une soumission (mono-id).
     * Utilisé par RgpdService::deleteUserData() et autoPurge().
     */
    public function deleteBySubmissionId(string $submissionId): int
    {
        $stmt = $this->pdo()->prepare('DELETE FROM attachments WHERE submission_id = ?');
        $stmt->execute([$submissionId]);
        return $stmt->rowCount();
    }

    /**
     * Taille totale de toutes les pièces jointes (SUM(file_size)).
     * Utilisé par StatsService::getGlobalStats().
     */
    public function getTotalFileSize(): int
    {
        /** @var array{total: int|string|null}|null $result */
        $result = $this->fetchOne('SELECT COALESCE(SUM(file_size), 0) as total FROM attachments');
        return (int) ($result['total'] ?? 0);
    }
}
