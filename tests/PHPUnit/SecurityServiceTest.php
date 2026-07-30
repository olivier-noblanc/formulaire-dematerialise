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
        self::assertIsString($token);
        self::assertNotEmpty($token);
    }

    public function testGenerateCsrfTokenIsConsistent(): void
    {
        $token1 = $this->service->generateCsrfToken();
        $token2 = $this->service->generateCsrfToken();
        self::assertSame($token1, $token2);
    }

    public function testCsrfFieldContainsHiddenInput(): void
    {
        $field = $this->service->csrfField();
        self::assertStringContainsString('type="hidden"', $field);
        self::assertStringContainsString('name="csrf_token"', $field);
    }

    public function testCsrfFieldContainsToken(): void
    {
        $token = $this->service->generateCsrfToken();
        $field = $this->service->csrfField();
        self::assertStringContainsString($token, $field);
    }

    public function testCsrfFieldWithPersonaToken(): void
    {
        $_GET['persona_token'] = 'test_token_123';
        $field = $this->service->csrfField();
        self::assertStringContainsString('name="persona_token"', $field);
        self::assertStringContainsString('test_token_123', $field);
        unset($_GET['persona_token']);
    }

    public function testCsrfFieldWithoutPersonaToken(): void
    {
        unset($_GET['persona_token']);
        $field = $this->service->csrfField();
        self::assertStringNotContainsString('persona_token', $field);
    }

    public function testVerifyCsrfReturnsTrueInTestMode(): void
    {
        // In TEST_MODE, CSRF is bypassed
        self::assertTrue($this->service->verifyCsrf());
    }

    public function testSendSecurityHeadersInCliDoesNothing(): void
    {
        // In CLI mode, sendSecurityHeaders should return early
        // This test verifies no exception is thrown
        $this->service->sendSecurityHeaders();
        self::assertTrue(true); // No exception = pass
    }

    // ── generateCsrfToken() edge cases ──────────────────────────

    public function testGenerateCsrfTokenCreatesNewWhenEmpty(): void
    {
        unset($_SESSION['csrf_token']);
        $token = $this->service->generateCsrfToken();
        self::assertNotEmpty($token);
        self::assertSame(64, strlen($token)); // bin2hex(32 bytes) = 64 chars
    }

    public function testGenerateCsrfTokenReusesExisting(): void
    {
        $_SESSION['csrf_token'] = 'existing_token_value';
        $token = $this->service->generateCsrfToken();
        self::assertSame('existing_token_value', $token);
    }

    // ── csrfField() without persona token ───────────────────────

    public function testCsrfFieldWithoutPersonaContainsOnlyOneInput(): void
    {
        unset($_GET['persona_token']);
        $field = $this->service->csrfField();
        self::assertStringContainsString('csrf_token', $field);
        // Count <input occurrences — should be exactly 1
        $count = substr_count($field, '<input');
        self::assertSame(1, $count);
    }

    public function testCsrfFieldWithPersonaContainsTwoInputs(): void
    {
        $_GET['persona_token'] = 'test123';
        $field = $this->service->csrfField();
        $count = substr_count($field, '<input');
        self::assertSame(2, $count);
        unset($_GET['persona_token']);
    }

    // ── verifyCsrf() behavior ───────────────────────────────────

    public function testVerifyCsrfReturnsTrueInTestModeAlways(): void
    {
        // In TEST_MODE, CSRF is always bypassed
        $_POST['csrf_token'] = '';
        $_SESSION['csrf_token'] = '';
        self::assertTrue($this->service->verifyCsrf());
    }

    public function testVerifyCsrfMethodExists(): void
    {
        self::assertTrue(method_exists($this->service, 'verifyCsrf'));
    }

    public function testRequireCsrfMethodExists(): void
    {
        self::assertTrue(method_exists($this->service, 'requireCsrf'));
    }

    public function testRequireCsrfIsPublic(): void
    {
        $reflection = new \ReflectionMethod($this->service, 'requireCsrf');
        self::assertTrue($reflection->isPublic());
    }

    public function testVerifyCsrfIsPublic(): void
    {
        $reflection = new \ReflectionMethod($this->service, 'verifyCsrf');
        self::assertTrue($reflection->isPublic());
    }

    // ── Constructor / DI ────────────────────────────────────────

    public function testConstructorCreatesInstance(): void
    {
        $service = new SecurityService(new HtmlService());
        self::assertInstanceOf(SecurityService::class, $service);
    }

    public function testImplementsSecurityInterface(): void
    {
        self::assertInstanceOf(\App\Contract\SecurityInterface::class, $this->service);
    }

    // ── sendSecurityHeaders() details ───────────────────────────

    public function testSendSecurityHeadersMethodExists(): void
    {
        self::assertTrue(method_exists($this->service, 'sendSecurityHeaders'));
    }

    public function testSendSecurityHeadersIsPublic(): void
    {
        $reflection = new \ReflectionMethod($this->service, 'sendSecurityHeaders');
        self::assertTrue($reflection->isPublic());
    }

    // ── Container integration ───────────────────────────────────

    public function testServiceRegisteredInContainer(): void
    {
        $app = \App\Core\App::getInstance();
        self::assertTrue($app->has(SecurityService::class));
    }

    public function testContainerReturnsSameType(): void
    {
        $app = \App\Core\App::getInstance();
        $service = $app->get(SecurityService::class);
        self::assertInstanceOf(SecurityService::class, $service);
    }
}
