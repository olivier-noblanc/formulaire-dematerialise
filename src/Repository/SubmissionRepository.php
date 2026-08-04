<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\FilledBy;
use App\Enum\SubmissionStatus;

final class SubmissionRepository extends BaseRepository
{
    use \App\Repository\Traits\SubmissionPurgeTrait;
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
            error_log('appendToDataJson: conflict on attempt ' . ($attempt + 1) . " for submission $submissionId");
        }

        error_log("appendToDataJson: max retries ($maxRetries) exceeded for submission $submissionId");
        return false;
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

    // ── Workflow / Validation context ─────────────────────────────

    /**
     * Récupère une soumission avec son form_label (pour WorkflowEngine).
     *
     * @return array{id: string, form_id: string, data: string, submitted_by: string, submitted_at: string|null, closed_at: string|null, status: string, admin_comment: string, rgpd_consent: int|null, form_label: string}|null
     */
    public function findWithFormLabelById(string $submissionId): ?array
    {
        /** @var array{id: string, form_id: string, data: string, submitted_by: string, submitted_at: string|null, closed_at: string|null, status: string, admin_comment: string, rgpd_consent: int|null, form_label: string}|null $result */
        $result = $this->fetchOne(
            'SELECT s.id, s.form_id, s.data, s.submitted_by, s.submitted_at,
                   s.closed_at, s.status, s.admin_comment, s.rgpd_consent,
                   f.label as form_label
            FROM submissions s
            JOIN forms f ON f.id = s.form_id
            WHERE s.id = ?',
            [$submissionId]
        );
        return $result;
    }

    /**
     * Récupère les soumissions paginées pour ExportService (avec form_label/slug).
     *
     * @param array<int, mixed> $params
     * @return list<array{id: string, data: string, submitted_by: string, submitted_at: string|null, closed_at: string|null, status: string, form_label: string, form_slug: string}>
     */
    public function findForExportWithForm(string $whereSql, array $params, int $limit, int $offset): array
    {
        /** @var list<array{id: string, data: string, submitted_by: string, submitted_at: string|null, closed_at: string|null, status: string, form_label: string, form_slug: string}> $result */
        $result = $this->fetchAll(
            "SELECT s.id, s.data, s.submitted_by, s.submitted_at, s.closed_at, s.status,
                   f.label as form_label, f.slug as form_slug
            FROM submissions s
            JOIN forms f ON f.id = s.form_id
            WHERE $whereSql
            ORDER BY s.submitted_at DESC
            LIMIT $limit OFFSET $offset",
            $params
        );
        return $result;
    }

    /**
     * Récupère les clés JSON distinctes dans submissions.data pour ExportService.
     *
     * @param array<int, mixed> $params
     * @return list<string>
     */
    public function findDistinctJsonKeys(string $whereSql, array $params): array
    {
        $rows = $this->fetchAll(
            "SELECT DISTINCT j.key
            FROM submissions s, json_each(s.data) j
            JOIN forms f ON f.id = s.form_id
            WHERE $whereSql AND json_valid(s.data) AND j.key != 'validations'",
            $params
        );
        return array_values(array_map(static fn(array $r): string => (string) $r['key'], $rows));
    }

    /**
     * Compte les soumissions actives (status = ?) pour un formulaire.
     * Utilisé par WorkflowEngine::hasActiveSubmissions().
     */
    public function countActiveByFormAndStatus(string $formId, string $status): int
    {
        /** @var array{cnt: int|string|null}|null $result */
        $result = $this->fetchOne(
            'SELECT COUNT(*) as cnt FROM submissions WHERE form_id = ? AND status = ?',
            [$formId, $status]
        );
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * Stats agrégées par période (week/month/year) pour StatsService::getStatsByPeriod().
     *
     * @return list<array{period: string, total: int|string, valide: int|string, refuse: int|string, en_cours: int|string, avg_processing_seconds: float|string|null}>
     */
    public function getStatsByPeriod(string $format, string $interval, int $limit): array
    {
        /** @var list<array{period: string, total: int|string, valide: int|string, refuse: int|string, en_cours: int|string, avg_processing_seconds: float|string|null}> $result */
        $result = $this->fetchAll(
            "SELECT
                strftime(?, s.submitted_at) as period,
                COUNT(*) as total,
                SUM(CASE WHEN s.status = '" . SubmissionStatus::Valide->value . "' THEN 1 ELSE 0 END) as valide,
                SUM(CASE WHEN s.status = '" . SubmissionStatus::Refuse->value . "' THEN 1 ELSE 0 END) as refuse,
                SUM(CASE WHEN s.status = '" . SubmissionStatus::EnCours->value . "' THEN 1 ELSE 0 END) as en_cours,
                AVG(CASE WHEN s.status = '" . SubmissionStatus::Valide->value . "' AND s.closed_at IS NOT NULL
                    THEN CAST(strftime('%s', s.closed_at) AS REAL) - CAST(strftime('%s', s.submitted_at) AS REAL)
                    ELSE NULL END) as avg_processing_seconds
            FROM submissions s
            WHERE s.submitted_at >= datetime('now', ?)
            GROUP BY strftime(?, s.submitted_at)
            ORDER BY period DESC
            LIMIT ?",
            [$format, $interval, $format, $limit]
        );
        return $result;
    }

    /**
     * Stats globales agrégées (total, en_cours, valide, refuse, today, this_week, this_month)
     * pour StatsService::getGlobalStats(). Une seule requête.
     *
     * @return array{total: int|string, en_cours: int|string, valide: int|string, refuse: int|string, today: int|string, this_week: int|string, this_month: int|string}
     */
    public function getGlobalStatsCounts(): array
    {
        /** @var array{total: int|string, en_cours: int|string, valide: int|string, refuse: int|string, today: int|string, this_week: int|string, this_month: int|string}|null $result */
        $result = $this->fetchOne(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = '" . SubmissionStatus::EnCours->value . "' THEN 1 ELSE 0 END) as en_cours,
                SUM(CASE WHEN status = '" . SubmissionStatus::Valide->value . "' THEN 1 ELSE 0 END) as valide,
                SUM(CASE WHEN status = '" . SubmissionStatus::Refuse->value . "' THEN 1 ELSE 0 END) as refuse,
                SUM(CASE WHEN DATE(submitted_at) = DATE('now') THEN 1 ELSE 0 END) as today,
                SUM(CASE WHEN submitted_at >= datetime('now', '-7 days') THEN 1 ELSE 0 END) as this_week,
                SUM(CASE WHEN submitted_at >= datetime('now', '-30 days') THEN 1 ELSE 0 END) as this_month
            FROM submissions"
        );
        return $result ?? ['total' => 0, SubmissionStatus::EnCours->value => 0, SubmissionStatus::Valide->value => 0, SubmissionStatus::Refuse->value => 0, 'today' => 0, 'this_week' => 0, 'this_month' => 0];
    }

    /**
     * Avg processing time en secondes pour les soumissions validées.
     * Utilisé par StatsService::getGlobalStats().
     */
    public function getAvgProcessingSeconds(): float
    {
        /** @var array{avg: float|string|null}|null $result */
        $result = $this->fetchOne(
            "SELECT AVG(CAST(strftime('%s', closed_at) AS REAL) - CAST(strftime('%s', submitted_at) AS REAL)) as avg
            FROM submissions WHERE status = '" . SubmissionStatus::Valide->value . "' AND closed_at IS NOT NULL"
        );
        return (float) ($result['avg'] ?? 0);
    }

    /**
     * Stats par formulaire (joins submissions) pour StatsService::getFormStats().
     *
     * @return list<array{label: string, slug: string, total: int|string, en_cours: int|string, valide: int|string, refuse: int|string, avg_seconds: float|string|null}>
     */
    public function getFormStats(): array
    {
        /** @var list<array{label: string, slug: string, total: int|string, en_cours: int|string, valide: int|string, refuse: int|string, avg_seconds: float|string|null}> $result */
        $result = $this->fetchAll(
            "SELECT f.label, f.slug, COUNT(s.id) as total,
                   SUM(CASE WHEN s.status = '" . SubmissionStatus::EnCours->value . "' THEN 1 ELSE 0 END) as en_cours,
                   SUM(CASE WHEN s.status = '" . SubmissionStatus::Valide->value . "' THEN 1 ELSE 0 END) as valide,
                   SUM(CASE WHEN s.status = '" . SubmissionStatus::Refuse->value . "' THEN 1 ELSE 0 END) as refuse,
                   AVG(CASE WHEN s.status = '" . SubmissionStatus::Valide->value . "' AND s.closed_at IS NOT NULL
                       THEN CAST(strftime('%s', s.closed_at) AS REAL) - CAST(strftime('%s', s.submitted_at) AS REAL)
                       ELSE NULL END) as avg_seconds
            FROM forms f
            LEFT JOIN submissions s ON s.form_id = f.id
            GROUP BY f.id
            ORDER BY total DESC"
        );
        return $result;
    }

    /**
     * UPSERT d'une entrée submission_validator_data.
     * Utilisé par FieldService::saveValidatorData().
     */
    public function upsertValidatorData(
        string $id,
        string $submissionId,
        string $fieldName,
        string $fieldLabel,
        string $fieldType,
        string $value,
        string $filledBy,
        string $filledAt,
        ?string $stepId,
        ?string $stepLabel,
        ?string $filledByEmail,
        ?string $tokenId
    ): bool {
        $sql = 'INSERT INTO submission_validator_data
            (id, submission_id, field_name, field_label, field_type, value, filled_by, filled_at, step_id, step_label, filled_by_email, token_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(submission_id, field_name) DO UPDATE SET
                value = excluded.value,
                field_label = excluded.field_label,
                field_type = excluded.field_type,
                filled_by = excluded.filled_by,
                filled_at = excluded.filled_at,
                step_id = excluded.step_id,
                step_label = excluded.step_label,
                filled_by_email = excluded.filled_by_email,
                token_id = excluded.token_id';
        return $this->execute($sql, [
            $id, $submissionId, $fieldName, $fieldLabel, $fieldType,
            $value, $filledBy, $filledAt, $stepId, $stepLabel, $filledByEmail, $tokenId,
        ]);
    }

    /**
     * Supprime une entrée submission_validator_data par (submission_id, field_name).
     * Utilisé par FieldService::deleteValidatorData().
     */
    public function deleteValidatorDataBySubmissionAndField(string $submissionId, string $fieldName): bool
    {
        return $this->execute(
            'DELETE FROM submission_validator_data WHERE submission_id = ? AND field_name = ?',
            [$submissionId, $fieldName]
        );
    }
}
