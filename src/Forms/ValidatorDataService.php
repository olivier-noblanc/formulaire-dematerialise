<?php

declare(strict_types=1);

namespace App\Forms;

use App\Repository\FormRepository;
use App\Repository\SubmissionRepository;

/**
 * Service de gestion des données validateur (filled_by).
 *
 * Délègue les opérations CRUD basiques à FieldService.
 * Conserve uniquement la logique spécifique aux validateurs :
 * getSubmissionValidatorData (filtrage par step) et
 * getValidatorStatusBatch (batch avec pré-résolution form_id).
 */
final readonly class ValidatorDataService
{
    public function __construct(
        private SubmissionRepository $submissionRepository,
        private FormRepository $formRepository,
        private FieldService $fieldService,
    ) {
    }

    /**
     * Récupère les données saisies par les validateurs pour une soumission.
     */
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
    public function getSubmissionValidatorData(string $submissionId, ?string $stepId = null): array
    {
        if ($stepId !== null && $stepId !== '') {
            $formId = $this->submissionRepository->findFormIdById($submissionId) ?? '';
            $stepLabel = '';
            if ($formId !== '') {
                $stepLabel = $this->formRepository->getStepLabel($stepId) ?? '';
            }

            return $this->submissionRepository->getValidatorDataByStepFields($submissionId, $stepId, $stepLabel);
        }

        return $this->submissionRepository->getValidatorData($submissionId);
    }

    /**
     * Sauvegarde les données saisies par un validateur pour un champ (UPSERT).
     * Délègue à FieldService.
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
        $this->fieldService->saveValidatorData(
            $submissionId,
            $fieldName,
            $value,
            $filledBy,
            $stepId,
            $stepLabel,
            $filledByEmail,
            $tokenId
        );
    }

    /**
     * Supprime la valeur d'un champ validator pour une soumission.
     * Délègue à FieldService.
     */
    public function deleteValidatorData(string $submissionId, string $fieldName): void
    {
        $this->fieldService->deleteValidatorData($submissionId, $fieldName);
    }

    /**
     * Récupère les champs d'un formulaire réservés aux validateurs.
     * Délègue à FieldService::getValidatorFields().
     */
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
    public function getFormValidatorFields(string $formId, ?string $stepId = null): array
    {
        return $this->fieldService->getValidatorFields($formId, $stepId);
    }

    /**
     * Récupère les champs d'un formulaire, filtrés optionnellement par filled_by.
     * Délègue à FieldService::getFields().
     */
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
    public function getFormFields(string $formId, ?string $filledBy = null): array
    {
        return $this->fieldService->getFields($formId, $filledBy);
    }

    /**
     * Calcule l'état de complétion des champs validator pour un ensemble
     * de soumissions (batch — 2 requêtes SQL pour N soumissions).
     *
     * @param array<int, array<string, mixed>> $submissions
     * @return array<string, array{total: int, filled: int, complet: bool}>
     */
    public function getValidatorStatusBatch(array $submissions): array
    {
        if ($submissions === []) {
            return [];
        }

        $formIdBySub = [];
        $subIdsIndex = [];
        foreach ($submissions as $submission) {
            $subId  = (string) ($submission['id'] ?? '');
            $formId = (string) ($submission['form_id'] ?? '');
            if ($subId === '' || $formId === '') {
                continue;
            }
            $formIdBySub[$subId] = $formId;
            $subIdsIndex[$subId] = true;
        }

        if ($subIdsIndex === []) {
            return [];
        }

        $formIds = array_values(array_unique(array_values($formIdBySub)));
        $validatorFieldsByForm = [];
        foreach ($formIds as $fid) {
            $fields = $this->formRepository->getValidatorFields($fid);
            foreach ($fields as $field) {
                $fn = (string) ($field['field_name'] ?? '');
                if ($fn !== '') {
                    $validatorFieldsByForm[$fid][] = $fn;
                }
            }
        }

        $filledBySub = [];
        foreach (array_keys($subIdsIndex) as $subId) {
            $data = $this->submissionRepository->getValidatorData($subId);
            foreach ($data as $row) {
                $fn  = (string) ($row['field_name'] ?? '');
                $val = $row['value'] ?? null;
                if ($fn !== '' && $val !== null && $val !== '') {
                    $filledBySub[$subId][] = $fn;
                }
            }
        }

        $result = [];
        foreach ($formIdBySub as $subId => $formId) {
            $expected = $validatorFieldsByForm[$formId] ?? [];
            $filled   = $filledBySub[$subId] ?? [];
            $total        = count($expected);
            $filledCount = count(array_intersect($expected, $filled));
            $result[$subId] = [
                'total'   => $total,
                'filled'  => $filledCount,
                'complet' => ($total === 0) ? true : ($filledCount >= $total),
            ];
        }

        return $result;
    }
}
