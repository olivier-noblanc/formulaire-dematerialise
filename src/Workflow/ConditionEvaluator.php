<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Contract\ConditionInterface;

/**
 * Évaluateur de conditions (partagé entre fields et steps).
 *
 * Format JSON : {"field": "nom_champ", "op": "eq", "value": "valeur"}
 * Opérateurs : eq, neq, in, not_empty, empty
 */
final class ConditionEvaluator implements ConditionInterface
{
    /** @var list<string> Opérateurs valides supportés par l'évaluateur */
    public const array VALID_OPS = ['eq', 'equals', 'neq', 'not_equals', 'contains', 'in', 'not_empty', 'empty'];
    /**
     * Évalue une condition générique.
     * @param string|null $conditionJson Le JSON de la condition
     * @param array<string, mixed> $data Les données disponibles
     */
    public function evaluate(?string $conditionJson, mixed $data): bool
    {
        if (in_array($conditionJson, [null, '', '0'], true)) {
            return true;
        }

        $condition = json_decode($conditionJson, true);
        if (!is_array($condition) || !((bool)($condition['field'] ?? ''))) {
            return true;
        }

        $fieldName = (string) $condition['field'];
        $op = (string) ($condition['op'] ?? 'eq');
        $expected = $condition['value'] ?? '';

        $rawActual = $data[$fieldName] ?? '';
        $actual = is_array($rawActual) ? implode(', ', $rawActual) : (string) $rawActual;

        $result = match ($op) {
            'eq', 'equals' => $actual === (string) $expected,
            'neq', 'not_equals' => $actual !== (string) $expected,
            'contains' => str_contains($actual, (string) $expected),
            'in' => is_array($expected)
                ? in_array($actual, $expected, true)
                : in_array($actual, array_map(trim(...), explode(',', (string) $expected)), true),
            'not_empty' => $actual !== '',
            'empty' => $actual === '',
            default => false,
        };

        if ($result === false && !in_array($op, self::VALID_OPS, true)) {
            error_log("ConditionEvaluator: opérateur inconnu '$op' pour le champ '$fieldName' — la condition est évaluée à false (fail-closed)");
        }

        return $result;
    }

    /**
     * Évalue la condition d'une étape de workflow en allant chercher les données
     * de validation en base.
     *
     * @param array{condition?: string} $step
     */
    public static function evaluateStepCondition(mixed $step, string $submission_id): bool
    {
        $condition_json = $step['condition'] ?? '';
        if ($condition_json === '' || $condition_json === '0') {
            return true;
        }

        $validator_data = \App\Core\App::validatorData()->getSubmissionValidatorData($submission_id);
        $data = [];
        foreach ($validator_data as $vd) {
            $data[$vd['field_name'] ?? ''] = $vd['value'] ?? '';
        }

        return \App\Core\App::conditions()->evaluate($condition_json, $data);
    }

    /**
     * Évalue la condition d'un champ avec les données du formulaire.
     *
     * @param array<string, mixed> $field
     * @param array<string, mixed> $form_data
     */
    public static function evaluateFieldCondition(mixed $field, mixed $form_data): bool
    {
        $condition_json = $field['condition'] ?? '';
        return \App\Core\App::conditions()->evaluate($condition_json, $form_data);
    }
}
