<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Forms\FieldService;
use App\Core\Database;

final class FieldServiceTest extends TestCase
{
    private FieldService $fields;
    private Database $db;

    private string $testFormId;
    private string $testSubmissionId;
    private string $testStepId;
    private string $testFieldName;

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(\App\Core\Database::class);
        $this->fields = new FieldService();
        $pdo = $this->db->getPdo();

        // Create test form
        $this->testFormId = \generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif) VALUES (?, ?, ?, ?, 1)")
            ->execute([$this->testFormId, 'test-form-' . substr($this->testFormId, 0, 8), 'Test Form', 'Test form for unit tests']);

        // Create test step
        $this->testStepId = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, 1, 1)")
            ->execute([$this->testStepId, $this->testFormId, 'Étape Test']);

        // Create test validator field
        $this->testFieldName = 'test_field_' . substr(\generate_uuid(), 0, 8);
        $fieldId = \generate_uuid();
        $pdo->prepare("INSERT INTO form_fields (id, form_id, label, field_type, field_name, required, ordre, filled_by, validator_step) VALUES (?, ?, ?, ?, ?, 0, 1, 'validator', ?)")
            ->execute([$fieldId, $this->testFormId, 'Test Field', 'text', $this->testFieldName, $this->testStepId]);

        // Create test submission
        $this->testSubmissionId = \generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status) VALUES (?, ?, ?, ?, 'en_cours')")
            ->execute([$this->testSubmissionId, $this->testFormId, '{}', 'testeur@test.com']);
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->getPdo();

        // Clean up test data in reverse dependency order
        $pdo->prepare("DELETE FROM submission_validator_data WHERE submission_id = ?")->execute([$this->testSubmissionId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$this->testSubmissionId]);
        $pdo->prepare("DELETE FROM form_fields WHERE form_id = ?")->execute([$this->testFormId]);
        $pdo->prepare("DELETE FROM steps WHERE form_id = ?")->execute([$this->testFormId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$this->testFormId]);
    }

    // ── getFields ──────────────────────────────────────────────

    public function testGetFieldsReturnsArray(): void
    {
        $fields = $this->fields->getFields($this->testFormId);
        self::assertIsArray($fields);
        self::assertNotEmpty($fields);
    }

    public function testGetFieldsWithFilledByFilter(): void
    {
        $fields = $this->fields->getFields($this->testFormId, 'validator');
        self::assertIsArray($fields);
        foreach ($fields as $field) {
            self::assertSame('validator', $field['filled_by']);
        }
    }

    public function testGetFieldsNormalizesRequiredToStrictBooleanInteger(): void
    {
        $fields = $this->fields->getFields($this->testFormId);
        self::assertNotEmpty($fields);
        foreach ($fields as $field) {
            self::assertIsInt($field['required']);
            self::assertContains($field['required'], [0, 1]);
        }
    }

    public function testGetValidatorFieldsNormalizesLegacyRequiredValue(): void
    {
        $method = new \ReflectionMethod(FieldService::class, 'normalizeRows');
        $method->setAccessible(true);
        $rows = [[
            'id' => 'field-legacy', 'form_id' => 'form-test', 'label' => 'Field', 'field_type' => 'text',
            'field_name' => 'legacy', 'options' => null, 'hint' => '', 'required' => 21, 'ordre' => 1,
            'card_group' => 'Général', 'filled_by' => 'validator', 'validator_step' => '',
            'visibility' => 'all', 'condition' => '',
        ]];
        $this->expectException(\InvalidArgumentException::class);
        $method->invoke($this->fields, $rows);
    }

    #[DataProvider('requiredNormalizationProvider')]
    public function testNormalizationContractForAllDatabaseRepresentations(mixed $raw, int $expected): void
    {
        $method = new \ReflectionMethod(FieldService::class, 'normalizeRows');
        $method->setAccessible(true);
        $row = [
            'id' => 'field-contract', 'form_id' => 'form-test', 'label' => 'Field', 'field_type' => 'text',
            'field_name' => 'contract', 'options' => null, 'hint' => '', 'required' => $raw, 'ordre' => 1,
            'card_group' => 'Général', 'filled_by' => 'demandeur', 'validator_step' => '',
            'visibility' => 'all', 'condition' => '',
        ];
        if ($expected === 0 && $raw !== 0 && $raw !== '0') {
            $this->expectException(\InvalidArgumentException::class);
        }
        $normalized = $method->invoke($this->fields, [$row])[0]['required'];
        self::assertSame($expected, $normalized);
        self::assertIsInt($normalized);
    }

    public function testCreateFieldRejectsLegacyRequiredBeforeDatabase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fields->formRepository->createField([
            'form_id' => $this->testFormId,
            'label' => 'Invalid',
            'field_name' => 'invalid_required',
            'required' => 21,
        ]);
    }

    public function testUpdateFieldRejectsNullRequiredBeforeDatabase(): void
    {
        $fieldId = $this->db->getPdo()->query("SELECT id FROM form_fields WHERE form_id = '" . $this->testFormId . "'")->fetchColumn();
        $this->expectException(\InvalidArgumentException::class);
        $this->fields->formRepository->updateField((string) $fieldId, ['required' => null]);
    }

    /** @return iterable<string, array{mixed, int}> */
    public static function requiredNormalizationProvider(): iterable
    {
        yield 'null' => [null, 0];
        yield 'empty' => ['', 0];
        yield 'zero integer' => [0, 0];
        yield 'zero string' => ['0', 0];
        yield 'one integer' => [1, 1];
        yield 'one string' => ['1', 1];
        yield 'legacy integer rejected' => [2, 0];
        yield 'legacy string rejected' => ['2', 0];
        yield 'legacy ordinal rejected' => [21, 0];
        yield 'negative rejected' => [-1, 0];
        yield 'true rejected' => [true, 0];
        yield 'false rejected' => [false, 0];
        yield 'arbitrary string rejected' => ['yes', 0];
    }

    public function testGetFieldsWithNonexistentFormReturnsEmpty(): void
    {
        $fields = $this->fields->getFields('nonexistent-form-id');
        self::assertIsArray($fields);
        self::assertEmpty($fields);
    }

    // ── getValidatorFields ─────────────────────────────────────

    public function testGetValidatorFieldsReturnsArray(): void
    {
        $fields = $this->fields->getValidatorFields($this->testFormId);
        self::assertIsArray($fields);
        self::assertNotEmpty($fields);
        foreach ($fields as $field) {
            self::assertSame('validator', $field['filled_by']);
        }
    }

    public function testGetValidatorFieldsWithStepId(): void
    {
        $fields = $this->fields->getValidatorFields($this->testFormId, $this->testStepId);
        self::assertIsArray($fields);
    }

    public function testGetValidatorFieldsWithEmptyStepId(): void
    {
        $fields = $this->fields->getValidatorFields($this->testFormId, '');
        self::assertIsArray($fields);
    }

    // ── getValidatorData ───────────────────────────────────────

    public function testGetValidatorDataReturnsArray(): void
    {
        $data = $this->fields->getValidatorData($this->testSubmissionId);
        self::assertIsArray($data);
    }

    public function testGetValidatorDataWithStepId(): void
    {
        $data = $this->fields->getValidatorData($this->testSubmissionId, $this->testStepId);
        self::assertIsArray($data);
    }

    public function testGetValidatorDataWithEmptyStepId(): void
    {
        $data = $this->fields->getValidatorData($this->testSubmissionId, '');
        self::assertIsArray($data);
    }

    // ── saveValidatorData ──────────────────────────────────────

    public function testSaveValidatorDataInsertsNewRecord(): void
    {
        $this->fields->saveValidatorData(
            $this->testSubmissionId,
            $this->testFieldName,
            'test_value',
            'validator',
            $this->testStepId
        );

        $data = $this->fields->getValidatorData($this->testSubmissionId);
        $found = false;
        foreach ($data as $row) {
            if ($row['field_name'] === $this->testFieldName) {
                $found = true;
                self::assertSame('test_value', $row['value']);
                self::assertSame('validator', $row['filled_by']);
            }
        }
        self::assertTrue($found, 'Saved validator data should be retrievable');
    }

    public function testSaveValidatorDataUpsertsExistingRecord(): void
    {
        // First save
        $this->fields->saveValidatorData(
            $this->testSubmissionId,
            $this->testFieldName,
            'original_value',
            'validator'
        );

        // Second save (upsert)
        $this->fields->saveValidatorData(
            $this->testSubmissionId,
            $this->testFieldName,
            'updated_value',
            'validator'
        );

        $data = $this->fields->getValidatorData($this->testSubmissionId);
        $matchingRows = array_filter($data, fn($row) => $row['field_name'] === $this->testFieldName);
        self::assertCount(1, $matchingRows, 'UPSERT should not create duplicate records');
        $row = array_values($matchingRows)[0];
        self::assertSame('updated_value', $row['value']);
    }

    public function testSaveValidatorDataWithStepLabel(): void
    {
        $this->fields->saveValidatorData(
            $this->testSubmissionId,
            $this->testFieldName,
            'value_with_label',
            'validator',
            $this->testStepId,
            'Custom Step Label'
        );

        $data = $this->fields->getValidatorData($this->testSubmissionId);
        foreach ($data as $row) {
            if ($row['field_name'] === $this->testFieldName) {
                self::assertSame('Custom Step Label', $row['step_label']);
            }
        }
    }

    public function testSaveValidatorDataResolvesStepLabelFromId(): void
    {
        $this->fields->saveValidatorData(
            $this->testSubmissionId,
            $this->testFieldName,
            'value_resolved',
            'validator',
            $this->testStepId,
            null // stepLabel = null, should resolve from stepId
        );

        $data = $this->fields->getValidatorData($this->testSubmissionId);
        foreach ($data as $row) {
            if ($row['field_name'] === $this->testFieldName) {
                self::assertSame('Étape Test', $row['step_label']);
            }
        }
    }

    public function testSaveValidatorDataWithNullStepId(): void
    {
        $this->fields->saveValidatorData(
            $this->testSubmissionId,
            $this->testFieldName,
            'no_step',
            'validator',
            null,
            null
        );

        $data = $this->fields->getValidatorData($this->testSubmissionId);
        foreach ($data as $row) {
            if ($row['field_name'] === $this->testFieldName) {
                self::assertSame('no_step', $row['value']);
                self::assertNull($row['step_id']);
            }
        }
    }

    public function testSaveValidatorDataStoresEmailAndToken(): void
    {
        $this->fields->saveValidatorData(
            $this->testSubmissionId,
            $this->testFieldName,
            'email_value',
            'validator',
            $this->testStepId,
            null,
            'validator@test.com',
            'token-abc-123'
        );

        $data = $this->fields->getValidatorData($this->testSubmissionId);
        foreach ($data as $row) {
            if ($row['field_name'] === $this->testFieldName) {
                self::assertSame('validator@test.com', $row['filled_by_email']);
                self::assertSame('token-abc-123', $row['token_id']);
            }
        }
    }

    // ── deleteValidatorData ────────────────────────────────────

    public function testDeleteValidatorDataRemovesRecord(): void
    {
        // Insert first
        $this->fields->saveValidatorData(
            $this->testSubmissionId,
            $this->testFieldName,
            'to_delete',
            'validator'
        );

        // Verify it exists
        $data = $this->fields->getValidatorData($this->testSubmissionId);
        $foundBefore = false;
        foreach ($data as $row) {
            if ($row['field_name'] === $this->testFieldName) {
                $foundBefore = true;
            }
        }
        self::assertTrue($foundBefore, 'Record should exist before deletion');

        // Delete
        $this->fields->deleteValidatorData($this->testSubmissionId, $this->testFieldName);

        // Verify it's gone
        $data = $this->fields->getValidatorData($this->testSubmissionId);
        $foundAfter = false;
        foreach ($data as $row) {
            if ($row['field_name'] === $this->testFieldName) {
                $foundAfter = true;
            }
        }
        self::assertFalse($foundAfter, 'Record should be deleted');
    }

    public function testDeleteValidatorDataDoesNothingForNonexistentRecord(): void
    {
        // Should not throw
        $this->fields->deleteValidatorData('nonexistent-submission', 'nonexistent-field');
        self::assertTrue(true, 'Deleting nonexistent record should not throw');
    }

}
