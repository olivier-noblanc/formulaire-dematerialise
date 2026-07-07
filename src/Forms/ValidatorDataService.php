<?php
declare(strict_types=1);

namespace App\Forms;

use App\Core\Database;

/**
 * Service de gestion des données validateur (filled_by).
 *
 * Extrait de lib/filled_by.php — CRUD des données validator dans
 * submission_validator_data, query des champs form_fields réservés aux validateurs.
 * Les fonctions globales dans lib/filled_by.php délèguent maintenant ici.
 */
final class ValidatorDataService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Récupère les données saisies par les validateurs pour une soumission.
     */
    public function getSubmissionValidatorData(string $submissionId, ?string $stepId = null): array
    {
        $pdo = $this->db->getPdo();

        if ($stepId !== null && $stepId !== '') {
            $formIdStmt = $pdo->prepare("SELECT form_id FROM submissions WHERE id = ?");
            $formIdStmt->execute([$submissionId]);
            $formId = (string)$formIdStmt->fetchColumn();

            $stepLabel = '';
            if ($formId !== '') {
                $labelStmt = $pdo->prepare("SELECT label FROM steps WHERE id = ? AND form_id = ?");
                $labelStmt->execute([$stepId, $formId]);
                $stepLabel = (string)$labelStmt->fetchColumn();
            }

            $sql = "
                SELECT svd.*
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
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        $sql = "
            SELECT svd.*
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
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Sauvegarde les données saisies par un validateur pour un champ (UPSERT).
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
        $pdo = $this->db->getPdo();

        $fieldStmt = $pdo->prepare("SELECT label, field_type FROM form_fields WHERE field_name = ?");
        $fieldStmt->execute([$fieldName]);
        $fieldInfo = $fieldStmt->fetch(\PDO::FETCH_ASSOC);
        $fieldLabel = $fieldInfo['label'] ?? $fieldName;
        $fieldType = $fieldInfo['field_type'] ?? 'text';

        if ($stepLabel === null && $stepId !== null && $stepId !== '') {
            $formIdStmt = $pdo->prepare("SELECT form_id FROM submissions WHERE id = ?");
            $formIdStmt->execute([$submissionId]);
            $formId = (string)$formIdStmt->fetchColumn();
            if ($formId !== '') {
                $labelStmt = $pdo->prepare("SELECT label FROM steps WHERE id = ? AND form_id = ?");
                $labelStmt->execute([$stepId, $formId]);
                $resolved = (string)$labelStmt->fetchColumn();
                $stepLabel = $resolved !== '' ? $resolved : null;
            }
        }

        $sql = "INSERT INTO submission_validator_data
                (id, submission_id, field_name, field_label, field_type, value, filled_by, filled_at, step_id, step_label, filled_by_email, token_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(submission_id, field_name) DO UPDATE SET
                    value = excluded.value,
                    field_label = excluded.field_label,
                    field_type = excluded.field_type,
                    filled_by = excluded.filled_by,
                    filled_at = excluded.filled_at,
                    step_id = excluded.step_id,
                    step_label = excluded.step_label,
                    filled_by_email = excluded.filled_by_email,
                    token_id = excluded.token_id";
        $pdo->prepare($sql)->execute([
            generate_uuid(),
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
            $tokenId,
        ]);
    }

    /**
     * Supprime la valeur d'un champ validator pour une soumission.
     */
    public function deleteValidatorData(string $submissionId, string $fieldName): void
    {
        $this->db->getPdo()
            ->prepare("DELETE FROM submission_validator_data WHERE submission_id = ? AND field_name = ?")
            ->execute([$submissionId, $fieldName]);
    }

    /**
     * Récupère les champs d'un formulaire réservés aux validateurs.
     */
    public function getFormValidatorFields(string $formId, ?string $stepId = null): array
    {
        $pdo = $this->db->getPdo();
        $sql = "SELECT * FROM form_fields
                WHERE form_id = ?
                  AND filled_by = 'validator'";
        $params = [$formId];

        if ($stepId !== null && $stepId !== '') {
            $labelStmt = $pdo->prepare("SELECT label FROM steps WHERE id = ? AND form_id = ?");
            $labelStmt->execute([$stepId, $formId]);
            $stepLabel = (string)$labelStmt->fetchColumn();

            $sql .= " AND (validator_step = ? OR validator_step = ? OR validator_step = '')";
            $params[] = $stepId;
            $params[] = $stepLabel;
        }

        $sql .= " ORDER BY ordre, id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Récupère les champs d'un formulaire, filtrés optionnellement par filled_by.
     */
    public function getFormFields(string $formId, ?string $filledBy = null): array
    {
        $pdo = $this->db->getPdo();
        $sql = "SELECT * FROM form_fields WHERE form_id = ?";
        $params = [$formId];
        if ($filledBy !== null) {
            $sql .= " AND filled_by = ?";
            $params[] = $filledBy;
        }
        $sql .= " ORDER BY ordre, id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Calcule l'état de complétion des champs validator pour un ensemble
     * de soumissions (batch — 2 requêtes SQL pour N soumissions).
     */
    public function getValidatorStatusBatch(array $submissions): array
    {
        $pdo = $this->db->getPdo();

        if (empty($submissions)) {
            return [];
        }

        $formIdBySub = [];
        $subIdsIndex = [];
        foreach ($submissions as $sub) {
            $subId  = (string)($sub['id'] ?? '');
            $formId = (string)($sub['form_id'] ?? '');
            if ($subId === '' || $formId === '') {
                continue;
            }
            $formIdBySub[$subId] = $formId;
            $subIdsIndex[$subId] = true;
        }

        if (empty($subIdsIndex)) {
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
            $fid = (string)($r['form_id'] ?? '');
            $fn  = (string)($r['field_name'] ?? '');
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
            $sid = (string)($r['submission_id'] ?? '');
            $fn  = (string)($r['field_name'] ?? '');
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
