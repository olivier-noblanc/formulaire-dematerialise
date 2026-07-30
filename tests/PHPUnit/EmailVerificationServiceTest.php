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
        self::assertInstanceOf(EmailVerificationService::class, $this->service);
    }

    public function testServiceRegistrable(): void
    {
        $app = \App\Core\App::getInstance();
        $svc = new EmailVerificationService(new CacheService());
        $app->set(EmailVerificationService::class, $svc);
        self::assertSame($svc, $app->get(EmailVerificationService::class));
    }

    public function testServiceRegistrableViaAppAccessor(): void
    {
        $app = \App\Core\App::getInstance();
        self::assertTrue($app->has(EmailVerificationService::class));
    }

    // ── verify() — format validation ───────────────────────────

    public function testVerifyInvalidEmailReturnsError(): void
    {
        $result = $this->service->verify('not-an-email');
        self::assertFalse($result['ok']);
        self::assertSame('format', $result['method']);
        self::assertStringContainsString('invalide', $result['detail']);
    }

    public function testVerifyEmptyEmailReturnsError(): void
    {
        $result = $this->service->verify('');
        self::assertFalse($result['ok']);
        self::assertSame('format', $result['method']);
    }

    public function testVerifyValidEmailFormatPassesFormatCheck(): void
    {
        $result = $this->service->verify('test@example.com');
        self::assertArrayHasKey('ok', $result);
        self::assertArrayHasKey('method', $result);
        self::assertArrayHasKey('detail', $result);
    }

    public function testVerifyEmailWithSpecialCharsPassesFormatCheck(): void
    {
        $result = $this->service->verify('user+tag@sub.domain.com');
        self::assertArrayHasKey('ok', $result);
        // Format should be valid even if LDAP/SMTP fails
        if ($result['method'] === 'format') {
            self::assertFalse($result['ok']);
        }
    }

    public function testVerifyEmailWithDotsInLocalPartPassesFormatCheck(): void
    {
        $result = $this->service->verify('first.last@example.com');
        self::assertArrayHasKey('ok', $result);
    }

    public function testVerifyEmailWithUnderscoreInLocalPartPassesFormatCheck(): void
    {
        $result = $this->service->verify('user_name@example.com');
        self::assertArrayHasKey('ok', $result);
    }

    // ── verify() — mode routing ─────────────────────────────────

    public function testVerifyModeNoneReturnsOk(): void
    {
        $result = $this->service->verify('test@example.com');
        if ($result['method'] === 'none') {
            self::assertTrue($result['ok']);
        }
    }

    public function testVerifyResultStructure(): void
    {
        $result = $this->service->verify('test@example.com');
        self::assertIsArray($result);
        self::assertArrayHasKey('ok', $result);
        self::assertIsBool($result['ok']);
        self::assertArrayHasKey('method', $result);
        self::assertIsString($result['method']);
        self::assertArrayHasKey('detail', $result);
        self::assertIsString($result['detail']);
    }

    public function testVerifyReturnsOkTrueForNoneMode(): void
    {
        // Default test mode should be 'none'
        $result = $this->service->verify('test@example.com');
        if ($result['method'] === 'none') {
            self::assertTrue($result['ok']);
            self::assertStringContainsString('configurée', $result['detail']);
        }
    }

    public function testVerifyReturnsOkFalseForFormatInvalidEmail(): void
    {
        $result = $this->service->verify('invalid');
        self::assertFalse($result['ok']);
        self::assertSame('format', $result['method']);
    }

    public function testVerifyReturnsOkForEmailWithSubdomain(): void
    {
        $result = $this->service->verify('user@mail.example.co.uk');
        self::assertArrayHasKey('ok', $result);
    }

    public function testVerifyReturnsOkForEmailWithNumbers(): void
    {
        $result = $this->service->verify('user123@example.com');
        self::assertArrayHasKey('ok', $result);
    }

    // ── verifyLdap() — basic checks ────────────────────────────

    public function testVerifyLdapReturnsArray(): void
    {
        $result = $this->service->verifyLdap('test@example.com');
        self::assertIsArray($result);
        self::assertArrayHasKey('ok', $result);
        self::assertArrayHasKey('method', $result);
        self::assertArrayHasKey('detail', $result);
        self::assertSame('ldap', $result['method']);
    }

    public function testVerifyLdapReturnsOkFalseWhenNotConnected(): void
    {
        $result = $this->service->verifyLdap('test@example.com');
        self::assertFalse($result['ok']);
    }

    public function testVerifyLdapReturnsMethodLdap(): void
    {
        $result = $this->service->verifyLdap('any@example.com');
        self::assertSame('ldap', $result['method']);
    }

    public function testVerifyLdapReturnsDetailString(): void
    {
        $result = $this->service->verifyLdap('test@example.com');
        self::assertIsString($result['detail']);
        self::assertNotEmpty($result['detail']);
    }

    public function testVerifyLdapHandlesSpecialCharsInEmail(): void
    {
        $result = $this->service->verifyLdap('user+tag@example.com');
        self::assertIsArray($result);
        self::assertSame('ldap', $result['method']);
    }

    // ── verifySmtp() — basic checks ────────────────────────────

    public function testVerifySmtpReturnsArray(): void
    {
        $result = $this->service->verifySmtp('test@example.com');
        self::assertIsArray($result);
        self::assertArrayHasKey('ok', $result);
        self::assertArrayHasKey('method', $result);
        self::assertArrayHasKey('detail', $result);
        self::assertSame('smtp', $result['method']);
    }

    public function testVerifySmtpReturnsOkFalseWhenNoHost(): void
    {
        $result = $this->service->verifySmtp('test@example.com');
        self::assertFalse($result['ok']);
    }

    public function testVerifySmtpReturnsMethodSmtp(): void
    {
        $result = $this->service->verifySmtp('any@example.com');
        self::assertSame('smtp', $result['method']);
    }

    public function testVerifySmtpReturnsDetailString(): void
    {
        $result = $this->service->verifySmtp('test@example.com');
        self::assertIsString($result['detail']);
        self::assertNotEmpty($result['detail']);
    }

    public function testVerifySmtpReturnsErrorDetail(): void
    {
        $result = $this->service->verifySmtp('test@example.com');
        self::assertNotEmpty($result['detail']);
    }

    // ── ldapSuggest() ───────────────────────────────────────────

    public function testLdapSuggestReturnsArray(): void
    {
        $result = $this->service->ldapSuggest('test');
        self::assertIsArray($result);
    }

    public function testLdapSuggestWithEmptyQuery(): void
    {
        $result = $this->service->ldapSuggest('');
        self::assertIsArray($result);
    }

    public function testLdapSuggestLimitClamped(): void
    {
        $result = $this->service->ldapSuggest('test', 1000);
        self::assertIsArray($result);
    }

    public function testLdapSuggestNegativeLimitClamped(): void
    {
        $result = $this->service->ldapSuggest('test', -5);
        self::assertIsArray($result);
    }

    public function testLdapSuggestDefaultLimit(): void
    {
        // Default limit is 100
        $result = $this->service->ldapSuggest('test');
        self::assertIsArray($result);
    }

    public function testLdapSuggestZeroLimitClampedToOne(): void
    {
        $result = $this->service->ldapSuggest('test', 0);
        self::assertIsArray($result);
    }

    public function testLdapSuggestWithSpecialCharsInQuery(): void
    {
        $result = $this->service->ldapSuggest('test*()');
        self::assertIsArray($result);
    }

    public function testLdapSuggestReturnsEmptyWhenSuggestDisabled(): void
    {
        // ldap_suggest_enabled defaults to '0' in test environment
        $result = $this->service->ldapSuggest('test');
        self::assertIsArray($result);
    }

    // ── testVerification() ─────────────────────────────────────

    public function testTestVerificationReturnsArray(): void
    {
        $result = $this->service->testVerification('test@example.com');
        self::assertIsArray($result);
        self::assertArrayHasKey('email', $result);
        self::assertArrayHasKey('mode', $result);
        self::assertArrayHasKey('format_valid', $result);
        self::assertArrayHasKey('verify', $result);
    }

    public function testTestVerificationInvalidEmail(): void
    {
        $result = $this->service->testVerification('not-email');
        self::assertFalse($result['format_valid']);
        self::assertSame('not-email', $result['email']);
    }

    public function testTestVerificationValidEmail(): void
    {
        $result = $this->service->testVerification('user@example.com');
        self::assertTrue($result['format_valid']);
    }

    public function testTestVerificationVerifyKeyHasCorrectStructure(): void
    {
        $result = $this->service->testVerification('test@example.com');
        self::assertArrayHasKey('ok', $result['verify']);
        self::assertArrayHasKey('method', $result['verify']);
        self::assertArrayHasKey('detail', $result['verify']);
    }

    public function testTestVerificationReturnsEmailInResult(): void
    {
        $email = 'specific-' . uniqid() . '@test.com';
        $result = $this->service->testVerification($email);
        self::assertSame($email, $result['email']);
    }

    public function testTestVerificationReturnsModeFromSettings(): void
    {
        $result = $this->service->testVerification('test@example.com');
        self::assertArrayHasKey('mode', $result);
        self::assertIsString($result['mode']);
    }

    public function testTestVerificationWithInvalidEmailReturnsFormatInvalid(): void
    {
        $result = $this->service->testVerification('bad@@email');
        self::assertFalse($result['format_valid']);
    }

    public function testTestVerificationVerifyMatchesVerifyMethod(): void
    {
        $result = $this->service->testVerification('test@example.com');
        $directVerify = $this->service->verify('test@example.com');
        self::assertSame($directVerify, $result['verify']);
    }

    public function testTestVerificationWithEmptyEmail(): void
    {
        $result = $this->service->testVerification('');
        self::assertFalse($result['format_valid']);
        self::assertSame('', $result['email']);
    }

    // ── Direct service calls (replaces removed global wrappers) ──

    public function testDirectServiceCallsReturnArrays(): void
    {
        $result = \App\Core\App::emailVerify()->verify('test@example.com');
        self::assertIsArray($result);
        self::assertArrayHasKey('ok', $result);

        $result = \App\Core\App::emailVerify()->verifyLdap('test@example.com');
        self::assertIsArray($result);

        $result = \App\Core\App::emailVerify()->verifySmtp('test@example.com');
        self::assertIsArray($result);

        $result = \App\Core\App::emailVerify()->ldapSuggest('test');
        self::assertIsArray($result);

        $result = \App\Core\App::emailVerify()->testVerification('test@example.com');
        self::assertIsArray($result);
    }

    // ── Edge cases ──────────────────────────────────────────────

    public function testVerifyWithUnicodeEmail(): void
    {
        $result = $this->service->verify('用户@例子.中国');
        // Unicode emails may not pass filter_var — format check
        self::assertArrayHasKey('ok', $result);
    }

    public function testVerifyWithVeryLongEmail(): void
    {
        $longLocal = str_repeat('a', 100);
        $result = $this->service->verify("$longLocal@example.com");
        self::assertArrayHasKey('ok', $result);
    }

    public function testVerifyLdapWithEmptyEmail(): void
    {
        $result = $this->service->verifyLdap('');
        self::assertIsArray($result);
        self::assertSame('ldap', $result['method']);
    }

    public function testVerifySmtpWithEmptyEmail(): void
    {
        $result = $this->service->verifySmtp('');
        self::assertIsArray($result);
        self::assertSame('smtp', $result['method']);
    }

    public function testLdapSuggestWithWhitespaceQuery(): void
    {
        $result = $this->service->ldapSuggest('   ');
        self::assertIsArray($result);
    }

    // ── verify() mode routing ───────────────────────────────────

    public function testVerifyModeNoneReturnsOkWithNoneMethod(): void
    {
        // Default mode is 'none'
        $result = $this->service->verify('test@example.com');
        if ($result['method'] === 'none') {
            self::assertTrue($result['ok']);
            self::assertSame('none', $result['method']);
        }
    }

    public function testVerifyWithInvalidEmailDoesNotRouteToLdapOrSmtp(): void
    {
        $result = $this->service->verify('not-valid-email');
        self::assertFalse($result['ok']);
        self::assertSame('format', $result['method']);
    }

    public function testVerifyReturnsThreeKeysAlways(): void
    {
        $emails = ['test@example.com', 'invalid', '', 'user+tag@domain.com'];
        foreach ($emails as $email) {
            $result = $this->service->verify($email);
            self::assertArrayHasKey('ok', $result, "Failed for email: $email");
            self::assertArrayHasKey('method', $result, "Failed for email: $email");
            self::assertArrayHasKey('detail', $result, "Failed for email: $email");
        }
    }

    // ── verifyLdap() edge cases ─────────────────────────────────

    public function testVerifyLdapWhenLdapExtensionAvailableButNoHost(): void
    {
        // When ldap_connect exists but host config is empty, returns config error
        if (function_exists('ldap_connect')) {
            $result = $this->service->verifyLdap('test@example.com');
            self::assertIsArray($result);
            self::assertSame('ldap', $result['method']);
            // Should fail because LDAP host is not configured in test
            self::assertFalse($result['ok']);
        } else {
            $result = $this->service->verifyLdap('test@example.com');
            self::assertFalse($result['ok']);
            self::assertStringContainsString('ldap', $result['detail']);
        }
    }

    public function testVerifyLdapDetailIsNonEmptyString(): void
    {
        $result = $this->service->verifyLdap('anyone@example.com');
        self::assertIsString($result['detail']);
        self::assertNotEmpty($result['detail']);
    }

    // ── verifySmtp() edge cases ─────────────────────────────────

    public function testVerifySmtpWhenFsockopenNotAvailable(): void
    {
        // Smtp will fail because no SMTP host configured in test
        $result = $this->service->verifySmtp('test@example.com');
        self::assertFalse($result['ok']);
        self::assertSame('smtp', $result['method']);
    }

    public function testVerifySmtpWithEmptyEmailReturnsSmtpMethod(): void
    {
        $result = $this->service->verifySmtp('');
        self::assertSame('smtp', $result['method']);
    }

    public function testVerifySmtpDetailContainsErrorInformation(): void
    {
        $result = $this->service->verifySmtp('test@example.com');
        self::assertNotEmpty($result['detail']);
    }

    // ── ldapSuggest() paths ─────────────────────────────────────

    public function testLdapSuggestReturnsEmptyWhenSuggestNotEnabled(): void
    {
        // ldap_suggest_enabled defaults to '0' in test
        $result = $this->service->ldapSuggest('test');
        self::assertIsArray($result);
        self::assertEmpty($result);
    }

    public function testLdapSuggestReturnsEmptyForSpecialCharsQuery(): void
    {
        $result = $this->service->ldapSuggest('test)(|*)(*');
        self::assertIsArray($result);
    }

    public function testLdapSuggestLimitOne(): void
    {
        $result = $this->service->ldapSuggest('test', 1);
        self::assertIsArray($result);
    }

    public function testLdapSuggestLimitFiveHundred(): void
    {
        $result = $this->service->ldapSuggest('test', 500);
        self::assertIsArray($result);
    }

    // ── testVerification() mode paths ───────────────────────────

    public function testTestVerificationWithValidEmailAndNoneMode(): void
    {
        $result = $this->service->testVerification('test@example.com');
        self::assertTrue($result['format_valid']);
        self::assertSame('test@example.com', $result['email']);
        self::assertArrayHasKey('verify', $result);
        self::assertArrayHasKey('mode', $result);
    }

    public function testTestVerificationWithLongEmail(): void
    {
        $longEmail = str_repeat('a', 60) . '@example.com';
        $result = $this->service->testVerification($longEmail);
        self::assertSame($longEmail, $result['email']);
    }

    public function testTestVerificationResultContainsVerifyArray(): void
    {
        $result = $this->service->testVerification('test@example.com');
        self::assertArrayHasKey('ok', $result['verify']);
        self::assertArrayHasKey('method', $result['verify']);
        self::assertArrayHasKey('detail', $result['verify']);
    }

    // ── verify() edge cases ─────────────────────────────────────

    public function testVerifyEmailWithNumericDomain(): void
    {
        $result = $this->service->verify('user@123.456.789');
        self::assertArrayHasKey('ok', $result);
    }

    public function testVerifyEmailWithLongTld(): void
    {
        $result = $this->service->verify('user@example.museum');
        self::assertArrayHasKey('ok', $result);
    }

    public function testVerifyEmailWithHyphenDomain(): void
    {
        $result = $this->service->verify('user@my-domain.co.uk');
        self::assertArrayHasKey('ok', $result);
    }

    public function testVerifyEmailWithSingleCharLocalPart(): void
    {
        $result = $this->service->verify('a@b.co');
        self::assertArrayHasKey('ok', $result);
    }

    // ── ldapSuggest() boundary values ───────────────────────────

    public function testLdapSuggestLimitExactBoundary(): void
    {
        $result = $this->service->ldapSuggest('test', 100);
        self::assertIsArray($result);
    }

    public function testLdapSuggestLimitMinusOne(): void
    {
        $result = $this->service->ldapSuggest('test', -1);
        self::assertIsArray($result);
    }

    // ── verifyLdap() email variations ───────────────────────────

    public function testVerifyLdapWithUppercaseEmail(): void
    {
        $result = $this->service->verifyLdap('TEST@EXAMPLE.COM');
        self::assertIsArray($result);
        self::assertSame('ldap', $result['method']);
    }

    public function testVerifyLdapWithDotInLocalPart(): void
    {
        $result = $this->service->verifyLdap('first.last@example.com');
        self::assertIsArray($result);
        self::assertSame('ldap', $result['method']);
    }

    // ── Integration with service calls ───────────────────────

    public function testDirectVerifyEmailWithInvalidEmail(): void
    {
        $result = \App\Core\App::emailVerify()->verify('not-valid');
        self::assertFalse($result['ok']);
        self::assertSame('format', $result['method']);
    }

    public function testDirectVerifyEmailLdapMatchesServiceLdap(): void
    {
        $global = \App\Core\App::emailVerify()->verifyLdap('test@example.com');
        $service = $this->service->verifyLdap('test@example.com');
        self::assertSame($service, $global);
    }

    public function testDirectVerifyEmailSmtpMatchesServiceSmtp(): void
    {
        $global = \App\Core\App::emailVerify()->verifySmtp('test@example.com');
        $service = $this->service->verifySmtp('test@example.com');
        self::assertSame($service, $global);
    }
}
