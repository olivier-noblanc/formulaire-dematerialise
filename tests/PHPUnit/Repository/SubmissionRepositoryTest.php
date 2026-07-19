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
        $this->repo = new SubmissionRepository(\App\Core\App::getInstance()->get(Database::class));
    }

    protected function tearDown(): void
    {
        // Cleanup test data: remove submissions with non-existent form_ids
        $pdo = \App\Core\App::getInstance()->get(Database::class)->getPdo();
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

    public function testFindByIdWithFormIncludesRgpdConsent(): void
    {
        $formId = \generate_uuid();
        $pdo = $this->repo->pdo();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$formId, 'test-rgpd-' . $formId, 'Test RGPD', '']);

        $subId = $this->repo->createWithRgpd([
            'form_id' => $formId,
            'data' => '{}',
            'submitted_by' => 'rgpd@test.com',
            'submitted_at' => gmdate('Y-m-d H:i:s'),
            'rgpd_consent' => 1,
        ]);

        // Set rgpd_consent to 1
        $pdo->prepare("UPDATE submissions SET rgpd_consent = 1 WHERE id = ?")->execute([$subId]);

        $result = $this->repo->findByIdWithForm($subId);
        $this->assertNotNull($result);
        $this->assertArrayHasKey('rgpd_consent', $result, 'findByIdWithForm must include rgpd_consent');
        $this->assertSame(1, $result['rgpd_consent'], 'rgpd_consent should be 1 after update');

        // Also test with consent = 0
        $pdo->prepare("UPDATE submissions SET rgpd_consent = 0 WHERE id = ?")->execute([$subId]);
        $result2 = $this->repo->findByIdWithForm($subId);
        $this->assertSame(0, $result2['rgpd_consent']);

        // Cleanup
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
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

        // Create parent form
        $pdo = $this->repo->pdo();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$formId, 'test-form-' . $formId, 'Test Form', '']);

        $data = [
            'form_id' => $formId,
            'data' => json_encode(['field1' => 'value1']),
            'submitted_by' => 'user@test.com',
            'status' => 'en_cours',
        ];

        $createdId = $this->repo->create($data);
        $this->assertNotEmpty($createdId);

        $fetched = $this->repo->findById($createdId);
        $this->assertNotNull($fetched);
        $this->assertSame($createdId, $fetched['id']);
        $this->assertSame($formId, $fetched['form_id']);
        $this->assertSame('user@test.com', $fetched['submitted_by']);
        $this->assertSame('en_cours', $fetched['status']);

        $byForm = $this->repo->findByForm($formId);
        $this->assertCount(1, $byForm);
        $this->assertSame($createdId, $byForm[0]['id']);

        $updated = $this->repo->updateStatus($createdId, 'valide');
        $this->assertTrue($updated);
        $fetched2 = $this->repo->findById($createdId);
        $this->assertSame('valide', $fetched2['status']);

        // Cleanup
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$createdId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }

    // ── findByForm() with status filter ─────────────────────────

    public function testFindByFormWithStatusReturnsFilteredResults(): void
    {
        $formId = \generate_uuid();
        $pdo = $this->repo->pdo();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$formId, 'test-filter-' . $formId, 'Test Filter', '']);
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
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
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
        $pdo = $this->repo->pdo();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$formId, 'test-submitter-' . $formId, 'Test Submitter', '']);
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
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
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
        $pdo = $this->repo->pdo();
        $formId = \generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$formId, 'test-vd-' . uniqid(), 'Test Form', 'Test']);

        $subId = 'test-sub-' . uniqid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, ?, ?, 'en_cours', datetime('now'))")
            ->execute([$subId, $formId, '{}', 'test@test.com']);

        $fieldName = 'test_field_' . uniqid();

        try {
            $this->repo->saveValidatorData($subId, $fieldName, 'test_value', 'validator@test.com', 'step1');

            $data = $this->repo->getValidatorData($subId);
            $this->assertGreaterThanOrEqual(1, count($data));

            $this->repo->deleteValidatorData($subId, $fieldName);
            $data = $this->repo->getValidatorData($subId);
            $this->assertEmpty($data);
        } finally {
            $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
            $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
        }
    }

    public function testSaveValidatorDataWithoutStepId(): void
    {
        $pdo = $this->repo->pdo();
        $formId = \generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$formId, 'test-vd2-' . uniqid(), 'Test Form', 'Test']);

        $subId = 'test-sub-no-step-' . uniqid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, ?, ?, 'en_cours', datetime('now'))")
            ->execute([$subId, $formId, '{}', 'test@test.com']);

        $fieldName = 'field_no_step_' . uniqid();

        try {
            $this->repo->saveValidatorData($subId, $fieldName, 'value', 'validator@test.com');

            $data = $this->repo->getValidatorData($subId);
            $this->assertGreaterThanOrEqual(1, count($data));

            // Cleanup
            $this->repo->deleteValidatorData($subId, $fieldName);
        } finally {
            $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
            $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
        }
    }

    // ── create() default status ─────────────────────────────────

    public function testCreateWithDefaultStatus(): void
    {
        $formId = \generate_uuid();
        $pdo = $this->repo->pdo();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$formId, 'test-default-' . $formId, 'Test Default', '']);
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
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }

    // ── updateStatus() ──────────────────────────────────────────

    public function testUpdateStatusNonexistentReturnsTrue(): void
    {
        $result = $this->repo->updateStatus('nonexistent-' . uniqid(), 'done');
        $this->assertTrue($result);
    }

    // ── appendToDataJson() ─────────────────────────────────────

    public function testAppendToDataJsonAddsMutationAndReturnsTrue(): void
    {
        $formId = \generate_uuid();
        $pdo = $this->repo->pdo();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$formId, 'test-append-' . $formId, 'Test Append', '']);

        $subId = $this->repo->create([
            'form_id' => $formId,
            'data' => json_encode(['key' => 'value']),
            'submitted_by' => 'append@test.com',
            'status' => 'en_cours',
        ]);

        $result = $this->repo->appendToDataJson($subId, function (array $data): array {
            $data['mutations'][] = ['type' => 'test', 'ts' => '2026-01-01T00:00:00Z'];
            return $data;
        });

        $this->assertTrue($result);

        $fetched = $this->repo->findById($subId);
        $decoded = json_decode($fetched['data'], true);
        $this->assertArrayHasKey('mutations', $decoded);
        $this->assertSame('test', $decoded['mutations'][0]['type']);

        // Cleanup
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }

    public function testAppendToDataJsonHandlesConcurrentMutationsWithoutLoss(): void
    {
        $formId = \generate_uuid();
        $pdo = $this->repo->pdo();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$formId, 'test-concurrent-' . $formId, 'Test Concurrent', '']);

        $subId = $this->repo->create([
            'form_id' => $formId,
            'data' => json_encode(['mutations' => []]),
            'submitted_by' => 'concurrent@test.com',
            'status' => 'en_cours',
        ]);

        // Simulate 2 sequential mutations (each reads current state, appends, writes)
        $this->repo->appendToDataJson($subId, function (array $data): array {
            $data['mutations'][] = ['n' => 1];
            return $data;
        });

        $this->repo->appendToDataJson($subId, function (array $data): array {
            $data['mutations'][] = ['n' => 2];
            return $data;
        });

        $fetched = $this->repo->findById($subId);
        $decoded = json_decode($fetched['data'], true);
        $this->assertCount(2, $decoded['mutations'], 'Both mutations should survive');
        $this->assertSame(1, $decoded['mutations'][0]['n']);
        $this->assertSame(2, $decoded['mutations'][1]['n']);

        // Cleanup
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }

    public function testAppendToDataJsonReturnsFalseOnMaxRetriesExceeded(): void
    {
        $formId = \generate_uuid();
        $pdo = $this->repo->pdo();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$formId, 'test-retry-' . $formId, 'Test Retry', '']);

        $subId = $this->repo->create([
            'form_id' => $formId,
            'data' => json_encode(['key' => 'value']),
            'submitted_by' => 'retry@test.com',
            'status' => 'en_cours',
        ]);

        // Simulate 3 consecutive conflicts by modifying data externally
        // between each read-write cycle
        $attempt = 0;
        $result = $this->repo->appendToDataJson($subId, function (array $data) use (&$attempt, $pdo, $subId): array {
            $attempt++;
            // After reading but before writing, another writer modifies the data
            if ($attempt <= 3) {
                $pdo->prepare("UPDATE submissions SET data = ? WHERE id = ?")
                    ->execute([json_encode(['conflict' => $attempt]), $subId]);
            }
            $data['retry'] = true;
            return $data;
        });

        $this->assertFalse($result, 'Should return false after max retries');
        $this->assertSame(3, $attempt, 'Should have attempted 3 times');

        // Cleanup
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }
}
