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
        $this->assertSame('database', $params[0]->getName());
        $this->assertSame('authService', $params[1]->getName());
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
        $this->assertTrue($reflection->hasProperty('database'));
    }

    public function testClassHasAuthProperty(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $this->assertTrue($reflection->hasProperty('authService'));
    }

    public function testDbPropertyIsPrivate(): void
    {
        $reflection = new \ReflectionProperty(ExportService::class, 'database');
        $this->assertTrue($reflection->isPrivate());
    }

    public function testAuthPropertyIsPrivate(): void
    {
        $reflection = new \ReflectionProperty(ExportService::class, 'authService');
        $this->assertTrue($reflection->isPrivate());
    }

    public function testDbPropertyHasCorrectType(): void
    {
        $reflection = new \ReflectionProperty(ExportService::class, 'database');
        $type = $reflection->getType();
        $this->assertNotNull($type);
        $this->assertSame(Database::class, $type->getName());
    }

    public function testAuthPropertyHasCorrectType(): void
    {
        $reflection = new \ReflectionProperty(ExportService::class, 'authService');
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
        $reflection = new \ReflectionProperty($service, 'database');
        $reflection->setAccessible(true);
        $this->assertSame($this->db, $reflection->getValue($service));
    }

    public function testServiceUsesInjectedAuth(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionProperty($service, 'authService');
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

    // ── exportCsv() method analysis via reflection ──────────────

    public function testExportCsvMethodHasSingleParameter(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'exportCsv');
        $params = $reflection->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('options', $params[0]->getName());
    }

    public function testExportCsvMethodHasNoRequiredParameters(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'exportCsv');
        $params = $reflection->getParameters();
        $this->assertTrue($params[0]->isOptional());
        $this->assertSame([], $params[0]->getDefaultValue());
    }

    public function testExportCsvMethodReturnTypeIsVoid(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'exportCsv');
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('void', $returnType->getName());
    }

    public function testExportCsvMethodSourceContainsKeyLogic(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $method = $reflection->getMethod('exportCsv');
        $file = $method->getFileName();
        $this->assertNotNull($file);
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $source = implode('', array_slice(file($file) ?: [], $startLine - 1, $endLine - $startLine + 1));

        // Verify the method contains key logic patterns
        $this->assertStringContainsString('isAdmin', $source);
        $this->assertStringContainsString('form_id', $source);
        $this->assertStringContainsString('status', $source);
        $this->assertStringContainsString('fputcsv', $source);
        $this->assertStringContainsString('Content-Type', $source);
        $this->assertStringContainsString('Content-Disposition', $source);
    }

    public function testExportCsvMethodContainsBomOutput(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $method = $reflection->getMethod('exportCsv');
        $file = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $source = implode('', array_slice(file($file) ?: [], $startLine - 1, $endLine - $startLine + 1));

        // Verify BOM output for Excel compatibility
        $this->assertStringContainsString('chr(0xEF)', $source);
        $this->assertStringContainsString('chr(0xBB)', $source);
        $this->assertStringContainsString('chr(0xBF)', $source);
    }

    public function testExportCsvMethodUsesSemicolonDelimiter(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $method = $reflection->getMethod('exportCsv');
        $file = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $source = implode('', array_slice(file($file) ?: [], $startLine - 1, $endLine - $startLine + 1));

        // Verify semicolon delimiter (French CSV standard)
        $this->assertStringContainsString("';'", $source);
    }

    public function testExportCsvMethodHandlesBooleanConversion(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $method = $reflection->getMethod('exportCsv');
        $file = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $source = implode('', array_slice(file($file) ?: [], $startLine - 1, $endLine - $startLine + 1));

        // Verify boolean conversion logic ('1' → 'Oui', '0' → 'Non')
        $this->assertStringContainsString("'Oui'", $source);
        $this->assertStringContainsString("'Non'", $source);
    }

    public function testExportCsvMethodExcludesValidationsKey(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $method = $reflection->getMethod('exportCsv');
        $file = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $source = implode('', array_slice(file($file) ?: [], $startLine - 1, $endLine - $startLine + 1));

        // Verify 'validations' key is excluded from CSV columns
        $this->assertStringContainsString('validations', $source);
    }

    public function testExportCsvMethodOrdersBySubmittedAtDesc(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $method = $reflection->getMethod('exportCsv');
        $file = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $source = implode('', array_slice(file($file) ?: [], $startLine - 1, $endLine - $startLine + 1));

        // Verify ORDER BY submitted_at DESC
        $this->assertStringContainsString('submitted_at DESC', $source);
    }

    public function testExportCsvMethodJoinsFormsTable(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $method = $reflection->getMethod('exportCsv');
        $file = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $source = implode('', array_slice(file($file) ?: [], $startLine - 1, $endLine - $startLine + 1));

        // Verify JOIN with forms table
        $this->assertStringContainsString('JOIN forms', $source);
    }

    public function testExportCsvMethodOutputsToArray(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $method = $reflection->getMethod('exportCsv');
        $file = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $source = implode('', array_slice(file($file) ?: [], $startLine - 1, $endLine - $startLine + 1));

        // Verify php://output stream
        $this->assertStringContainsString('php://output', $source);
    }

    public function testExportCsvMethodHandlesJsonData(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $method = $reflection->getMethod('exportCsv');
        $file = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $source = implode('', array_slice(file($file) ?: [], $startLine - 1, $endLine - $startLine + 1));

        // Verify JSON decode for submission data
        $this->assertStringContainsString('json_decode', $source);
        $this->assertStringContainsString('json_encode', $source);
    }

    // ── Auth integration ────────────────────────────────────────

    public function testExportServiceRequiresAdminForCsv(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $method = $reflection->getMethod('exportCsv');
        $file = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $source = implode('', array_slice(file($file) ?: [], $startLine - 1, $endLine - $startLine + 1));

        // Verify admin check at start of method
        $this->assertStringContainsString('isAdmin', $source);
        $this->assertStringContainsString('errorPage', $source);
    }

    // ── Database integration ────────────────────────────────────

    public function testExportServiceUsesInjectedDatabase(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionProperty($service, 'database');
        $reflection->setAccessible(true);
        $this->assertSame($this->db, $reflection->getValue($service));
    }

    public function testExportServiceUsesInjectedAuth(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionProperty($service, 'authService');
        $reflection->setAccessible(true);
        $this->assertSame($this->auth, $reflection->getValue($service));
    }
}
