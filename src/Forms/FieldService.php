<?php

declare(strict_types=1);

namespace App\Forms;

use App\Contract\FieldInterface;
use App\Core\App;
use App\Enum\FieldType;
use App\Enum\FilledBy;
use App\Repository\FormRepository;
use App\Repository\SubmissionRepository;

/**
 * Service de gestion des champs de formulaire (demandeur + validateur).
 *
 * Tout accès DB passe par les repositories injectés ($formRepository,
 * $submissionRepository) ou résolus via App::getInstance().
 */
final readonly class FieldService implements FieldInterface
{
    public FormRepository $formRepository;
    public SubmissionRepository $submissionRepository;

    public function __construct(
        ?FormRepository $formRepository = null,
        ?SubmissionRepository $submissionRepository = null
    ) {
        $app = App::getInstance();
        $this->formRepository = $formRepository ?? $app->get(FormRepository::class);
        $this->submissionRepository = $submissionRepository ?? $app->get(SubmissionRepository::class);
    }

    /**
     * Récupère les champs d'un formulaire, optionnellement filtrés par filled_by.
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
    public function getFields(string $formId, ?string $filledBy = null): array
    {
        static $cache = [];
        $cacheKey = $formId . ($filledBy !== null ? ':' . $filledBy : '');
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $result = $this->formRepository->getFieldsByFilledBy($formId, $filledBy);
        $cache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Récupère les champs validateur d'un formulaire, optionnellement filtrés par step.
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
    public function getValidatorFields(string $formId, ?string $stepId = null): array
    {
        return $this->formRepository->getValidatorFields($formId, $stepId);
    }

    /**
     * Récupère les données validator d'une soumission.
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
    public function getValidatorData(string $submissionId, ?string $stepId = null): array
    {
        if ($stepId === null || $stepId === '') {
            return $this->submissionRepository->getValidatorData($submissionId);
        }

        // Avec filtre step_id : on doit lookup le step_label (via FormRepository)
        // puis utiliser getValidatorDataByStepFields() qui filtre par validator_step.
        $stepLabel = $this->formRepository->getStepLabel($stepId) ?? '';
        return $this->submissionRepository->getValidatorDataByStepFields($submissionId, $stepId, $stepLabel);
    }

    /**
     * Sauvegarde une donnée validator (UPSERT).
     */
    public function saveValidatorData(
        string $submissionId,
        string $fieldName,
        string $value,
        string $filledBy,
        ?string $stepId = null,
        ?string $stepLabel = null,
        ?string $filledByEmail = null,
        ?string $tokenId = null
    ): void {
        $fieldInfo = $this->formRepository->findFieldLabelAndTypeByName($fieldName);
        $fieldLabel = $fieldInfo['label'] ?? $fieldName;
        $fieldType = $fieldInfo['field_type'] ?? FieldType::Text->value;

        if ($stepLabel === null && $stepId !== null) {
            $stepLabel = $this->formRepository->getStepLabel($stepId) ?? '';
        }

        $this->submissionRepository->upsertValidatorData(
            $this->generateUuid(),
            $submissionId,
            $fieldName,
            $fieldLabel,
            $fieldType,
            $value,
            $filledBy,
            gmdate('Y-m-d H:i:s'),
            $stepId,
            $stepLabel,
            $filledByEmail,
            $tokenId
        );
    }

    public function deleteValidatorData(string $submissionId, string $fieldName): void
    {
        $this->submissionRepository->deleteValidatorDataBySubmissionAndField($submissionId, $fieldName);
    }

    private function generateUuid(): string
    {
        return \generate_uuid();
    }
}
