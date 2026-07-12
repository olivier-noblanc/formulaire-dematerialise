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

    public function findActiveByFormAndSubmitter(string $formId, string $submittedBy): ?array
    {
        return $this->fetchOne(
            "SELECT id, submitted_at FROM submissions
             WHERE form_id = ? AND submitted_by = ? AND status = 'en_cours'
             ORDER BY submitted_at DESC LIMIT 1",
            [$formId, $submittedBy]
        );
    }

    public function createWithRgpd(array $data): string
    {
        $id = \generate_uuid();
        $this->execute(
            "INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, rgpd_consent)
             VALUES (?,?,?,?,?,?)",
            [$id, $data['form_id'], $data['data'], $data['submitted_by'], $data['submitted_at'], $data['rgpd_consent']]
        );
        return $id;
    }

    public function deleteById(string $id): bool
    {
        return $this->execute("DELETE FROM submissions WHERE id = ?", [$id]);
    }

    public function getSubmitterById(string $id): ?string
    {
        $result = $this->fetchOne("SELECT submitted_by FROM submissions WHERE id = ?", [$id]);
        return $result !== null ? (string) $result['submitted_by'] : null;
    }

    /**
     * Paginated submissions with form join for the dashboard.
     *
     * @param array<int, mixed> $params WHERE parameters
     * @param string $whereSql Pre-built WHERE clause (e.g. "1=1 AND s.status = ?")
     * @param int $limit
     * @param int $offset
     * @return array<int, array<string, mixed>>
     */
    public function findPaginatedWithForm(string $whereSql, array $params, int $limit, int $offset): array
    {
        return $this->fetchAll(
            "SELECT s.*, f.label as form_label, f.slug as form_slug, f.deadline_field
             FROM submissions s
             JOIN forms f ON f.id = s.form_id
             WHERE $whereSql
             ORDER BY s.submitted_at DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
    }

    public function countWithForm(string $whereSql, array $params): int
    {
        $result = $this->fetchOne(
            "SELECT COUNT(*) as cnt FROM submissions s JOIN forms f ON f.id = s.form_id WHERE $whereSql",
            $params
        );
        return (int) ($result['cnt'] ?? 0);
    }

    public function findByIdWithForm(string $id): ?array
    {
        return $this->fetchOne(
            "SELECT s.*, f.label as form_label, f.slug as form_slug, f.deadline_field
             FROM submissions s
             JOIN forms f ON f.id = s.form_id
             WHERE s.id = ?",
            [$id]
        );
    }

    public function cancelById(string $id): bool
    {
        return $this->execute(
            "UPDATE submissions SET status = 'annule', closed_at = datetime('now') WHERE id = ? AND status = 'en_cours'",
            [$id]
        );
    }

    public function deleteCascade(string $id): bool
    {
        $this->pdo()->exec('PRAGMA foreign_keys = ON');
        $this->execute("DELETE FROM submission_validator_data WHERE submission_id = ?", [$id]);
        $this->execute("DELETE FROM alert_log WHERE submission_id = ?", [$id]);
        $this->execute("DELETE FROM tokens WHERE submission_id = ?", [$id]);
        $this->execute("DELETE FROM attachments WHERE submission_id = ?", [$id]);
        return $this->execute("DELETE FROM submissions WHERE id = ?", [$id]);
    }

    public function countByForm(string $formId): int
    {
        $result = $this->fetchOne("SELECT COUNT(*) as cnt FROM submissions WHERE form_id = ?", [$formId]);
        return (int) ($result['cnt'] ?? 0);
    }

    public function getStatusCountsByForm(string $formId): array
    {
        return $this->fetchAll(
            "SELECT status, COUNT(*) as cnt FROM submissions WHERE form_id = ? GROUP BY status",
            [$formId]
        );
    }

    public function findPaginatedByForm(string $formId, int $limit, int $offset): array
    {
        return $this->fetchAll(
            "SELECT s.id, s.data, s.submitted_by, s.submitted_at, s.status, s.closed_at
             FROM submissions s
             WHERE s.form_id = ?
             ORDER BY s.submitted_at DESC
             LIMIT ? OFFSET ?",
            [$formId, $limit, $offset]
        );
    }

    public function getStatusCountsBySubmitter(string $email): array
    {
        return $this->fetchAll(
            "SELECT status, COUNT(*) as cnt FROM submissions WHERE submitted_by = ? GROUP BY status",
            [$email]
        );
    }

    public function findPaginatedBySubmitter(string $email, string $whereSql, array $params, int $limit, int $offset): array
    {
        return $this->fetchAll(
            "SELECT s.id, s.form_id, s.data, s.submitted_at, s.status, s.closed_at,
                    f.label as form_label, f.slug as form_slug, f.description as form_description, f.deadline_field
             FROM submissions s
             JOIN forms f ON f.id = s.form_id
             WHERE $whereSql
             ORDER BY s.submitted_at DESC",
            $params
        );
    }

    public function getValidatorDataFilledByEmail(string $email): array
    {
        return $this->fetchAll(
            "SELECT submission_id, field_name, field_label, field_type, value,
                    filled_at, step_id, step_label
             FROM submission_validator_data
             WHERE filled_by_email = ?
             ORDER BY filled_at DESC, field_name",
            [$email]
        );
    }

    public function getValidatorDataOnSubmissionsByEmail(string $email): array
    {
        return $this->fetchAll(
            "SELECT svd.submission_id, svd.field_name, svd.field_label, svd.field_type,
                    svd.value, svd.filled_at, svd.step_id, svd.step_label, svd.filled_by_email
             FROM submission_validator_data svd
             JOIN submissions s ON s.id = svd.submission_id
             WHERE s.submitted_by = ?
             ORDER BY svd.submission_id, svd.filled_at, svd.field_name",
            [$email]
        );
    }

    public function deleteValidatorDataBySubmitter(string $email): bool
    {
        return $this->execute(
            "DELETE FROM submission_validator_data
             WHERE submission_id IN (SELECT id FROM submissions WHERE submitted_by = ?)",
            [$email]
        );
    }

    public function deleteValidatorDataByEmail(string $email): bool
    {
        return $this->execute(
            "DELETE FROM submission_validator_data WHERE filled_by_email = ?",
            [$email]
        );
    }

    public function purgeOrphanValidatorData(): bool
    {
        $this->pdo()->exec('PRAGMA foreign_keys = ON');
        return $this->execute(
            "DELETE FROM submission_validator_data
             WHERE submission_id NOT IN (SELECT id FROM submissions)"
        );
    }

    public function countAll(): int
    {
        $result = $this->fetchOne("SELECT COUNT(*) as cnt FROM submissions");
        return (int) ($result['cnt'] ?? 0);
    }

    public function countOldByRetention(int $retentionMonths): int
    {
        $result = $this->fetchOne(
            "SELECT COUNT(*) as cnt FROM submissions WHERE status != 'en_cours' AND closed_at < datetime('now', '-' || ? || ' months')",
            [$retentionMonths]
        );
        return (int) ($result['cnt'] ?? 0);
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

    public function getValidatorDataOrdered(string $submissionId): array
    {
        return $this->fetchAll(
            "SELECT * FROM submission_validator_data WHERE submission_id = ? ORDER BY filled_at ASC, field_name ASC",
            [$submissionId]
        );
    }

    public function saveValidatorData(string $submissionId, string $fieldName, string $value, string $filledBy, ?string $stepId = null): void
    {
        $labelStmt = $this->pdo()->prepare("SELECT label FROM form_fields WHERE field_name = ?");
        $labelStmt->execute([$fieldName]);
        $fieldLabel = (string) ($labelStmt->fetchColumn() ?: $fieldName);

        $this->execute(
            "INSERT OR REPLACE INTO submission_validator_data (submission_id, field_name, field_label, value, filled_by, filled_by_email, step_id, filled_at) VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))",
            [$submissionId, $fieldName, $fieldLabel, $value, 'validator', $filledBy, $stepId]
        );
    }

    public function deleteValidatorData(string $submissionId, string $fieldName): void
    {
        $this->execute(
            "DELETE FROM submission_validator_data WHERE submission_id = ? AND field_name = ?",
            [$submissionId, $fieldName]
        );
    }

    public function findValidatorDataByEmail(string $email, int $limit = 50): array
    {
        return $this->fetchAll(
            "SELECT svd.*, s.form_id, f.label as form_label
             FROM submission_validator_data svd
             JOIN submissions s ON s.id = svd.submission_id
             JOIN forms f ON f.id = s.form_id
             WHERE svd.filled_by_email = ?
             ORDER BY svd.filled_at DESC
             LIMIT ?",
            [$email, $limit]
        );
    }
}
