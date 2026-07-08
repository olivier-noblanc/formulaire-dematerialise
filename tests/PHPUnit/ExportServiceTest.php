<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Export\ExportService;
use App\Core\Database;
use App\Auth\AuthService;

final class ExportServiceTest extends TestCase
{
    private Database $db;
    private AuthService $auth;

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(Database::class);
        $this->auth = \App\Core\App::getInstance()->get(AuthService::class);
    }

    public function testServiceCanBeInstantiated(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $this->assertInstanceOf(ExportService::class, $service);
    }

    public function testServiceIsRegisteredInContainer(): void
    {
        $app = \App\Core\App::getInstance();
        $this->assertTrue($app->has(ExportService::class));
        $service = $app->get(ExportService::class);
        $this->assertInstanceOf(ExportService::class, $service);
    }

    public function testAppExportReturnsExportService(): void
    {
        $service = \App\Core\App::export();
        $this->assertInstanceOf(ExportService::class, $service);
    }

    public function testExportCsvMethodExists(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $this->assertTrue(method_exists($service, 'exportCsv'));
    }

    public function testExportCsvMethodIsPublic(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'exportCsv');
        $this->assertTrue($reflection->isPublic());
    }

    public function testExportCsvAcceptsOptionalOptions(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'exportCsv');
        $params = $reflection->getParameters();
        $this->assertCount(1, $params);
        $this->assertTrue($params[0]->isOptional());
    }
}
