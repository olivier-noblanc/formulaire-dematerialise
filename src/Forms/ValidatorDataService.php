<?php

declare(strict_types=1);

namespace App\Forms;

use App\Core\Database;

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
    public function __construct(private Database $database, private FieldService $fieldService)
    {
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
        $pdo = $this->database->getPdo();

        if ($stepId !== null && $stepId !== '') {
            $formIdStmt = $pdo->prepare('SELECT form_id FROM submissions WHERE id = ?');
            $formIdStmt->execute([$submissionId]);
            $formId = (string) $formIdStmt->fetchColumn();

            $stepLabel = '';
            if ($formId !== '') {
                $labelStmt = $pdo->prepare('SELECT label FROM steps WHERE id = ? AND form_id = ?');
                $labelStmt->execute([$stepId, $formId]);
                $stepLabel = (string) $labelStmt->fetchColumn();
            }

            $sql = "
                SELECT svd.id, svd.submission_id, svd.field_name, svd.field_label, svd.field_type, svd.value, svd.filled_by, svd.filled_at, svd.step_id, svd.step_label, svd.filled_by_email, svd.token_id
                FROM submission_validator_data svd
                WHERE svd.submission_id = ?
                AND svd.field_name IN (
                    SELECT ff.field_name FROM form_fields ff
                    WHERE ff.form_id = (SELECT form_id FROM submissions WHERE id = ?)
                    AND ff.filled_by = 'validator'
                    AND (ff.validator_step = ? OR ff.validator_step = ? OR ff.validator_step = '')
                )
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$submissionId, $submissionId, $stepId, $stepLabel]);
            /** @var array<int, array{id: string, submission_id: string, field_name: string, field_label: string, field_type: string, value: string|null, filled_by: string, filled_at: string, step_id: string|null, step_label: string|null, filled_by_email: string|null, token_id: string|null}> $result */
            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return $result;
        }

        $sql = "
            SELECT svd.id, svd.submission_id, svd.field_name, svd.field_label, svd.field_type, svd.value, svd.filled_by, svd.filled_at, svd.step_id, svd.step_label, svd.filled_by_email, svd.token_id
            FROM submission_validator_data svd
            WHERE svd.submission_id = ?
            AND svd.field_name IN (
                SELECT ff.field_name FROM form_fields ff
                WHERE ff.form_id = (SELECT form_id FROM submissions WHERE id = ?)
                AND ff.filled_by = 'validator'
            )
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$submissionId, $submissionId]);
        /** @var array<int, array{id: string, submission_id: string, field_name: string, field_label: string, field_type: string, value: string|null, filled_by: string, filled_at: string, step_id: string|null, step_label: string|null, filled_by_email: string|null, token_id: string|null}> $result */
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $result;
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
        $pdo = $this->database->getPdo();

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
        $formPlaceholders = implode(',', array_fill(0, count($formIds), '?'));
        $stmtFields = $pdo->prepare(
            "SELECT form_id, field_name FROM form_fields
             WHERE filled_by = 'validator' AND form_id IN ($formPlaceholders)"
        );
        $stmtFields->execute($formIds);
        $validatorFieldsByForm = [];
        foreach ($stmtFields->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $fid = (string) ($r['form_id'] ?? '');
            $fn  = (string) ($r['field_name'] ?? '');
            if ($fid !== '' && $fn !== '') {
                $validatorFieldsByForm[$fid][] = $fn;
            }
        }

        $subIdList = array_keys($subIdsIndex);
        $subPlaceholders = implode(',', array_fill(0, count($subIdList), '?'));
        $stmtData = $pdo->prepare(
            "SELECT submission_id, field_name FROM submission_validator_data
             WHERE submission_id IN ($subPlaceholders)
             AND value IS NOT NULL AND value != ''"
        );
        $stmtData->execute($subIdList);
        $filledBySub = [];
        foreach ($stmtData->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $sid = (string) ($r['submission_id'] ?? '');
            $fn  = (string) ($r['field_name'] ?? '');
            if ($sid !== '' && $fn !== '') {
                $filledBySub[$sid][] = $fn;
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
