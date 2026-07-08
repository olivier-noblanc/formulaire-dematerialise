<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Token\TokenService;
use App\Core\Database;
use App\Settings\SettingsService;
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

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(\App\Core\Database::class);
        $settings = new SettingsService($this->db);
        $auth = new AuthService($this->db);
        $audit = new AuditLogService($this->db);
        $mailer = new MailService($this->db, $settings);
        $fields = new FieldService($this->db);
        $conditions = new ConditionEvaluator();
        $workflow = new WorkflowEngine($this->db, $settings, $mailer, $fields, $conditions);

        $this->tokenService = new TokenService(
            $this->db,
            $settings,
            $auth,
            $audit,
            $mailer,
            $workflow
        );

        $this->originalUser = $_SERVER['HTTP_X_TEST_USER'] ?? '';
    }

    protected function tearDown(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = $this->originalUser;
    }

    // ── getForSubmission ────────────────────────────────────────

    public function testGetTokensForSubmissionReturnsArray(): void
    {
        $tokens = $this->tokenService->getForSubmission('nonexistent-id');
        $this->assertIsArray($tokens);
        $this->assertEmpty($tokens);
    }

    public function testGetForSubmissionWithExtraFields(): void
    {
        $tokens = $this->tokenService->getForSubmission('nonexistent', ['t.id', 't.token']);
        $this->assertIsArray($tokens);
    }

    public function testGetForSubmissionFiltersDisallowedFields(): void
    {
        // Extra fields not in the allowed list should be filtered out
        $tokens = $this->tokenService->getForSubmission('nonexistent', ['t.id', 'nonexistent_column']);
        $this->assertIsArray($tokens);
    }

    public function testGetForSubmissionWithRealData(): void
    {
        $pdo = $this->db->getPdo();
        $submissionId = $pdo->query("SELECT submission_id FROM tokens LIMIT 1")->fetchColumn();

        if (!$submissionId) {
            $this->markTestSkipped('No tokens available');
        }

        $tokens = $this->tokenService->getForSubmission($submissionId);
        $this->assertNotEmpty($tokens);
        $this->assertArrayHasKey('email', $tokens[0]);
        $this->assertArrayHasKey('done_at', $tokens[0]);
        $this->assertArrayHasKey('step_id', $tokens[0]);
    }

    // ── regenerate ──────────────────────────────────────────────

    public function testRegenerateReturnsErrorForNonAdmin(): void
    {
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

        $pdo = $this->db->getPdo();
        // Find a token that's already done
        $doneToken = $pdo->query("SELECT t.id FROM tokens t WHERE t.done_at IS NOT NULL LIMIT 1")->fetchColumn();

        if (!$doneToken) {
            $this->markTestSkipped('No done token available');
        }

        $result = $this->tokenService->regenerate($doneToken);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('déjà été traité', $result['message']);
    }

    public function testRegenerateReturnsErrorForClosedSubmission(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';

        $pdo = $this->db->getPdo();
        // Find a token on a closed submission
        $row = $pdo->query("SELECT t.id FROM tokens t JOIN submissions s ON s.id = t.submission_id WHERE s.status != 'en_cours' AND t.done_at IS NULL LIMIT 1")->fetch();

        if (!$row) {
            $this->markTestSkipped('No token on closed submission available');
        }

        $result = $this->tokenService->regenerate($row['id']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString("n'est plus en cours", $result['message']);
    }

    public function testRegenerateSuccessAsAdmin(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';

        $pdo = $this->db->getPdo();
        // Find a pending token on an en_cours submission
        $row = $pdo->query("SELECT t.id, t.email FROM tokens t JOIN submissions s ON s.id = t.submission_id WHERE s.status = 'en_cours' AND t.done_at IS NULL LIMIT 1")->fetch();

        if (!$row) {
            $this->markTestSkipped('No pending token on en_cours submission available');
        }

        $result = $this->tokenService->regenerate($row['id']);
        // Mail may or may not succeed in TEST_MODE, but regenerate should succeed
        $this->assertTrue($result['success']);
        $this->assertStringContainsString($row['email'], $result['message']);
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
        $pdo = $this->db->getPdo();
        $refused = $pdo->query("SELECT id FROM submissions WHERE status = 'refuse' LIMIT 1")->fetchColumn();

        if (!$refused) {
            $this->markTestSkipped('No non-en_cours submission available');
        }

        $result = $this->tokenService->cancel($refused, 'admin@test.com');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('en cours', $result['message']);
    }

    public function testCancelReturnsErrorForUnauthorizedNonAdmin(): void
    {
        $pdo = $this->db->getPdo();
        $sub = $pdo->query("SELECT id, submitted_by FROM submissions WHERE status = 'en_cours' LIMIT 1")->fetch(\PDO::FETCH_ASSOC);

        if (!$sub) {
            $this->markTestSkipped('No en_cours submission available');
        }

        $result = $this->tokenService->cancel($sub['id'], 'unauthorized@test.com');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('autorisé', $result['message']);
    }

    public function testCancelSuccessAsOwner(): void
    {
        $pdo = $this->db->getPdo();
        $sub = $pdo->query("SELECT id, submitted_by FROM submissions WHERE status = 'en_cours' LIMIT 1")->fetch(\PDO::FETCH_ASSOC);

        if (!$sub) {
            $this->markTestSkipped('No en_cours submission available');
        }

        $result = $this->tokenService->cancel($sub['id'], $sub['submitted_by']);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('annulée', $result['message']);

        // Verify status changed
        $check = $pdo->prepare("SELECT status FROM submissions WHERE id = ?");
        $check->execute([$sub['id']]);
        $this->assertSame('annule', $check->fetchColumn());
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
        $pdo = $this->db->getPdo();
        $doneTokenId = $pdo->query("SELECT t.id FROM tokens t WHERE t.done_at IS NOT NULL LIMIT 1")->fetchColumn();

        if (!$doneTokenId) {
            $this->markTestSkipped('No done token available');
        }

        $result = $this->tokenService->remind($doneTokenId);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('déjà été traité', $result['message']);
    }

    public function testRemindReturnsErrorForClosedSubmission(): void
    {
        $pdo = $this->db->getPdo();
        $row = $pdo->query("SELECT t.id FROM tokens t JOIN submissions s ON s.id = t.submission_id WHERE s.status != 'en_cours' AND t.done_at IS NULL LIMIT 1")->fetch();

        if (!$row) {
            $this->markTestSkipped('No token on closed submission available');
        }

        $result = $this->tokenService->remind($row['id']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString("n'est plus en cours", $result['message']);
    }

    public function testRemindSuccessOnPendingToken(): void
    {
        $pdo = $this->db->getPdo();
        $row = $pdo->query("SELECT t.id, t.email FROM tokens t JOIN submissions s ON s.id = t.submission_id WHERE s.status = 'en_cours' AND t.done_at IS NULL LIMIT 1")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No pending token on en_cours submission available');
        }

        $result = $this->tokenService->remind($row['id']);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString($row['email'], $result['message']);
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
        $result = $this->tokenService->delegate('nonexistent-token-id', 'not-an-email');
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['message']);
    }

    public function testDelegateReturnsErrorForAlreadyDoneToken(): void
    {
        $pdo = $this->db->getPdo();
        $doneTokenId = $pdo->query("SELECT t.id FROM tokens t WHERE t.done_at IS NOT NULL LIMIT 1")->fetchColumn();

        if (!$doneTokenId) {
            $this->markTestSkipped('No done token available');
        }

        $result = $this->tokenService->delegate($doneTokenId, 'target@test.com');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('déjà été traité', $result['message']);
    }

    public function testDelegateReturnsErrorForSelfDelegation(): void
    {
        $pdo = $this->db->getPdo();
        $row = $pdo->query("SELECT t.id, t.email FROM tokens t WHERE t.done_at IS NULL LIMIT 1")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No pending token available');
        }

        $result = $this->tokenService->delegate($row['id'], $row['email']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('vous-même', $result['message']);
    }

    public function testDelegateReturnsErrorForClosedSubmission(): void
    {
        $pdo = $this->db->getPdo();
        $row = $pdo->query("SELECT t.id FROM tokens t JOIN submissions s ON s.id = t.submission_id WHERE s.status != 'en_cours' AND t.done_at IS NULL LIMIT 1")->fetch();

        if (!$row) {
            $this->markTestSkipped('No token on closed submission available');
        }

        $result = $this->tokenService->delegate($row['id'], 'target@test.com');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString("n'est plus en cours", $result['message']);
    }

    public function testDelegateSuccess(): void
    {
        $pdo = $this->db->getPdo();
        $row = $pdo->query("SELECT t.id, t.email FROM tokens t JOIN submissions s ON s.id = t.submission_id WHERE s.status = 'en_cours' AND t.done_at IS NULL LIMIT 1")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No pending token on en_cours submission available');
        }

        $toEmail = 'delegate_target_' . uniqid() . '@test.com';
        $result = $this->tokenService->delegate($row['id'], $toEmail, 'Test delegation');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString($toEmail, $result['message']);

        // Verify new token was created
        $check = $pdo->prepare("SELECT 1 FROM tokens WHERE submission_id = (SELECT submission_id FROM tokens WHERE id = ?) AND email = ? AND done_at IS NULL");
        $check->execute([$row['id'], $toEmail]);
        $this->assertNotEmpty($check->fetch());

        // Verify delegation record
        $delCheck = $pdo->prepare("SELECT 1 FROM delegations WHERE token_id = ? AND to_email = ?");
        $delCheck->execute([$row['id'], $toEmail]);
        $this->assertNotEmpty($delCheck->fetch());

        // Cleanup
        $pdo->prepare("DELETE FROM delegations WHERE token_id = ? AND to_email = ?")->execute([$row['id'], $toEmail]);
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = (SELECT submission_id FROM tokens WHERE id = ?) AND email = ? AND done_at IS NULL")->execute([$row['id'], $toEmail]);
    }

    // ── getDelegations ──────────────────────────────────────────

    public function testGetDelegationsReturnsArray(): void
    {
        $delegations = $this->tokenService->getDelegations('nonexistent-id');
        $this->assertIsArray($delegations);
        $this->assertEmpty($delegations);
    }

    public function testGetDelegationsWithRealData(): void
    {
        $pdo = $this->db->getPdo();
        // Check if there are any delegations
        $count = $pdo->query("SELECT COUNT(*) FROM delegations")->fetchColumn();
        if ((int)$count === 0) {
            $this->markTestSkipped('No delegation records available');
        }

        $subId = $pdo->query("SELECT t.submission_id FROM delegations d JOIN tokens t ON t.id = d.token_id LIMIT 1")->fetchColumn();
        $delegations = $this->tokenService->getDelegations($subId);
        $this->assertNotEmpty($delegations);
    }
}
