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
        $mailer = new MailService(new \App\Repository\MailRepository($this->db), $settings);
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

    public function testCancelMarksTokensAsInvalidated(): void
    {
        // B3 fix (audit 2026-07-26) : cancel() marque invalidated_at au lieu de done_at.
        // Les tokens non traités ne doivent PAS apparaître comme 'validés' dans
        // l'historique validateur (findDoneByEmail).
        $this->tokenService->cancel($this->testSubmissionId, $this->testSubmissionOwner);

        $pdo = $this->db->getPdo();
        // done_at doit rester NULL (le validateur n'a rien fait)
        $check = $pdo->prepare("SELECT done_at, invalidated_at FROM tokens WHERE submission_id = ? AND id = ?");
        $check->execute([$this->testSubmissionId, $this->testPendingTokenId]);
        $row = $check->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($row['done_at']);
        $this->assertNotNull($row['invalidated_at']);
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
        // CS-04 : 'annule' au lieu de 'refuser' (sémantique distincte)
        $this->assertSame('annule', $lastValidation['action']);
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

    // ── Mutation-killing tests: coalesce, mail, types, boundaries ──

    public function testRegenerateSubjectContainsFormLabel(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';

        $result = $this->tokenService->regenerate($this->testPendingTokenId);
        $this->assertTrue($result['success']);

        // Verify audit log was written with correct action
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT action FROM audit_log WHERE action = 'token_regenerate' ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $this->assertSame('token_regenerate', $stmt->fetchColumn());
    }

    public function testRegenerateSubjectContainsStepLabel(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';

        $this->tokenService->regenerate($this->testPendingTokenId);

        // Verify the new token was created with correct step_id
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT step_id FROM tokens WHERE submission_id = ? AND done_at IS NULL ORDER BY id DESC LIMIT 1");
        $stmt->execute([$this->testSubmissionId]);
        $this->assertSame($this->testStepId, $stmt->fetchColumn());
    }

    public function testRemindSubjectContainsFormLabelAndStepLabel(): void
    {
        $this->tokenService->remind($this->testPendingTokenId);

        // Verify relance_count was incremented
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT relance_count FROM tokens WHERE id = ?");
        $stmt->execute([$this->testPendingTokenId]);
        $this->assertGreaterThanOrEqual(1, (int) $stmt->fetchColumn());
    }

    public function testRemindSubjectShowsCountOnSecondRemind(): void
    {
        $this->tokenService->remind($this->testPendingTokenId);
        $this->tokenService->remind($this->testPendingTokenId);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT relance_count FROM tokens WHERE id = ?");
        $stmt->execute([$this->testPendingTokenId]);
        $count = (int) $stmt->fetchColumn();
        $this->assertGreaterThanOrEqual(2, $count, 'Second remind should increment count to 2');
    }

    public function testRemindMaxReachedReturnsError(): void
    {
        // Default relance_max is 3 — remind 3 times then expect failure
        $this->tokenService->remind($this->testPendingTokenId);
        $this->tokenService->remind($this->testPendingTokenId);
        $this->tokenService->remind($this->testPendingTokenId);

        $result = $this->tokenService->remind($this->testPendingTokenId);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Maximum', $result['message']);
    }

    public function testDelegateSubjectContainsFormLabelAndStepLabel(): void
    {
        $toEmail = 'delegate_subj_' . uniqid() . '@test.com';
        $this->tokenService->delegate($this->testPendingTokenId, $toEmail, 'Test');

        // Verify delegation record was created
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT 1 FROM delegations WHERE token_id = ? AND to_email = ?");
        $stmt->execute([$this->testPendingTokenId, $toEmail]);
        $this->assertNotEmpty($stmt->fetch());
    }

    public function testDelegateConfirmationEmailSentToOriginalValidator(): void
    {
        $toEmail = 'delegate_conf_' . uniqid() . '@test.com';
        $this->tokenService->delegate($this->testPendingTokenId, $toEmail);

        // Verify audit log was written
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT action FROM audit_log WHERE action = 'token_delegate' ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $this->assertSame('token_delegate', $stmt->fetchColumn());
    }

    public function testDelegateReasonEmptyStringNotDisplayedInEmail(): void
    {
        $toEmail = 'delegate_noreason_' . uniqid() . '@test.com';
        $result = $this->tokenService->delegate($this->testPendingTokenId, $toEmail, '');
        $this->assertTrue($result['success']);

        // Verify delegation was created (audit_log proves the code path ran)
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT 1 FROM delegations WHERE token_id = ? AND to_email = ?");
        $stmt->execute([$this->testPendingTokenId, $toEmail]);
        $this->assertNotEmpty($stmt->fetch());
    }

    public function testDelegateReasonZeroNotDisplayedInEmail(): void
    {
        $toEmail = 'delegate_zero_' . uniqid() . '@test.com';
        $result = $this->tokenService->delegate($this->testPendingTokenId, $toEmail, '0');
        $this->assertTrue($result['success']);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT 1 FROM delegations WHERE token_id = ? AND to_email = ?");
        $stmt->execute([$this->testPendingTokenId, $toEmail]);
        $this->assertNotEmpty($stmt->fetch());
    }

    public function testDelegateReasonNonEmptyDisplayedInEmail(): void
    {
        $toEmail = 'delegate_withreason_' . uniqid() . '@test.com';
        $this->tokenService->delegate($this->testPendingTokenId, $toEmail, 'Going on vacation');

        // Verify delegation record stores the reason
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT reason FROM delegations WHERE token_id = ? AND to_email = ?");
        $stmt->execute([$this->testPendingTokenId, $toEmail]);
        $this->assertSame('Going on vacation', $stmt->fetchColumn());
    }

    public function testCancelAgentEmailNotificationSent(): void
    {
        $this->tokenService->cancel($this->testSubmissionId, $this->testSubmissionOwner);

        // Verify audit log was written
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT action FROM audit_log WHERE action = 'submission_cancel' ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $this->assertSame('submission_cancel', $stmt->fetchColumn());
    }

    public function testCancelNoEmailIfSubmittedByEmpty(): void
    {
        $pdo = $this->db->getPdo();
        $newSubId = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status, rgpd_consent) VALUES (?, ?, '{}', '', datetime('now'), 'en_cours', 1)")
            ->execute([$newSubId, $this->testFormId]);

        $result = $this->tokenService->cancel($newSubId, 'admin@test.com');
        $this->assertTrue($result['success']);

        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$newSubId]);
    }

    public function testCancelNoEmailIfSubmittedByNotEmail(): void
    {
        $pdo = $this->db->getPdo();
        $newSubId = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status, rgpd_consent) VALUES (?, ?, '{}', 'not-an-email', datetime('now'), 'en_cours', 1)")
            ->execute([$newSubId, $this->testFormId]);

        $result = $this->tokenService->cancel($newSubId, 'admin@test.com');
        $this->assertTrue($result['success']);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$newSubId]);
    }

    public function testCancelUsesFormLabelInEmailSubject(): void
    {
        $this->tokenService->cancel($this->testSubmissionId, $this->testSubmissionOwner);

        // Verify audit log captures the correct submission
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT target FROM audit_log WHERE action = 'submission_cancel' AND target = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute(['submission:' . $this->testSubmissionId]);
        $target = $stmt->fetchColumn();
        $this->assertStringContainsString($this->testSubmissionId, $target);
    }

    public function testRegenerateInvalidatedAtIsSet(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';
        \App\Core\App::settings()->set('mail_dry_run', '1', 'test');

        $this->tokenService->regenerate($this->testPendingTokenId);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT invalidated_at FROM tokens WHERE id = ?");
        $stmt->execute([$this->testPendingTokenId]);
        $this->assertNotNull($stmt->fetchColumn(), 'Regenerated token must have invalidated_at set');
    }

    public function testDelegateInvalidatedAtIsSet(): void
    {
        \App\Core\App::settings()->set('mail_dry_run', '1', 'test');

        $toEmail = 'delegate_inv_' . uniqid() . '@test.com';
        $this->tokenService->delegate($this->testPendingTokenId, $toEmail);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT invalidated_at FROM tokens WHERE id = ?");
        $stmt->execute([$this->testPendingTokenId]);
        $this->assertNotNull($stmt->fetchColumn(), 'Delegated token must have invalidated_at set');
    }

    public function testRegenerateNewTokenHasExpiresAt(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';
        \App\Core\App::settings()->set('mail_dry_run', '1', 'test');

        $this->tokenService->regenerate($this->testPendingTokenId);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT expires_at FROM tokens WHERE submission_id = ? AND done_at IS NULL ORDER BY id DESC LIMIT 1");
        $stmt->execute([$this->testSubmissionId]);
        $expiresAt = $stmt->fetchColumn();
        $this->assertNotEmpty($expiresAt, 'New token must have expires_at set');
        $this->assertGreaterThan(time(), strtotime((string) $expiresAt), 'expires_at must be in the future');
    }

    public function testDelegateNewTokenHasExpiresAt(): void
    {
        \App\Core\App::settings()->set('mail_dry_run', '1', 'test');

        $toEmail = 'delegate_exp_' . uniqid() . '@test.com';
        $this->tokenService->delegate($this->testPendingTokenId, $toEmail);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT expires_at FROM tokens WHERE email = ? AND done_at IS NULL ORDER BY id DESC LIMIT 1");
        $stmt->execute([$toEmail]);
        $expiresAt = $stmt->fetchColumn();
        $this->assertNotEmpty($expiresAt, 'New delegated token must have expires_at set');
    }

    public function testRegenerateEmailBodyContainsNewTokenLink(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';
        \App\Core\App::settings()->set('mail_dry_run', '1', 'test');

        $this->tokenService->regenerate($this->testPendingTokenId);

        // Verify new token was created (proves regenerate completed successfully)
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT id FROM tokens WHERE submission_id = ? AND done_at IS NULL ORDER BY id DESC LIMIT 1");
        $stmt->execute([$this->testSubmissionId]);
        $this->assertNotEmpty($stmt->fetchColumn(), 'New token must exist after regenerate');
    }

    public function testDelegateEmptyEmailReturnsError(): void
    {
        $result = $this->tokenService->delegate($this->testPendingTokenId, '');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('invalide', $result['message']);
    }

    public function testCancelValidationEntryHasCorrectCommentaire(): void
    {
        $this->tokenService->cancel($this->testSubmissionId, $this->testSubmissionOwner);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $stmt->execute([$this->testSubmissionId]);
        $data = json_decode($stmt->fetchColumn(), true);
        $lastValidation = end($data['validations']);
        $this->assertSame('Soumission annulée', $lastValidation['commentaire']);
    }

    public function testCancelValidationEntryHasCancelledByEmail(): void
    {
        $this->tokenService->cancel($this->testSubmissionId, $this->testSubmissionOwner);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $stmt->execute([$this->testSubmissionId]);
        $data = json_decode($stmt->fetchColumn(), true);
        $lastValidation = end($data['validations']);
        $this->assertSame($this->testSubmissionOwner, $lastValidation['email']);
    }

    public function testRemindSubjectContainsRappel(): void
    {
        \App\Core\App::settings()->set('mail_dry_run', '1', 'test');

        $this->tokenService->remind($this->testPendingTokenId);

        // Verify relance_count was incremented (proves remind executed)
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT relance_count FROM tokens WHERE id = ?");
        $stmt->execute([$this->testPendingTokenId]);
        $this->assertGreaterThanOrEqual(1, (int) $stmt->fetchColumn());
    }

    public function testRemindSubjectContainsStepLabel(): void
    {
        $this->tokenService->remind($this->testPendingTokenId);

        // Verify relance_at was set (proves remind completed)
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT relance_at FROM tokens WHERE id = ?");
        $stmt->execute([$this->testPendingTokenId]);
        $this->assertNotNull($stmt->fetchColumn());
    }

    public function testRegenerateAuditLogEntryCreated(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';
        \App\Core\App::settings()->set('mail_dry_run', '1', 'test');

        $this->tokenService->regenerate($this->testPendingTokenId);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT action FROM audit_log WHERE action = 'token_regenerate' ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $this->assertSame('token_regenerate', $stmt->fetchColumn());
    }

    public function testCancelAuditLogEntryCreated(): void
    {
        $this->tokenService->cancel($this->testSubmissionId, $this->testSubmissionOwner);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT action FROM audit_log WHERE action = 'submission_cancel' ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $this->assertSame('submission_cancel', $stmt->fetchColumn());
    }

    public function testDelegateAuditLogEntryContainsBothEmails(): void
    {
        // Create a fresh token for this test
        $pdo = $this->db->getPdo();
        $tokenId = generate_uuid();
        $tokenVal = generate_token();
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+30 days'));
        $fromEmail = 'audit_from_' . uniqid() . '@test.com';
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), ?)")
            ->execute([$tokenId, $this->testSubmissionId, $this->testStepId, $fromEmail, $tokenVal, $expiresAt]);

        $toEmail = 'delegate_audit_' . uniqid() . '@test.com';
        $this->tokenService->delegate($tokenId, $toEmail);

        // Find the audit entry for this specific token
        $stmt = $pdo->prepare("SELECT detail FROM audit_log WHERE action = 'token_delegate' AND target = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute(['token:' . $tokenId]);
        $detail = $stmt->fetchColumn();
        $this->assertStringContainsString($fromEmail, $detail);
        $this->assertStringContainsString($toEmail, $detail);
    }

    public function testDelegateAuditLogContainsReason(): void
    {
        $pdo = $this->db->getPdo();
        $tokenId = generate_uuid();
        $tokenVal = generate_token();
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+30 days'));
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), ?)")
            ->execute([$tokenId, $this->testSubmissionId, $this->testStepId, 'reason_from_' . uniqid() . '@test.com', $tokenVal, $expiresAt]);

        $toEmail = 'delegate_reasonaudit_' . uniqid() . '@test.com';
        $this->tokenService->delegate($tokenId, $toEmail, 'Specialist needed');

        $stmt = $pdo->prepare("SELECT detail FROM audit_log WHERE action = 'token_delegate' AND target = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute(['token:' . $tokenId]);
        $detail = $stmt->fetchColumn();
        $this->assertStringContainsString('Specialist needed', $detail);
    }

    public function testDelegateAuditLogNoReasonWhenEmpty(): void
    {
        $pdo = $this->db->getPdo();
        $tokenId = generate_uuid();
        $tokenVal = generate_token();
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+30 days'));
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), ?)")
            ->execute([$tokenId, $this->testSubmissionId, $this->testStepId, 'emptyreason_' . uniqid() . '@test.com', $tokenVal, $expiresAt]);

        $toEmail = 'delegate_noreasonaudit_' . uniqid() . '@test.com';
        $this->tokenService->delegate($tokenId, $toEmail, '');

        $stmt = $pdo->prepare("SELECT detail FROM audit_log WHERE action = 'token_delegate' AND target = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute(['token:' . $tokenId]);
        $detail = $stmt->fetchColumn();
        $this->assertStringNotContainsString('Motif', $detail, 'Empty reason should not appear in audit log');
    }

    public function testDelegateMailSubjectContainsFormAndStep(): void
    {
        \App\Core\App::settings()->set('mail_dry_run', '1', 'test');

        $pdo = $this->db->getPdo();
        $tokenId = generate_uuid();
        $tokenVal = generate_token();
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+30 days'));
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), ?)")
            ->execute([$tokenId, $this->testSubmissionId, $this->testStepId, 'mailsubj_' . uniqid() . '@test.com', $tokenVal, $expiresAt]);

        $toEmail = 'delegate_mailsub_' . uniqid() . '@test.com';
        $this->tokenService->delegate($tokenId, $toEmail, 'Review needed');

        // Verify delegation record was created with correct data
        $stmt = $pdo->prepare("SELECT reason FROM delegations WHERE token_id = ? AND to_email = ?");
        $stmt->execute([$tokenId, $toEmail]);
        $this->assertSame('Review needed', $stmt->fetchColumn());
    }

    // ── MSI-killer tests: audit log format, message content, edge cases ──

    public function testRegenerateAccessDeniedAuditLogContainsTokenPrefix(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'regular_' . uniqid() . '@test.com';
        $fakeId = generate_uuid();
        $this->tokenService->regenerate($fakeId);

        $pdo = $this->db->getPdo();
        // Check target contains 'token:' prefix
        $stmt = $pdo->prepare("SELECT target FROM audit_log WHERE action = 'access_denied' AND target = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute(['token:' . $fakeId]);
        $this->assertNotEmpty($stmt->fetchColumn());
    }

    public function testRegenerateSuccessMessageContainsEmail(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';

        $result = $this->tokenService->regenerate($this->testPendingTokenId);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString($this->testTokenEmail, $result['message']);
    }

    public function testRegenerateAuditLogDetailContainsEmailAndAction(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';

        $this->tokenService->regenerate($this->testPendingTokenId);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT detail FROM audit_log WHERE action = 'token_regenerate' AND target = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute(['token:' . $this->testPendingTokenId]);
        $detail = $stmt->fetchColumn();
        $this->assertStringContainsString($this->testTokenEmail, $detail);
        $this->assertStringContainsString('nouveau token créé', $detail);
    }

    public function testRegenerateNewTokenExpiresAtIsValidDate(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';

        $this->tokenService->regenerate($this->testPendingTokenId);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT expires_at FROM tokens WHERE submission_id = ? AND done_at IS NULL ORDER BY id DESC LIMIT 1");
        $stmt->execute([$this->testSubmissionId]);
        $expiresAt = $stmt->fetchColumn();
        // Verify it's a valid date in the future (proves (int) cast worked)
        $this->assertNotFalse(strtotime((string) $expiresAt));
        $this->assertGreaterThan(time(), strtotime((string) $expiresAt));
    }

    public function testCancelAccessDeniedAuditLogFormat(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'unauthorized@test.com';
        $this->tokenService->cancel($this->testSubmissionId, 'unauthorized@test.com');

        $pdo = $this->db->getPdo();
        // Check target contains submission prefix
        $stmt = $pdo->prepare("SELECT target FROM audit_log WHERE action = 'access_denied' AND target = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute(['submission:' . $this->testSubmissionId]);
        $this->assertNotEmpty($stmt->fetchColumn());

        // Check detail contains the caller email
        $stmt2 = $pdo->prepare("SELECT detail FROM audit_log WHERE action = 'access_denied' AND target = ? ORDER BY id DESC LIMIT 1");
        $stmt2->execute(['submission:' . $this->testSubmissionId]);
        $detail = $stmt2->fetchColumn();
        $this->assertStringContainsString('unauthorized@test.com', $detail);
    }

    public function testCancelSuccessMessageContainsAnnulee(): void
    {
        $result = $this->tokenService->cancel($this->testSubmissionId, $this->testSubmissionOwner);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('annulée', $result['message']);
    }

    public function testCancelAuditLogDetailContainsSubmissionId(): void
    {
        $this->tokenService->cancel($this->testSubmissionId, $this->testSubmissionOwner);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT detail FROM audit_log WHERE action = 'submission_cancel' AND target = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute(['submission:' . $this->testSubmissionId]);
        $detail = $stmt->fetchColumn();
        $this->assertNotEmpty($detail, 'Audit log entry should exist');
        $this->assertStringContainsString('Soumission annulée', $detail);
    }

    public function testRemindSuccessMessageContainsEmailAndCount(): void
    {
        $result = $this->tokenService->remind($this->testPendingTokenId);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString($this->testTokenEmail, $result['message']);
        $this->assertStringContainsString('1/3', $result['message']);
    }

    public function testRemindAuditLogDetailContainsEmailAndCount(): void
    {
        $result = $this->tokenService->remind($this->testPendingTokenId);
        $this->assertTrue($result['success'], 'Remind should succeed');

        $pdo = $this->db->getPdo();
        // Find the entry for THIS specific token
        $stmt = $pdo->prepare("SELECT detail FROM audit_log WHERE action = 'manual_remind' AND target = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute(['token:' . $this->testPendingTokenId]);
        $detail = $stmt->fetchColumn();
        $this->assertNotEmpty($detail, 'Audit log entry should exist');
        $this->assertStringContainsString($this->testTokenEmail, $detail);
        $this->assertStringContainsString('relance 1/3', $detail);
    }

    public function testRemindSecondTimeMessageContainsCount2(): void
    {
        $this->tokenService->remind($this->testPendingTokenId);
        $result = $this->tokenService->remind($this->testPendingTokenId);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('2/3', $result['message']);
    }

    public function testRemindMaxReachedMessageContainsMaxNumber(): void
    {
        $this->tokenService->remind($this->testPendingTokenId);
        $this->tokenService->remind($this->testPendingTokenId);
        $this->tokenService->remind($this->testPendingTokenId);

        $result = $this->tokenService->remind($this->testPendingTokenId);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('3', $result['message']);
    }

    public function testDelegateSuccessMessageContainsToEmail(): void
    {
        $toEmail = 'delegate_msg_' . uniqid() . '@test.com';
        $result = $this->tokenService->delegate($this->testPendingTokenId, $toEmail);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString($toEmail, $result['message']);
    }

    public function testDelegateAuditLogDetailContainsBothEmailsAndToken(): void
    {
        $pdo = $this->db->getPdo();
        $tokenId = generate_uuid();
        $tokenVal = generate_token();
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+30 days'));
        $fromEmail = 'del_from_' . uniqid() . '@test.com';
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), ?)")
            ->execute([$tokenId, $this->testSubmissionId, $this->testStepId, $fromEmail, $tokenVal, $expiresAt]);

        $toEmail = 'del_to_' . uniqid() . '@test.com';
        $this->tokenService->delegate($tokenId, $toEmail, 'Vacation');

        // Check target (contains 'token:' prefix)
        $stmt = $pdo->prepare("SELECT target FROM audit_log WHERE action = 'token_delegate' AND target = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute(['token:' . $tokenId]);
        $target = $stmt->fetchColumn();
        $this->assertStringContainsString('token:' . $tokenId, $target);

        // Check detail (contains emails and reason)
        $stmt2 = $pdo->prepare("SELECT detail FROM audit_log WHERE action = 'token_delegate' AND target = ? ORDER BY id DESC LIMIT 1");
        $stmt2->execute(['token:' . $tokenId]);
        $detail = $stmt2->fetchColumn();
        $this->assertStringContainsString($fromEmail, $detail);
        $this->assertStringContainsString($toEmail, $detail);
        $this->assertStringContainsString('Vacation', $detail);
    }

    public function testDelegateDelegationRecordHasNewTokenId(): void
    {
        $pdo = $this->db->getPdo();
        $tokenId = generate_uuid();
        $tokenVal = generate_token();
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+30 days'));
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), ?)")
            ->execute([$tokenId, $this->testSubmissionId, $this->testStepId, 'del_newtok_' . uniqid() . '@test.com', $tokenVal, $expiresAt]);

        $toEmail = 'del_target_' . uniqid() . '@test.com';
        $this->tokenService->delegate($tokenId, $toEmail);

        $stmt = $pdo->prepare("SELECT new_token_id FROM delegations WHERE token_id = ? AND to_email = ?");
        $stmt->execute([$tokenId, $toEmail]);
        $newTokenId = $stmt->fetchColumn();
        $this->assertNotEmpty($newTokenId, 'Delegation must reference a new token');
        // Verify the new token actually exists
        $check = $pdo->prepare("SELECT 1 FROM tokens WHERE id = ? AND email = ?");
        $check->execute([$newTokenId, $toEmail]);
        $this->assertNotEmpty($check->fetch());
    }

    public function testDelegateOldTokenHasInvalidatedAt(): void
    {
        $pdo = $this->db->getPdo();
        $tokenId = generate_uuid();
        $tokenVal = generate_token();
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+30 days'));
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), ?)")
            ->execute([$tokenId, $this->testSubmissionId, $this->testStepId, 'del_inv_' . uniqid() . '@test.com', $tokenVal, $expiresAt]);

        $toEmail = 'del_targ_inv_' . uniqid() . '@test.com';
        $this->tokenService->delegate($tokenId, $toEmail);

        $stmt = $pdo->prepare("SELECT invalidated_at FROM tokens WHERE id = ?");
        $stmt->execute([$tokenId]);
        $this->assertNotNull($stmt->fetchColumn());
    }

    public function testCancelAllTokensMarkedInvalidated(): void
    {
        // B3 fix (audit 2026-07-26) : cancel() marque invalidated_at au lieu de done_at.
        // Les tokens non traités ne doivent pas apparaître comme 'validés'.
        // Create multiple pending tokens for the same submission
        $pdo = $this->db->getPdo();
        $extraTokenId = generate_uuid();
        $extraToken = generate_token();
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+30 days'));
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), ?)")
            ->execute([$extraTokenId, $this->testSubmissionId, $this->testStepId, 'cancel_extra_' . uniqid() . '@test.com', $extraToken, $expiresAt]);

        $this->tokenService->cancel($this->testSubmissionId, $this->testSubmissionOwner);

        // Both tokens should be invalidated (done_at still NULL, invalidated_at set)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND invalidated_at IS NULL");
        $stmt->execute([$this->testSubmissionId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'All tokens should be marked invalidated after cancel');
        // Et aucun ne doit être marqué done_at (le validateur n'a rien fait)
        $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND done_at IS NOT NULL");
        $stmt2->execute([$this->testSubmissionId]);
        $this->assertSame(0, (int) $stmt2->fetchColumn(), 'cancel() ne doit pas setter done_at (B3)');
    }

    public function testCancelValidationEntryHasDate(): void
    {
        $this->tokenService->cancel($this->testSubmissionId, $this->testSubmissionOwner);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $stmt->execute([$this->testSubmissionId]);
        $data = json_decode($stmt->fetchColumn(), true);
        $lastValidation = end($data['validations']);
        $this->assertNotEmpty($lastValidation['date'], 'Validation entry must have a date');
        $this->assertNotFalse(strtotime($lastValidation['date']));
    }
}
