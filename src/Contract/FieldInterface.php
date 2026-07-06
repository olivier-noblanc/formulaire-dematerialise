<?php
declare(strict_types=1);

namespace App\Contract;

interface FieldInterface
{
    /** @return array<int, array<string, mixed>> */
    public function getFields(string $formId, ?string $filledBy = null): array;
    /** @return array<int, array<string, mixed>> */
    public function getValidatorFields(string $formId, ?string $stepId = null): array;
    public function saveValidatorData(string $submissionId, string $fieldName, string $value, string $filledBy, ?string $stepId = null): void;
    /** @return array<int, array<string, mixed>> */
    public function getValidatorData(string $submissionId, ?string $stepId = null): array;
    public function deleteValidatorData(string $submissionId, string $fieldName): void;
}
