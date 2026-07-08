<?php
declare(strict_types=1);

namespace App\Tests\Repository;

use PHPUnit\Framework\TestCase;
use App\Repository\FormRepository;
use App\Core\Database;

final class FormRepositoryTest extends TestCase
{
    private FormRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new FormRepository(new Database());
    }

    public function testFindByIdReturnsNullForNonexistent(): void
    {
        $result = $this->repo->findById('nonexistent');
        $this->assertNull($result);
    }

    public function testFindAllReturnsArray(): void
    {
        $result = $this->repo->findAll();
        $this->assertIsArray($result);
    }

    public function testGetFieldsReturnsArray(): void
    {
        $result = $this->repo->getFields('nonexistent');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
