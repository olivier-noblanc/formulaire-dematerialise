<?php
declare(strict_types=1);

namespace App\Tests\Repository;

use PHPUnit\Framework\TestCase;
use App\Repository\TokenRepository;
use App\Core\Database;

final class TokenRepositoryTest extends TestCase
{
    private TokenRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new TokenRepository(new Database());
    }

    public function testFindByValueReturnsNullForNonexistent(): void
    {
        $result = $this->repo->findByValue('nonexistent');
        $this->assertNull($result);
    }

    public function testFindBySubmissionReturnsArray(): void
    {
        $result = $this->repo->findBySubmission('nonexistent');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetActiveCountReturnsInt(): void
    {
        $result = $this->repo->getActiveCount('nonexistent');
        $this->assertIsInt($result);
        $this->assertSame(0, $result);
    }

    public function testCreateAndReadBackRoundTrip(): void
    {
        $pdo = $this->repo->pdo();
        $formId = \generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$formId, 'test-token-form-' . $formId, 'Test Token Form', '']);
        $submissionId = \generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status) VALUES (?, ?, '{}', 'test@test.com', 'en_cours')")
            ->execute([$submissionId, $formId]);
        $stepId = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, 1, 1)")
            ->execute([$stepId, $formId, 'Test Step']);

        $tokenValue = 'tok-' . \generate_uuid();

        $data = [
            'submission_id' => $submissionId,
            'step_id' => $stepId,
            'email' => 'validator@test.com',
            'token' => $tokenValue,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+1 day')),
        ];

        $createdId = $this->repo->create($data);
        $this->assertNotEmpty($createdId);

        $fetched = $this->repo->findById($createdId);
        $this->assertNotNull($fetched);
        $this->assertSame($createdId, $fetched['id']);
        $this->assertSame($submissionId, $fetched['submission_id']);
        $this->assertSame($tokenValue, $fetched['token']);
        $this->assertSame('validator@test.com', $fetched['email']);

        $byValue = $this->repo->findByValue($tokenValue);
        $this->assertNotNull($byValue);
        $this->assertSame($createdId, $byValue['id']);

        $bySubmission = $this->repo->findBySubmission($submissionId);
        $this->assertCount(1, $bySubmission);

        $used = $this->repo->markUsed($createdId);
        $this->assertTrue($used);
        $fetched2 = $this->repo->findById($createdId);
        $this->assertNotNull($fetched2['done_at']);

        $expired = $this->repo->markExpired($createdId);
        $this->assertTrue($expired);

        // Cleanup
        $pdo->prepare("DELETE FROM tokens WHERE id = ?")->execute([$createdId]);
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$stepId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$submissionId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }
}
