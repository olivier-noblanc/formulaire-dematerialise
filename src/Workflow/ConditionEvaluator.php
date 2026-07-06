<?php
declare(strict_types=1);

namespace App\Workflow;

use App\Core\Database;

/**
 * Évaluateur de conditions (partagé entre fields et steps).
 *
 * Format JSON : {"field": "nom_champ", "op": "eq", "value": "valeur"}
 * Opérateurs : eq, neq, in, not_empty, empty
 */
final class ConditionEvaluator
{
    /**
     * Évalue une condition générique.
     * @param string|null $conditionJson Le JSON de la condition
     * @param array<string, mixed> $data Les données disponibles
     */
    public function evaluate(?string $conditionJson, array $data): bool
    {
        if (empty($conditionJson)) return true;

        $condition = json_decode($conditionJson, true);
        if (!is_array($condition) || empty($condition['field'])) return true;

        $fieldName = (string) $condition['field'];
        $op = (string) ($condition['op'] ?? 'eq');
        $expected = $condition['value'] ?? '';

        $actual = $data[$fieldName] ?? '';
        if (is_array($actual)) {
            $actual = implode(', ', $actual);
        }
        $actual = (string) $actual;

        return match ($op) {
            'eq' => $actual === (string) $expected,
            'neq' => $actual !== (string) $expected,
            'in' => is_array($expected)
                ? in_array($actual, $expected, true)
                : in_array($actual, array_map('trim', explode(',', (string) $expected)), true),
            'not_empty' => $actual !== '',
            'empty' => $actual === '',
            default => true,
        };
    }
}
