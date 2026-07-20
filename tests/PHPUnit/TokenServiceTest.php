<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Token\TokenService;
use App\Core\Database;
use App\Settings\SettingsService;
use App\Repository\SettingsRepository;
use App\Auth\AuthService;
use App\Audit\AuditLogService;
use App\Mail\MailService;
use App\Workflow\WorkflowEngine;
use App\Workflow\ConditionEvaluator;
use App\Forms\FieldService;
use App\Render\HtmlService;

final class TokenServiceTest extends TestCase
{
    private TokenService $tokenService;
    private Database $db;
    private string $originalUser;

    private string $testFormId;
    private string $testStepId;
    private string $testSubmissionId;
    private string $testClosedSubmissionId;
    private string $testPendingTokenId;
    private string $testDoneTokenId;
    private string $testClosedTokenId;
    private string $testTokenEmail;
    private string $testSubmissionOwner;

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(\App\Core\Database::class);
        $settings = new SettingsService(new SettingsRepository($this->db));
        $auth = new AuthService($this->db);
        $audit = new AuditLogService(new \App\Repository\AuditRepository($this->db));
        $mailer = new MailService($this->db, $settings);
        $fields = new FieldService($this->db);
        $conditions = new ConditionEvaluator();
        $workflow = new WorkflowEngine($this->db, $settings, $mailer, $fields, $conditions, new \App\Repository\SubmissionRepository($this->db));

        $this->tokenService = new TokenService(
            $this->db,
            $settings,
            $auth,
            $audit,
            $mailer,
            new \App\Repository\SubmissionRepository($this->db)
        );

        $this->originalUser = $_SERVER['HTTP_X_TEST_USER'] ?? '';

