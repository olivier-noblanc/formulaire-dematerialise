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
    public function evaluate(?string $conditionJson, array $data): bool
    {
        if (in_array($conditionJson, [null, '', '0'], true)) {
            return true;
        }

        $condition = json_decode($conditionJson, true);
        if (!is_array($condition) || empty($condition['field'])) {
            return true;
        }

        $fieldName = (string) $condition['field'];
        $op = (string) ($condition['op'] ?? 'eq');
        $expected = $condition['value'] ?? '';

        $actual = $data[$fieldName] ?? '';
        if (is_array($actual)) {
            $actual = implode(', ', $actual);
        }
        $actual = (string) $actual;

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
}
