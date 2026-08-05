<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\FieldType;
use App\Enum\FilledBy;

/**
 * @internal Trait utilisé par FormRepository pour limiter la taille du fichier principal.
 *
 * @method array<string, mixed>|null fetchOne(string $sql, array<int, mixed> $params = [])
 * @method array<int, array<string, mixed>> fetchAll(string $sql, array<int, mixed> $params = [])
 * @method bool execute(string $sql, array<int, mixed> $params = [])
 * @method string|null getStepLabel(string $stepId)  Défini dans FormStepsTrait
 */
trait FormFieldsTrait
{
    /**
     * @return array<int, array{id: string, form_id: string, label: string, field_type: string, field_name: string, options: string|null, hint: string, required: int, ordre: int, card_group: string, filled_by: string, validator_step: string, visibility: string, condition: string}>
     */
    public function getFields(string $formId): array
    {
        /** @var array<int, array{id: string, form_id: string, label: string, field_type: string, field_name: string, options: string|null, hint: string, required: int, ordre: int, card_group: string, filled_by: string, validator_step: string, visibility: string, condition: string}> $result */
        $result = $this->fetchAll(
            'SELECT id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group, filled_by, validator_step, visibility, condition FROM form_fields WHERE form_id = ? ORDER BY ordre',
            [$formId]
        );
        return $result;
    }

    /**
     * @return array<int, array{field_name: string, label: string}>
     */
    public function getDateFields(string $formId): array
    {
        /** @var array<int, array{field_name: string, label: string}> $result */
        $result = $this->fetchAll(
            "SELECT field_name, label FROM form_fields WHERE form_id = ? AND field_type = '" . FieldType::Date->value . "' AND filled_by = '" . FilledBy::Demandeur->value . "' ORDER BY ordre",
            [$formId]
        );
        return $result;
    }

    /**
     * Récupère les champs d'un formulaire filtrés par filled_by (optionnel).
     * Variante de getFields() avec filtre filled_by — utilisée par FieldService::getFields().
     *
     * @return list<array{id: string, form_id: string, label: string, field_type: string, field_name: string, options: string|null, hint: string, required: int, ordre: int, card_group: string, filled_by: string, validator_step: string, visibility: string, condition: string}>
     */
    public function getFieldsByFilledBy(string $formId, ?string $filledBy = null): array
    {
        $sql = 'SELECT id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group, filled_by, validator_step, visibility, condition FROM form_fields WHERE form_id = ?';
        $params = [$formId];
        if ($filledBy !== null) {
            $sql .= ' AND filled_by = ?';
            $params[] = $filledBy;
        }
        $sql .= ' ORDER BY ordre, id';
        /** @var list<array{id: string, form_id: string, label: string, field_type: string, field_name: string, options: string|null, hint: string, required: int, ordre: int, card_group: string, filled_by: string, validator_step: string, visibility: string, condition: string}> $result */
        $result = $this->fetchAll($sql, $params);
        return $result;
    }

    /**
     * @return array<int, array{id: string, form_id: string, label: string, field_type: string, field_name: string, options: string|null, hint: string, required: int, ordre: int, card_group: string, filled_by: string, validator_step: string, visibility: string, condition: string}>
     */
    public function getValidatorFields(string $formId, ?string $stepId = null): array
    {
        $sql = "SELECT id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group, filled_by, validator_step, visibility, condition FROM form_fields WHERE form_id = ? AND filled_by = '" . FilledBy::Validator->value . "'";
        $params = [$formId];

        if ($stepId !== null && $stepId !== '') {
            $stepLabel = $this->getStepLabel($stepId) ?? '';

            $sql .= " AND (validator_step = ? OR validator_step = ? OR validator_step = '')";
            $params[] = $stepId;
            $params[] = $stepLabel;
        }

        $sql .= ' ORDER BY ordre, id';
        /** @var array<int, array{id: string, form_id: string, label: string, field_type: string, field_name: string, options: string|null, hint: string, required: int, ordre: int, card_group: string, filled_by: string, validator_step: string, visibility: string, condition: string}> $result */
        $result = $this->fetchAll($sql, $params);
        return $result;
    }

    /**
     * @param array{form_id: string, label: string, field_name: string, field_type?: string, options?: string|null, hint?: string, required?: int, ordre?: int, card_group?: string, filled_by?: string, validator_step?: string, visibility?: string} $data
     */
    public function createField(array $data): string
    {
        $id = \generate_uuid();
        $this->execute(
            'INSERT INTO form_fields (id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group, filled_by, validator_step, visibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $data['form_id'], $data['label'], $data['field_type'] ?? FieldType::Text->value, $data['field_name'], $data['options'] ?? null, $data['hint'] ?? '', $data['required'] ?? 0, $data['ordre'] ?? 0, $data['card_group'] ?? 'Général', $data['filled_by'] ?? FilledBy::Demandeur->value, $data['validator_step'] ?? '', $data['visibility'] ?? 'all']
        );
        return $id;
    }

    /**
     * @param array{label?: string, field_type?: string, field_name?: string, options?: string|null, hint?: string, required?: int, ordre?: int, card_group?: string, filled_by?: string, validator_step?: string, visibility?: string, condition?: string} $data
     */
    public function updateField(string $fieldId, array $data): bool
    {
        $allowed = ['label', 'field_type', 'field_name', 'options', 'hint', 'required', 'ordre', 'card_group', 'filled_by', 'validator_step', 'visibility', 'condition'];
        $fields = [];
        $params = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $fields[] = "`$key` = ?";
                $params[] = $value;
            }
        }
        if ($fields === []) {
            return false;
        }
        $params[] = $fieldId;
        return $this->execute('UPDATE form_fields SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
    }

    public function deleteField(string $fieldId): bool
    {
        return $this->execute('DELETE FROM form_fields WHERE id = ?', [$fieldId]);
    }

    /**
     * Récupère les infos d'un champ (label, field_type) par son field_name.
     * Utilisé par FieldService::saveValidatorData() pour récupérer le label/type
     * avant l'UPSERT dans submission_validator_data.
     *
     * @return array{label: string, field_type: string}|null
     */
    public function findFieldLabelAndTypeByName(string $fieldName): ?array
    {
        /** @var array{label: string, field_type: string}|null $result */
        $result = $this->fetchOne(
            'SELECT label, field_type FROM form_fields WHERE field_name = ?',
            [$fieldName]
        );
        return $result;
    }
}
