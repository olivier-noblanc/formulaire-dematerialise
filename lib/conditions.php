<?php
declare(strict_types=1);

/**
 * Condition evaluator — shared logic for fields and steps.
 *
 * Format JSON stocké (identique pour fields et steps) :
 *   {"field": "origine_demande", "op": "eq", "value": "Agent"}
 *   {"field": "type_demande", "op": "in", "value": ["A", "B"]}
 *
 * Opérateurs : eq, neq, in, not_empty, empty
 *
 * @package lib
 */

/**
 * Évalue une condition générique.
 *
 * @param string|null  $condition_json Le JSON de la condition (ou '' / null)
 * @param array<string,mixed> $data    Les données disponibles (POST ou validator_data)
 * @return bool True si la condition est satisfaite (ou absente)
 */
function evaluate_condition(?string $condition_json, array $data): bool {
    return \App\Core\App::conditions()->evaluate($condition_json, $data);
}

/**
 * Évalue la condition d'un step pour une soumission.
 *
 * @param array<string,mixed> $step          L'étape (doit contenir 'condition')
 * @param string              $submission_id ID de la soumission
 * @return bool True si l'étape doit s'exécuter
 */
function evaluate_step_condition(array $step, string $submission_id): bool {
    $condition_json = $step['condition'] ?? '';
    if (empty($condition_json)) return true;

    // Récupérer les données validateur pour cette soumission
    $validator_data = get_submission_validator_data($submission_id);
    $data = [];
    foreach ($validator_data as $vd) {
        $data[$vd['field_name'] ?? ''] = $vd['value'] ?? '';
    }

    return evaluate_condition($condition_json, $data);
}

/**
 * Évalue la condition d'affichage d'un champ (filled_by='demandeur').
 *
 * @param array<string,mixed> $field     Le champ (doit contenir 'condition')
 * @param array<string,mixed> $form_data Les valeurs du formulaire (POST ou DB)
 * @return bool True si le champ doit être affiché
 */
function evaluate_field_condition(array $field, array $form_data): bool {
    $condition_json = $field['condition'] ?? '';
    return evaluate_condition($condition_json, $form_data);
}
