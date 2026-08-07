<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\SubmissionStatus;
use App\Enum\SubmissionField;

final class SubmissionRepository extends BaseRepository
{
    use \App\Repository\Traits\SubmissionStatsTrait;
    use \App\Repository\Traits\SubmissionValidatorDataTrait;
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
     * @param array{form_id: string, data: string, submitted_by: string, submitted_at: string, rgpd_consent: int} $data
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
        /** @var array{cnt: int|string|null}|null $result */
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

    // ── Workflow / Export context ─────────────────────────────────

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
        /** @var list<array{key: string}> $rows */
        $rows = $this->fetchAll(
            "SELECT DISTINCT j.key
            FROM submissions s, json_each(s.data) j
            JOIN forms f ON f.id = s.form_id
            WHERE $whereSql AND json_valid(s.data) AND j.key != :exclude_key",
            [...$params, 'exclude_key' => SubmissionField::VALIDATIONS->value]
        );
        return array_values(array_map(static fn(array $r): string => (string) $r['key'], $rows));
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
        /** @var list<array{tbl: string, cnt: int|string}> $rows */
        $rows = $this->fetchAll(implode(' UNION ALL ', $unionParts));
        foreach ($rows as $row) {
            $counts[(string) $row['tbl']] = (int) $row['cnt'];
        }
        return $counts;
    }

    public function existsBySubmitter(string $email): bool
    {
        $result = $this->fetchOne('SELECT 1 FROM submissions WHERE submitted_by = ? LIMIT 1', [$email]);
        return $result !== null;
    }

    public function findFormIdById(string $submissionId): ?string
    {
        $result = $this->fetchOne('SELECT form_id FROM submissions WHERE id = ?', [$submissionId]);
        return $result !== null ? (string) $result['form_id'] : null;
    }
}
