<?php
declare(strict_types=1);

namespace App\Tests\Lib;

use App\Core\App;
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
        $token = App::security()->generateCsrfToken();
        self::assertIsString($token);
        self::assertNotEmpty($token);
    }

    public function testGenerateCsrfTokenIsConsistent(): void
    {
        $t1 = App::security()->generateCsrfToken();
        $t2 = App::security()->generateCsrfToken();
        self::assertSame($t1, $t2);
    }

    public function testCsrfFieldContainsHiddenInput(): void
    {
        $field = App::security()->csrfField();
        self::assertStringContainsString('type="hidden"', $field);
        self::assertStringContainsString('name="csrf_token"', $field);
    }

    public function testCsrfFieldContainsToken(): void
    {
        $token = App::security()->generateCsrfToken();
        $field = App::security()->csrfField();
        self::assertStringContainsString($token, $field);
    }

    public function testVerifyCsrfReturnsTrueInTestMode(): void
    {
        // TEST_MODE is set by the bootstrap — CSRF is bypassed
        self::assertTrue(App::security()->verifyCsrf());
    }

    public function testCsrfFieldWithPersonaToken(): void
    {
        $_GET['persona_token'] = 'persona_abc';
        $field = App::security()->csrfField();
        self::assertStringContainsString('name="persona_token"', $field);
        self::assertStringContainsString('persona_abc', $field);
        unset($_GET['persona_token']);
    }

    public function testCsrfFieldWithoutPersonaToken(): void
    {
        unset($_GET['persona_token']);
        $field = App::security()->csrfField();
        self::assertStringNotContainsString('persona_token', $field);
    }
}
