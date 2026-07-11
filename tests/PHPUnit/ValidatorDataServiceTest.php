<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Forms\ValidatorDataService;
use App\Forms\FieldService;
use App\Core\Database;

final class ValidatorDataServiceTest extends TestCase
{
    private ValidatorDataService $service;
    private Database $db;

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(\App\Core\Database::class);
        $fields = new FieldService($this->db);
        $this->service = new ValidatorDataService($this->db, $fields);
    }

    protected function tearDown(): void
    {
        // Cleanup test data: forms with test slugs and their dependencies
        $pdo = $this->db->getPdo();
        $testForms = $pdo->query("SELECT id FROM forms WHERE slug LIKE 'test-%'")->fetchAll(\PDO::FETCH_COLUMN);
        if (!empty($testForms)) {
            $placeholders = implode(',', array_fill(0, count($testForms), '?'));
            $stmt = $pdo->prepare("SELECT id FROM submissions WHERE form_id IN ({$placeholders})");
            $stmt->execute($testForms);
            $testSubs = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            if (!empty($testSubs)) {
                $subPH = implode(',', array_fill(0, count($testSubs), '?'));
                $pdo->prepare("DELETE FROM submission_validator_data WHERE submission_id IN ({$subPH})")->execute($testSubs);
                $pdo->prepare("DELETE FROM tokens WHERE submission_id IN ({$subPH})")->execute($testSubs);
                $pdo->prepare("DELETE FROM submissions WHERE id IN ({$subPH})")->execute($testSubs);
            }
            $pdo->prepare("DELETE FROM form_fields WHERE form_id IN ({$placeholders})")->execute($testForms);
            $pdo->prepare("DELETE FROM steps WHERE form_id IN ({$placeholders})")->execute($testForms);
            $pdo->prepare("DELETE FROM forms WHERE id IN ({$placeholders})")->execute($testForms);
        }
    }

    public function testGetSubmissionValidatorDataReturnsEmptyForNonexistent(): void
    {
        $result = $this->service->getSubmissionValidatorData('nonexistent-id');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetSubmissionValidatorDataWithStepReturnsEmpty(): void
    {
        $result = $this->service->getSubmissionValidatorData('nonexistent-id', 'nonexistent-step');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testDeleteValidatorDataDoesNotThrow(): void
    {
        $this->service->deleteValidatorData('nonexistent-sub', 'nonexistent-field');
        // No exception = pass
        $this->assertTrue(true);
    }

    public function testGetFormValidatorFieldsReturnsEmptyForNonexistentForm(): void
    {
        $result = $this->service->getFormValidatorFields('nonexistent-form-id');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetFormValidatorFieldsWithStepReturnsEmpty(): void
    {
        $result = $this->service->getFormValidatorFields('nonexistent-form-id', 'nonexistent-step');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetFormFieldsReturnsEmptyForNonexistentForm(): void
    {
        $result = $this->service->getFormFields('nonexistent-form-id');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetFormFieldsWithFilledByReturnsEmpty(): void
    {
        $result = $this->service->getFormFields('nonexistent-form-id', 'validator');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetValidatorStatusBatchReturnsEmptyForEmptyInput(): void
    {
        $result = $this->service->getValidatorStatusBatch([]);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetValidatorStatusBatchReturnsEmptyForInvalidSubmissions(): void
    {
        $result = $this->service->getValidatorStatusBatch([
            ['id' => '', 'form_id' => ''],
            ['form_id' => 'valid-form'],
        ]);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetValidatorStatusBatchWithRealData(): void
    {
        $pdo = $this->db->getPdo();

        // Insert a form and a validator field
        $formId = generate_uuid();
        $slug = 'test-batch-' . substr(generate_uuid(), 0, 8);
        $pdo->prepare("INSERT INTO forms (id, slug, label, actif) VALUES (?, ?, 'Test Form', 1)")
            ->execute([$formId, $slug]);

        $fieldName = 'avis_medecin_' . generate_uuid();
        $pdo->prepare("INSERT INTO form_fields (id, form_id, field_name, label, field_type, filled_by, ordre)
                        VALUES (?, ?, ?, 'Avis médecin', 'text', 'validator', 1)")
            ->execute([generate_uuid(), $formId, $fieldName]);

        // Insert a submission
        $subId = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, status, submitted_by, data) VALUES (?, ?, 'en_cours', 'test@test.com', '{}')")
            ->execute([$subId, $formId]);

        // Test with no filled data → total=1, filled=0, complet=false
        $result = $this->service->getValidatorStatusBatch([
            ['id' => $subId, 'form_id' => $formId],
        ]);

        $this->assertArrayHasKey($subId, $result);
        $this->assertSame(1, $result[$subId]['total']);
        $this->assertSame(0, $result[$subId]['filled']);
        $this->assertFalse($result[$subId]['complet']);
    }

    public function testSaveAndRetrieveValidatorData(): void
    {
        $pdo = $this->db->getPdo();

        // Setup: form, field, submission
        $formId = generate_uuid();
        $slug = 'test-crud-' . substr(generate_uuid(), 0, 8);
        $pdo->prepare("INSERT INTO forms (id, slug, label, actif) VALUES (?, ?, 'Test Form', 1)")
            ->execute([$formId, $slug]);

        $fieldName = 'decision_sg_' . generate_uuid();
        $pdo->prepare("INSERT INTO form_fields (id, form_id, field_name, label, field_type, filled_by, ordre)
                        VALUES (?, ?, ?, 'Décision SG', 'text', 'validator', 1)")
            ->execute([generate_uuid(), $formId, $fieldName]);

        $subId = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, status, submitted_by, data) VALUES (?, ?, 'en_cours', 'test@test.com', '{}')")
            ->execute([$subId, $formId]);

        // Save validator data
        $this->service->saveValidatorData($subId, $fieldName, 'Approuvé', 'validator');

        // Retrieve it
        $result = $this->service->getSubmissionValidatorData($subId);
        $this->assertNotEmpty($result);
        $this->assertSame($fieldName, $result[0]['field_name']);
        $this->assertSame('Approuvé', $result[0]['value']);

        // Delete it
        $this->service->deleteValidatorData($subId, $fieldName);
        $afterDelete = $this->service->getSubmissionValidatorData($subId);
        $this->assertEmpty($afterDelete);
    }

    public function testValidatorStatusBatchWithFilledData(): void
    {
        $pdo = $this->db->getPdo();

        $formId = generate_uuid();
        $slug = 'test-filled-' . substr(generate_uuid(), 0, 8);
        $pdo->prepare("INSERT INTO forms (id, slug, label, actif) VALUES (?, ?, 'Test Form', 1)")
            ->execute([$formId, $slug]);

        $fieldName = 'avis_medecin_' . generate_uuid();
        $pdo->prepare("INSERT INTO form_fields (id, form_id, field_name, label, field_type, filled_by, ordre)
                        VALUES (?, ?, ?, 'Avis médecin', 'text', 'validator', 1)")
            ->execute([generate_uuid(), $formId, $fieldName]);

        $subId = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, status, submitted_by, data) VALUES (?, ?, 'en_cours', 'test@test.com', '{}')")
            ->execute([$subId, $formId]);

        // Save data → total=1, filled=1, complet=true
        $this->service->saveValidatorData($subId, $fieldName, 'Favorable', 'validator');

        $result = $this->service->getValidatorStatusBatch([
            ['id' => $subId, 'form_id' => $formId],
        ]);

        $this->assertArrayHasKey($subId, $result);
        $this->assertSame(1, $result[$subId]['total']);
        $this->assertSame(1, $result[$subId]['filled']);
        $this->assertTrue($result[$subId]['complet']);
    }
}
