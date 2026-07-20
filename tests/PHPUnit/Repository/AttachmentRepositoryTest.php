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
        $this->repo = new AttachmentRepository(\App\Core\App::getInstance()->get(Database::class));
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
}
