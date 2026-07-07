<?php
declare(strict_types=1);

namespace App\Tests\Lib;

use PHPUnit\Framework\TestCase;

final class SecurityLibTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function testGenerateCsrfTokenReturnsString(): void
    {
        $token = generate_csrf_token();
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    public function testGenerateCsrfTokenIsConsistent(): void
    {
        $t1 = generate_csrf_token();
        $t2 = generate_csrf_token();
        $this->assertSame($t1, $t2);
    }

    public function testCsrfFieldContainsHiddenInput(): void
    {
        $field = csrf_field();
        $this->assertStringContainsString('type="hidden"', $field);
        $this->assertStringContainsString('name="csrf_token"', $field);
    }

    public function testCsrfFieldContainsToken(): void
    {
        $token = generate_csrf_token();
        $field = csrf_field();
        $this->assertStringContainsString($token, $field);
    }

    public function testVerifyCsrfReturnsTrueInTestMode(): void
    {
        // TEST_MODE is set by the bootstrap — CSRF is bypassed
        $this->assertTrue(verify_csrf());
    }

    public function testCsrfFieldWithPersonaToken(): void
    {
        $_GET['persona_token'] = 'persona_abc';
        $field = csrf_field();
        $this->assertStringContainsString('name="persona_token"', $field);
        $this->assertStringContainsString('persona_abc', $field);
        unset($_GET['persona_token']);
    }

    public function testCsrfFieldWithoutPersonaToken(): void
    {
        unset($_GET['persona_token']);
        $field = csrf_field();
        $this->assertStringNotContainsString('persona_token', $field);
    }

    public function testRateLimitCheckAllowsNormalRequest(): void
    {
        $this->assertTrue(rate_limit_check('test_action_' . uniqid(), 10, 60));
    }

    public function testRateLimitCheckWithDifferentActions(): void
    {
        $action = 'unique_action_' . uniqid();
        $this->assertTrue(rate_limit_check($action, 5, 60));
        $this->assertTrue(rate_limit_check($action, 5, 60));
    }
}
