<?php
declare(strict_types=1);

namespace App\Tests\Lib;

use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    // ── validate_email() ──────────────────────────────────────

    public function testValidateEmailValid(): void
    {
        $this->assertSame('test@example.com', validate_email('test@example.com'));
    }

    public function testValidateEmailNormalizes(): void
    {
        $this->assertSame('test@example.com', validate_email('  TEST@Example.COM  '));
    }

    public function testValidateEmailInvalid(): void
    {
        $this->assertSame('', validate_email('not-an-email'));
        $this->assertSame('', validate_email(''));
        $this->assertSame('', validate_email('@example.com'));
    }

    // ── validate_input() — uuid ───────────────────────────────

    public function testValidateInputUuidValid(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $this->assertSame(strtolower($uuid), validate_input($uuid, 'uuid'));
    }

    public function testValidateInputUuidInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        validate_input('not-a-uuid', 'uuid');
    }

    public function testValidateInputUuidUppercaseNormalized(): void
    {
        $uuid = '550E8400-E29B-41D4-A716-446655440000';
        $this->assertSame(strtolower($uuid), validate_input($uuid, 'uuid'));
    }

    // ── validate_input() — email ──────────────────────────────

    public function testValidateInputEmailValid(): void
    {
        $this->assertSame('test@example.com', validate_input('TEST@EXAMPLE.COM', 'email'));
    }

    public function testValidateInputEmailInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        validate_input('not-email', 'email');
    }

    public function testValidateInputEmailMaxLength(): void
    {
        // Truncating to 5 chars makes the email invalid, so it throws
        $this->expectException(\InvalidArgumentException::class);
        validate_input('long@address.com', 'email', ['max_length' => 5]);
    }

    public function testValidateInputEmailMaxLengthValid(): void
    {
        // A longer max_length should pass
        $result = validate_input('test@example.com', 'email', ['max_length' => 50]);
        $this->assertSame('test@example.com', $result);
    }

    // ── validate_input() — slug ───────────────────────────────

    public function testValidateInputSlugValid(): void
    {
        $this->assertSame('onboarding', validate_input('onboarding', 'slug'));
        $this->assertSame('acces-si', validate_input('acces-si', 'slug'));
        $this->assertSame('test_form', validate_input('test_form', 'slug'));
    }

    public function testValidateInputSlugInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        validate_input('invalid slug!', 'slug');
    }

    // ── validate_input() — action ─────────────────────────────

    public function testValidateInputActionValid(): void
    {
        $this->assertSame('add_form', validate_input('add_form', 'action'));
    }

    public function testValidateInputActionInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        validate_input('invalid action!', 'action');
    }

    // ── validate_input() — status ─────────────────────────────

    public function testValidateInputStatusValid(): void
    {
        $this->assertSame('en_cours', validate_input('en_cours', 'status'));
        $this->assertSame('valide', validate_input('valide', 'status'));
    }

    public function testValidateInputStatusInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        validate_input('unknown', 'status');
    }

    public function testValidateInputStatusCustomAllowed(): void
    {
        $this->assertSame('custom', validate_input('custom', 'status', [
            'allowed_values' => ['custom', 'other'],
        ]));
    }

    // ── validate_input() — alpha_num ──────────────────────────

    public function testValidateInputAlphaNumValid(): void
    {
        $this->assertSame('Hello World 123', validate_input('Hello World 123', 'alpha_num'));
    }

    public function testValidateInputAlphaNumAccents(): void
    {
        $this->assertSame('café résumé', validate_input('café résumé', 'alpha_num'));
    }

    public function testValidateInputAlphaNumInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        validate_input('invalid<script>', 'alpha_num');
    }

    // ── validate_input() — int ────────────────────────────────

    public function testValidateInputIntValid(): void
    {
        $this->assertSame(42, validate_input('42', 'int'));
    }

    public function testValidateInputIntInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        validate_input('not-int', 'int');
    }

    public function testValidateInputIntMin(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        validate_input('5', 'int', ['min' => 10]);
    }

    public function testValidateInputIntMax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        validate_input('100', 'int', ['max' => 50]);
    }

    // ── validate_input() — date ───────────────────────────────

    public function testValidateInputDateValid(): void
    {
        $this->assertSame('2026-01-15', validate_input('2026-01-15', 'date'));
    }

    public function testValidateInputDateInvalidFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        validate_input('15/01/2026', 'date');
    }

    public function testValidateInputDateInvalidValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        validate_input('2026-13-01', 'date');
    }

    // ── validate_input() — token ──────────────────────────────

    public function testValidateInputTokenValid(): void
    {
        $token = str_repeat('a', 64);
        $this->assertSame($token, validate_input($token, 'token'));
    }

    public function testValidateInputTokenInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        validate_input('short', 'token');
    }

    // ── validate_input() — unknown rule ───────────────────────

    public function testValidateInputUnknownRulePassthrough(): void
    {
        $this->assertSame('hello', validate_input('hello', 'unknown_rule'));
    }
}
