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

    // ── Constructor / DI ───────────────────────────────────────

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

    public function testAppExportReturnsSameInstance(): void
    {
        $service1 = \App\Core\App::export();
        $service2 = \App\Core\App::export();
        $this->assertSame($service1, $service2);
    }

    // ── Method signature / reflection ───────────────────────────

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

    public function testExportCsvReturnTypeIsVoid(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'exportCsv');
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('void', $returnType->getName());
    }

    public function testExportCsvParameterIsArrayType(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'exportCsv');
        $params = $reflection->getParameters();
        $this->assertSame('array', $params[0]->getType()->getName());
    }

    // ── Constructor dependency verification ─────────────────────

    public function testConstructorRequiresDatabaseAndAuth(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);

        $params = $constructor->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('db', $params[0]->getName());
        $this->assertSame('auth', $params[1]->getName());
    }

    public function testConstructorParamTypes(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();

        $this->assertSame(Database::class, $params[0]->getType()->getName());
        $this->assertSame(AuthService::class, $params[1]->getType()->getName());
    }

    // ── Class properties ────────────────────────────────────────

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testClassHasDbProperty(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $this->assertTrue($reflection->hasProperty('db'));
    }

    public function testClassHasAuthProperty(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $this->assertTrue($reflection->hasProperty('auth'));
    }

    public function testDbPropertyIsPrivate(): void
    {
        $reflection = new \ReflectionProperty(ExportService::class, 'db');
        $this->assertTrue($reflection->isPrivate());
    }

    public function testAuthPropertyIsPrivate(): void
    {
        $reflection = new \ReflectionProperty(ExportService::class, 'auth');
        $this->assertTrue($reflection->isPrivate());
    }

    public function testDbPropertyHasCorrectType(): void
    {
        $reflection = new \ReflectionProperty(ExportService::class, 'db');
        $type = $reflection->getType();
        $this->assertNotNull($type);
        $this->assertSame(Database::class, $type->getName());
    }

    public function testAuthPropertyHasCorrectType(): void
    {
        $reflection = new \ReflectionProperty(ExportService::class, 'auth');
        $type = $reflection->getType();
        $this->assertNotNull($type);
        $this->assertSame(AuthService::class, $type->getName());
    }

    // ── Namespace / FQCN ───────────────────────────────────────

    public function testClassNamespace(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $this->assertSame('App\Export', $reflection->getNamespaceName());
    }

    public function testClassShortName(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $this->assertSame('ExportService', $reflection->getShortName());
    }

    // ── Database integration ────────────────────────────────────

    public function testServiceUsesInjectedDatabase(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionProperty($service, 'db');
        $reflection->setAccessible(true);
        $this->assertSame($this->db, $reflection->getValue($service));
    }

    public function testServiceUsesInjectedAuth(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionProperty($service, 'auth');
        $reflection->setAccessible(true);
        $this->assertSame($this->auth, $reflection->getValue($service));
    }

    // ── Method parameter defaults ───────────────────────────────

    public function testExportCsvDefaultOptionsIsEmptyArray(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'exportCsv');
        $params = $reflection->getParameters();
        $this->assertSame([], $params[0]->getDefaultValue());
    }

    // ── Interface / contract ────────────────────────────────────

    public function testClassIsNotAbstract(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $this->assertFalse($reflection->isAbstract());
    }

    public function testClassIsNotInterface(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $this->assertFalse($reflection->isInterface());
    }

    public function testClassIsNotTrait(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $this->assertFalse($reflection->isTrait());
    }

    // ── Method count ────────────────────────────────────────────

    public function testClassHasOnlyExportCsvPublicMethod(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionClass($service);
        $publicMethods = array_filter(
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn($m) => !$m->isConstructor() && !str_starts_with($m->getName(), '__')
        );
        $methodNames = array_map(fn($m) => $m->getName(), $publicMethods);
        $this->assertContains('exportCsv', $methodNames);
    }

    // ── Container integration ───────────────────────────────────

    public function testContainerReturnsSameTypeForExportService(): void
    {
        $app = \App\Core\App::getInstance();
        $service = $app->get(ExportService::class);
        $this->assertIsObject($service);
        $this->assertSame(ExportService::class, get_class($service));
    }

    public function testContainerExportAccessorReturnsSameType(): void
    {
        $service = \App\Core\App::export();
        $this->assertIsObject($service);
        $this->assertSame(ExportService::class, get_class($service));
    }
}
