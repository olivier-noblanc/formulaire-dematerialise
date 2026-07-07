<?php
declare(strict_types=1);

/**
 * Validator-only fields (filled_by) — Option A.
 *
 * Thin wrappers delegating to App\Forms\ValidatorDataService.
 *
 * @package lib
 */

/**
 * Récupère les données saisies par les validateurs pour une soumission.
 * @param string      $submission_id ID de la soumission
 * @param string|null $step_id       Si fourni, limite aux champs de cette étape
 * @return array<string, mixed> Tableau des données validateur
 */
function get_submission_validator_data(string $submission_id, ?string $step_id = null): array {
    return \App\Core\App::validatorData()->getSubmissionValidatorData($submission_id, $step_id);
}

/**
 * Sauvegarde les données saisies par un validateur pour un champ (UPSERT).
 */
function save_validator_data(
    string $submission_id,
    string $field_name,
    string $value,
    string $filled_by,
    ?string $step_id = null,
    ?string $step_label = null,
    ?string $filled_by_email = null,
    ?string $token_id = null
): void {
    \App\Core\App::validatorData()->saveValidatorData(
        $submission_id, $field_name, $value, $filled_by,
        $step_id, $step_label, $filled_by_email, $token_id
    );
}

/**
 * Supprime la valeur d'un champ validator pour une soumission.
 */
function delete_validator_data(string $submission_id, string $field_name): void {
    \App\Core\App::validatorData()->deleteValidatorData($submission_id, $field_name);
}

/**
 * Récupère les champs d'un formulaire réservés aux validateurs.
 */
function get_form_validator_fields(string $form_id, ?string $step_id = null): array {
    return \App\Core\App::validatorData()->getFormValidatorFields($form_id, $step_id);
}

/**
 * Modifie get_form_fields() — filtre optionnel par filled_by.
 */
function get_form_fields(string $form_id, ?string $filled_by = null): array {
    return \App\Core\App::validatorData()->getFormFields($form_id, $filled_by);
}

/**
 * Calcule l'état de complétion des champs validator pour un ensemble de soumissions (batch).
 *
 * @param PDO                        $pdo         Connexion PDO (ignoré — utilisé via DI)
 * @param array<int, array<string, mixed>> $submissions Lignes submissions
 * @return array<string, array{total: int, filled: int, complet: bool}>
 */
function get_validator_status_batch(PDO $pdo, array $submissions): array {
    return \App\Core\App::validatorData()->getValidatorStatusBatch($submissions);
}
