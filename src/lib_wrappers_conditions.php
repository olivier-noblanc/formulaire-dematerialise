<?php

declare(strict_types=1);

/**
 * Global condition evaluation wrappers.
 *
 * Delegates to App\Workflow\ConditionEvaluator.
 * Loaded by lib_wrappers.php (main loader).
 */

/**
 * @param array<string, mixed> $data
 */
function evaluate_condition(?string $condition_json, array $data): bool
{
    return \App\Core\App::conditions()->evaluate($condition_json, $data);
}

/**
 * @param array{condition?: string} $step
 */
function evaluate_step_condition(array $step, string $submission_id): bool
{
    $condition_json = $step['condition'] ?? '';
    if ($condition_json === '' || $condition_json === null || $condition_json === '0') {
        return true;
    }

    $validator_data = \App\Core\App::validatorData()->getSubmissionValidatorData($submission_id);
    $data = [];
    foreach ($validator_data as $vd) {
        $data[$vd['field_name'] ?? ''] = $vd['value'] ?? '';
    }

    return evaluate_condition($condition_json, $data);
}

/**
 * @param array<string, mixed> $field
 * @param array<string, mixed> $form_data
 */
function evaluate_field_condition(array $field, array $form_data): bool
{
    $condition_json = $field['condition'] ?? '';
    return evaluate_condition($condition_json, $form_data);
}
