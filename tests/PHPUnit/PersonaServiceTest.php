<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Persona\PersonaService;
use App\Core\Database;

final class PersonaServiceTest extends TestCase
{
    private PersonaService $persona;
    private Database $db;

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(\App\Core\Database::class);
        $this->persona = new PersonaService($this->db);

        $pdo = $this->db->getPdo();
        $pdo->exec("DELETE FROM persona_tokens");

        // Seed admin user required by lookup()
        $admin_id = generate_uuid();
        $pdo->prepare("INSERT OR IGNORE INTO admins (id, email) VALUES (?, ?)")
            ->execute([$admin_id, 'admin@test.com']);
    }

    public function testCreateTokenReturnsEmptyWithEmptyAdmin(): void
    {
        $result = $this->persona->createToken('', 'target@test.com');
        self::assertSame('', $result);
    }

    public function testCreateTokenReturnsEmptyWithEmptyTarget(): void
    {
        $result = $this->persona->createToken('admin@test.com', '');
        self::assertSame('', $result);
    }

    public function testCreateTokenReturns32HexChars(): void
    {
        $token = $this->persona->createToken('admin@test.com', 'target@test.com');
        self::assertNotEmpty($token);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
    }

    public function testLookupReturnsEmptyForEmptyToken(): void
    {
        $result = $this->persona->lookup('');
        self::assertSame('', $result);
    }

    public function testLookupReturnsEmptyForNonexistentToken(): void
    {
        $result = $this->persona->lookup('deadbeef000000000000000000000000');
        self::assertSame('', $result);
    }

    public function testLookupReturnsTargetEmailForValidToken(): void
    {
        $token = $this->persona->createToken('admin@test.com', 'target@test.com');
        self::assertNotEmpty($token);

        $target = $this->persona->lookup($token);
        self::assertSame('target@test.com', $target);
    }

    public function testRevokeReturnsFalseForEmptyToken(): void
    {
        $result = $this->persona->revoke('');
        self::assertFalse($result);
    }

    public function testRevokeReturnsFalseForNonexistentToken(): void
    {
        $result = $this->persona->revoke('deadbeef000000000000000000000000');
        self::assertFalse($result);
    }

    public function testRevokeReturnsTrueForValidToken(): void
    {
        $token = $this->persona->createToken('admin@test.com', 'target@test.com');
        self::assertNotEmpty($token);

        $result = $this->persona->revoke($token);
        self::assertTrue($result);
    }

    public function testRevokedTokenLookupReturnsEmpty(): void
    {
        $token = $this->persona->createToken('admin@test.com', 'target@test.com');
        $this->persona->revoke($token);

        $target = $this->persona->lookup($token);
        self::assertSame('', $target);
    }

    public function testDoubleRevokeReturnsFalse(): void
    {
        $token = $this->persona->createToken('admin@test.com', 'target@test.com');
        self::assertTrue($this->persona->revoke($token));
        self::assertFalse($this->persona->revoke($token));
    }

    public function testCleanupReturnsInt(): void
    {
        $token = $this->persona->createToken('admin@test.com', 'target@test.com');
        $this->persona->revoke($token);

        $result = $this->persona->cleanup();
        self::assertIsInt($result);
        self::assertGreaterThanOrEqual(0, $result);
    }

    public function testCurrentTokenReturnsEmptyByDefault(): void
    {
        unset($_GET['persona_token']);
        self::assertSame('', $this->persona->currentToken());
    }

    public function testCurrentTokenReturnsGetValue(): void
    {
        $_GET['persona_token'] = 'mytoken';
        self::assertSame('mytoken', $this->persona->currentToken());
        unset($_GET['persona_token']);
    }

    public function testCurrentTargetReturnsEmptyWhenNoToken(): void
    {
        unset($_GET['persona_token']);
        self::assertSame('', $this->persona->currentTarget());
    }

    public function testCurrentTargetReturnsTargetWhenValidToken(): void
    {
        $token = $this->persona->createToken('admin@test.com', 'target@test.com');
        $_GET['persona_token'] = $token;
        self::assertSame('target@test.com', $this->persona->currentTarget());
        unset($_GET['persona_token']);
    }

    public function testTokenTtlConstantMatchesExpectedValue(): void
    {
        self::assertSame(28800, PersonaService::TOKEN_TTL);
    }
}
