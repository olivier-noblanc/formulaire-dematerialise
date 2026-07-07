<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Auth\AuthService;
use App\Core\Database;

final class AuthServiceTest extends TestCase
{
    private AuthService $auth;
    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database();
        $this->auth = new AuthService($this->db);
    }

    public function testGetUserReturnsEmailInTestMode(): void
    {
        $user = $this->auth->getUser();
        $this->assertNotEmpty($user);
        $this->assertStringContainsString('@', $user);
    }

    public function testGetUserFromTestHeader(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'test@example.com';
        $user = $this->auth->getUser();
        $this->assertSame('test@example.com', $user);
    }

    public function testGetUserFromAuthUser(): void
    {
        // Reset test mode user
        unset($_SERVER['HTTP_X_TEST_USER']);
        $_SERVER['AUTH_USER'] = 'DREETS\\test.user';
        $user = $this->auth->getUser();
        $this->assertStringContainsString('test.user', $user);
        $this->assertStringContainsString('@', $user);
    }

    public function testGetEmailDomain(): void
    {
        $domain = $this->auth->getEmailDomain();
        $this->assertNotEmpty($domain);
        $this->assertStringContainsString('.', $domain);
    }

    public function testGetAdminEmail(): void
    {
        $email = $this->auth->getAdminEmail();
        $this->assertNotEmpty($email);
        $this->assertStringContainsString('@', $email);
    }

    public function testIsSuperAdminReturnsBoolean(): void
    {
        $result = $this->auth->isSuperAdmin();
        $this->assertIsBool($result);
    }

    public function testIsAdminReturnsBoolean(): void
    {
        $result = $this->auth->isAdmin();
        $this->assertIsBool($result);
    }

    public function testIsAdminEffectiveReturnsBoolean(): void
    {
        $result = $this->auth->isAdminEffective();
        $this->assertIsBool($result);
    }
}
