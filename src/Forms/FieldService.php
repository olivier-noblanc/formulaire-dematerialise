<?php
declare(strict_types=1);

namespace App\Forms;

use App\Core\Database;

/**
 * Service de gestion des champs de formulaire (demandeur + validateur).
 */
final class FieldService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Récupère les champs d'un formulaire, optionnellement filtrés par filled_by.
     * @return array<int, array<string, mixed>>
     */
    public function getFields(string $formId, ?string $filledBy = null): array
    {
        static $cache = [];
        $cacheKey = $formId . ($filledBy !== null ? ':' . $filledBy : '');
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];

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
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $cache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Récupère les champs validateur d'un formulaire, optionnellement filtrés par step.
     * @return array<int, array<string, mixed>>
     */
    public function getValidatorFields(string $formId, ?string $stepId = null): array
    {
        $pdo = $this->db->getPdo();
        $sql = "SELECT * FROM form_fields WHERE form_id = ? AND filled_by = 'validator'";
        $params = [$formId];

        if ($stepId !== null && $stepId !== '') {
            $stepLabel = '';
            $labelStmt = $pdo->prepare("SELECT label FROM steps WHERE id = ? AND form_id = ?");
            $labelStmt->execute([$stepId, $formId]);
            $stepLabel = (string) ($labelStmt->fetchColumn() ?? '');

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
     * Récupère les données validator d'une soumission.
     * @return array<int, array<string, mixed>>
     */
    public function getValidatorData(string $submissionId, ?string $stepId = null): array
    {
        $pdo = $this->db->getPdo();
        $sql = "SELECT svd.* FROM submission_validator_data svd WHERE svd.submission_id = ?";
        $params = [$submissionId];

        if ($stepId !== null && $stepId !== '') {
            $sql .= " AND svd.field_name IN (
                SELECT ff.field_name FROM form_fields ff
                WHERE ff.form_id = (SELECT form_id FROM submissions WHERE id = ?)
                AND ff.filled_by = 'validator'
                AND (ff.validator_step = ? OR ff.validator_step = ? OR ff.validator_step = '')
            )";
            $params[] = $submissionId;
            $params[] = $stepId;

            $labelStmt = $pdo->prepare("SELECT label FROM steps WHERE id = ?");
            $labelStmt->execute([$stepId]);
            $stepLabel = (string) ($labelStmt->fetchColumn() ?? '');
            $params[] = $stepLabel;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
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
        $pdo = $this->db->getPdo();

        $fieldStmt = $pdo->prepare("SELECT label, field_type FROM form_fields WHERE field_name = ?");
        $fieldStmt->execute([$fieldName]);
        $fieldInfo = $fieldStmt->fetch(\PDO::FETCH_ASSOC);
        $fieldLabel = $fieldInfo['label'] ?? $fieldName;
        $fieldType = $fieldInfo['field_type'] ?? 'text';

        if ($stepLabel === null && $stepId !== null) {
            $labelStmt = $pdo->prepare("SELECT label FROM steps WHERE id = ?");
            $labelStmt->execute([$stepId]);
            $stepLabel = (string) ($labelStmt->fetchColumn() ?? '');
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
            $this->generateUuid(),
            $submissionId, $fieldName, $fieldLabel, $fieldType,
            $value, $filledBy, gmdate('Y-m-d H:i:s'),
            $stepId, $stepLabel, $filledByEmail, $tokenId,
        ]);
    }

    public function deleteValidatorData(string $submissionId, string $fieldName): void
    {
        $pdo = $this->db->getPdo();
        $pdo->prepare("DELETE FROM submission_validator_data WHERE submission_id = ? AND field_name = ?")
            ->execute([$submissionId, $fieldName]);
    }

    /**
     * Statut batch des champs validator pour N soumissions (pas de N+1).
     * @param array<int, string> $submissionIds
     * @return array<string, array{expected: int, filled: int, complete: bool}>
     */
    public function getValidatorStatusBatch(array $submissionIds): array
    {
        if (empty($submissionIds)) return [];

        $pdo = $this->db->getPdo();
        $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));

        // Champs validator attendus par form_id
        $stmt = $pdo->prepare("
            SELECT s.form_id, COUNT(DISTINCT ff.field_name) as expected
            FROM submissions s
            JOIN form_fields ff ON ff.form_id = s.form_id AND ff.filled_by = 'validator'
            WHERE s.id IN ($placeholders)
            GROUP BY s.form_id
        ");
        $stmt->execute($submissionIds);
        $expectedByForm = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $expectedByForm[$row['form_id']] = (int) $row['expected'];
        }

        // Champs validator remplis par submission_id
        $stmt2 = $pdo->prepare("
            SELECT svd.submission_id, COUNT(DISTINCT svd.field_name) as filled, s.form_id
            FROM submission_validator_data svd
            JOIN submissions s ON s.id = svd.submission_id
            WHERE svd.submission_id IN ($placeholders) AND svd.value IS NOT NULL AND svd.value != ''
            GROUP BY svd.submission_id
        ");
        $stmt2->execute($submissionIds);
        $filledBySub = [];
        $formBySub = [];
        foreach ($stmt2->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $filledBySub[$row['submission_id']] = (int) $row['filled'];
            $formBySub[$row['submission_id']] = $row['form_id'];
        }

        // Construire le résultat
        $result = [];
        foreach ($submissionIds as $subId) {
            $formId = $formBySub[$subId] ?? null;
            $expected = $formId !== null ? ($expectedByForm[$formId] ?? 0) : 0;
            $filled = $filledBySub[$subId] ?? 0;
            $result[$subId] = [
                'expected' => $expected,
                'filled' => $filled,
                'complete' => $expected === 0 || $filled >= $expected,
            ];
        }

        return $result;
    }

    private function generateUuid(): string
    {
        return bin2hex(random_bytes(16));
    }
}
