<?php
declare(strict_types=1);

namespace App\Tests\Lib;

use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function testGenerateUuidFormat(): void
    {
        $uuid = generate_uuid();
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/',
            $uuid
        );
    }

    public function testGenerateUuidVersion4(): void
    {
        $uuid = generate_uuid();
        // Version 4: character at position 14 must be '4'
        self::assertSame('4', $uuid[14]);
    }

    public function testGenerateUuidVariant(): void
    {
        $uuid = generate_uuid();
        // Variant RFC 4122: character at position 19 must be 8, 9, a, or b
        self::assertContains($uuid[19], ['8', '9', 'a', 'b']);
    }

    public function testGenerateUuidUniqueness(): void
    {
        $uuids = [];
        for ($i = 0; $i < 100; $i++) {
            $uuids[] = generate_uuid();
        }
        self::assertCount(100, array_unique($uuids));
    }

    public function testGenerateUuidLength(): void
    {
        $uuid = generate_uuid();
        self::assertSame(36, strlen($uuid));
    }

    public function testGenerateTokenLength(): void
    {
        $token = generate_token();
        self::assertSame(64, strlen($token));
    }

    public function testGenerateTokenHexFormat(): void
    {
        $token = generate_token();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }

    public function testGenerateTokenUniqueness(): void
    {
        $tokens = [];
        for ($i = 0; $i < 50; $i++) {
            $tokens[] = generate_token();
        }
        self::assertCount(50, array_unique($tokens));
    }
}
