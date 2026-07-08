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
}
