<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Forms\FieldService;
use App\Core\Database;

final class FieldServiceTest extends TestCase
{
    private FieldService $fields;
    private Database $db;

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(\App\Core\Database::class);
        $this->fields = new FieldService($this->db);
    }

    public function testGetFieldsReturnsArray(): void
    {
        // Get a form ID from the test database
        $pdo = $this->db->getPdo();
        $formId = $pdo->query("SELECT id FROM forms LIMIT 1")->fetchColumn();

        if ($formId) {
            $fields = $this->fields->getFields($formId);
            $this->assertIsArray($fields);
        }
    }

    public function testGetFieldsWithFilledByFilter(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $pdo->query("SELECT id FROM forms LIMIT 1")->fetchColumn();

        if ($formId) {
            $fields = $this->fields->getFields($formId, 'demandeur');
            $this->assertIsArray($fields);
        }
    }

    public function testGetValidatorFieldsReturnsArray(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $pdo->query("SELECT id FROM forms LIMIT 1")->fetchColumn();

        if ($formId) {
            $fields = $this->fields->getValidatorFields($formId);
            $this->assertIsArray($fields);
        }
    }

    public function testGetValidatorDataReturnsArray(): void
    {
        $pdo = $this->db->getPdo();
        $submissionId = $pdo->query("SELECT id FROM submissions LIMIT 1")->fetchColumn();

        if ($submissionId) {
            $data = $this->fields->getValidatorData($submissionId);
            $this->assertIsArray($data);
        }
    }

    public function testGetValidatorStatusBatchReturnsArray(): void
    {
        $result = $this->fields->getValidatorStatusBatch([]);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetValidatorStatusBatchWithIds(): void
    {
        $pdo = $this->db->getPdo();
        $submissionId = $pdo->query("SELECT id FROM submissions LIMIT 1")->fetchColumn();

        if ($submissionId) {
            $result = $this->fields->getValidatorStatusBatch([$submissionId]);
            $this->assertIsArray($result);
            $this->assertArrayHasKey($submissionId, $result);
        }
    }
}
