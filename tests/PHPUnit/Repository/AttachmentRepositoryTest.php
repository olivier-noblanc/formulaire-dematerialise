<?php
declare(strict_types=1);

namespace App\Tests\Repository;

use PHPUnit\Framework\TestCase;
use App\Repository\AttachmentRepository;
use App\Core\Database;

final class AttachmentRepositoryTest extends TestCase
{
    private AttachmentRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new AttachmentRepository(new Database());
    }

    public function testFindByIdReturnsNullForNonexistent(): void
    {
        $result = $this->repo->findById('nonexistent');
        $this->assertNull($result);
    }

    public function testFindBySubmissionReturnsArray(): void
    {
        $result = $this->repo->findBySubmission('nonexistent');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testCreateAndReadBackRoundTrip(): void
    {
        $submissionId = \generate_uuid();

        $data = [
            'submission_id' => $submissionId,
            'field_name' => 'document',
            'original_name' => 'test-file.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'file_data' => 'fake-file-content',
        ];

        $id = $this->repo->create($data);
        $this->assertNotEmpty($id);

        $fetched = $this->repo->findById($id);
        $this->assertNotNull($fetched);
        $this->assertSame($id, $fetched['id']);
        $this->assertSame($submissionId, $fetched['submission_id']);
        $this->assertSame('document', $fetched['field_name']);
        $this->assertSame('test-file.pdf', $fetched['original_name']);
        $this->assertSame('test-file.pdf', $fetched['stored_name']);
        $this->assertSame('application/pdf', $fetched['mime_type']);
        $this->assertSame(1024, (int)$fetched['file_size']);
        $this->assertSame('fake-file-content', $fetched['file_data']);
        $this->assertNotEmpty($fetched['uploaded_at']);

        $bySubmission = $this->repo->findBySubmission($submissionId);
        $this->assertCount(1, $bySubmission);
        $this->assertSame($id, $bySubmission[0]['id']);

        $deleted = $this->repo->delete($id);
        $this->assertTrue($deleted);
        $this->assertNull($this->repo->findById($id));
    }
}
