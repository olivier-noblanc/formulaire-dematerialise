<?php

declare(strict_types=1);

namespace App\Forms;

use App\Contract\FieldInterface;
use App\Core\Database;

/**
 * Service de gestion des champs de formulaire (demandeur + validateur).
 */
final readonly class FieldService implements FieldInterface
{
    public function __construct(private Database $database) {}

    /**
     * Récupère les champs d'un formulaire, optionnellement filtrés par filled_by.
     * @return array<int, array{
     *   id: string,
     *   form_id: string,
     *   label: string,
     *   field_type: string,
     *   field_name: string,
     *   options: string|null,
     *   hint: string,
     *   required: int,
     *   ordre: int,
     *   card_group: string,
     *   filled_by: string,
     *   validator_step: string,
     *   visibility: string,
     *   condition: string
     * }>
     */
    public function getFields(string $formId, ?string $filledBy = null): array
    {
        static $cache = [];
        $cacheKey = $formId . ($filledBy !== null ? ':' . $filledBy : '');
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $pdo = $this->database->getPdo();
        $sql = 'SELECT id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group, filled_by, validator_step, visibility, condition FROM form_fields WHERE form_id = ?';
        $params = [$formId];

        if ($filledBy !== null) {
            $sql .= ' AND filled_by = ?';
            $params[] = $filledBy;
        }

        $sql .= ' ORDER BY ordre, id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        /** @var array<int, array{id: string, form_id: string, label: string, field_type: string, field_name: string, options: string|null, hint: string, required: int, ordre: int, card_group: string, filled_by: string, validator_step: string, visibility: string, condition: string}> $result */
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $cache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Récupère les champs validateur d'un formulaire, optionnellement filtrés par step.
     * @return array<int, array{
     *   id: string,
     *   form_id: string,
     *   label: string,
     *   field_type: string,
     *   field_name: string,
     *   options: string|null,
     *   hint: string,
     *   required: int,
     *   ordre: int,
     *   card_group: string,
     *   filled_by: string,
     *   validator_step: string,
     *   visibility: string,
     *   condition: string
     * }>
     */
    public function getValidatorFields(string $formId, ?string $stepId = null): array
    {
        $pdo = $this->database->getPdo();
        $sql = 'SELECT id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group, filled_by, validator_step, visibility, condition FROM form_fields WHERE form_id = ? AND filled_by = ?';
        $params = [$formId, \App\Enum\FilledBy::Validator->value];

        if ($stepId !== null && $stepId !== '') {
            $stepLabel = '';
            $labelStmt = $pdo->prepare('SELECT label FROM steps WHERE id = ? AND form_id = ?');
            $labelStmt->execute([$stepId, $formId]);
            $stepLabel = (string) ($labelStmt->fetchColumn() ?? '');

            $sql .= " AND (validator_step = ? OR validator_step = ? OR validator_step = '')";
            $params[] = $stepId;
            $params[] = $stepLabel;
        }

        $sql .= ' ORDER BY ordre, id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        /** @var array<int, array{id: string, form_id: string, label: string, field_type: string, field_name: string, options: string|null, hint: string, required: int, ordre: int, card_group: string, filled_by: string, validator_step: string, visibility: string, condition: string}> $result */
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $result;
    }

    /**
     * Récupère les données validator d'une soumission.
     * @return array<int, array{
     *   id: string,
     *   submission_id: string,
     *   field_name: string,
     *   field_label: string,
     *   field_type: string,
     *   value: string|null,
     *   filled_by: string,
     *   filled_at: string,
     *   step_id: string|null,
     *   step_label: string|null,
     *   filled_by_email: string|null,
     *   token_id: string|null
     * }>
     */
    public function getValidatorData(string $submissionId, ?string $stepId = null): array
    {
        $pdo = $this->database->getPdo();
        $sql = 'SELECT svd.id, svd.submission_id, svd.field_name, svd.field_label, svd.field_type, svd.value, svd.filled_by, svd.filled_at, svd.step_id, svd.step_label, svd.filled_by_email, svd.token_id FROM submission_validator_data svd WHERE svd.submission_id = ?';
        $params = [$submissionId];

        if ($stepId !== null && $stepId !== '') {
            $sql .= ' AND svd.field_name IN (
                SELECT ff.field_name FROM form_fields ff
                WHERE ff.form_id = (SELECT form_id FROM submissions WHERE id = ?)
                AND ff.filled_by = ?
                AND (ff.validator_step = ? OR ff.validator_step = ? OR ff.validator_step = \'\')
            )';
            $params[] = $submissionId;
            $params[] = \App\Enum\FilledBy::Validator->value;
            $params[] = $stepId;

            $labelStmt = $pdo->prepare('SELECT label FROM steps WHERE id = ?');
            $labelStmt->execute([$stepId]);
            $stepLabel = (string) ($labelStmt->fetchColumn() ?? '');
            $params[] = $stepLabel;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        /** @var array<int, array{id: string, submission_id: string, field_name: string, field_label: string, field_type: string, value: string|null, filled_by: string, filled_at: string, step_id: string|null, step_label: string|null, filled_by_email: string|null, token_id: string|null}> $result */
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $result;
    }

    /**
     * Sauvegarde une donnée validator (UPSERT).
     */
    public function saveValidatorData(
        string $submissionId,
        string $fieldName,
        string $value,
        string $filledBy,
        ?string $stepId = null,
        ?string $stepLabel = null,
        ?string $filledByEmail = null,
        ?string $tokenId = null
    ): void {
        $pdo = $this->database->getPdo();

        $fieldStmt = $pdo->prepare('SELECT label, field_type FROM form_fields WHERE field_name = ?');
        $fieldStmt->execute([$fieldName]);
        $fieldInfo = $fieldStmt->fetch(\PDO::FETCH_ASSOC);
        $fieldLabel = $fieldInfo['label'] ?? $fieldName;
        $fieldType = $fieldInfo['field_type'] ?? \App\Enum\FieldType::Text->value;

        if ($stepLabel === null && $stepId !== null) {
            $labelStmt = $pdo->prepare('SELECT label FROM steps WHERE id = ?');
            $labelStmt->execute([$stepId]);
            $stepLabel = (string) ($labelStmt->fetchColumn() ?? '');
        }

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

        $pdo->prepare($sql)->execute([
            $this->generateUuid(),
            $submissionId, $fieldName, $fieldLabel, $fieldType,
            $value, $filledBy, gmdate('Y-m-d H:i:s'),
            $stepId, $stepLabel, $filledByEmail, $tokenId,
        ]);
    }

    public function deleteValidatorData(string $submissionId, string $fieldName): void
    {
        $pdo = $this->database->getPdo();
        $pdo->prepare('DELETE FROM submission_validator_data WHERE submission_id = ? AND field_name = ?')
            ->execute([$submissionId, $fieldName]);
    }

    private function generateUuid(): string
    {
        return \generate_uuid();
    }
}
