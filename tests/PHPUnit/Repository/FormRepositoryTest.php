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

    public function testCreateAndReadBackRoundTrip(): void
    {
        $id = \generate_uuid();
        $slug = 'test-form-' . substr($id, 0, 8);

        $data = [
            'label' => 'Test Form',
            'slug' => $slug,
            'description' => 'A test form',
            'actif' => true,
        ];

        $createdId = $this->repo->create($data);
        $this->assertNotEmpty($createdId);

        $fetched = $this->repo->findById($createdId);
        $this->assertNotNull($fetched);
        $this->assertSame($createdId, $fetched['id']);
        $this->assertSame('Test Form', $fetched['label']);
        $this->assertSame($slug, $fetched['slug']);
        $this->assertSame('A test form', $fetched['description']);

        $bySlug = $this->repo->findBySlug($slug);
        $this->assertNotNull($bySlug);
        $this->assertSame($createdId, $bySlug['id']);

        $deleted = $this->repo->delete($createdId);
        $this->assertTrue($deleted);
        $this->assertNull($this->repo->findById($createdId));
    }
}
