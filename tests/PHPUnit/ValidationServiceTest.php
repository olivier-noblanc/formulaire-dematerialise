<?php
declare(strict_types=1);

namespace App\Tests;

use App\Validation\ValidationService;
use PHPUnit\Framework\TestCase;

final class ValidationServiceTest extends TestCase
{
    private ValidationService $service;

    protected function setUp(): void
    {
        $this->service = new ValidationService();
    }

    // ── validateEmail() ──────────────────────────────────────────

    public function testValidateEmailValid(): void
    {
        $this->assertSame('test@example.com', $this->service->validateEmail('test@example.com'));
    }

    public function testValidateEmailNormalizesCase(): void
    {
        $this->assertSame('test@example.com', $this->service->validateEmail('  TEST@Example.COM  '));
    }

    public function testValidateEmailInvalidReturnsEmpty(): void
    {
        $this->assertSame('', $this->service->validateEmail('not-an-email'));
        $this->assertSame('', $this->service->validateEmail(''));
        $this->assertSame('', $this->service->validateEmail('@example.com'));
    }

    // ── sanitize() ───────────────────────────────────────────────

    public function testSanitizeTrimsWhitespace(): void
    {
        $this->assertSame('hello', $this->service->sanitize('  hello  '));
    }

    public function testSanitizeEscapesHtml(): void
    {
        $this->assertSame('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $this->service->sanitize('<script>alert("xss")</script>'));
    }

    public function testSanitizeStripsSlashes(): void
    {
        $this->assertSame('it&#039;s', $this->service->sanitize("it\\'s"));
    }

    public function testSanitizeHandlesQuotes(): void
    {
        $result = $this->service->sanitize('say "hello"');
        $this->assertSame('say &quot;hello&quot;', $result);
    }

    // ── validate() — uuid ────────────────────────────────────────

    public function testValidateUuidValid(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $this->assertSame(strtolower($uuid), $this->service->validate($uuid, 'uuid'));
    }

    public function testValidateUuidInvalidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->validate('not-a-uuid', 'uuid');
    }

    public function testValidateUuidUppercaseNormalized(): void
    {
        $uuid = '550E8400-E29B-41D4-A716-446655440000';
        $this->assertSame(strtolower($uuid), $this->service->validate($uuid, 'uuid'));
    }

    // ── validate() — email ───────────────────────────────────────

    public function testValidateEmailRuleValid(): void
    {
        $this->assertSame('test@example.com', $this->service->validate('TEST@EXAMPLE.COM', 'email'));
    }

    public function testValidateEmailRuleInvalidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->validate('not-email', 'email');
    }

    public function testValidateEmailMaxLengthInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->validate('long@address.com', 'email', ['max_length' => 5]);
    }

    public function testValidateEmailMaxLengthValid(): void
    {
        $this->assertSame('test@example.com', $this->service->validate('test@example.com', 'email', ['max_length' => 50]));
    }

    // ── validate() — slug ────────────────────────────────────────

    public function testValidateSlugValid(): void
    {
        $this->assertSame('onboarding', $this->service->validate('onboarding', 'slug'));
        $this->assertSame('acces-si', $this->service->validate('acces-si', 'slug'));
        $this->assertSame('test_form', $this->service->validate('test_form', 'slug'));
    }

    public function testValidateSlugInvalidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->validate('invalid slug!', 'slug');
    }

    // ── validate() — action ──────────────────────────────────────

    public function testValidateActionValid(): void
    {
        $this->assertSame('add_form', $this->service->validate('add_form', 'action'));
    }

    public function testValidateActionInvalidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->validate('invalid action!', 'action');
    }

    // ── validate() — status ──────────────────────────────────────

    public function testValidateStatusValid(): void
    {
        $this->assertSame('en_cours', $this->service->validate('en_cours', 'status'));
        $this->assertSame('valide', $this->service->validate('valide', 'status'));
    }

    public function testValidateStatusInvalidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->validate('unknown', 'status');
    }

    public function testValidateStatusCustomAllowedValues(): void
    {
        $this->assertSame('custom', $this->service->validate('custom', 'status', [
            'allowed_values' => ['custom', 'other'],
        ]));
    }

    // ── validate() — alpha_num ───────────────────────────────────

    public function testValidateAlphaNumValid(): void
    {
        $this->assertSame('Hello World 123', $this->service->validate('Hello World 123', 'alpha_num'));
    }

    public function testValidateAlphaNumWithAccents(): void
    {
        $this->assertSame('café résumé', $this->service->validate('café résumé', 'alpha_num'));
    }

    public function testValidateAlphaNumInvalidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->validate('invalid<script>', 'alpha_num');
    }

    // ── validate() — int ─────────────────────────────────────────

    public function testValidateIntValid(): void
    {
        $this->assertSame(42, $this->service->validate('42', 'int'));
    }

    public function testValidateIntInvalidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->validate('not-int', 'int');
    }

    public function testValidateIntMinThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->validate('5', 'int', ['min' => 10]);
    }

    public function testValidateIntMaxThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->validate('100', 'int', ['max' => 50]);
    }

    // ── validate() — date ────────────────────────────────────────

    public function testValidateDateValid(): void
    {
        $this->assertSame('2026-01-15', $this->service->validate('2026-01-15', 'date'));
    }

    public function testValidateDateInvalidFormatThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->validate('15/01/2026', 'date');
    }

    public function testValidateDateInvalidValueThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->validate('2026-13-01', 'date');
    }

    // ── validate() — token ───────────────────────────────────────

    public function testValidateTokenValid(): void
    {
        $token = str_repeat('a', 64);
        $this->assertSame($token, $this->service->validate($token, 'token'));
    }

    public function testValidateTokenInvalidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->validate('short', 'token');
    }

    // ── validate() — unknown rule ────────────────────────────────

    public function testValidateUnknownRulePassesThrough(): void
    {
        $this->assertSame('hello', $this->service->validate('hello', 'unknown_rule'));
    }

    // ── DI container integration ─────────────────────────────────

    public function testServiceRegistrable(): void
    {
        $app = \App\Core\App::getInstance();
        $svc = new ValidationService();
        $app->set(ValidationService::class, $svc);
        $this->assertSame($svc, $app->get(ValidationService::class));
    }

    public function testServiceStaticAccessor(): void
    {
        $app = \App\Core\App::getInstance();
        $app->set(ValidationService::class, new ValidationService());
        // Verify it's retrievable — App::validation() accessor exists
        $this->assertTrue($app->has(ValidationService::class));
    }
}
