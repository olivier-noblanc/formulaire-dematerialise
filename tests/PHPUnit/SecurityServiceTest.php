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
}
