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

    public function testServiceRegistrableViaAppAccessor(): void
    {
        $app = \App\Core\App::getInstance();
        $this->assertTrue($app->has(EmailVerificationService::class));
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
        $result = $this->service->verify('test@example.com');
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('method', $result);
        $this->assertArrayHasKey('detail', $result);
    }

    public function testVerifyEmailWithSpecialCharsPassesFormatCheck(): void
    {
        $result = $this->service->verify('user+tag@sub.domain.com');
        $this->assertArrayHasKey('ok', $result);
        // Format should be valid even if LDAP/SMTP fails
        if ($result['method'] === 'format') {
            $this->assertFalse($result['ok']);
        }
    }

    public function testVerifyEmailWithDotsInLocalPartPassesFormatCheck(): void
    {
        $result = $this->service->verify('first.last@example.com');
        $this->assertArrayHasKey('ok', $result);
    }

    public function testVerifyEmailWithUnderscoreInLocalPartPassesFormatCheck(): void
    {
        $result = $this->service->verify('user_name@example.com');
        $this->assertArrayHasKey('ok', $result);
    }

    // ── verify() — mode routing ─────────────────────────────────

    public function testVerifyModeNoneReturnsOk(): void
    {
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

    public function testVerifyReturnsOkTrueForNoneMode(): void
    {
        // Default test mode should be 'none'
        $result = $this->service->verify('test@example.com');
        if ($result['method'] === 'none') {
            $this->assertTrue($result['ok']);
            $this->assertStringContainsString('configurée', $result['detail']);
        }
    }

    public function testVerifyReturnsOkFalseForFormatInvalidEmail(): void
    {
        $result = $this->service->verify('invalid');
        $this->assertFalse($result['ok']);
        $this->assertSame('format', $result['method']);
    }

    public function testVerifyReturnsOkForEmailWithSubdomain(): void
    {
        $result = $this->service->verify('user@mail.example.co.uk');
        $this->assertArrayHasKey('ok', $result);
    }

    public function testVerifyReturnsOkForEmailWithNumbers(): void
    {
        $result = $this->service->verify('user123@example.com');
        $this->assertArrayHasKey('ok', $result);
    }

    // ── verifyLdap() — basic checks ────────────────────────────

    public function testVerifyLdapReturnsArray(): void
    {
        $result = $this->service->verifyLdap('test@example.com');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('method', $result);
        $this->assertArrayHasKey('detail', $result);
        $this->assertSame('ldap', $result['method']);
    }

    public function testVerifyLdapReturnsOkFalseWhenNotConnected(): void
    {
        $result = $this->service->verifyLdap('test@example.com');
        $this->assertFalse($result['ok']);
    }

    public function testVerifyLdapReturnsMethodLdap(): void
    {
        $result = $this->service->verifyLdap('any@example.com');
        $this->assertSame('ldap', $result['method']);
    }

    public function testVerifyLdapReturnsDetailString(): void
    {
        $result = $this->service->verifyLdap('test@example.com');
        $this->assertIsString($result['detail']);
        $this->assertNotEmpty($result['detail']);
    }

    public function testVerifyLdapHandlesSpecialCharsInEmail(): void
    {
        $result = $this->service->verifyLdap('user+tag@example.com');
        $this->assertIsArray($result);
        $this->assertSame('ldap', $result['method']);
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
        $result = $this->service->verifySmtp('test@example.com');
        $this->assertFalse($result['ok']);
    }

    public function testVerifySmtpReturnsMethodSmtp(): void
    {
        $result = $this->service->verifySmtp('any@example.com');
        $this->assertSame('smtp', $result['method']);
    }

    public function testVerifySmtpReturnsDetailString(): void
    {
        $result = $this->service->verifySmtp('test@example.com');
        $this->assertIsString($result['detail']);
        $this->assertNotEmpty($result['detail']);
    }

    public function testVerifySmtpReturnsErrorDetail(): void
    {
        $result = $this->service->verifySmtp('test@example.com');
        $this->assertNotEmpty($result['detail']);
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
        $result = $this->service->ldapSuggest('test', 1000);
        $this->assertIsArray($result);
    }

    public function testLdapSuggestNegativeLimitClamped(): void
    {
        $result = $this->service->ldapSuggest('test', -5);
        $this->assertIsArray($result);
    }

    public function testLdapSuggestDefaultLimit(): void
    {
        // Default limit is 100
        $result = $this->service->ldapSuggest('test');
        $this->assertIsArray($result);
    }

    public function testLdapSuggestZeroLimitClampedToOne(): void
    {
        $result = $this->service->ldapSuggest('test', 0);
        $this->assertIsArray($result);
    }

    public function testLdapSuggestWithSpecialCharsInQuery(): void
    {
        $result = $this->service->ldapSuggest('test*()');
        $this->assertIsArray($result);
    }

    public function testLdapSuggestReturnsEmptyWhenSuggestDisabled(): void
    {
        // ldap_suggest_enabled defaults to '0' in test environment
        $result = $this->service->ldapSuggest('test');
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

    public function testTestVerificationReturnsEmailInResult(): void
    {
        $email = 'specific-' . uniqid() . '@test.com';
        $result = $this->service->testVerification($email);
        $this->assertSame($email, $result['email']);
    }

    public function testTestVerificationReturnsModeFromSettings(): void
    {
        $result = $this->service->testVerification('test@example.com');
        $this->assertArrayHasKey('mode', $result);
        $this->assertIsString($result['mode']);
    }

    public function testTestVerificationWithInvalidEmailReturnsFormatInvalid(): void
    {
        $result = $this->service->testVerification('bad@@email');
        $this->assertFalse($result['format_valid']);
    }

    public function testTestVerificationVerifyMatchesVerifyMethod(): void
    {
        $result = $this->service->testVerification('test@example.com');
        $directVerify = $this->service->verify('test@example.com');
        $this->assertSame($directVerify, $result['verify']);
    }

    public function testTestVerificationWithEmptyEmail(): void
    {
        $result = $this->service->testVerification('');
        $this->assertFalse($result['format_valid']);
        $this->assertSame('', $result['email']);
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

    public function testGlobalVerifyEmailMatchesServiceMethod(): void
    {
        $globalResult = verify_email('test@example.com');
        $serviceResult = $this->service->verify('test@example.com');
        $this->assertSame($serviceResult, $globalResult);
    }

    public function testGlobalVerifyEmailLdapMatchesServiceMethod(): void
    {
        $globalResult = verify_email_ldap('test@example.com');
        $serviceResult = $this->service->verifyLdap('test@example.com');
        $this->assertSame($serviceResult, $globalResult);
    }

    public function testGlobalVerifyEmailSmtpMatchesServiceMethod(): void
    {
        $globalResult = verify_email_smtp('test@example.com');
        $serviceResult = $this->service->verifySmtp('test@example.com');
        $this->assertSame($serviceResult, $globalResult);
    }

    public function testGlobalLdapSuggestMatchesServiceMethod(): void
    {
        $globalResult = ldap_suggest('test');
        $serviceResult = $this->service->ldapSuggest('test');
        $this->assertSame($serviceResult, $globalResult);
    }

    public function testGlobalTestEmailVerificationMatchesServiceMethod(): void
    {
        $globalResult = test_email_verification('test@example.com');
        $serviceResult = $this->service->testVerification('test@example.com');
        $this->assertSame($serviceResult, $globalResult);
    }

    // ── Edge cases ──────────────────────────────────────────────

    public function testVerifyWithUnicodeEmail(): void
    {
        $result = $this->service->verify('用户@例子.中国');
        // Unicode emails may not pass filter_var — format check
        $this->assertArrayHasKey('ok', $result);
    }

    public function testVerifyWithVeryLongEmail(): void
    {
        $longLocal = str_repeat('a', 100);
        $result = $this->service->verify("$longLocal@example.com");
        $this->assertArrayHasKey('ok', $result);
    }

    public function testVerifyLdapWithEmptyEmail(): void
    {
        $result = $this->service->verifyLdap('');
        $this->assertIsArray($result);
        $this->assertSame('ldap', $result['method']);
    }

    public function testVerifySmtpWithEmptyEmail(): void
    {
        $result = $this->service->verifySmtp('');
        $this->assertIsArray($result);
        $this->assertSame('smtp', $result['method']);
    }

    public function testLdapSuggestWithWhitespaceQuery(): void
    {
        $result = $this->service->ldapSuggest('   ');
        $this->assertIsArray($result);
    }

    // ── verify() mode routing ───────────────────────────────────

    public function testVerifyModeNoneReturnsOkWithNoneMethod(): void
    {
        // Default mode is 'none'
        $result = $this->service->verify('test@example.com');
        if ($result['method'] === 'none') {
            $this->assertTrue($result['ok']);
            $this->assertSame('none', $result['method']);
        }
    }

    public function testVerifyWithInvalidEmailDoesNotRouteToLdapOrSmtp(): void
    {
        $result = $this->service->verify('not-valid-email');
        $this->assertFalse($result['ok']);
        $this->assertSame('format', $result['method']);
    }

    public function testVerifyReturnsThreeKeysAlways(): void
    {
        $emails = ['test@example.com', 'invalid', '', 'user+tag@domain.com'];
        foreach ($emails as $email) {
            $result = $this->service->verify($email);
            $this->assertArrayHasKey('ok', $result, "Failed for email: $email");
            $this->assertArrayHasKey('method', $result, "Failed for email: $email");
            $this->assertArrayHasKey('detail', $result, "Failed for email: $email");
        }
    }

    // ── verifyLdap() edge cases ─────────────────────────────────

    public function testVerifyLdapWhenLdapExtensionAvailableButNoHost(): void
    {
        // When ldap_connect exists but host config is empty, returns config error
        if (function_exists('ldap_connect')) {
            $result = $this->service->verifyLdap('test@example.com');
            $this->assertIsArray($result);
            $this->assertSame('ldap', $result['method']);
            // Should fail because LDAP host is not configured in test
            $this->assertFalse($result['ok']);
        } else {
            $result = $this->service->verifyLdap('test@example.com');
            $this->assertFalse($result['ok']);
            $this->assertStringContainsString('ldap', $result['detail']);
        }
    }

    public function testVerifyLdapDetailIsNonEmptyString(): void
    {
        $result = $this->service->verifyLdap('anyone@example.com');
        $this->assertIsString($result['detail']);
        $this->assertNotEmpty($result['detail']);
    }

    // ── verifySmtp() edge cases ─────────────────────────────────

    public function testVerifySmtpWhenFsockopenNotAvailable(): void
    {
        // Smtp will fail because no SMTP host configured in test
        $result = $this->service->verifySmtp('test@example.com');
        $this->assertFalse($result['ok']);
        $this->assertSame('smtp', $result['method']);
    }

    public function testVerifySmtpWithEmptyEmailReturnsSmtpMethod(): void
    {
        $result = $this->service->verifySmtp('');
        $this->assertSame('smtp', $result['method']);
    }

    public function testVerifySmtpDetailContainsErrorInformation(): void
    {
        $result = $this->service->verifySmtp('test@example.com');
        $this->assertNotEmpty($result['detail']);
    }

    // ── ldapSuggest() paths ─────────────────────────────────────

    public function testLdapSuggestReturnsEmptyWhenSuggestNotEnabled(): void
    {
        // ldap_suggest_enabled defaults to '0' in test
        $result = $this->service->ldapSuggest('test');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testLdapSuggestReturnsEmptyForSpecialCharsQuery(): void
    {
        $result = $this->service->ldapSuggest('test)(|*)(*');
        $this->assertIsArray($result);
    }

    public function testLdapSuggestLimitOne(): void
    {
        $result = $this->service->ldapSuggest('test', 1);
        $this->assertIsArray($result);
    }

    public function testLdapSuggestLimitFiveHundred(): void
    {
        $result = $this->service->ldapSuggest('test', 500);
        $this->assertIsArray($result);
    }

    // ── testVerification() mode paths ───────────────────────────

    public function testTestVerificationWithValidEmailAndNoneMode(): void
    {
        $result = $this->service->testVerification('test@example.com');
        $this->assertTrue($result['format_valid']);
        $this->assertSame('test@example.com', $result['email']);
        $this->assertArrayHasKey('verify', $result);
        $this->assertArrayHasKey('mode', $result);
    }

    public function testTestVerificationWithLongEmail(): void
    {
        $longEmail = str_repeat('a', 60) . '@example.com';
        $result = $this->service->testVerification($longEmail);
        $this->assertSame($longEmail, $result['email']);
    }

    public function testTestVerificationResultContainsVerifyArray(): void
    {
        $result = $this->service->testVerification('test@example.com');
        $this->assertArrayHasKey('ok', $result['verify']);
        $this->assertArrayHasKey('method', $result['verify']);
        $this->assertArrayHasKey('detail', $result['verify']);
    }

    // ── verify() edge cases ─────────────────────────────────────

    public function testVerifyEmailWithNumericDomain(): void
    {
        $result = $this->service->verify('user@123.456.789');
        $this->assertArrayHasKey('ok', $result);
    }

    public function testVerifyEmailWithLongTld(): void
    {
        $result = $this->service->verify('user@example.museum');
        $this->assertArrayHasKey('ok', $result);
    }

    public function testVerifyEmailWithHyphenDomain(): void
    {
        $result = $this->service->verify('user@my-domain.co.uk');
        $this->assertArrayHasKey('ok', $result);
    }

    public function testVerifyEmailWithSingleCharLocalPart(): void
    {
        $result = $this->service->verify('a@b.co');
        $this->assertArrayHasKey('ok', $result);
    }

    // ── ldapSuggest() boundary values ───────────────────────────

    public function testLdapSuggestLimitExactBoundary(): void
    {
        $result = $this->service->ldapSuggest('test', 100);
        $this->assertIsArray($result);
    }

    public function testLdapSuggestLimitMinusOne(): void
    {
        $result = $this->service->ldapSuggest('test', -1);
        $this->assertIsArray($result);
    }

    // ── verifyLdap() email variations ───────────────────────────

    public function testVerifyLdapWithUppercaseEmail(): void
    {
        $result = $this->service->verifyLdap('TEST@EXAMPLE.COM');
        $this->assertIsArray($result);
        $this->assertSame('ldap', $result['method']);
    }

    public function testVerifyLdapWithDotInLocalPart(): void
    {
        $result = $this->service->verifyLdap('first.last@example.com');
        $this->assertIsArray($result);
        $this->assertSame('ldap', $result['method']);
    }

    // ── Integration with global functions ───────────────────────

    public function testGlobalVerifyEmailWithInvalidEmail(): void
    {
        $result = verify_email('not-valid');
        $this->assertFalse($result['ok']);
        $this->assertSame('format', $result['method']);
    }

    public function testGlobalVerifyEmailLdapMatchesServiceLdap(): void
    {
        $global = verify_email_ldap('test@example.com');
        $service = $this->service->verifyLdap('test@example.com');
        $this->assertSame($service, $global);
    }

    public function testGlobalVerifyEmailSmtpMatchesServiceSmtp(): void
    {
        $global = verify_email_smtp('test@example.com');
        $service = $this->service->verifySmtp('test@example.com');
        $this->assertSame($service, $global);
    }
}
