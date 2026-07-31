<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\FilledBy;
use App\Enum\SubmissionStatus;

final class SubmissionRepository extends BaseRepository
{
    /**
     * @return array{id: string, submitted_at: string|null}|null
     */
    public function findActiveByFormAndSubmitter(string $formId, string $submittedBy): ?array
    {
        /** @var array{id: string, submitted_at: string|null}|null $result */
        $result = $this->fetchOne(
            "SELECT id, submitted_at FROM submissions
             WHERE form_id = ? AND submitted_by = ? AND status = '" . SubmissionStatus::EnCours->value . "'
             ORDER BY submitted_at DESC LIMIT 1",
            [$formId, $submittedBy]
        );
        return $result;
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
     * @return array<int, array{id: string, form_id: string, data: string, submitted_by: string, submitted_at: string|null, closed_at: string|null, status: string, admin_comment: string, rgpd_consent: int, form_label: string, form_slug: string, deadline_field: string}>
     */
    public function findPaginatedWithForm(string $whereSql, array $params, int $limit, int $offset): array
    {
        /** @var array<int, array{id: string, form_id: string, data: string, submitted_by: string, submitted_at: string|null, closed_at: string|null, status: string, admin_comment: string, rgpd_consent: int, form_label: string, form_slug: string, deadline_field: string}> $result */
        $result = $this->fetchAll(
            "SELECT s.id, s.form_id, s.data, s.submitted_by, s.submitted_at, s.closed_at, s.status, s.admin_comment, s.rgpd_consent, f.label as form_label, f.slug as form_slug, f.deadline_field
             FROM submissions s
             JOIN forms f ON f.id = s.form_id
             WHERE $whereSql
             ORDER BY s.submitted_at DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
        return $result;
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
     * @return array{id: string, form_id: string, data: string, submitted_by: string, submitted_at: string|null, closed_at: string|null, status: string, admin_comment: string, rgpd_consent: int, form_label: string, form_slug: string, deadline_field: string}|null
     */
    public function findByIdWithForm(string $id): ?array
    {
        /** @var array{id: string, form_id: string, data: string, submitted_by: string, submitted_at: string|null, closed_at: string|null, status: string, admin_comment: string, rgpd_consent: int, form_label: string, form_slug: string, deadline_field: string}|null $result */
        $result = $this->fetchOne(
            'SELECT s.id, s.form_id, s.data, s.submitted_by, s.submitted_at, s.closed_at, s.status, s.admin_comment, s.rgpd_consent, f.label as form_label, f.slug as form_slug, f.deadline_field
             FROM submissions s
             JOIN forms f ON f.id = s.form_id
             WHERE s.id = ?',
            [$id]
        );
        return $result;
    }

    public function cancelById(string $id): bool
    {
        return $this->execute(
            "UPDATE submissions SET status = '" . SubmissionStatus::Annule->value . "', closed_at = datetime('now') WHERE id = ? AND status = '" . SubmissionStatus::EnCours->value . "'",
            [$id]
        );
    }

    /**
     * Atomically read-modify-write submissions.data JSON with optimistic locking.
     *
     * Reads the current JSON, applies $mutator, writes back with WHERE data = ?.
     * If a concurrent write happened, retries up to 3 times.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     * @return bool true on success, false after max retries
     */
    public function appendToDataJson(string $submissionId, callable $mutator): bool
    {
        $pdo = $this->pdo();
        $maxRetries = 3;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            // Read current JSON
            $stmt = $pdo->prepare('SELECT data FROM submissions WHERE id = ?');
            $stmt->execute([$submissionId]);
            $currentData = $stmt->fetchColumn();

            if ($currentData === false) {
                return false;
            }

            $decoded = json_decode((string) $currentData, true) ?? [];

            // Apply mutation
            $decoded = $mutator($decoded);
            $newJson = json_encode($decoded, JSON_THROW_ON_ERROR);

            // Optimistic write: WHERE data = old_json
            $update = $pdo->prepare('UPDATE submissions SET data = ? WHERE id = ? AND data = ?');
            $update->execute([$newJson, $submissionId, $currentData]);

            if ($update->rowCount() > 0) {
                return true;
            }

            // Conflict: someone else wrote between our read and write
            error_log("appendToDataJson: conflict on attempt " . ($attempt + 1) . " for submission $submissionId");
        }

        error_log("appendToDataJson: max retries ($maxRetries) exceeded for submission $submissionId");
        return false;
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
     * @return array<int, array{status: string, cnt: int}>
     */
    public function getStatusCountsByForm(string $formId): array
    {
        /** @var array<int, array{status: string, cnt: int}> $result */
        $result = $this->fetchAll(
            'SELECT status, COUNT(*) as cnt FROM submissions WHERE form_id = ? GROUP BY status',
            [$formId]
        );
        return $result;
    }

    /**
     * @return array<int, array{id: string, data: string, submitted_by: string, submitted_at: string|null, status: string, closed_at: string|null}>
     */
    public function findPaginatedByForm(string $formId, int $limit, int $offset): array
    {
        /** @var array<int, array{id: string, data: string, submitted_by: string, submitted_at: string|null, status: string, closed_at: string|null}> $result */
        $result = $this->fetchAll(
            'SELECT s.id, s.data, s.submitted_by, s.submitted_at, s.status, s.closed_at
             FROM submissions s
             WHERE s.form_id = ?
             ORDER BY s.submitted_at DESC
             LIMIT ? OFFSET ?',
            [$formId, $limit, $offset]
        );
        return $result;
    }

    /**
     * @return array<int, array{status: string, cnt: int}>
     */
    public function getStatusCountsBySubmitter(string $email): array
    {
        /** @var array<int, array{status: string, cnt: int}> $result */
        $result = $this->fetchAll(
            'SELECT status, COUNT(*) as cnt FROM submissions WHERE submitted_by = ? GROUP BY status',
            [$email]
        );
        return $result;
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, array{id: string, form_id: string, data: string, submitted_at: string|null, status: string, closed_at: string|null, form_label: string, form_slug: string, form_description: string|null, deadline_field: string}>
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
        /** @var array<int, array{id: string, form_id: string, data: string, submitted_at: string|null, status: string, closed_at: string|null, form_label: string, form_slug: string, form_description: string|null, deadline_field: string}> $result */
        $result = $this->fetchAll($sql, $params);
        return $result;
    }

    /**
     * @return array<int, array{submission_id: string, field_name: string, field_label: string, field_type: string, value: string|null, filled_at: string, step_id: string|null, step_label: string|null}>
     */
    public function getValidatorDataFilledByEmail(string $email): array
    {
        /** @var array<int, array{submission_id: string, field_name: string, field_label: string, field_type: string, value: string|null, filled_at: string, step_id: string|null, step_label: string|null}> $result */
        $result = $this->fetchAll(
            'SELECT submission_id, field_name, field_label, field_type, value,
                    filled_at, step_id, step_label
             FROM submission_validator_data
             WHERE filled_by_email = ?
             ORDER BY filled_at DESC, field_name',
            [$email]
        );
        return $result;
    }

    /**
     * @return array<int, array{submission_id: string, field_name: string, field_label: string, field_type: string, value: string|null, filled_at: string, step_id: string|null, step_label: string|null, filled_by_email: string|null}>
     */
    public function getValidatorDataOnSubmissionsByEmail(string $email): array
    {
        /** @var array<int, array{submission_id: string, field_name: string, field_label: string, field_type: string, value: string|null, filled_at: string, step_id: string|null, step_label: string|null, filled_by_email: string|null}> $result */
        $result = $this->fetchAll(
            'SELECT svd.submission_id, svd.field_name, svd.field_label, svd.field_type,
                    svd.value, svd.filled_at, svd.step_id, svd.step_label, svd.filled_by_email
             FROM submission_validator_data svd
             JOIN submissions s ON s.id = svd.submission_id
             WHERE s.submitted_by = ?
             ORDER BY svd.submission_id, svd.filled_at, svd.field_name',
            [$email]
        );
        return $result;
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
            WHERE s.status = '" . SubmissionStatus::Valide->value . "' AND s.closed_at IS NOT NULL"
        );
        return (float) ($result['avg_seconds'] ?? 0);
    }

    /**
     * @return array<int, array{id: string, data: string, submitted_by: string, submitted_at: string|null, form_id: string, form_label: string, deadline_field: string}>
     */
    public function findActiveWithDeadlineField(): array
    {
        /** @var array<int, array{id: string, data: string, submitted_by: string, submitted_at: string|null, form_id: string, form_label: string, deadline_field: string}> $result */
        $result = $this->fetchAll(
            "SELECT s.id, s.data, s.submitted_by, s.submitted_at, s.form_id,
                   f.label as form_label, f.deadline_field
             FROM submissions s
             JOIN forms f ON f.id = s.form_id
             WHERE s.status = '" . SubmissionStatus::EnCours->value . "' AND f.deadline_field !== ''"
        );
        return $result;
    }

    /**
     * @return array<int, array{day: string, cnt: int}>
     */
    public function getDailyCounts(int $days): array
    {
        /** @var array<int, array{day: string, cnt: int}> $result */
        $result = $this->fetchAll(
            "SELECT DATE(submitted_at) as day, COUNT(*) as cnt
             FROM submissions
             WHERE submitted_at >= datetime('now', '-' || ? || ' days')
             GROUP BY DATE(submitted_at)
             ORDER BY day DESC",
            [$days]
        );
        return $result;
    }

    public function countOldByRetention(int $retentionMonths): int
    {
        $result = $this->fetchOne(
            "SELECT COUNT(*) as cnt FROM submissions WHERE status != '" . SubmissionStatus::EnCours->value . "' AND closed_at < datetime('now', '-' || ? || ' months')",
            [$retentionMonths]
        );
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * @return array<int, array{id: string, submission_id: string, field_name: string, field_label: string, field_type: string, value: string|null, filled_by: string, filled_at: string, step_id: string|null, step_label: string|null, filled_by_email: string|null, token_id: string|null}>
     */
    public function getValidatorData(string $submissionId, ?string $stepId = null): array
    {
        $sql = 'SELECT id, submission_id, field_name, field_label, field_type, value, filled_by, filled_at, step_id, step_label, filled_by_email, token_id FROM submission_validator_data WHERE submission_id = ?';
        $params = [$submissionId];
        if ($stepId !== null) {
            $sql .= ' AND step_id = ?';
            $params[] = $stepId;
        }
        /** @var array<int, array{id: string, submission_id: string, field_name: string, field_label: string, field_type: string, value: string|null, filled_by: string, filled_at: string, step_id: string|null, step_label: string|null, filled_by_email: string|null, token_id: string|null}> $result */
        $result = $this->fetchAll($sql . ' ORDER BY filled_at', $params);
        return $result;
    }

    /**
     * @return array<int, array{id: string, submission_id: string, field_name: string, field_label: string, field_type: string, value: string|null, filled_by: string, filled_at: string, step_id: string|null, step_label: string|null, filled_by_email: string|null, token_id: string|null}>
     */
    public function getValidatorDataOrdered(string $submissionId): array
    {
        /** @var array<int, array{id: string, submission_id: string, field_name: string, field_label: string, field_type: string, value: string|null, filled_by: string, filled_at: string, step_id: string|null, step_label: string|null, filled_by_email: string|null, token_id: string|null}> $result */
        $result = $this->fetchAll(
            'SELECT id, submission_id, field_name, field_label, field_type, value, filled_by, filled_at, step_id, step_label, filled_by_email, token_id FROM submission_validator_data WHERE submission_id = ? ORDER BY filled_at ASC, field_name ASC',
            [$submissionId]
        );
        return $result;
    }

    /**
     * @return array<int, array{id: string, submission_id: string, field_name: string, field_label: string, field_type: string, value: string|null, filled_by: string, filled_at: string, step_id: string|null, step_label: string|null, filled_by_email: string|null, token_id: string|null, form_id: string, form_label: string}>
     */
    public function findValidatorDataByEmail(string $email, int $limit = 50): array
    {
        /** @var array<int, array{id: string, submission_id: string, field_name: string, field_label: string, field_type: string, value: string|null, filled_by: string, filled_at: string, step_id: string|null, step_label: string|null, filled_by_email: string|null, token_id: string|null, form_id: string, form_label: string}> $result */
        $result = $this->fetchAll(
            'SELECT svd.id, svd.submission_id, svd.field_name, svd.field_label, svd.field_type, svd.value, svd.filled_by, svd.filled_at, svd.step_id, svd.step_label, svd.filled_by_email, svd.token_id, s.form_id, f.label as form_label
             FROM submission_validator_data svd
             JOIN submissions s ON s.id = svd.submission_id
             JOIN forms f ON f.id = s.form_id
             WHERE svd.filled_by_email = ?
             ORDER BY svd.filled_at DESC
             LIMIT ?',
            [$email, $limit]
        );
        return $result;
    }

    public function countPurgeableByCutoff(string $cutoff): int
    {
        $result = $this->fetchOne(
            "SELECT COUNT(*) as cnt FROM submissions
             WHERE status IN ('" . SubmissionStatus::Valide->value . "', '" . SubmissionStatus::Refuse->value . "') AND closed_at IS NOT NULL AND closed_at < ?",
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
             WHERE status IN ('" . SubmissionStatus::Valide->value . "', '" . SubmissionStatus::Refuse->value . "') AND closed_at IS NOT NULL AND closed_at < ?",
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
             WHERE s.status IN ('" . SubmissionStatus::Valide->value . "', '" . SubmissionStatus::Refuse->value . "') AND s.closed_at IS NOT NULL AND s.closed_at < ?",
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
        $result = ['total' => 0, SubmissionStatus::EnCours->value => 0, SubmissionStatus::Valide->value => 0];
        foreach ($rows as $row) {
            $result['total'] += (int) $row['cnt'];
            if ($row['status'] === SubmissionStatus::EnCours->value) {
                $result[SubmissionStatus::EnCours->value] = (int) $row['cnt'];
            } elseif ($row['status'] === SubmissionStatus::Valide->value) {
                $result[SubmissionStatus::Valide->value] = (int) $row['cnt'];
            }
        }
        return $result;
    }

    public function findFormIdById(string $submissionId): ?string
    {
        $result = $this->fetchOne('SELECT form_id FROM submissions WHERE id = ?', [$submissionId]);
        return $result !== null ? (string) $result['form_id'] : null;
    }

    /**
     * @return array<int, array{id: string, submission_id: string, field_name: string, field_label: string, field_type: string, value: string|null, filled_by: string, filled_at: string, step_id: string|null, step_label: string|null, filled_by_email: string|null, token_id: string|null}>
     */
    public function getValidatorDataByStepFields(string $submissionId, string $stepId, string $stepLabel): array
    {
        /** @var array<int, array{id: string, submission_id: string, field_name: string, field_label: string, field_type: string, value: string|null, filled_by: string, filled_at: string, step_id: string|null, step_label: string|null, filled_by_email: string|null, token_id: string|null}> $result */
        $result = $this->fetchAll(
            "SELECT svd.id, svd.submission_id, svd.field_name, svd.field_label, svd.field_type, svd.value, svd.filled_by, svd.filled_at, svd.step_id, svd.step_label, svd.filled_by_email, svd.token_id
             FROM submission_validator_data svd
             WHERE svd.submission_id = ?
             AND svd.field_name IN (
                 SELECT ff.field_name FROM form_fields ff
                 WHERE ff.form_id = (SELECT form_id FROM submissions WHERE id = ?)
                 AND ff.filled_by = '" . FilledBy::Validator->value . "'
                 AND (ff.validator_step = ? OR ff.validator_step = ? OR ff.validator_step = '')
             )",
            [$submissionId, $submissionId, $stepId, $stepLabel]
        );
        return $result;
    }

    /**
     * Nombre de soumissions en cours pour un demandeur donné.
     */
    public function countEnCoursBySubmitter(string $email): int
    {
        /** @var array{cnt: int}|null $result */
        $result = $this->fetchOne(
            "SELECT COUNT(*) as cnt FROM submissions
             WHERE submitted_by = ? AND status = '" . SubmissionStatus::EnCours->value . "' AND closed_at IS NULL",
            [$email]
        );
        return (int) ($result['cnt'] ?? 0);
    }
}
