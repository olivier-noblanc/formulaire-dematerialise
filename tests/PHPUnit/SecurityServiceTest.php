<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Security\SecurityService;
use App\Render\HtmlService;

final class SecurityServiceTest extends TestCase
{
    private SecurityService $service;

    protected function setUp(): void
    {
        $this->service = new SecurityService(new HtmlService());
        // Start a session for CSRF tests
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function testGenerateCsrfTokenReturnsString(): void
    {
        $token = $this->service->generateCsrfToken();
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    public function testGenerateCsrfTokenIsConsistent(): void
    {
        $token1 = $this->service->generateCsrfToken();
        $token2 = $this->service->generateCsrfToken();
        $this->assertSame($token1, $token2);
    }

    public function testCsrfFieldContainsHiddenInput(): void
    {
        $field = $this->service->csrfField();
        $this->assertStringContainsString('type="hidden"', $field);
        $this->assertStringContainsString('name="csrf_token"', $field);
    }

    public function testCsrfFieldContainsToken(): void
    {
        $token = $this->service->generateCsrfToken();
        $field = $this->service->csrfField();
        $this->assertStringContainsString($token, $field);
    }

    public function testCsrfFieldWithPersonaToken(): void
    {
        $_GET['persona_token'] = 'test_token_123';
        $field = $this->service->csrfField();
        $this->assertStringContainsString('name="persona_token"', $field);
        $this->assertStringContainsString('test_token_123', $field);
        unset($_GET['persona_token']);
    }

    public function testCsrfFieldWithoutPersonaToken(): void
    {
        unset($_GET['persona_token']);
        $field = $this->service->csrfField();
        $this->assertStringNotContainsString('persona_token', $field);
    }

    public function testVerifyCsrfReturnsTrueInTestMode(): void
    {
        // In TEST_MODE, CSRF is bypassed
        $this->assertTrue($this->service->verifyCsrf());
    }

    public function testSendSecurityHeadersInCliDoesNothing(): void
    {
        // In CLI mode, sendSecurityHeaders should return early
        // This test verifies no exception is thrown
        $this->service->sendSecurityHeaders();
        $this->assertTrue(true); // No exception = pass
    }

    // ── generateCsrfToken() edge cases ──────────────────────────

    public function testGenerateCsrfTokenCreatesNewWhenEmpty(): void
    {
        unset($_SESSION['csrf_token']);
        $token = $this->service->generateCsrfToken();
        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen($token)); // bin2hex(32 bytes) = 64 chars
    }

    public function testGenerateCsrfTokenReusesExisting(): void
    {
        $_SESSION['csrf_token'] = 'existing_token_value';
        $token = $this->service->generateCsrfToken();
        $this->assertSame('existing_token_value', $token);
    }

    // ── csrfField() without persona token ───────────────────────

    public function testCsrfFieldWithoutPersonaContainsOnlyOneInput(): void
    {
        unset($_GET['persona_token']);
        $field = $this->service->csrfField();
        $this->assertStringContainsString('csrf_token', $field);
        // Count <input occurrences — should be exactly 1
        $count = substr_count($field, '<input');
        $this->assertSame(1, $count);
    }

    public function testCsrfFieldWithPersonaContainsTwoInputs(): void
    {
        $_GET['persona_token'] = 'test123';
        $field = $this->service->csrfField();
        $count = substr_count($field, '<input');
        $this->assertSame(2, $count);
        unset($_GET['persona_token']);
    }

    // ── verifyCsrf() behavior ───────────────────────────────────

    public function testVerifyCsrfReturnsTrueInTestModeAlways(): void
    {
        // In TEST_MODE, CSRF is always bypassed
        $_POST['csrf_token'] = '';
        $_SESSION['csrf_token'] = '';
        $this->assertTrue($this->service->verifyCsrf());
    }

    public function testVerifyCsrfMethodExists(): void
    {
        $this->assertTrue(method_exists($this->service, 'verifyCsrf'));
    }

    public function testRequireCsrfMethodExists(): void
    {
        $this->assertTrue(method_exists($this->service, 'requireCsrf'));
    }

    public function testRequireCsrfIsPublic(): void
    {
        $reflection = new \ReflectionMethod($this->service, 'requireCsrf');
        $this->assertTrue($reflection->isPublic());
    }

    public function testVerifyCsrfIsPublic(): void
    {
        $reflection = new \ReflectionMethod($this->service, 'verifyCsrf');
        $this->assertTrue($reflection->isPublic());
    }

    // ── Constructor / DI ────────────────────────────────────────

    public function testConstructorCreatesInstance(): void
    {
        $service = new SecurityService(new HtmlService());
        $this->assertInstanceOf(SecurityService::class, $service);
    }

    public function testImplementsSecurityInterface(): void
    {
        $this->assertInstanceOf(\App\Contract\SecurityInterface::class, $this->service);
    }

    // ── sendSecurityHeaders() details ───────────────────────────

    public function testSendSecurityHeadersMethodExists(): void
    {
        $this->assertTrue(method_exists($this->service, 'sendSecurityHeaders'));
    }

    public function testSendSecurityHeadersIsPublic(): void
    {
        $reflection = new \ReflectionMethod($this->service, 'sendSecurityHeaders');
        $this->assertTrue($reflection->isPublic());
    }

    // ── Container integration ───────────────────────────────────

    public function testServiceRegisteredInContainer(): void
    {
        $app = \App\Core\App::getInstance();
        $this->assertTrue($app->has(SecurityService::class));
    }

    public function testContainerReturnsSameType(): void
    {
        $app = \App\Core\App::getInstance();
        $service = $app->get(SecurityService::class);
        $this->assertInstanceOf(SecurityService::class, $service);
    }
}
