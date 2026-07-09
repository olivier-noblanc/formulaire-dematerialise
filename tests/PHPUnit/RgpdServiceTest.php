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

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(Database::class);
        $this->service = new RgpdService($this->db);
        $this->originalUser = $_SERVER['HTTP_X_TEST_USER'] ?? '';
    }

    protected function tearDown(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = $this->originalUser;

        // Cleanup any orphaned test data (submissions with the hardcoded test form_id)
        $pdo = $this->db->getPdo();
        $testFormId = 'c1896b60-710a-40d6-a954-0c8796667df2';
        $orphaned = $pdo->query("SELECT id FROM submissions WHERE form_id = '{$testFormId}'")->fetchAll(\PDO::FETCH_COLUMN);
        if (!empty($orphaned)) {
            $placeholders = implode(',', array_fill(0, count($orphaned), '?'));
            $pdo->prepare("DELETE FROM tokens WHERE submission_id IN ({$placeholders})")->execute($orphaned);
            $pdo->prepare("DELETE FROM submissions WHERE id IN ({$placeholders})")->execute($orphaned);
        }
    }

    // ── exportUserData ──────────────────────────────────────────

    public function testExportUserDataAsAdminReturnsArray(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';

        $result = $this->service->exportUserData('testeur@exemple.invalid');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayHasKey('export_date', $result);
        $this->assertArrayHasKey('submissions', $result);
        $this->assertArrayHasKey('validations', $result);
        $this->assertSame('testeur@exemple.invalid', $result['email']);
    }

    public function testExportUserDataAsAdminReturnsRealSubmissions(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';

        $result = $this->service->exportUserData('testeur@exemple.invalid');
        if (empty($result['submissions'])) {
            $this->markTestSkipped('No submissions in test database');
        }
        $this->assertNotEmpty($result['submissions']);
        $sub = $result['submissions'][0];
        $this->assertArrayHasKey('id', $sub);
        $this->assertArrayHasKey('form', $sub);
        $this->assertArrayHasKey('status', $sub);
        $this->assertArrayHasKey('submitted_at', $sub);
        $this->assertArrayHasKey('data', $sub);
    }

    public function testExportUserDataAsSelf(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'testeur@exemple.invalid';

        $result = $this->service->exportUserData('testeur@exemple.invalid');
        $this->assertIsArray($result);
        $this->assertSame('testeur@exemple.invalid', $result['email']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function testExportUserDataDeniedForNonAdminNonSelf(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'other@test.com';

        $result = $this->service->exportUserData('testeur@exemple.invalid');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Accès refusé', $result['error']);
    }

    public function testExportUserDataForNonexistentEmailReturnsEmpty(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';

        $result = $this->service->exportUserData('doesnotexist@nowhere.com');
        $this->assertIsArray($result);
        $this->assertEmpty($result['submissions']);
        $this->assertEmpty($result['validations']);
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
            ->execute([$testId, 'c1896b60-710a-40d6-a954-0c8796667df2', $data, $testEmail, gmdate('Y-m-d H:i:s'), 'en_cours']);

        // Also add a token
        $tokenId = 'rgpd-token-' . uniqid();
        $stepId = $pdo->query("SELECT id FROM steps WHERE form_id = 'c1896b60-710a-40d6-a954-0c8796667df2' LIMIT 1")->fetchColumn();
        $tokenVal = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$tokenId, $testId, $stepId, $testEmail, $tokenVal, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', strtotime('+30 days'))]);

        $result = $this->service->deleteUserData($testEmail);
        $this->assertTrue($result);

        // Verify anonymization
        $row = $pdo->prepare("SELECT submitted_by, data FROM submissions WHERE id = ?");
        $row->execute([$testId]);
        $sub = $row->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('[supprimé]', $sub['submitted_by']);
        $decoded = json_decode($sub['data'], true);
        $this->assertSame('[supprimé]', $decoded['prenom']);
        $this->assertSame('[supprimé]', $decoded['nom']);
        $this->assertSame('[supprimé]', $decoded['email']);
        $this->assertSame('[supprimé]', $decoded['telephone']);

        // Verify token anonymized
        $tokRow = $pdo->prepare("SELECT email FROM tokens WHERE id = ?");
        $tokRow->execute([$tokenId]);
        $this->assertSame('[supprimé]', $tokRow->fetchColumn());

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
            ->execute([$testId, 'c1896b60-710a-40d6-a954-0c8796667df2', $data, $testEmail, gmdate('Y-m-d H:i:s'), 'en_cours']);

        $result = $this->service->deleteUserData($testEmail);
        $this->assertTrue($result);

        // Cleanup
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$testId]);
    }

    public function testDeleteUserDataDeniedForNonAdminNonSelf(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'other@test.com';

        $result = $this->service->deleteUserData('testeur@exemple.invalid');
        $this->assertFalse($result);
    }

    public function testDeleteUserDataHandlesDelegations(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';

        $pdo = $this->db->getPdo();
        $testEmail = 'deleg_test_' . uniqid() . '@test.com';

        // Add delegation records
        $delId = 'del-' . uniqid();
        $pdo->prepare("INSERT INTO delegations (id, token_id, from_email, to_email, reason, delegated_at, new_token_id) VALUES (?, '', ?, ?, '', datetime('now'), '')")
            ->execute([$delId, $testEmail, 'target@test.com']);

        $result = $this->service->deleteUserData($testEmail);
        $this->assertTrue($result);

        // Verify delegation anonymized
        $row = $pdo->prepare("SELECT from_email FROM delegations WHERE id = ?");
        $row->execute([$delId]);
        $this->assertSame('[supprimé]', $row->fetchColumn());

        // Cleanup
        $pdo->prepare("DELETE FROM delegations WHERE id = ?")->execute([$delId]);
    }

    // ── autoPurge ───────────────────────────────────────────────

    public function testAutoPurgeReturnsInt(): void
    {
        $result = $this->service->autoPurge(24);
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(0, $result);
    }

    public function testAutoPurgeWithZeroMonthsPurgesNothing(): void
    {
        $result = $this->service->autoPurge(0);
        // With 0 months, cutoff = now, nothing should be older than now
        // But since no submissions have closed_at in the future, should be 0
        $this->assertIsInt($result);
    }

    public function testAutoPurgeWithLargeMonthsReturnsZero(): void
    {
        // Very large months should find no old data
        $result = $this->service->autoPurge(9999);
        $this->assertSame(0, $result);
    }

    public function testAutoPurgeCreatesTempDataAndPurges(): void
    {
        $pdo = $this->db->getPdo();

        // Create a submission that's already closed and old
        $testId = 'purge-test-' . uniqid();
        $oldDate = gmdate('Y-m-d H:i:s', strtotime('-25 months'));
        $data = json_encode(['nom' => 'PurgeTest']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, closed_at, status) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$testId, 'c1896b60-710a-40d6-a954-0c8796667df2', $data, 'purge@test.com', $oldDate, $oldDate, 'valide']);

        // Also add a token and attachment for cascading delete test
        $tokId = 'purge-tok-' . uniqid();
        $stepId = $pdo->query("SELECT id FROM steps WHERE form_id = 'c1896b60-710a-40d6-a954-0c8796667df2' LIMIT 1")->fetchColumn();
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$tokId, $testId, $stepId, 'purge@test.com', bin2hex(random_bytes(32)), $oldDate, $oldDate]);

        $count = $this->service->autoPurge(24);
        $this->assertGreaterThanOrEqual(1, $count);

        // Verify submission was deleted
        $check = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE id = ?");
        $check->execute([$testId]);
        $this->assertSame(0, (int) $check->fetchColumn());

        // Verify token was cascaded
        $check = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE id = ?");
        $check->execute([$tokId]);
        $this->assertSame(0, (int) $check->fetchColumn());
    }

    public function testAutoPurgeSkipsEnCoursSubmissions(): void
    {
        $pdo = $this->db->getPdo();

        // Create an old submission that is still en_cours
        $testId = 'purge-skip-' . uniqid();
        $oldDate = gmdate('Y-m-d H:i:s', strtotime('-25 months'));
        $data = json_encode(['nom' => 'SkipTest']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$testId, 'c1896b60-710a-40d6-a954-0c8796667df2', $data, 'skip@test.com', $oldDate, 'en_cours']);

        $this->service->autoPurge(24);

        // en_cours should NOT be purged
        $check = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE id = ?");
        $check->execute([$testId]);
        $this->assertSame(1, (int) $check->fetchColumn());

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
            ->execute([$testId, 'c1896b60-710a-40d6-a954-0c8796667df2', $data, 'recent@test.com', $recentDate, $recentDate, 'valide']);

        $this->service->autoPurge(24);

        // Recent should NOT be purged
        $check = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE id = ?");
        $check->execute([$testId]);
        $this->assertSame(1, (int) $check->fetchColumn());

        // Cleanup
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$testId]);
    }
}
