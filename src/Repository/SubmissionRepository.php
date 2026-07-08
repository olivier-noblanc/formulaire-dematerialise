<?php
declare(strict_types=1);

namespace App\Repository;

final class SubmissionRepository extends BaseRepository
{
    public function findById(string $id): ?array
    {
        return $this->fetchOne("SELECT * FROM submissions WHERE id = ?", [$id]);
    }

    public function findByForm(string $formId, ?string $status = null): array
    {
        $sql = "SELECT * FROM submissions WHERE form_id = ?";
        $params = [$formId];
        if ($status !== null) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        return $this->fetchAll($sql . " ORDER BY submitted_at DESC", $params);
    }

    public function findBySubmitter(string $email): array
    {
        return $this->fetchAll(
            "SELECT * FROM submissions WHERE submitted_by = ? ORDER BY submitted_at DESC",
            [$email]
        );
    }

    public function findPendingForValidator(string $email): array
    {
        return $this->fetchAll(
            "SELECT s.*, t.id as token_id, t.step_id, t.action
             FROM submissions s
             JOIN tokens t ON t.submission_id = s.id
             WHERE t.email = ? AND t.done_at IS NULL AND t.expires_at > datetime('now')
             ORDER BY t.sent_at",
            [$email]
        );
    }

    public function create(array $data): string
    {
        $id = \generate_uuid();
        $this->execute(
            "INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, ?, ?, ?, datetime('now'))",
            [$id, $data['form_id'], $data['data'], $data['submitted_by'], $data['status'] ?? 'en_cours']
        );
        return $id;
    }

    public function updateStatus(string $id, string $status): bool
    {
        return $this->execute(
            "UPDATE submissions SET status = ? WHERE id = ?",
            [$status, $id]
        );
    }

    public function getValidatorData(string $submissionId, ?string $stepId = null): array
    {
        $sql = "SELECT * FROM submission_validator_data WHERE submission_id = ?";
        $params = [$submissionId];
        if ($stepId !== null) {
            $sql .= " AND step_id = ?";
            $params[] = $stepId;
        }
        return $this->fetchAll($sql . " ORDER BY filled_at", $params);
    }

    public function saveValidatorData(string $submissionId, string $fieldName, string $value, string $filledBy, ?string $stepId = null): void
    {
        $this->execute(
            "INSERT OR REPLACE INTO submission_validator_data (submission_id, field_name, field_label, value, filled_by, filled_by_email, step_id, filled_at) VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))",
            [$submissionId, $fieldName, $fieldName, $value, 'validator', $filledBy, $stepId]
        );
    }

    public function deleteValidatorData(string $submissionId, string $fieldName): void
    {
        $this->execute(
            "DELETE FROM submission_validator_data WHERE submission_id = ? AND field_name = ?",
            [$submissionId, $fieldName]
        );
    }
}
