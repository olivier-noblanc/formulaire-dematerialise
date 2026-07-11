<?php
declare(strict_types=1);

namespace App\Repository;

final class AttachmentRepository extends BaseRepository
{
    public function findById(string $id): ?array
    {
        return $this->fetchOne("SELECT * FROM attachments WHERE id = ?", [$id]);
    }

    public function findBySubmission(string $submissionId): array
    {
        return $this->fetchAll(
            "SELECT * FROM attachments WHERE submission_id = ? ORDER BY uploaded_at ASC",
            [$submissionId]
        );
    }

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
        return $this->execute("DELETE FROM attachments WHERE id = ?", [$id]);
    }

    public function deleteBySubmission(string $submissionId): bool
    {
        return $this->execute("DELETE FROM attachments WHERE submission_id = ?", [$submissionId]);
    }

    public function findBySubmissionWithUploader(string $submissionId): array
    {
        return $this->fetchAll(
            "SELECT a.*, u.display_name as uploader_name
             FROM attachments a
             LEFT JOIN users u ON u.email = a.uploaded_by
             WHERE a.submission_id = ?
             ORDER BY a.uploaded_at ASC",
            [$submissionId]
        );
    }

    public function countAll(): int
    {
        $result = $this->fetchOne("SELECT COUNT(*) as cnt FROM attachments");
        return (int) ($result['cnt'] ?? 0);
    }
}
