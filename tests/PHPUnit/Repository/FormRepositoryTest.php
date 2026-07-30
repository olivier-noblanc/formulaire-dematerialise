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
        self::assertNull($result);
    }

    public function testFindAllReturnsArray(): void
    {
        $result = $this->repo->findAll();
        self::assertIsArray($result);
    }

    public function testGetFieldsReturnsArray(): void
    {
        $result = $this->repo->getFields('nonexistent');
        self::assertIsArray($result);
        self::assertEmpty($result);
    }

    // ── findAll() with activeOnly ───────────────────────────────

    public function testFindAllActiveOnlyReturnsArray(): void
    {
        $result = $this->repo->findAll(true);
        self::assertIsArray($result);
    }

    public function testFindAllActiveOnlyReturnsOnlyActiveForms(): void
    {
        $result = $this->repo->findAll(true);
        foreach ($result as $form) {
            self::assertSame(1, (int)$form['actif']);
        }
    }

    // ── getSteps() ──────────────────────────────────────────────

    public function testGetStepsReturnsArray(): void
    {
        $result = $this->repo->getSteps('nonexistent');
        self::assertIsArray($result);
        self::assertEmpty($result);
    }
}
