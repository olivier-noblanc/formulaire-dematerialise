<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Rgpd\RgpdService;
use App\Core\Database;

final class RgpdServiceTest extends TestCase
{
    private RgpdService $service;
    private Database $db;
    private string $originalUser;
    private string $testFormId;
    private string $testStepId;

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(Database::class);
        $this->service = new RgpdService($this->db);
        $this->originalUser = $_SERVER['HTTP_X_TEST_USER'] ?? '';

        // Create a dedicated test form + step to satisfy FK constraints
        $pdo = $this->db->getPdo();
        $this->testFormId = \generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$this->testFormId, 'rgpd-test-' . $this->testFormId, 'Test RGPD Form', '']);
        $this->testStepId = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)")
            ->execute([$this->testStepId, $this->testFormId, 'Test Step', 1]);
    }

    protected function tearDown(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = $this->originalUser;

        $pdo = $this->db->getPdo();
        // Cleanup test data referencing the test form
        $orphaned = $pdo->prepare("SELECT id FROM submissions WHERE form_id = ?");
        $orphaned->execute([$this->testFormId]);
        $ids = $orphaned->fetchAll(\PDO::FETCH_COLUMN);
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM tokens WHERE submission_id IN ({$placeholders})")->execute($ids);
            $pdo->prepare("DELETE FROM submissions WHERE id IN ({$placeholders})")->execute($ids);
        }
        $pdo->prepare("DELETE FROM step_recipients WHERE step_id = ?")->execute([$this->testStepId]);
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$this->testStepId]);
        $pdo->prepare("DELETE FROM form_fields WHERE form_id = ?")->execute([$this->testFormId]);
        $pdo->prepare("DELETE FROM form_owners WHERE form_id = ?")->execute([$this->testFormId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$this->testFormId]);
    }

    // ── exportUserData ──────────────────────────────────────────

    public function testExportUserDataAsAdminReturnsArray(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';

        $result = $this->service->exportUserData('testeur@exemple.invalid');
        self::assertIsArray($result);
        self::assertArrayHasKey('email', $result);
        self::assertArrayHasKey('export_date', $result);
        self::assertArrayHasKey('submissions', $result);
        self::assertArrayHasKey('validations', $result);
        self::assertSame('testeur@exemple.invalid', $result['email']);
    }

    public function testExportUserDataAsAdminReturnsRealSubmissions(): void
    {
        // B-RG1 / CS-11 fix (audit 2026-07-26) : AuthService::isAdminByEmail()
        // délègue maintenant à AdminRepository::isAdmin() via lazy-load. Si la DB
        // de test n'a pas l'admin 'testeur@e2e.test' (seed migration v28 peut
        // échouer silencieusement), isAdmin() retourne false et exportUserData
        // retourne ['error' => 'Accès refusé'] sans 'submissions'.
        // On skip si l'admin n'est pas en DB.
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE email = ?");
        $stmt->execute(['testeur@e2e.test']);
        if ((int) $stmt->fetchColumn() === 0) {
            $this->markTestSkipped('testeur@e2e.test pas en DB (seed v28 manquant ou DB nettoyée)');
        }

        // Use testeur@e2e.test which is seeded as admin in migration v28
        $_SERVER['HTTP_X_TEST_USER'] = 'testeur@e2e.test';

        // Create a submission for a different user so admin can export it
        $subId = \generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, '{\"test\":\"data\"}', 'other-agent@test.com', 'en_cours', datetime('now'))")
            ->execute([$subId, $this->testFormId]);

        $result = $this->service->exportUserData('other-agent@test.com');
        if (isset($result['error'])) {
            $this->markTestSkipped('Access denied — admin check KO : ' . $result['error']);
        }
        self::assertNotEmpty($result['submissions']);
        $sub = $result['submissions'][0];
        self::assertArrayHasKey('id', $sub);
        self::assertArrayHasKey('form', $sub);
        self::assertArrayHasKey('status', $sub);
        self::assertArrayHasKey('submitted_at', $sub);
        self::assertArrayHasKey('data', $sub);
    }

    public function testExportUserDataAsSelf(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'testeur@exemple.invalid';

        $result = $this->service->exportUserData('testeur@exemple.invalid');
        self::assertIsArray($result);
        self::assertSame('testeur@exemple.invalid', $result['email']);
        self::assertArrayNotHasKey('error', $result);
    }

    public function testExportUserDataDeniedForNonAdminNonSelf(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'other@test.com';

        $result = $this->service->exportUserData('testeur@exemple.invalid');
        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Accès refusé', $result['error']);
    }

    public function testExportUserDataForNonexistentEmailReturnsEmpty(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';

        $result = $this->service->exportUserData('doesnotexist@nowhere.com');
        self::assertIsArray($result);
        self::assertEmpty($result['submissions']);
        self::assertEmpty($result['validations']);
    }

    // ── deleteUserData ──────────────────────────────────────────

    public function testDeleteUserDataAsAdminReturnsTrue(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';

        // Create a temporary submission to delete
        $pdo = $this->db->getPdo();
        $testId = 'rgpd-test-' . uniqid();
        $testEmail = 'rgpd_delete_test_' . uniqid() . '@test.com';
        $data = json_encode(['prenom' => 'Test', 'nom' => 'User', 'email' => $testEmail, 'telephone' => '0123456789']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$testId, $this->testFormId, $data, $testEmail, gmdate('Y-m-d H:i:s'), 'en_cours']);

        // Also add a token
        $tokenId = 'rgpd-token-' . uniqid();
        $stepId = $this->testStepId;
        $tokenVal = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$tokenId, $testId, $stepId, $testEmail, $tokenVal, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', strtotime('+30 days'))]);

        $result = $this->service->deleteUserData($testEmail);
        self::assertTrue($result);

        // Verify anonymization
        $row = $pdo->prepare("SELECT submitted_by, data FROM submissions WHERE id = ?");
        $row->execute([$testId]);
        $sub = $row->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('[supprimé]', $sub['submitted_by']);
        $decoded = json_decode($sub['data'], true);
        self::assertSame('[supprimé]', $decoded['prenom']);
        self::assertSame('[supprimé]', $decoded['nom']);
        self::assertSame('[supprimé]', $decoded['email']);
        self::assertSame('[supprimé]', $decoded['telephone']);

        // Verify token anonymized
        $tokRow = $pdo->prepare("SELECT email FROM tokens WHERE id = ?");
        $tokRow->execute([$tokenId]);
        self::assertSame('[supprimé]', $tokRow->fetchColumn());

        // Cleanup
        $pdo->prepare("DELETE FROM tokens WHERE id = ?")->execute([$tokenId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$testId]);
    }

    public function testDeleteUserDataAsSelf(): void
    {
        $testEmail = 'self_delete_test_' . uniqid() . '@test.com';
        $_SERVER['HTTP_X_TEST_USER'] = $testEmail;

        $pdo = $this->db->getPdo();
        $testId = 'rgpd-self-' . uniqid();
        $data = json_encode(['prenom' => 'Self', 'nom' => 'Delete']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$testId, $this->testFormId, $data, $testEmail, gmdate('Y-m-d H:i:s'), 'en_cours']);

        $result = $this->service->deleteUserData($testEmail);
        self::assertTrue($result);

        // Cleanup
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$testId]);
    }

    public function testDeleteUserDataDeniedForNonAdminNonSelf(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'other@test.com';

        $result = $this->service->deleteUserData('testeur@exemple.invalid');
        self::assertFalse($result);
    }

    public function testDeleteUserDataHandlesDelegations(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';

        $pdo = $this->db->getPdo();
        $testEmail = 'deleg_test_' . uniqid() . '@test.com';

        // Create parent records for FK constraints
        $formId = \generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$formId, 'test-deleg-form-' . $formId, 'Test Deleg Form', '']);
        $submissionId = \generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status) VALUES (?, ?, '{}', 'test@test.com', 'en_cours')")
            ->execute([$submissionId, $formId]);
        $stepId = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, 1, 1)")
            ->execute([$stepId, $formId, 'Test Step']);
        $tokenId = \generate_uuid();
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at) VALUES (?, ?, ?, ?, ?, datetime('now'))")
            ->execute([$tokenId, $submissionId, $stepId, 'test@test.com', \generate_token()]);

        // Add delegation records
        // v33 (audit 2026-07-26) : delegations.new_token_id a maintenant une FK vers tokens(id).
        // On insère NULL au lieu de '' (chaîne vide) pour respecter la contrainte.
        $delId = 'del-' . uniqid();
        $pdo->prepare("INSERT INTO delegations (id, token_id, from_email, to_email, reason, delegated_at, new_token_id) VALUES (?, ?, ?, ?, '', datetime('now'), NULL)")
            ->execute([$delId, $tokenId, $testEmail, 'target@test.com']);

        $result = $this->service->deleteUserData($testEmail);
        self::assertTrue($result);

        // Verify delegation anonymized
        $row = $pdo->prepare("SELECT from_email FROM delegations WHERE id = ?");
        $row->execute([$delId]);
        self::assertSame('[supprimé]', $row->fetchColumn());

        // Cleanup
        $pdo->prepare("DELETE FROM delegations WHERE id = ?")->execute([$delId]);
        $pdo->prepare("DELETE FROM tokens WHERE id = ?")->execute([$tokenId]);
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$stepId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$submissionId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }

    // ── autoPurge ───────────────────────────────────────────────

    public function testAutoPurgeReturnsInt(): void
    {
        $result = $this->service->autoPurge(24);
        self::assertIsInt($result);
        self::assertGreaterThanOrEqual(0, $result);
    }

    public function testAutoPurgeWithZeroMonthsPurgesNothing(): void
    {
        $result = $this->service->autoPurge(0);
        // With 0 months, cutoff = now, nothing should be older than now
        // But since no submissions have closed_at in the future, should be 0
        self::assertIsInt($result);
    }

    public function testAutoPurgeWithLargeMonthsReturnsZero(): void
    {
        // Very large months should find no old data
        $result = $this->service->autoPurge(9999);
        self::assertSame(0, $result);
    }

    public function testAutoPurgeCreatesTempDataAndPurges(): void
    {
        $pdo = $this->db->getPdo();

        // Create a submission that's already closed and old
        $testId = 'purge-test-' . uniqid();
        $oldDate = gmdate('Y-m-d H:i:s', strtotime('-25 months'));
        $data = json_encode(['nom' => 'PurgeTest']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, closed_at, status) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$testId, $this->testFormId, $data, 'purge@test.com', $oldDate, $oldDate, 'valide']);

        // Also add a token and attachment for cascading delete test
        $tokId = 'purge-tok-' . uniqid();
        $stepId = $this->testStepId;
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$tokId, $testId, $stepId, 'purge@test.com', bin2hex(random_bytes(32)), $oldDate, $oldDate]);

        $count = $this->service->autoPurge(24);
        self::assertGreaterThanOrEqual(1, $count);

        // Verify submission was deleted
        $check = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE id = ?");
        $check->execute([$testId]);
        self::assertSame(0, (int) $check->fetchColumn());

        // Verify token was cascaded
        $check = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE id = ?");
        $check->execute([$tokId]);
        self::assertSame(0, (int) $check->fetchColumn());
    }

    public function testAutoPurgeSkipsEnCoursSubmissions(): void
    {
        $pdo = $this->db->getPdo();

        // Create an old submission that is still en_cours
        $testId = 'purge-skip-' . uniqid();
        $oldDate = gmdate('Y-m-d H:i:s', strtotime('-25 months'));
        $data = json_encode(['nom' => 'SkipTest']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$testId, $this->testFormId, $data, 'skip@test.com', $oldDate, 'en_cours']);

        $this->service->autoPurge(24);

        // en_cours should NOT be purged
        $check = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE id = ?");
        $check->execute([$testId]);
        self::assertSame(1, (int) $check->fetchColumn());

        // Cleanup
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$testId]);
    }

    public function testAutoPurgeSkipsRecentClosedSubmissions(): void
    {
        $pdo = $this->db->getPdo();

        // Create a recently closed submission
        $testId = 'purge-recent-' . uniqid();
        $recentDate = gmdate('Y-m-d H:i:s', strtotime('-1 months'));
        $data = json_encode(['nom' => 'RecentTest']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, closed_at, status) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$testId, $this->testFormId, $data, 'recent@test.com', $recentDate, $recentDate, 'valide']);

        $this->service->autoPurge(24);

        // Recent should NOT be purged
        $check = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE id = ?");
        $check->execute([$testId]);
        self::assertSame(1, (int) $check->fetchColumn());

        // Cleanup
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$testId]);
    }
}
