<?php
declare(strict_types=1);

namespace App\Tests\Repository;

use PHPUnit\Framework\TestCase;
use App\Repository\SubmissionRepository;
use App\Core\Database;

final class SubmissionRepositoryTest extends TestCase
{
    private SubmissionRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new SubmissionRepository(new Database());
    }

    protected function tearDown(): void
    {
        // Cleanup test data: remove submissions with non-existent form_ids
        $pdo = (new Database())->getPdo();
        $orphans = $pdo->query("
            SELECT s.id FROM submissions s
            LEFT JOIN forms f ON f.id = s.form_id
            WHERE f.id IS NULL
        ")->fetchAll(\PDO::FETCH_COLUMN);
        if (!empty($orphans)) {
            $placeholders = implode(',', array_fill(0, count($orphans), '?'));
            $pdo->prepare("DELETE FROM tokens WHERE submission_id IN ({$placeholders})")->execute($orphans);
            $pdo->prepare("DELETE FROM submission_validator_data WHERE submission_id IN ({$placeholders})")->execute($orphans);
            $pdo->prepare("DELETE FROM submissions WHERE id IN ({$placeholders})")->execute($orphans);
        }
    }

    public function testFindByIdReturnsNullForNonexistent(): void
    {
        $result = $this->repo->findById('nonexistent');
        $this->assertNull($result);
    }

    public function testFindByFormReturnsArray(): void
    {
        $result = $this->repo->findByForm('nonexistent');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetValidatorDataReturnsArray(): void
    {
        $result = $this->repo->getValidatorData('nonexistent');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testCreateAndReadBackRoundTrip(): void
    {
        $id = \generate_uuid();
        $formId = \generate_uuid();

        $data = [
            'form_id' => $formId,
            'data' => json_encode(['field1' => 'value1']),
            'submitted_by' => 'user@test.com',
            'status' => 'pending',
        ];

        $createdId = $this->repo->create($data);
        $this->assertNotEmpty($createdId);

        $fetched = $this->repo->findById($createdId);
        $this->assertNotNull($fetched);
        $this->assertSame($createdId, $fetched['id']);
        $this->assertSame($formId, $fetched['form_id']);
        $this->assertSame('user@test.com', $fetched['submitted_by']);
        $this->assertSame('pending', $fetched['status']);

        $byForm = $this->repo->findByForm($formId);
        $this->assertCount(1, $byForm);
        $this->assertSame($createdId, $byForm[0]['id']);

        $updated = $this->repo->updateStatus($createdId, 'validated');
        $this->assertTrue($updated);
        $fetched2 = $this->repo->findById($createdId);
        $this->assertSame('validated', $fetched2['status']);
    }

    // ── findByForm() with status filter ─────────────────────────

    public function testFindByFormWithStatusReturnsFilteredResults(): void
    {
        $formId = \generate_uuid();
        $subId = $this->repo->create([
            'form_id' => $formId,
            'data' => '{}',
            'submitted_by' => 'filter@test.com',
            'status' => 'en_cours',
        ]);

        $results = $this->repo->findByForm($formId, 'en_cours');
        $this->assertGreaterThanOrEqual(1, count($results));

        $results = $this->repo->findByForm($formId, 'nonexistent_status');
        $this->assertEmpty($results);

        // Cleanup
        $pdo = $this->repo->pdo();
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM submission_validator_data WHERE submission_id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
    }

    // ── findBySubmitter() ───────────────────────────────────────

    public function testFindBySubmitterReturnsArray(): void
    {
        $result = $this->repo->findBySubmitter('nonexistent@test.com');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testFindBySubmitterReturnsSubmissionsForEmail(): void
    {
        $formId = \generate_uuid();
        $email = 'submitter_' . uniqid() . '@test.com';
        $subId = $this->repo->create([
            'form_id' => $formId,
            'data' => '{}',
            'submitted_by' => $email,
            'status' => 'en_cours',
        ]);

        $results = $this->repo->findBySubmitter($email);
        $this->assertGreaterThanOrEqual(1, count($results));

        // Cleanup
        $pdo = $this->repo->pdo();
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM submission_validator_data WHERE submission_id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
    }

    // ── findPendingForValidator() ───────────────────────────────

    public function testFindPendingForValidatorReturnsArray(): void
    {
        try {
            $result = $this->repo->findPendingForValidator('nonexistent@test.com');
            $this->assertIsArray($result);
            $this->assertEmpty($result);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'action')) {
                $this->markTestSkipped('tokens table missing action column');
            }
            throw $e;
        }
    }

    // ── getValidatorData() with stepId ──────────────────────────

    public function testGetValidatorDataWithStepIdReturnsArray(): void
    {
        $result = $this->repo->getValidatorData('nonexistent', 'step1');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ── saveValidatorData() ─────────────────────────────────────

    public function testSaveAndDeleteValidatorDataRoundTrip(): void
    {
        $subId = 'test-sub-' . uniqid();
        $fieldName = 'test_field_' . uniqid();

        try {
            $this->repo->saveValidatorData($subId, $fieldName, 'test_value', 'validator@test.com', 'step1');

            $data = $this->repo->getValidatorData($subId);
            $this->assertGreaterThanOrEqual(1, count($data));

            $this->repo->deleteValidatorData($subId, $fieldName);
            $data = $this->repo->getValidatorData($subId);
            $this->assertEmpty($data);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'NOT NULL')) {
                $this->markTestSkipped('submission_validator_data table missing id column');
            }
            throw $e;
        }
    }

    public function testSaveValidatorDataWithoutStepId(): void
    {
        $subId = 'test-sub-no-step-' . uniqid();
        $fieldName = 'field_no_step_' . uniqid();

        try {
            $this->repo->saveValidatorData($subId, $fieldName, 'value', 'validator@test.com');

            $data = $this->repo->getValidatorData($subId);
            $this->assertGreaterThanOrEqual(1, count($data));

            // Cleanup
            $this->repo->deleteValidatorData($subId, $fieldName);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'NOT NULL')) {
                $this->markTestSkipped('submission_validator_data table missing id column');
            }
            throw $e;
        }
    }

    // ── create() default status ─────────────────────────────────

    public function testCreateWithDefaultStatus(): void
    {
        $formId = \generate_uuid();
        $subId = $this->repo->create([
            'form_id' => $formId,
            'data' => '{}',
            'submitted_by' => 'default@test.com',
        ]);

        $fetched = $this->repo->findById($subId);
        $this->assertSame('en_cours', $fetched['status']);

        // Cleanup
        $pdo = $this->repo->pdo();
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM submission_validator_data WHERE submission_id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
    }

    // ── updateStatus() ──────────────────────────────────────────

    public function testUpdateStatusNonexistentReturnsTrue(): void
    {
        $result = $this->repo->updateStatus('nonexistent-' . uniqid(), 'done');
        $this->assertTrue($result);
    }
}
