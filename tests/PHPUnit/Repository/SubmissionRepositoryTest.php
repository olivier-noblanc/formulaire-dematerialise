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
        self::assertNotNull($result);
        self::assertArrayHasKey('rgpd_consent', $result, 'findByIdWithForm must include rgpd_consent');
        self::assertSame(1, $result['rgpd_consent'], 'rgpd_consent should be 1 after update');

        // Also test with consent = 0
        $pdo->prepare("UPDATE submissions SET rgpd_consent = 0 WHERE id = ?")->execute([$subId]);
        $result2 = $this->repo->findByIdWithForm($subId);
        self::assertSame(0, $result2['rgpd_consent']);

        // Cleanup
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }

    public function testGetValidatorDataReturnsArray(): void
    {
        $result = $this->repo->getValidatorData('nonexistent');
        self::assertIsArray($result);
        self::assertEmpty($result);
    }

    // ── getValidatorData() with stepId ──────────────────────────

    public function testGetValidatorDataWithStepIdReturnsArray(): void
    {
        $result = $this->repo->getValidatorData('nonexistent', 'step1');
        self::assertIsArray($result);
        self::assertEmpty($result);
    }

    // ── appendToDataJson() ─────────────────────────────────────

    public function testAppendToDataJsonAddsMutationAndReturnsTrue(): void
    {
        $formId = \generate_uuid();
        $pdo = $this->repo->pdo();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$formId, 'test-append-' . $formId, 'Test Append', '']);

        $subId = $this->repo->createWithRgpd([
            'form_id' => $formId,
            'data' => json_encode(['key' => 'value']),
            'submitted_by' => 'append@test.com',
            'submitted_at' => gmdate('Y-m-d H:i:s'),
            'rgpd_consent' => 1,
        ]);

        $result = $this->repo->appendToDataJson($subId, function (array $data): array {
            $data['mutations'][] = ['type' => 'test', 'ts' => '2026-01-01T00:00:00Z'];
            return $data;
        });

        self::assertTrue($result);

        $fetched = $pdo->query("SELECT data FROM submissions WHERE id = '{$subId}'")->fetch(\PDO::FETCH_ASSOC);
        $decoded = json_decode($fetched['data'], true);
        self::assertArrayHasKey('mutations', $decoded);
        self::assertSame('test', $decoded['mutations'][0]['type']);

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

        $subId = $this->repo->createWithRgpd([
            'form_id' => $formId,
            'data' => json_encode(['mutations' => []]),
            'submitted_by' => 'concurrent@test.com',
            'submitted_at' => gmdate('Y-m-d H:i:s'),
            'rgpd_consent' => 1,
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

        $fetched = $pdo->query("SELECT data FROM submissions WHERE id = '{$subId}'")->fetch(\PDO::FETCH_ASSOC);
        $decoded = json_decode($fetched['data'], true);
        self::assertCount(2, $decoded['mutations'], 'Both mutations should survive');
        self::assertSame(1, $decoded['mutations'][0]['n']);
        self::assertSame(2, $decoded['mutations'][1]['n']);

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

        $subId = $this->repo->createWithRgpd([
            'form_id' => $formId,
            'data' => json_encode(['key' => 'value']),
            'submitted_by' => 'retry@test.com',
            'submitted_at' => gmdate('Y-m-d H:i:s'),
            'rgpd_consent' => 1,
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

        self::assertFalse($result, 'Should return false after max retries');
        self::assertSame(3, $attempt, 'Should have attempted 3 times');

        // Cleanup
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }
}
