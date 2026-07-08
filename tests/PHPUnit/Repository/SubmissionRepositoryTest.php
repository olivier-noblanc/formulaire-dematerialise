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
}
