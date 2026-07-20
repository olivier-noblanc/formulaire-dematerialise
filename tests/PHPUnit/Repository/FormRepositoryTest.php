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
        $this->repo = new FormRepository(\App\Core\App::getInstance()->get(Database::class));
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

    // ── findAll() with activeOnly ───────────────────────────────

    public function testFindAllActiveOnlyReturnsArray(): void
    {
        $result = $this->repo->findAll(true);
        $this->assertIsArray($result);
    }

    public function testFindAllActiveOnlyReturnsOnlyActiveForms(): void
    {
        $result = $this->repo->findAll(true);
        foreach ($result as $form) {
            $this->assertSame(1, (int)$form['actif']);
        }
    }

    // ── getSteps() ──────────────────────────────────────────────

    public function testGetStepsReturnsArray(): void
    {
        $result = $this->repo->getSteps('nonexistent');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
