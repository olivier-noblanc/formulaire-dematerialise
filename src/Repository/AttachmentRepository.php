<?php

declare(strict_types=1);

namespace App\Repository;

final class AttachmentRepository extends BaseRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        return $this->fetchOne('SELECT * FROM attachments WHERE id = ?', [$id]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findBySubmission(string $submissionId): array
    {
        return $this->fetchAll(
            'SELECT * FROM attachments WHERE submission_id = ? ORDER BY uploaded_at ASC',
            [$submissionId]
        );
    }

    /**
     * @param array<string, mixed> $data
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

    public function delete(string $id): bool
    {
        return $this->execute('DELETE FROM attachments WHERE id = ?', [$id]);
    }

    public function deleteBySubmission(string $submissionId): bool
    {
        return $this->execute('DELETE FROM attachments WHERE submission_id = ?', [$submissionId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findBySubmissionWithUploader(string $submissionId): array
    {
        return $this->findBySubmission($submissionId);
    }

    public function countAll(): int
    {
        $result = $this->fetchOne('SELECT COUNT(*) as cnt FROM attachments');
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findForExport(string $submissionId): array
    {
        return $this->fetchAll(
            'SELECT id, field_name, original_name, mime_type, file_size, uploaded_at
             FROM attachments WHERE submission_id = ? ORDER BY uploaded_at',
            [$submissionId]
        );
    }
}
