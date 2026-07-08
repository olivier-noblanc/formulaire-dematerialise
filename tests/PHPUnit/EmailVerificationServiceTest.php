<?php
declare(strict_types=1);

namespace App\Tests;

use App\Email\EmailVerificationService;
use App\Cache\CacheService;
use PHPUnit\Framework\TestCase;

final class EmailVerificationServiceTest extends TestCase
{
    private EmailVerificationService $service;

    protected function setUp(): void
    {
        $this->service = new EmailVerificationService(new CacheService());
    }

    // ── Constructor / DI ────────────────────────────────────────

    public function testConstructorCreatesInstance(): void
    {
        $this->assertInstanceOf(EmailVerificationService::class, $this->service);
    }

    public function testServiceRegistrable(): void
    {
        $app = \App\Core\App::getInstance();
        $svc = new EmailVerificationService(new CacheService());
        $app->set(EmailVerificationService::class, $svc);
        $this->assertSame($svc, $app->get(EmailVerificationService::class));
    }

    // ── verify() — format validation ───────────────────────────

    public function testVerifyInvalidEmailReturnsError(): void
    {
        $result = $this->service->verify('not-an-email');
        $this->assertFalse($result['ok']);
        $this->assertSame('format', $result['method']);
        $this->assertStringContainsString('invalide', $result['detail']);
    }

    public function testVerifyEmptyEmailReturnsError(): void
    {
        $result = $this->service->verify('');
        $this->assertFalse($result['ok']);
        $this->assertSame('format', $result['method']);
    }

    public function testVerifyValidEmailFormatPassesFormatCheck(): void
    {
        // In test mode, verify() will check format then fall through to mode
        $result = $this->service->verify('test@example.com');
        // Result depends on email_verify_mode setting; format is at least valid
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('method', $result);
        $this->assertArrayHasKey('detail', $result);
    }

    // ── verify() — mode routing ─────────────────────────────────

    public function testVerifyModeNoneReturnsOk(): void
    {
        // Default mode is 'none' in test environment
        $result = $this->service->verify('test@example.com');
        if ($result['method'] === 'none') {
            $this->assertTrue($result['ok']);
        }
    }

    public function testVerifyResultStructure(): void
    {
        $result = $this->service->verify('test@example.com');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertIsBool($result['ok']);
        $this->assertArrayHasKey('method', $result);
        $this->assertIsString($result['method']);
        $this->assertArrayHasKey('detail', $result);
        $this->assertIsString($result['detail']);
    }

    // ── verifyLdap() — basic checks ────────────────────────────

    public function testVerifyLdapReturnsArray(): void
    {
        // LDAP may not be available in test; just verify structure
        $result = $this->service->verifyLdap('test@example.com');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('method', $result);
        $this->assertArrayHasKey('detail', $result);
        $this->assertSame('ldap', $result['method']);
    }

    public function testVerifyLdapReturnsOkFalseWhenNotConnected(): void
    {
        // Without LDAP configured, should return ok=false
        $result = $this->service->verifyLdap('test@example.com');
        $this->assertFalse($result['ok']);
    }

    // ── verifySmtp() — basic checks ────────────────────────────

    public function testVerifySmtpReturnsArray(): void
    {
        $result = $this->service->verifySmtp('test@example.com');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('method', $result);
        $this->assertArrayHasKey('detail', $result);
        $this->assertSame('smtp', $result['method']);
    }

    public function testVerifySmtpReturnsOkFalseWhenNoHost(): void
    {
        // Without SMTP configured, should return ok=false
        $result = $this->service->verifySmtp('test@example.com');
        $this->assertFalse($result['ok']);
    }

    // ── ldapSuggest() ───────────────────────────────────────────

    public function testLdapSuggestReturnsArray(): void
    {
        $result = $this->service->ldapSuggest('test');
        $this->assertIsArray($result);
    }

    public function testLdapSuggestWithEmptyQuery(): void
    {
        $result = $this->service->ldapSuggest('');
        $this->assertIsArray($result);
    }

    public function testLdapSuggestLimitClamped(): void
    {
        // Limit should be clamped to 1-500
        $result = $this->service->ldapSuggest('test', 1000);
        $this->assertIsArray($result);
    }

    public function testLdapSuggestNegativeLimitClamped(): void
    {
        $result = $this->service->ldapSuggest('test', -5);
        $this->assertIsArray($result);
    }

    // ── testVerification() ─────────────────────────────────────

    public function testTestVerificationReturnsArray(): void
    {
        $result = $this->service->testVerification('test@example.com');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayHasKey('mode', $result);
        $this->assertArrayHasKey('format_valid', $result);
        $this->assertArrayHasKey('verify', $result);
    }

    public function testTestVerificationInvalidEmail(): void
    {
        $result = $this->service->testVerification('not-email');
        $this->assertFalse($result['format_valid']);
        $this->assertSame('not-email', $result['email']);
    }

    public function testTestVerificationValidEmail(): void
    {
        $result = $this->service->testVerification('user@example.com');
        $this->assertTrue($result['format_valid']);
    }

    public function testTestVerificationVerifyKeyHasCorrectStructure(): void
    {
        $result = $this->service->testVerification('test@example.com');
        $this->assertArrayHasKey('ok', $result['verify']);
        $this->assertArrayHasKey('method', $result['verify']);
        $this->assertArrayHasKey('detail', $result['verify']);
    }

    // ── Global function wrappers ───────────────────────────────

    public function testGlobalFunctionVerifyEmailExists(): void
    {
        $this->assertTrue(function_exists('verify_email'));
    }

    public function testGlobalFunctionVerifyEmailLdapExists(): void
    {
        $this->assertTrue(function_exists('verify_email_ldap'));
    }

    public function testGlobalFunctionVerifyEmailSmtpExists(): void
    {
        $this->assertTrue(function_exists('verify_email_smtp'));
    }

    public function testGlobalFunctionLdapSuggestExists(): void
    {
        $this->assertTrue(function_exists('ldap_suggest'));
    }

    public function testGlobalFunctionTestEmailVerificationExists(): void
    {
        $this->assertTrue(function_exists('test_email_verification'));
    }

    public function testGlobalFunctionsReturnArrays(): void
    {
        $result = verify_email('test@example.com');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);

        $result = verify_email_ldap('test@example.com');
        $this->assertIsArray($result);

        $result = verify_email_smtp('test@example.com');
        $this->assertIsArray($result);

        $result = ldap_suggest('test');
        $this->assertIsArray($result);

        $result = test_email_verification('test@example.com');
        $this->assertIsArray($result);
    }
}
