<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Contract\ConditionInterface;

/**
 * Évaluateur de conditions (partagé entre fields et steps).
 *
 * Format JSON : {"field": "nom_champ", "op": "eq", "value": "valeur"}
 * Opérateurs canoniques : eq, neq, contains, in, not_empty, empty
 * Alias historiques acceptés : equals (→ eq), not_equals (→ neq)
 *
 * VALID_OPS est la seule source de vérité des valeurs acceptées : la validation
 * (import JSON, handlers) et les listes déroulantes de l'UI doivent la
 * référencer, jamais recopier une liste d'opérateurs.
 */
final class ConditionEvaluator implements ConditionInterface
{
    /** @var list<string> Opérateurs valides supportés par l'évaluateur */
    public const array VALID_OPS = ['eq', 'equals', 'neq', 'not_equals', 'contains', 'in', 'not_empty', 'empty'];

    /**
     * Libellés des opérateurs canoniques — source unique des libellés UI.
     * Toute clé de cette table doit exister dans VALID_OPS (couvert par test).
     *
     * @var array<string, string>
     */
    public const array OP_LABELS = [
        'eq'        => 'Égal à',
        'neq'       => 'Différent de',
        'contains'  => 'Contient',
        'in'        => 'Dans la liste (valeurs séparées par des virgules)',
        'not_empty' => 'Non vide',
        'empty'     => 'Vide',
    ];

    /**
     * Alias historiques → opérateur canonique équivalent.
     * Toute clé et toute valeur doivent exister dans VALID_OPS (couvert par test).
     *
     * @var array<string, string>
     */
    public const array OP_ALIASES = [
        'equals'     => 'eq',
        'not_equals' => 'neq',
    ];

    /**
     * Opérateur canonique d'un alias (`equals` → `eq`).
     * Une valeur inconnue (ou déjà canonique) est retournée inchangée.
     */
    public static function canonicalOp(string $op): string
    {
        return self::OP_ALIASES[$op] ?? $op;
    }

    /**
     * Couples valeur => libellé à afficher dans un <select> d'opérateur.
     *
     * `$currentOp` est la valeur brute de la condition déjà enregistrée. Si elle
     * est un alias historique absent de OP_LABELS, elle est ajoutée à la liste :
     * sans cela, le navigateur sélectionnerait la première option du select et
     * l'enregistrement réécrirait silencieusement la condition vers un synonyme
     * (voire vers un opérateur différent si l'alias disparaissait de la liste).
     *
     * @return array<string, string>
     */
    public static function opChoices(string $currentOp = ''): array
    {
        $choices = self::OP_LABELS;
        if ($currentOp !== '' && !isset($choices[$currentOp]) && in_array($currentOp, self::VALID_OPS, true)) {
            $label = $choices[self::canonicalOp($currentOp)] ?? $currentOp;
            $choices[$currentOp] = $label . ' (alias « ' . $currentOp . ' »)';
        }

        return $choices;
    }
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
