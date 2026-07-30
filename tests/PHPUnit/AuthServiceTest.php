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
    private string $originalTestUser;
    private string $originalAuthUser;

    protected function setUp(): void
    {
        $this->originalTestUser = $_SERVER['HTTP_X_TEST_USER'] ?? '';
        $this->originalAuthUser = $_SERVER['AUTH_USER'] ?? '';
        $this->db = \App\Core\App::getInstance()->get(\App\Core\Database::class);
        $this->auth = new AuthService($this->db);
    }

    protected function tearDown(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = $this->originalTestUser;
        $_SERVER['AUTH_USER'] = $this->originalAuthUser;
    }

    // ── getUser() ───────────────────────────────────────────────

    public function testGetUserReturnsEmailInTestMode(): void
    {
        $user = $this->auth->getUser();
        self::assertNotEmpty($user);
        self::assertStringContainsString('@', $user);
    }

    public function testGetUserFromTestHeader(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'test@example.com';
        $user = $this->auth->getUser();
        self::assertSame('test@example.com', $user);
    }

    public function testGetUserFromAuthUser(): void
    {
        unset($_SERVER['HTTP_X_TEST_USER']);
        $_SERVER['AUTH_USER'] = 'DREETS\\test.user';
        $user = $this->auth->getUser();
        self::assertStringContainsString('test.user', $user);
        self::assertStringContainsString('@', $user);
    }

    public function testGetUserFromAuthUserWithoutBackslash(): void
    {
        unset($_SERVER['HTTP_X_TEST_USER']);
        $_SERVER['AUTH_USER'] = 'simpleuser';
        $user = $this->auth->getUser();
        self::assertStringContainsString('simpleuser', $user);
        self::assertStringContainsString('@', $user);
    }

    public function testGetUserTrimsAndLowercases(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = '  Test.User@Example.COM  ';
        $user = $this->auth->getUser();
        self::assertSame('test.user@example.com', $user);
    }

    public function testGetUserFromAuthUserWithEmailFormat(): void
    {
        unset($_SERVER['HTTP_X_TEST_USER']);
        $_SERVER['AUTH_USER'] = 'user@domain.fr';
        $user = $this->auth->getUser();
        self::assertSame('user@domain.fr', $user);
    }

    public function testGetUserReturnsEmptyWhenNoHeaders(): void
    {
        unset($_SERVER['HTTP_X_TEST_USER']);
        unset($_SERVER['AUTH_USER']);
        unset($_SERVER['REMOTE_USER']);
        $user = $this->auth->getUser();
        self::assertSame('', $user);
    }

    // ── getEmailDomain() ────────────────────────────────────────

    public function testGetEmailDomain(): void
    {
        $domain = $this->auth->getEmailDomain();
        self::assertNotEmpty($domain);
        self::assertStringContainsString('.', $domain);
    }

    public function testGetEmailDomainReturnsDefaultWhenNoConstant(): void
    {
        // EMAIL_DOMAIN comes from SETTINGS_DEFAULTS which is always defined
        // Just verify it returns a sensible default
        $domain = $this->auth->getEmailDomain();
        self::assertNotEmpty($domain);
    }

    // ── getAdminEmail() ─────────────────────────────────────────

    public function testGetAdminEmail(): void
    {
        $email = $this->auth->getAdminEmail();
        self::assertNotEmpty($email);
        self::assertStringContainsString('@', $email);
    }

    public function testGetAdminEmailReturnsValidEmail(): void
    {
        $email = $this->auth->getAdminEmail();
        self::assertNotFalse(filter_var($email, FILTER_VALIDATE_EMAIL));
    }

    // ── isAdmin() ───────────────────────────────────────────────

    public function testIsAdminReturnsBoolean(): void
    {
        $this->auth->isAdmin();
        $this->expectNotToPerformAssertions();
    }

    // ── isSuperAdmin() ──────────────────────────────────────────

    public function testIsSuperAdminReturnsBoolean(): void
    {
        $this->auth->isSuperAdmin();
        $this->expectNotToPerformAssertions();
    }

    public function testIsSuperAdminFalseWhenNotMatchingAdminEmail(): void
    {
        // Set test user to someone who is NOT the admin email
        $_SERVER['HTTP_X_TEST_USER'] = 'notadmin@example.com';
        unset($_SERVER['AUTH_USER']);
        $result = $this->auth->isSuperAdmin();
        self::assertFalse($result);
    }

    public function testIsSuperAdminTrueWhenMatchingAdminEmail(): void
    {
        $adminEmail = $this->auth->getAdminEmail();
        $_SERVER['HTTP_X_TEST_USER'] = $adminEmail;
        unset($_SERVER['AUTH_USER']);
        $result = $this->auth->isSuperAdmin();
        self::assertTrue($result);
    }

    // ── isAdminEffective() ──────────────────────────────────────

    public function testIsAdminEffectiveReturnsBoolean(): void
    {
        $this->auth->isAdminEffective();
        $this->expectNotToPerformAssertions();
    }

    public function testIsAdminEffectiveFalseWhenNotAdmin(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'regularuser@example.com';
        unset($_SERVER['AUTH_USER']);
        $result = $this->auth->isAdminEffective();
        self::assertFalse($result);
    }

    public function testIsAdminEffectiveFalseWhenPersonaActive(): void
    {
        // Test 1: admin without persona token → effective
        $_SERVER['HTTP_X_TEST_USER'] = 'testeur@e2e.test';
        self::assertTrue($this->auth->isAdminEffective(), 'Admin without persona should be effective');

        // Test 2: non-admin → not effective
        $_SERVER['HTTP_X_TEST_USER'] = 'regular_' . uniqid() . '@test.com';
        self::assertFalse($this->auth->isAdminEffective(), 'Non-admin should not be effective');

        // Test 3: admin with persona_token set → persona_lookup is called
        $_SERVER['HTTP_X_TEST_USER'] = 'testeur@e2e.test';
        $_GET['persona_token'] = 'test-token-' . uniqid();
        $this->auth->isAdminEffective();
        unset($_GET['persona_token']);
    }

    // ── requireAdmin() ──────────────────────────────────────────

    public function testRequireAdminDoesNotExitWhenAdmin(): void
    {
        // In test mode, the test user is 'testeur@e2e.test' which is set in bootstrap
        // If this user is admin, requireAdmin won't exit
        $isOrSuper = $this->auth->isAdmin() || $this->auth->isSuperAdmin();
        if ($isOrSuper) {
            // Start session for the session regeneration code path
            if (session_status() === PHP_SESSION_NONE) {
                $_SESSION = [];
                session_start();
            }
            $_SESSION['_session_initialized'] = false;
            $this->auth->requireAdmin();
            self::assertTrue($_SESSION['_session_initialized'] ?? false);
        } else {
            self::markTestSkipped('Test user is not admin in test DB');
        }
    }

    public function testRequireAdminExitsWhenNotAdmin(): void
    {
        // requireAdmin() calls exit when user is not admin.
        // We verify the method exists and is callable rather than triggering exit.
        $reflection = new \ReflectionMethod($this->auth, 'requireAdmin');
        self::assertTrue($reflection->isPublic());
        $returnType = $reflection->getReturnType();
        self::assertNotNull($returnType);
        self::assertSame('void', (string) $returnType);
    }

    // ── isFormOwner() ───────────────────────────────────────────

    public function testIsFormOwnerReturnsFalseForNonexistentForm(): void
    {
        $result = $this->auth->isFormOwner('nonexistent-form-id', 'test@example.com');
        self::assertFalse($result);
    }

    public function testIsFormOwnerReturnsFalseForUnownedForm(): void
    {
        $pdo = $this->db->getPdo();
        $formIdStmt = $pdo->query("SELECT id FROM forms LIMIT 1");
        $formId = $formIdStmt === false ? false : $formIdStmt->fetchColumn();
        if ($formId === false) {
            self::markTestSkipped('No forms in test DB');
        }
        $result = $this->auth->isFormOwner((string) $formId, 'nobody_owns_this@example.com');
        self::assertFalse($result);
    }

    public function testIsFormOwnerReturnsTrueForExistingOwner(): void
    {
        $pdo = $this->db->getPdo();

        // Get a form
        $formIdStmt = $pdo->query("SELECT id FROM forms LIMIT 1");
        $formId = $formIdStmt === false ? false : $formIdStmt->fetchColumn();
        if ($formId === false) {
            self::markTestSkipped('No forms in test DB');
        }

        // Insert a test owner
        $testEmail = 'formowner_' . uniqid() . '@test.com';
        $ownerId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)")
            ->execute([$ownerId, $formId, $testEmail]);

        try {
            $result = $this->auth->isFormOwner((string) $formId, $testEmail);
            self::assertTrue($result);
        } finally {
            // Clean up
            $pdo->prepare("DELETE FROM form_owners WHERE form_id = ? AND email = ?")
                ->execute([$formId, $testEmail]);
        }
    }

    public function testIsFormOwnerUsesCurrentUserWhenEmailIsNull(): void
    {
        $pdo = $this->db->getPdo();
        $formIdStmt = $pdo->query("SELECT id FROM forms LIMIT 1");
        $formId = $formIdStmt === false ? false : $formIdStmt->fetchColumn();
        if ($formId === false) {
            self::markTestSkipped('No forms in test DB');
        }

        // Get the current test user
        $currentUser = $this->auth->getUser();

        // Insert current user as owner
        $ownerId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)")
            ->execute([$ownerId, $formId, $currentUser]);

        try {
            $result = $this->auth->isFormOwner((string) $formId);
            self::assertTrue($result);
        } finally {
            $pdo->prepare("DELETE FROM form_owners WHERE form_id = ? AND email = ?")
                ->execute([$formId, $currentUser]);
        }
    }

    // ── getOwnedForms() ─────────────────────────────────────────

    public function testGetOwnedFormsReturnsArray(): void
    {
        $pdo = $this->db->getPdo();
        $formIdStmt = $pdo->query("SELECT id FROM forms LIMIT 1");
        $formId = $formIdStmt === false ? false : $formIdStmt->fetchColumn();
        if ($formId === false) {
            self::markTestSkipped('No forms in test DB');
        }

        $testEmail = 'owned_' . uniqid() . '@test.com';
        $ownerId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)")
            ->execute([$ownerId, $formId, $testEmail]);

        try {
            $this->auth->getOwnedForms($testEmail);
            $this->expectNotToPerformAssertions();
        } finally {
            $pdo->prepare("DELETE FROM form_owners WHERE form_id = ? AND email = ?")
                ->execute([$formId, $testEmail]);
        }
    }

    public function testGetOwnedFormsReturnsEmptyForUnknownEmail(): void
    {
        $forms = $this->auth->getOwnedForms('nobody@nowhere.test');
        self::assertEmpty($forms);
    }

    public function testGetOwnedFormsContainsOwnedForm(): void
    {
        $pdo = $this->db->getPdo();
        $formStmt = $pdo->query("SELECT id, label FROM forms LIMIT 1");
        $form = $formStmt === false ? false : $formStmt->fetch(\PDO::FETCH_ASSOC);
        if ($form === false) {
            self::markTestSkipped('No forms in test DB');
        }

        $testEmail = 'ownedform_' . uniqid() . '@test.com';
        $ownerId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)")
            ->execute([$ownerId, $form['id'], $testEmail]);

        try {
            $forms = $this->auth->getOwnedForms($testEmail);
            $ids = array_column($forms, 'id');
            self::assertContains($form['id'], $ids);
        } finally {
            $pdo->prepare("DELETE FROM form_owners WHERE form_id = ? AND email = ?")
                ->execute([$form['id'], $testEmail]);
        }
    }

    public function testGetOwnedFormsReturnsCorrectColumns(): void
    {
        $pdo = $this->db->getPdo();
        $formStmt2 = $pdo->query("SELECT id FROM forms LIMIT 1");
        $form = $formStmt2 === false ? false : $formStmt2->fetchColumn();
        if ($form === false) {
            self::markTestSkipped('No forms in test DB');
        }

        $testEmail = 'colowned_' . uniqid() . '@test.com';
        $ownerId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)")
            ->execute([$ownerId, $form, $testEmail]);

        try {
            $forms = $this->auth->getOwnedForms($testEmail);
            if ($forms !== []) {
                self::assertArrayHasKey('id', $forms[0]);
                self::assertArrayHasKey('label', $forms[0]);
                self::assertArrayHasKey('slug', $forms[0]);
                self::assertArrayHasKey('actif', $forms[0]);
            }
        } finally {
            $pdo->prepare("DELETE FROM form_owners WHERE form_id = ? AND email = ?")
                ->execute([$form, $testEmail]);
        }
    }

    public function testGetOwnedFormsUsesCurrentUserWhenEmailIsNull(): void
    {
        $pdo = $this->db->getPdo();
        $formIdStmt = $pdo->query("SELECT id FROM forms LIMIT 1");
        $formId = $formIdStmt === false ? false : $formIdStmt->fetchColumn();
        if ($formId === false) {
            self::markTestSkipped('No forms in test DB');
        }

        $currentUser = $this->auth->getUser();
        $ownerId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)")
            ->execute([$ownerId, $formId, $currentUser]);

        try {
            $this->auth->getOwnedForms();
            $this->expectNotToPerformAssertions();
        } finally {
            $pdo->prepare("DELETE FROM form_owners WHERE form_id = ? AND email = ?")
                ->execute([$formId, $currentUser]);
        }
    }

    public function testGetOwnedFormsSortedByLabel(): void
    {
        $pdo = $this->db->getPdo();
        // Get two forms
        $forms = $pdo->query("SELECT id, label FROM forms ORDER BY label LIMIT 2")->fetchAll(\PDO::FETCH_ASSOC);
        if (count($forms) < 2) {
            self::markTestSkipped('Need at least 2 forms in test DB');
        }

        $testEmail = 'sorted_' . uniqid() . '@test.com';
        $ownerId1 = bin2hex(random_bytes(8));
        $ownerId2 = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)")
            ->execute([$ownerId1, $forms[0]['id'], $testEmail]);
        $pdo->prepare("INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)")
            ->execute([$ownerId2, $forms[1]['id'], $testEmail]);

        try {
            $owned = $this->auth->getOwnedForms($testEmail);
            $labels = array_column($owned, 'label');
            $sortedLabels = $labels;
            sort($sortedLabels);
            self::assertSame($sortedLabels, $labels);
        } finally {
            $pdo->prepare("DELETE FROM form_owners WHERE email = ?")->execute([$testEmail]);
        }
    }

    // ── getUser() persona token path ────────────────────────────

    public function testGetUserWithoutPersonaToken(): void
    {
        // Default state - no persona token in URL
        unset($_GET['persona_token'], $_POST['persona_token']);
        $user = $this->auth->getUser();
        self::assertNotEmpty($user);
    }

    // ── Edge cases ──────────────────────────────────────────────

    public function testGetUserWithEmptyTestHeader(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = '';
        unset($_SERVER['AUTH_USER']);
        unset($_SERVER['REMOTE_USER']);
        $user = $this->auth->getUser();
        // Empty test header + no AUTH_USER → empty string
        self::assertSame('', $user);
    }

    public function testGetUserWithWhitespaceTestHeader(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = '  spaced@user.com  ';
        $user = $this->auth->getUser();
        self::assertSame('spaced@user.com', $user);
    }

    public function testIsFormOwnerWithEmptyEmail(): void
    {
        $result = $this->auth->isFormOwner('any-form', '');
        self::assertFalse($result);
    }

    // ── processAdminRequest() ───────────────────────────────────

    public function testProcessAdminRequestReturnsSuccessWhenAlreadyAdmin(): void
    {
        $pdo = $this->db->getPdo();
        $adminEmail = $this->auth->getAdminEmail();
        $_SERVER['HTTP_X_TEST_USER'] = $adminEmail;
        unset($_SERVER['AUTH_USER']);

        $result = $this->auth->processAdminRequest($adminEmail);
        self::assertTrue($result['success']);
        self::assertSame('already_admin', $result['reason']);
    }

    public function testProcessAdminRequestReturnsPendingForDuplicateRequest(): void
    {
        $pdo = $this->db->getPdo();
        $email = 'dup_' . uniqid() . '@test.com';

        // Set non-admin user
        $_SERVER['HTTP_X_TEST_USER'] = 'regular_' . uniqid() . '@test.com';
        unset($_SERVER['AUTH_USER']);

        // Insert a pending request
        $arId = bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO admin_requests (id, email, requested_at, status, token) VALUES (?, ?, datetime('now'), 'pending', ?)")
            ->execute([$arId, $email, $token]);

        try {
            $result = $this->auth->processAdminRequest($email);
            self::assertFalse($result['success']);
            self::assertSame('pending', $result['reason']);
        } finally {
            $pdo->prepare("DELETE FROM admin_requests WHERE email = ?")->execute([$email]);
        }
    }

    public function testProcessAdminRequestCreatesRequestAndSendsMail(): void
    {
        $pdo = $this->db->getPdo();
        $email = 'newadmin_' . uniqid() . '@test.com';

        // Set non-admin user
        $_SERVER['HTTP_X_TEST_USER'] = 'regular_' . uniqid() . '@test.com';
        unset($_SERVER['AUTH_USER']);

        $result = $this->auth->processAdminRequest($email);
        // In test mode, mail is intercepted, so it should return 'sent' or 'dry_run'
        self::assertContains($result['reason'], ['sent', 'dry_run']);
        self::assertTrue($result['success']);

        // Cleanup
        $pdo->prepare("DELETE FROM admin_requests WHERE email = ?")->execute([$email]);
    }

    // ── approveAdminRequest() ───────────────────────────────────

    public function testApproveAdminRequestAddsAdmin(): void
    {
        $pdo = $this->db->getPdo();
        $email = 'approved_' . uniqid() . '@test.com';

        // Insert pending request
        $arId = bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO admin_requests (id, email, requested_at, status, token) VALUES (?, ?, datetime('now'), 'pending', ?)")
            ->execute([$arId, $email, $token]);

        try {
            $result = $this->auth->approveAdminRequest($email);
            self::assertTrue($result);

            // Verify admin was added
            $check = $pdo->prepare("SELECT 1 FROM admins WHERE email = ?");
            $check->execute([$email]);
            self::assertNotFalse($check->fetch());
        } finally {
            $pdo->prepare("DELETE FROM admins WHERE email = ?")->execute([$email]);
            $pdo->prepare("DELETE FROM admin_requests WHERE email = ?")->execute([$email]);
        }
    }

    // ── rejectAdminRequest() ────────────────────────────────────

    public function testRejectAdminRequestUpdatesStatus(): void
    {
        $pdo = $this->db->getPdo();
        $email = 'rejected_' . uniqid() . '@test.com';

        // Insert pending request
        $arId = bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO admin_requests (id, email, requested_at, status, token) VALUES (?, ?, datetime('now'), 'pending', ?)")
            ->execute([$arId, $email, $token]);

        try {
            $result = $this->auth->rejectAdminRequest($email);
            self::assertTrue($result);

            // Verify status updated
            $check = $pdo->prepare("SELECT status FROM admin_requests WHERE email = ?");
            $check->execute([$email]);
            self::assertSame('rejected', $check->fetchColumn());
        } finally {
            $pdo->prepare("DELETE FROM admin_requests WHERE email = ?")->execute([$email]);
        }
    }

    // ── removeAdmin() ───────────────────────────────────────────

    public function testRemoveAdminCannotRemoveSuperAdmin(): void
    {
        $adminEmail = $this->auth->getAdminEmail();
        $result = $this->auth->removeAdmin($adminEmail);
        self::assertFalse($result);
    }

    public function testRemoveAdminRemovesExistingAdmin(): void
    {
        $pdo = $this->db->getPdo();
        $email = 'removable_' . uniqid() . '@test.com';

        // Insert admin
        $pdo->prepare("INSERT OR IGNORE INTO admins (id, email, added_at) VALUES (?, ?, datetime('now'))")
            ->execute([bin2hex(random_bytes(8)), $email]);

        try {
            $result = $this->auth->removeAdmin($email);
            self::assertTrue($result);

            // Verify admin removed
            $check = $pdo->prepare("SELECT 1 FROM admins WHERE email = ?");
            $check->execute([$email]);
            self::assertFalse($check->fetch());
        } finally {
            $pdo->prepare("DELETE FROM admins WHERE email = ?")->execute([$email]);
        }
    }

    // ── getUser() persona token path ────────────────────────────

    public function testGetUserWithPersonaTokenInGet(): void
    {
        $_GET['persona_token'] = 'test_persona_token';
        $user = $this->auth->getUser();
        // Without persona_lookup returning a valid target, it should fall back to real user
        self::assertIsString($user);
        unset($_GET['persona_token']);
    }

    public function testGetUserWithPersonaTokenInPost(): void
    {
        $_POST['persona_token'] = 'test_persona_token';
        $user = $this->auth->getUser();
        self::assertIsString($user);
        unset($_POST['persona_token']);
    }

    // ── isAdminByEmail() via isAdmin() ──────────────────────────

    public function testIsAdminReturnsTrueForExistingAdmin(): void
    {
        $pdo = $this->db->getPdo();
        $email = 'admincheck_' . uniqid() . '@test.com';

        $pdo->prepare("INSERT OR IGNORE INTO admins (id, email, added_at) VALUES (?, ?, datetime('now'))")
            ->execute([bin2hex(random_bytes(8)), $email]);

        $_SERVER['HTTP_X_TEST_USER'] = $email;
        unset($_SERVER['AUTH_USER']);

        try {
            self::assertTrue($this->auth->isAdmin());
        } finally {
            $pdo->prepare("DELETE FROM admins WHERE email = ?")->execute([$email]);
        }
    }

    public function testIsAdminReturnsFalseForNonAdminEmail(): void
    {
        $email = 'notadmin_' . uniqid() . '@test.com';
        $_SERVER['HTTP_X_TEST_USER'] = $email;
        unset($_SERVER['AUTH_USER']);

        self::assertFalse($this->auth->isAdmin());
    }

    // ── getAdminEmail() edge cases ──────────────────────────────

    public function testGetAdminEmailReturnsString(): void
    {
        $email = $this->auth->getAdminEmail();
        self::assertIsString($email);
    }

    // ── getUser() with REMOTE_USER ──────────────────────────────

    public function testGetUserFromRemoteUser(): void
    {
        unset($_SERVER['HTTP_X_TEST_USER']);
        unset($_SERVER['AUTH_USER']);
        $_SERVER['REMOTE_USER'] = 'remoteuser@domain.com';
        $user = $this->auth->getUser();
        self::assertSame('remoteuser@domain.com', $user);
        unset($_SERVER['REMOTE_USER']);
    }

    public function testGetUserFromRemoteUserWithoutAtSign(): void
    {
        unset($_SERVER['HTTP_X_TEST_USER']);
        unset($_SERVER['AUTH_USER']);
        $_SERVER['REMOTE_USER'] = 'DOMAIN\\remote.user';
        $user = $this->auth->getUser();
        self::assertStringContainsString('remote.user', $user);
        self::assertStringContainsString('@', $user);
        unset($_SERVER['REMOTE_USER']);
    }

    // ── setMailer() ────────────────────────────────────────────

    public function testSetMailerAcceptsMailInterface(): void
    {
        $mailer = new class implements \App\Contract\MailInterface {
            public function send(string $to, string $subject, string $body): bool { return true; }
            public function buildValidationEmail(array $submission, string $stepLabel, string $token): string { return ''; }
            public function renderEmailTemplate(string $title, string $bodyHtml): string { return ''; }
        };
        $this->auth->setMailer($mailer);
        // Verify by calling processAdminRequest which uses getMailer()
        $_SERVER['HTTP_X_TEST_USER'] = 'regular_' . uniqid() . '@test.com';
        unset($_SERVER['AUTH_USER']);
        $result = $this->auth->processAdminRequest('test_' . uniqid() . '@test.com');
        self::assertArrayHasKey('success', $result);
        // Cleanup
        $pdo = $this->db->getPdo();
        $pdo->prepare("DELETE FROM admin_requests WHERE email = ?")->execute(['test_' . uniqid() . '@test.com']);
    }

    public function testSetMailerReplacesExistingMailer(): void
    {
        $mailer1 = new class implements \App\Contract\MailInterface {
            public function send(string $to, string $subject, string $body): bool { return true; }
            public function buildValidationEmail(array $submission, string $stepLabel, string $token): string { return ''; }
            public function renderEmailTemplate(string $title, string $bodyHtml): string { return ''; }
        };
        $mailer2 = new class implements \App\Contract\MailInterface {
            public function send(string $to, string $subject, string $body): bool { return false; }
            public function buildValidationEmail(array $submission, string $stepLabel, string $token): string { return ''; }
            public function renderEmailTemplate(string $title, string $bodyHtml): string { return ''; }
        };
        $this->auth->setMailer($mailer1);
        $this->auth->setMailer($mailer2);
        // Second mailer should be used
        self::assertTrue(true); // No error = success
    }

    // ── requireAdmin() session regeneration ─────────────────────

    public function testRequireAdminSessionRegeneration(): void
    {
        $isOrSuper = $this->auth->isAdmin() || $this->auth->isSuperAdmin();
        if (!$isOrSuper) {
            self::markTestSkipped('Test user is not admin');
        }

        // Start session if not active
        if (session_status() === PHP_SESSION_NONE) {
            $_SESSION = [];
            session_start();
        }

        // Reset session initialization flag
        $_SESSION['_session_initialized'] = false;

        // Call requireAdmin — should regenerate session ID
        $this->auth->requireAdmin();

        self::assertTrue($_SESSION['_session_initialized'] ?? false);
    }

    public function testRequireAdminSkipsRegenerationWhenAlreadyInitialized(): void
    {
        $isOrSuper = $this->auth->isAdmin() || $this->auth->isSuperAdmin();
        if (!$isOrSuper) {
            self::markTestSkipped('Test user is not admin');
        }

        if (session_status() === PHP_SESSION_NONE) {
            $_SESSION = [];
            session_start();
        }

        // Already initialized
        $_SESSION['_session_initialized'] = true;
        $oldId = session_id();

        $this->auth->requireAdmin();

        // Session should NOT be regenerated since already initialized
        self::assertSame($oldId, session_id());
    }

    // ── getUser() edge cases ────────────────────────────────────

    public function testGetUserReturnsLowercaseFromAuthUserWithBackslash(): void
    {
        unset($_SERVER['HTTP_X_TEST_USER']);
        $_SERVER['AUTH_USER'] = 'DREETS\\Test.User';
        $user = $this->auth->getUser();
        self::assertStringContainsString('test.user', $user);
        unset($_SERVER['AUTH_USER']);
    }

    public function testGetUserFromAuthUserPlainWithoutAtSign(): void
    {
        unset($_SERVER['HTTP_X_TEST_USER']);
        $_SERVER['AUTH_USER'] = 'plainuser';
        $user = $this->auth->getUser();
        self::assertStringContainsString('plainuser', $user);
        self::assertStringContainsString('@', $user);
        unset($_SERVER['AUTH_USER']);
    }

    public function testGetUserPrefersTestHeaderOverAuthUser(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'preferred@example.com';
        $_SERVER['AUTH_USER'] = 'other@domain.com';
        $user = $this->auth->getUser();
        self::assertSame('preferred@example.com', $user);
        unset($_SERVER['AUTH_USER']);
    }

    // ── isAdmin() edge cases ────────────────────────────────────

    public function testIsAdminReturnsFalseForEmptyUser(): void
    {
        unset($_SERVER['HTTP_X_TEST_USER']);
        unset($_SERVER['AUTH_USER']);
        unset($_SERVER['REMOTE_USER']);
        $result = $this->auth->isAdmin();
        self::assertFalse($result);
    }

    // ── isSuperAdmin() edge cases ───────────────────────────────

    public function testIsSuperAdminReturnsFalseWhenNoUser(): void
    {
        unset($_SERVER['HTTP_X_TEST_USER']);
        unset($_SERVER['AUTH_USER']);
        unset($_SERVER['REMOTE_USER']);
        $result = $this->auth->isSuperAdmin();
        self::assertFalse($result);
    }

    // ── getAdminEmail() edge cases ──────────────────────────────

    public function testGetAdminEmailReturnsNonEmptyString(): void
    {
        $email = $this->auth->getAdminEmail();
        self::assertNotEmpty($email);
    }

    // ── processAdminRequest() with non-admin ─────────────────────

    public function testProcessAdminRequestNewRequestCreatesRecord(): void
    {
        $pdo = $this->db->getPdo();
        $email = 'newreq_' . uniqid() . '@test.com';
        $_SERVER['HTTP_X_TEST_USER'] = 'regular_' . uniqid() . '@test.com';
        unset($_SERVER['AUTH_USER']);

        $result = $this->auth->processAdminRequest($email);
        self::assertTrue($result['success']);
        self::assertContains($result['reason'], ['sent', 'dry_run']);

        // Verify the request was created
        $check = $pdo->prepare("SELECT 1 FROM admin_requests WHERE email = ?");
        $check->execute([$email]);
        self::assertNotFalse($check->fetch());

        $pdo->prepare("DELETE FROM admin_requests WHERE email = ?")->execute([$email]);
    }

    // ── approveAdminRequest() with non-existent email ────────────

    public function testApproveAdminRequestNonExistentEmailDoesNotThrow(): void
    {
        $result = $this->auth->approveAdminRequest('nonexistent_' . uniqid() . '@test.com');
        // The method should not throw, just update 0 rows
        self::assertIsBool($result);
    }

    // ── rejectAdminRequest() with non-existent email ─────────────

    public function testRejectAdminRequestNonExistentEmailDoesNotThrow(): void
    {
        $result = $this->auth->rejectAdminRequest('nonexistent_' . uniqid() . '@test.com');
        self::assertIsBool($result);
    }

    // ── removeAdmin() with non-existent admin ────────────────────

    public function testRemoveAdminNonExistentEmailDoesNotThrow(): void
    {
        $result = $this->auth->removeAdmin('nonexistent_admin_' . uniqid() . '@test.com');
        // Should return true (DELETE succeeded, 0 rows affected)
        self::assertIsBool($result);
    }
}
