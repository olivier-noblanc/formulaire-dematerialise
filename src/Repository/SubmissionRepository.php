<?php

declare(strict_types=1);

namespace App\Repository;

final class SubmissionRepository extends BaseRepository
{
    /** @return array<string, mixed>|null */
    public function findById(string $id): ?array
    {
        return $this->fetchOne('SELECT * FROM submissions WHERE id = ?', [$id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function findByForm(string $formId, ?string $status = null): array
    {
        $sql = 'SELECT * FROM submissions WHERE form_id = ?';
        $params = [$formId];
        if ($status !== null) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        return $this->fetchAll($sql . ' ORDER BY submitted_at DESC', $params);
    }

    /** @return array<int, array<string, mixed>> */
    public function findBySubmitter(string $email): array
    {
        return $this->fetchAll(
            'SELECT * FROM submissions WHERE submitted_by = ? ORDER BY submitted_at DESC',
            [$email]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByFormAndSubmitter(string $formId, string $submittedBy): ?array
    {
        return $this->fetchOne(
            "SELECT id, submitted_at FROM submissions
             WHERE form_id = ? AND submitted_by = ? AND status = 'en_cours'
             ORDER BY submitted_at DESC LIMIT 1",
            [$formId, $submittedBy]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createWithRgpd(array $data): string
    {
        $id = \generate_uuid();
        $this->execute(
            'INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, rgpd_consent)
             VALUES (?,?,?,?,?,?)',
            [$id, $data['form_id'], $data['data'], $data['submitted_by'], $data['submitted_at'], $data['rgpd_consent']]
        );
        return $id;
    }

    public function deleteById(string $id): bool
    {
        return $this->execute('DELETE FROM submissions WHERE id = ?', [$id]);
    }

    public function getSubmitterById(string $id): ?string
    {
        $result = $this->fetchOne('SELECT submitted_by FROM submissions WHERE id = ?', [$id]);
        return $result !== null ? (string) $result['submitted_by'] : null;
    }

    /**
     * Paginated submissions with form join for the dashboard.
     *
     * @param array<int, mixed> $params WHERE parameters
     * @param string $whereSql Pre-built WHERE clause (e.g. "1=1 AND s.status = ?")
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

    /**
     * @param array<int, mixed> $params
     */
    public function countWithForm(string $whereSql, array $params): int
    {
        $result = $this->fetchOne(
            "SELECT COUNT(*) as cnt FROM submissions s JOIN forms f ON f.id = s.form_id WHERE $whereSql",
            $params
        );
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdWithForm(string $id): ?array
    {
        return $this->fetchOne(
            'SELECT s.*, f.label as form_label, f.slug as form_slug, f.deadline_field
             FROM submissions s
             JOIN forms f ON f.id = s.form_id
             WHERE s.id = ?',
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

    public function countByForm(string $formId): int
    {
        $result = $this->fetchOne('SELECT COUNT(*) as cnt FROM submissions WHERE form_id = ?', [$formId]);
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getStatusCountsByForm(string $formId): array
    {
        return $this->fetchAll(
            'SELECT status, COUNT(*) as cnt FROM submissions WHERE form_id = ? GROUP BY status',
            [$formId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findPaginatedByForm(string $formId, int $limit, int $offset): array
    {
        return $this->fetchAll(
            'SELECT s.id, s.data, s.submitted_by, s.submitted_at, s.status, s.closed_at
             FROM submissions s
             WHERE s.form_id = ?
             ORDER BY s.submitted_at DESC
             LIMIT ? OFFSET ?',
            [$formId, $limit, $offset]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getStatusCountsBySubmitter(string $email): array
    {
        return $this->fetchAll(
            'SELECT status, COUNT(*) as cnt FROM submissions WHERE submitted_by = ? GROUP BY status',
            [$email]
        );
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function findPaginatedBySubmitter(string $email, string $whereSql, array $params, int $limit, int $offset): array
    {
        $sql = "SELECT s.id, s.form_id, s.data, s.submitted_at, s.status, s.closed_at,
                    f.label as form_label, f.slug as form_slug, f.description as form_description, f.deadline_field
             FROM submissions s
             JOIN forms f ON f.id = s.form_id
             WHERE $whereSql
             ORDER BY s.submitted_at DESC";
        if ($limit > 0) {
            $sql .= " LIMIT $limit OFFSET $offset";
        }
        return $this->fetchAll($sql, $params);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getValidatorDataFilledByEmail(string $email): array
    {
        return $this->fetchAll(
            'SELECT submission_id, field_name, field_label, field_type, value,
                    filled_at, step_id, step_label
             FROM submission_validator_data
             WHERE filled_by_email = ?
             ORDER BY filled_at DESC, field_name',
            [$email]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getValidatorDataOnSubmissionsByEmail(string $email): array
    {
        return $this->fetchAll(
            'SELECT svd.submission_id, svd.field_name, svd.field_label, svd.field_type,
                    svd.value, svd.filled_at, svd.step_id, svd.step_label, svd.filled_by_email
             FROM submission_validator_data svd
             JOIN submissions s ON s.id = svd.submission_id
             WHERE s.submitted_by = ?
             ORDER BY svd.submission_id, svd.filled_at, svd.field_name',
            [$email]
        );
    }

    public function deleteValidatorDataBySubmitter(string $email): bool
    {
        return $this->execute(
            'DELETE FROM submission_validator_data
             WHERE submission_id IN (SELECT id FROM submissions WHERE submitted_by = ?)',
            [$email]
        );
    }

    public function deleteValidatorDataByEmail(string $email): bool
    {
        return $this->execute(
            'DELETE FROM submission_validator_data WHERE filled_by_email = ?',
            [$email]
        );
    }

    public function purgeOrphanValidatorData(): bool
    {
        $this->pdo()->exec('PRAGMA foreign_keys = ON');
        return $this->execute(
            'DELETE FROM submission_validator_data
             WHERE submission_id NOT IN (SELECT id FROM submissions)'
        );
    }

    public function countAll(): int
    {
        $result = $this->fetchOne('SELECT COUNT(*) as cnt FROM submissions');
        return (int) ($result['cnt'] ?? 0);
    }

    public function getAvgProcessingTime(): float
    {
        $result = $this->fetchOne(
            "SELECT AVG(
                CAST(strftime('%s', s.closed_at) AS REAL) - CAST(strftime('%s', s.submitted_at) AS REAL)
            ) as avg_seconds
            FROM submissions s
            WHERE s.status = 'valide' AND s.closed_at IS NOT NULL"
        );
        return (float) ($result['avg_seconds'] ?? 0);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findActiveWithDeadlineField(): array
    {
        return $this->fetchAll(
            "SELECT s.id, s.data, s.submitted_by, s.submitted_at, s.form_id,
                   f.label as form_label, f.deadline_field
             FROM submissions s
             JOIN forms f ON f.id = s.form_id
             WHERE s.status = 'en_cours' AND f.deadline_field != ''"
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDailyCounts(int $days): array
    {
        return $this->fetchAll(
            "SELECT DATE(submitted_at) as day, COUNT(*) as cnt
             FROM submissions
             WHERE submitted_at >= datetime('now', '-' || ? || ' days')
             GROUP BY DATE(submitted_at)
             ORDER BY day DESC",
            [$days]
        );
    }

    public function countOldByRetention(int $retentionMonths): int
    {
        $result = $this->fetchOne(
            "SELECT COUNT(*) as cnt FROM submissions WHERE status != 'en_cours' AND closed_at < datetime('now', '-' || ? || ' months')",
            [$retentionMonths]
        );
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
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

    /**
     * @param array<string, mixed> $data
     */
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
            'UPDATE submissions SET status = ? WHERE id = ?',
            [$status, $id]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getValidatorData(string $submissionId, ?string $stepId = null): array
    {
        $sql = 'SELECT * FROM submission_validator_data WHERE submission_id = ?';
        $params = [$submissionId];
        if ($stepId !== null) {
            $sql .= ' AND step_id = ?';
            $params[] = $stepId;
        }
        return $this->fetchAll($sql . ' ORDER BY filled_at', $params);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getValidatorDataOrdered(string $submissionId): array
    {
        return $this->fetchAll(
            'SELECT * FROM submission_validator_data WHERE submission_id = ? ORDER BY filled_at ASC, field_name ASC',
            [$submissionId]
        );
    }

    public function saveValidatorData(string $submissionId, string $fieldName, string $value, string $filledBy, ?string $stepId = null): void
    {
        $labelStmt = $this->pdo()->prepare('SELECT label FROM form_fields WHERE field_name = ?');
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
            'DELETE FROM submission_validator_data WHERE submission_id = ? AND field_name = ?',
            [$submissionId, $fieldName]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findValidatorDataByEmail(string $email, int $limit = 50): array
    {
        return $this->fetchAll(
            'SELECT svd.*, s.form_id, f.label as form_label
             FROM submission_validator_data svd
             JOIN submissions s ON s.id = svd.submission_id
             JOIN forms f ON f.id = s.form_id
             WHERE svd.filled_by_email = ?
             ORDER BY svd.filled_at DESC
             LIMIT ?',
            [$email, $limit]
        );
    }

    public function countPurgeableByCutoff(string $cutoff): int
    {
        $result = $this->fetchOne(
            "SELECT COUNT(*) as cnt FROM submissions
             WHERE status IN ('valide', 'refuse') AND closed_at IS NOT NULL AND closed_at < ?",
            [$cutoff]
        );
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * @return array<int, string>
     */
    public function findPurgeableIds(string $cutoff): array
    {
        $rows = $this->fetchAll(
            "SELECT id FROM submissions
             WHERE status IN ('valide', 'refuse') AND closed_at IS NOT NULL AND closed_at < ?",
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
     * @param array<int, string> $submissionIds
     */
    public function deleteValidatorDataBySubmissionIds(array $submissionIds): int
    {
        if ($submissionIds === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
        $stmt = $this->pdo()->prepare("DELETE FROM submission_validator_data WHERE submission_id IN ($placeholders)");
        $stmt->execute($submissionIds);
        return $stmt->rowCount();
    }

    public function countValidatorDataPurgeable(string $cutoff): int
    {
        $result = $this->fetchOne(
            "SELECT COUNT(*) as cnt FROM submission_validator_data svd
             JOIN submissions s ON s.id = svd.submission_id
             WHERE s.status IN ('valide', 'refuse') AND s.closed_at IS NOT NULL AND s.closed_at < ?",
            [$cutoff]
        );
        return (int) ($result['cnt'] ?? 0);
    }

    public function getOldestSubmittedAt(): ?string
    {
        $result = $this->fetchOne('SELECT MIN(submitted_at) as val FROM submissions');
        return $result !== null && $result['val'] !== null ? (string) $result['val'] : null;
    }

    public function getNewestSubmittedAt(): ?string
    {
        $result = $this->fetchOne('SELECT MAX(submitted_at) as val FROM submissions');
        return $result !== null && $result['val'] !== null ? (string) $result['val'] : null;
    }

    /**
     * Dynamic row counts via UNION ALL for a list of table names.
     *
     * @param array<int, string> $tables
     * @return array<string, int>
     */
    public function countByTableNames(array $tables): array
    {
        $counts = [];
        $unionParts = [];
        foreach ($tables as $table) {
            $unionParts[] = "SELECT '" . $table . "' AS tbl, COUNT(*) AS cnt FROM " . $table;
        }
        $rows = $this->fetchAll(implode(' UNION ALL ', $unionParts));
        foreach ($rows as $row) {
            $counts[$row['tbl']] = (int) $row['cnt'];
        }
        return $counts;
    }

    public function existsBySubmitter(string $email): bool
    {
        $result = $this->fetchOne('SELECT 1 FROM submissions WHERE submitted_by = ? LIMIT 1', [$email]);
        return $result !== null;
    }

    /**
     * @return array<string, int>
     */
    public function countByStatusForSubmitter(string $email): array
    {
        $rows = $this->fetchAll(
            'SELECT status, COUNT(*) as cnt FROM submissions WHERE submitted_by = ? GROUP BY status',
            [$email]
        );
        $result = ['total' => 0, 'en_cours' => 0, 'valide' => 0];
        foreach ($rows as $row) {
            $result['total'] += (int) $row['cnt'];
            if ($row['status'] === 'en_cours') {
                $result['en_cours'] = (int) $row['cnt'];
            } elseif ($row['status'] === 'valide') {
                $result['valide'] = (int) $row['cnt'];
            }
        }
        return $result;
    }
}