        // Seed test data
        $this->seedTestData();
    }

    protected function tearDown(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = $this->originalUser;
        $this->cleanupTestData();
    }

    private function seedTestData(): void
    {
        $pdo = $this->db->getPdo();

        // Create test form
        $this->testFormId = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif) VALUES (?, ?, ?, ?, 1)")
            ->execute([$this->testFormId, 'test-form-' . uniqid(), 'Test Form', 'Test form for TokenService tests']);

        // Create test step
        $this->testStepId = generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, 1, 1)")
            ->execute([$this->testStepId, $this->testFormId, 'Validation test']);

        // Create step recipient
        $this->testTokenEmail = 'validator_' . uniqid() . '@test.com';
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)")
            ->execute([generate_uuid(), $this->testStepId, $this->testTokenEmail]);

        // Create en_cours submission
        $this->testSubmissionOwner = 'owner_' . uniqid() . '@test.com';
        $this->testSubmissionId = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status, rgpd_consent) VALUES (?, ?, '{}', ?, datetime('now'), 'en_cours', 1)")
            ->execute([$this->testSubmissionId, $this->testFormId, $this->testSubmissionOwner]);

        // Create pending token on en_cours submission
        $this->testPendingTokenId = generate_uuid();
        $pendingTokenValue = generate_token();
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+30 days'));
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), ?)")
            ->execute([$this->testPendingTokenId, $this->testSubmissionId, $this->testStepId, $this->testTokenEmail, $pendingTokenValue, $expiresAt]);

        // Create done token on en_cours submission
        $this->testDoneTokenId = generate_uuid();
        $doneTokenValue = generate_token();
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), datetime('now'), ?)")
            ->execute([$this->testDoneTokenId, $this->testSubmissionId, $this->testStepId, 'done_' . uniqid() . '@test.com', $doneTokenValue, $expiresAt]);

        // Create closed submission
        $this->testClosedSubmissionId = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status, closed_at, rgpd_consent) VALUES (?, ?, '{}', ?, datetime('now'), 'valide', datetime('now'), 1)")
            ->execute([$this->testClosedSubmissionId, $this->testFormId, $this->testSubmissionOwner]);

        // Create pending token on closed submission
        $this->testClosedTokenId = generate_uuid();
        $closedTokenValue = generate_token();
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), ?)")
            ->execute([$this->testClosedTokenId, $this->testClosedSubmissionId, $this->testStepId, 'closed_validator@test.com', $closedTokenValue, $expiresAt]);
    }

    private function cleanupTestData(): void
    {
        $pdo = $this->db->getPdo();

        // Remove test data in reverse order of dependencies
        $pdo->prepare("DELETE FROM tokens WHERE id IN (?, ?, ?)")->execute([
            $this->testPendingTokenId,
            $this->testDoneTokenId,
            $this->testClosedTokenId,
        ]);
        // Also clean up any tokens created during tests (e.g. by regenerate/delegate)
        $pdo->prepare("DELETE FROM tokens WHERE submission_id IN (?, ?)")->execute([
            $this->testSubmissionId,
            $this->testClosedSubmissionId,
        ]);
        $pdo->prepare("DELETE FROM delegations WHERE token_id IN (?, ?, ?)")->execute([
            $this->testPendingTokenId,
            $this->testDoneTokenId,
            $this->testClosedTokenId,
        ]);
        $pdo->prepare("DELETE FROM submissions WHERE id IN (?, ?)")->execute([
            $this->testSubmissionId,
            $this->testClosedSubmissionId,
        ]);
        $pdo->prepare("DELETE FROM step_recipients WHERE step_id = ?")->execute([$this->testStepId]);
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$this->testStepId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$this->testFormId]);
    }

    // ── regenerate ──────────────────────────────────────────────

    public function testRegenerateReturnsErrorForNonAdmin(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'regular_' . uniqid() . '@test.com';
        $result = $this->tokenService->regenerate('nonexistent-token-id');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Accès refusé', $result['message']);
    }

    public function testRegenerateReturnsErrorForNonexistentToken(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';

        $result = $this->tokenService->regenerate('nonexistent-token-id');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('introuvable', $result['message']);
    }

    public function testRegenerateReturnsErrorForAlreadyDoneToken(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';

        $result = $this->tokenService->regenerate($this->testDoneTokenId);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('déjà été traité', $result['message']);
    }

    public function testRegenerateReturnsErrorForClosedSubmission(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';

        $result = $this->tokenService->regenerate($this->testClosedTokenId);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString("n'est plus en cours", $result['message']);
    }

    public function testRegenerateSuccessAsAdmin(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';

        $result = $this->tokenService->regenerate($this->testPendingTokenId);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString($this->testTokenEmail, $result['message']);

        // Verify old token is now done
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT done_at FROM tokens WHERE id = ?");
        $stmt->execute([$this->testPendingTokenId]);
        $this->assertNotNull($stmt->fetchColumn());

        // Verify new token was created
        $newCount = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND id != ?");
        $newCount->execute([$this->testSubmissionId, $this->testDoneTokenId]);
        $this->assertGreaterThanOrEqual(1, (int)$newCount->fetchColumn());
    }

    public function testRegenerateCreatesNewTokenWithCorrectEmail(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';

        $result = $this->tokenService->regenerate($this->testPendingTokenId);
        $this->assertTrue($result['success']);

        // Check the new token exists with the same email
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT email FROM tokens WHERE submission_id = ? AND done_at IS NULL AND id != ?");
        $stmt->execute([$this->testSubmissionId, $this->testDoneTokenId]);
        $email = $stmt->fetchColumn();
        $this->assertSame($this->testTokenEmail, $email);
    }

    // ── cancel ──────────────────────────────────────────────────

    public function testCancelReturnsErrorForNonexistentSubmission(): void
    {
        $result = $this->tokenService->cancel('nonexistent-submission-id', 'test@test.com');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('introuvable', $result['message']);
    }

    public function testCancelReturnsErrorForNonEnCoursSubmission(): void
    {
        $result = $this->tokenService->cancel($this->testClosedSubmissionId, 'admin@test.com');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('en cours', $result['message']);
    }

    public function testCancelReturnsErrorForUnauthorizedNonAdmin(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'unauthorized@test.com';
        $result = $this->tokenService->cancel($this->testSubmissionId, 'unauthorized@test.com');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('autorisé', $result['message']);
    }

    public function testCancelSuccessAsOwner(): void
    {
        $result = $this->tokenService->cancel($this->testSubmissionId, $this->testSubmissionOwner);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('annulée', $result['message']);

        // Verify status changed
        $pdo = $this->db->getPdo();
        $check = $pdo->prepare("SELECT status FROM submissions WHERE id = ?");
        $check->execute([$this->testSubmissionId]);
        $this->assertSame('annule', $check->fetchColumn());
    }

    public function testCancelSetsClosedAt(): void
    {
        $this->tokenService->cancel($this->testSubmissionId, $this->testSubmissionOwner);

        $pdo = $this->db->getPdo();
        $check = $pdo->prepare("SELECT closed_at FROM submissions WHERE id = ?");
        $check->execute([$this->testSubmissionId]);
        $this->assertNotNull($check->fetchColumn());
    }

    public function testCancelMarksTokensAsDone(): void
    {
        $this->tokenService->cancel($this->testSubmissionId, $this->testSubmissionOwner);

        $pdo = $this->db->getPdo();
        $check = $pdo->prepare("SELECT done_at FROM tokens WHERE submission_id = ? AND id = ?");
        $check->execute([$this->testSubmissionId, $this->testPendingTokenId]);
        $this->assertNotNull($check->fetchColumn());
    }

    public function testCancelAddsValidationToData(): void
    {
        $this->tokenService->cancel($this->testSubmissionId, $this->testSubmissionOwner);

        $pdo = $this->db->getPdo();
        $check = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $check->execute([$this->testSubmissionId]);
        $data = json_decode($check->fetchColumn(), true);
        $this->assertArrayHasKey('validations', $data);
        $this->assertNotEmpty($data['validations']);
        $lastValidation = end($data['validations']);
        $this->assertSame('Annulation', $lastValidation['step_label']);
        $this->assertSame('refuser', $lastValidation['action']);
    }

    public function testCancelSuccessAsAdmin(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';

        // Re-create the submission since it may have been cancelled by another test
        $pdo = $this->db->getPdo();
        $newSubId = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status, rgpd_consent) VALUES (?, ?, '{}', ?, datetime('now'), 'en_cours', 1)")
            ->execute([$newSubId, $this->testFormId, 'other_owner@test.com']);

        $result = $this->tokenService->cancel($newSubId, 'admin@test.com');
        $this->assertTrue($result['success']);

        // Cleanup
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$newSubId]);
    }

    // ── remind ──────────────────────────────────────────────────

    public function testRemindReturnsErrorForNonexistentToken(): void
    {
        $result = $this->tokenService->remind('nonexistent-token-id');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('introuvable', $result['message']);
    }

    public function testRemindReturnsErrorForAlreadyDoneToken(): void
    {
        $result = $this->tokenService->remind($this->testDoneTokenId);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('déjà été traité', $result['message']);
    }

    public function testRemindReturnsErrorForClosedSubmission(): void
    {
        $result = $this->tokenService->remind($this->testClosedTokenId);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString("n'est plus en cours", $result['message']);
    }

    public function testRemindSuccessOnPendingToken(): void
    {
        $result = $this->tokenService->remind($this->testPendingTokenId);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString($this->testTokenEmail, $result['message']);
    }

    public function testRemindIncrementsRelanceCount(): void
    {
        $this->tokenService->remind($this->testPendingTokenId);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT relance_count FROM tokens WHERE id = ?");
        $stmt->execute([$this->testPendingTokenId]);
        $count = (int)$stmt->fetchColumn();
        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function testRemindSetsRelanceAt(): void
    {
        $this->tokenService->remind($this->testPendingTokenId);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT relance_at FROM tokens WHERE id = ?");
        $stmt->execute([$this->testPendingTokenId]);
        $this->assertNotNull($stmt->fetchColumn());
    }

    public function testRemindMultipleTimesIncreasesCount(): void
    {
        $this->tokenService->remind($this->testPendingTokenId);
        $this->tokenService->remind($this->testPendingTokenId);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT relance_count FROM tokens WHERE id = ?");
        $stmt->execute([$this->testPendingTokenId]);
        $count = (int)$stmt->fetchColumn();
        $this->assertGreaterThanOrEqual(2, $count);
    }

    // ── delegate ────────────────────────────────────────────────

    public function testDelegateReturnsErrorForNonexistentToken(): void
    {
        $result = $this->tokenService->delegate('nonexistent-token-id', 'target@test.com');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('introuvable', $result['message']);
    }

    public function testDelegateReturnsErrorForInvalidEmail(): void
    {
        $result = $this->tokenService->delegate($this->testPendingTokenId, 'not-an-email');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('invalide', $result['message']);
    }

    public function testDelegateReturnsErrorForAlreadyDoneToken(): void
    {
        $result = $this->tokenService->delegate($this->testDoneTokenId, 'target@test.com');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('déjà été traité', $result['message']);
    }

    public function testDelegateReturnsErrorForSelfDelegation(): void
    {
        $result = $this->tokenService->delegate($this->testPendingTokenId, $this->testTokenEmail);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('vous-même', $result['message']);
    }

    public function testDelegateReturnsErrorForClosedSubmission(): void
    {
        $result = $this->tokenService->delegate($this->testClosedTokenId, 'target@test.com');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString("n'est plus en cours", $result['message']);
    }

    public function testDelegateSuccess(): void
    {
        $toEmail = 'delegate_target_' . uniqid() . '@test.com';
        $result = $this->tokenService->delegate($this->testPendingTokenId, $toEmail, 'Test delegation');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString($toEmail, $result['message']);

        // Verify new token was created
        $pdo = $this->db->getPdo();
        $check = $pdo->prepare("SELECT 1 FROM tokens WHERE submission_id = ? AND email = ? AND done_at IS NULL");
        $check->execute([$this->testSubmissionId, $toEmail]);
        $this->assertNotEmpty($check->fetch());

        // Verify delegation record
        $delCheck = $pdo->prepare("SELECT 1 FROM delegations WHERE token_id = ? AND to_email = ?");
        $delCheck->execute([$this->testPendingTokenId, $toEmail]);
        $this->assertNotEmpty($delCheck->fetch());
    }

    public function testDelegateMarksOldTokenAsDone(): void
    {
        $toEmail = 'delegate_target2_' . uniqid() . '@test.com';
        $this->tokenService->delegate($this->testPendingTokenId, $toEmail);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT done_at FROM tokens WHERE id = ?");
        $stmt->execute([$this->testPendingTokenId]);
        $this->assertNotNull($stmt->fetchColumn());
    }

    public function testDelegateStoresReason(): void
    {
        $toEmail = 'delegate_target3_' . uniqid() . '@test.com';
        $reason = 'Going on vacation';
        $this->tokenService->delegate($this->testPendingTokenId, $toEmail, $reason);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT reason FROM delegations WHERE token_id = ? AND to_email = ?");
        $stmt->execute([$this->testPendingTokenId, $toEmail]);
        $this->assertSame($reason, $stmt->fetchColumn());
    }

    public function testDelegateReturnsErrorForDuplicateActiveToken(): void
    {
        $pdo = $this->db->getPdo();

        // Create a second active token on the same submission/step for another email
        $existingEmail = 'existing_validator_' . uniqid() . '@test.com';
        $existingTokenId = generate_uuid();
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+30 days'));
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), ?)")
            ->execute([$existingTokenId, $this->testSubmissionId, $this->testStepId, $existingEmail, generate_token(), $expiresAt]);

        // Try to delegate our pending token to the email that already has an active token
        $result = $this->tokenService->delegate($this->testPendingTokenId, $existingEmail, 'Conflict');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('déjà actif', $result['message']);

        // Cleanup
        $pdo->prepare("DELETE FROM tokens WHERE id = ?")->execute([$existingTokenId]);
    }

    // ── Bug 1: invalidated_at — regenerate should not pollute findDoneByEmail ──

    public function testRegenerateTokenSetsInvalidatedAtAndExcludedFromDone(): void
    {
        // Create an expired token for our test submission
        $pdo = $this->db->getPdo();
        $expiredTokenId = generate_uuid();
        $expiredToken = generate_token();
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, 'validator@test.com', ?, datetime('now'), datetime('now', '-1 day'))")
            ->execute([$expiredTokenId, $this->testSubmissionId, $this->testStepId, $expiredToken]);

        // Regenerate the expired token
        $result = $this->tokenService->regenerate($expiredTokenId);
        $this->assertTrue($result['success'], 'Regenerate should succeed');

        // The old token should have invalidated_at set
        $check = $pdo->prepare("SELECT invalidated_at FROM tokens WHERE id = ?");
        $check->execute([$expiredTokenId]);
        $row = $check->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotNull($row['invalidated_at'], 'Invalidated token should have invalidated_at set');

        // findDoneByEmail should NOT return the invalidated token
        $tokenRepo = new \App\Repository\TokenRepository($this->db);
        $doneTokens = $tokenRepo->findDoneByEmail('validator@test.com');
        $foundInvalidated = false;
        foreach ($doneTokens as $t) {
            if ($t['token_id'] === $expiredTokenId) {
                $foundInvalidated = true;
                break;
            }
        }
        $this->assertFalse($foundInvalidated, 'findDoneByEmail must not return invalidated tokens');
    }

    public function testRegenerateDoesNotBreakAdvanceWorkflowStepUnblocking(): void
    {
        // Create an expired token, regenerate it, then verify advanceWorkflow
        // still sees the step as "done" (done_at IS NOT NULL still holds)
        $pdo = $this->db->getPdo();
        $expiredTokenId = generate_uuid();
        $expiredToken = generate_token();
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, 'validator@test.com', ?, datetime('now'), datetime('now', '-1 day'))")
            ->execute([$expiredTokenId, $this->testSubmissionId, $this->testStepId, $expiredToken]);

        // Regenerate
        $this->tokenService->regenerate($expiredTokenId);

        // The old token still has done_at set (advanceWorkflow depends on it)
        $check = $pdo->prepare("SELECT done_at FROM tokens WHERE id = ?");
        $check->execute([$expiredTokenId]);
        $doneAt = $check->fetchColumn();
        $this->assertNotEmpty($doneAt, 'Regenerated token must still have done_at set for workflow unblocking');
    }

    // ── Bug 8: delegate() must also set invalidated_at ──────────

    public function testDelegateSetsInvalidatedAtAndExcludedFromDone(): void
    {
        $pdo = $this->db->getPdo();

        // Create a pending token for delegation
        $tokenId = generate_uuid();
        $tokenVal = generate_token();
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, 'validator@test.com', ?, datetime('now'), datetime('now', '+7 days'))")
            ->execute([$tokenId, $this->testSubmissionId, $this->testStepId, $tokenVal]);

        // Delegate to another user
        $result = $this->tokenService->delegate($tokenId, 'delegatee@test.com', 'Test delegation');
        $this->assertTrue($result['success'], 'Delegate should succeed');

        // The delegated token should have invalidated_at set
        $check = $pdo->prepare("SELECT invalidated_at FROM tokens WHERE id = ?");
        $check->execute([$tokenId]);
        $row = $check->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotNull($row['invalidated_at'], 'Delegated token should have invalidated_at set');

        // findDoneByEmail should NOT return the delegated token
        $tokenRepo = new \App\Repository\TokenRepository($this->db);
        $doneTokens = $tokenRepo->findDoneByEmail('validator@test.com');
        $foundDelegated = false;
        foreach ($doneTokens as $t) {
            if ($t['token_id'] === $tokenId) {
                $foundDelegated = true;
                break;
            }
        }
        $this->assertFalse($foundDelegated, 'findDoneByEmail must not return delegated tokens');
    }
}
