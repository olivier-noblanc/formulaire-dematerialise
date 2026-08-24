<?php

declare(strict_types=1);

namespace App\Repository\Traits;

use App\Enum\FilledBy;

/**
 * Trait regroupant les méthodes d'accès à submission_validator_data.
 *
 * Utilisé par SubmissionRepository.
 *
 * @method \PDO pdo()
 * @method bool execute(string $sql, array<int, mixed> $params = [])
 */
trait SubmissionValidatorDataTrait
{
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
             WHERE s.status IN ('" . \App\Enum\SubmissionStatus::Valide->value . "', '" . \App\Enum\SubmissionStatus::Refuse->value . "') AND s.closed_at IS NOT NULL AND s.closed_at < ?",
            [$cutoff]
        );
        return (int) ($result['cnt'] ?? 0);
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
     * UPSERT d'une entrée submission_validator_data.
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
     */
    public function deleteValidatorDataBySubmissionAndField(string $submissionId, string $fieldName): bool
    {
        return $this->execute(
            'DELETE FROM submission_validator_data WHERE submission_id = ? AND field_name = ?',
            [$submissionId, $fieldName]
        );
    }
}
