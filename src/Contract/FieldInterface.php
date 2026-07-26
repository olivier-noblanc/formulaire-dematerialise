<?php

declare(strict_types=1);

namespace App\Contract;

interface FieldInterface
{
    /**
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
    public function getFields(string $formId, ?string $filledBy = null): array;
    /**
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
    public function getValidatorFields(string $formId, ?string $stepId = null): array;
    public function saveValidatorData(string $submissionId, string $fieldName, string $value, string $filledBy, ?string $stepId = null): void;
    /**
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
    public function getValidatorData(string $submissionId, ?string $stepId = null): array;
    public function deleteValidatorData(string $submissionId, string $fieldName): void;
}
