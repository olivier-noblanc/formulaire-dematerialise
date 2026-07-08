<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Workflow\WorkflowEngine;
use App\Workflow\ConditionEvaluator;
use App\Core\Database;
use App\Settings\SettingsService;
use App\Repository\SettingsRepository;
use App\Mail\MailService;
use App\Forms\FieldService;

final class WorkflowEngineTest extends TestCase
{
    private WorkflowEngine $workflow;
    private Database $db;

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(\App\Core\Database::class);
        $settings = new SettingsService($this->db, new SettingsRepository($this->db));
        $mail = new MailService($this->db, $settings);
        $fields = new FieldService($this->db);
        $conditions = new ConditionEvaluator();
        $this->workflow = new WorkflowEngine($this->db, $settings, $mail, $fields, $conditions);
    }

    // ── getTokenWithContext ──────────────────────────────────────

    public function testGetTokenWithContextReturnsNullForInvalidToken(): void
    {
        $result = $this->workflow->getTokenWithContext('nonexistent_token');
        $this->assertNull($result);
    }

    public function testGetTokenWithContextReturnsDataForRealToken(): void
    {
        $pdo = $this->db->getPdo();
        $tokenVal = $pdo->query("SELECT token FROM tokens LIMIT 1")->fetchColumn();

        if (!$tokenVal) {
            $this->markTestSkipped('No tokens available');
        }

        $result = $this->workflow->getTokenWithContext($tokenVal);
        $this->assertNotNull($result);
        $this->assertArrayHasKey('step_label', $result);
        $this->assertArrayHasKey('form_label', $result);
        $this->assertArrayHasKey('email', $result);
    }

    // ── getTokenByIdWithContext ──────────────────────────────────

    public function testGetTokenByIdWithContextReturnsNullForInvalidId(): void
    {
        $result = $this->workflow->getTokenByIdWithContext('nonexistent_id');
        $this->assertNull($result);
    }

    public function testGetTokenByIdWithContextReturnsDataForRealId(): void
    {
        $pdo = $this->db->getPdo();
        $tokenId = $pdo->query("SELECT id FROM tokens LIMIT 1")->fetchColumn();

        if (!$tokenId) {
            $this->markTestSkipped('No tokens available');
        }

        $result = $this->workflow->getTokenByIdWithContext($tokenId);
        $this->assertNotNull($result);
        $this->assertArrayHasKey('step_label', $result);
        $this->assertArrayHasKey('form_label', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('status', $result);
    }

    // ── getWorkflowSteps ────────────────────────────────────────

    public function testGetWorkflowStepsReturnsArray(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $pdo->query("SELECT id FROM forms LIMIT 1")->fetchColumn();

        if ($formId) {
            $steps = $this->workflow->getWorkflowSteps($formId);
            $this->assertIsArray($steps);
        }
    }

    public function testGetWorkflowStepsReturnsStepDetails(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $pdo->query("SELECT id FROM forms LIMIT 1")->fetchColumn();

        if (!$formId) {
            $this->markTestSkipped('No forms available');
        }

        $steps = $this->workflow->getWorkflowSteps($formId);
        if (empty($steps)) {
            $this->markTestSkipped('No active steps for this form');
        }

        $this->assertArrayHasKey('step_id', $steps[0]);
        $this->assertArrayHasKey('step_label', $steps[0]);
        $this->assertArrayHasKey('ordre', $steps[0]);
        $this->assertArrayHasKey('actif', $steps[0]);
    }

    public function testGetWorkflowStepsReturnsEmptyForNonexistentForm(): void
    {
        $steps = $this->workflow->getWorkflowSteps('nonexistent-form-id');
        $this->assertIsArray($steps);
        $this->assertEmpty($steps);
    }

    // ── getSubmissionWithFormLabel ───────────────────────────────

    public function testGetSubmissionWithFormLabelReturnsNullForInvalidId(): void
    {
        $result = $this->workflow->getSubmissionWithFormLabel('nonexistent_id');
        $this->assertNull($result);
    }

    public function testGetSubmissionWithFormLabelReturnsDataForRealSubmission(): void
    {
        $pdo = $this->db->getPdo();
        $subId = $pdo->query("SELECT id FROM submissions LIMIT 1")->fetchColumn();

        if (!$subId) {
            $this->markTestSkipped('No submissions available');
        }

        $result = $this->workflow->getSubmissionWithFormLabel($subId);
        $this->assertNotNull($result);
        $this->assertArrayHasKey('form_label', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('data', $result);
    }

    // ── resolveDynamicRecipient ──────────────────────────────────

    public function testResolveDynamicRecipientReturnsEmailForNonTemplate(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('user@example.com', []);
        $this->assertSame('user@example.com', $result);
    }

    public function testResolveDynamicRecipientResolvesTemplate(): void
    {
        $formData = ['manager_email' => 'manager@example.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{manager_email}}', $formData);
        $this->assertSame('manager@example.com', $result);
    }

    public function testResolveDynamicRecipientReturnsTemplateForMissingField(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{missing_field}}', []);
        $this->assertSame('{{missing_field}}', $result);
    }

    public function testResolveDynamicRecipientReturnsTemplateForInvalidEmail(): void
    {
        $formData = ['email' => 'not_an_email'];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        $this->assertSame('{{email}}', $result);
    }

    public function testResolveDynamicRecipientResolvesCaseInsensitive(): void
    {
        $formData = ['Manager_Email' => 'manager@example.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{manager_email}}', $formData);
        $this->assertSame('manager@example.com', $result);
    }

    public function testResolveDynamicRecipientReturnsEmptyEmailUnchanged(): void
    {
        $formData = ['email' => ''];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        $this->assertSame('{{email}}', $result);
    }

    public function testResolveDynamicRecipientWithOwnerTemplate(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{owner}}', [], 'nonexistent-submission');
        // Without a valid submission, owner falls back to the template string
        $this->assertSame('{{owner}}', $result);
    }

    // ── validateToken ───────────────────────────────────────────

    public function testValidateTokenReturnsInvalidForBadFormat(): void
    {
        $result = $this->workflow->validateToken('bad_format');
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenReturnsInvalidForNonexistentToken(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64));
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenReturnsInvalidForBadAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'bad_action');
        $this->assertSame('invalid', $result['status']);
        $this->assertArrayHasKey('message', $result);
    }

    public function testValidateTokenReturnsAlreadyDoneForUsedToken(): void
    {
        $pdo = $this->db->getPdo();
        $doneToken = $pdo->query("SELECT token FROM tokens WHERE done_at IS NOT NULL LIMIT 1")->fetchColumn();

        if (!$doneToken) {
            $this->markTestSkipped('No done token available');
        }

        $result = $this->workflow->validateToken($doneToken);
        $this->assertSame('already_done', $result['status']);
    }

    public function testValidateTokenSuccessForPendingToken(): void
    {
        $pdo = $this->db->getPdo();
        $row = $pdo->query("SELECT t.token, t.submission_id FROM tokens t JOIN submissions s ON s.id = t.submission_id WHERE s.status = 'en_cours' AND t.done_at IS NULL LIMIT 1")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No pending token on en_cours submission available');
        }

        $result = $this->workflow->validateToken($row['token'], 'valider', 'Test validation', 'validator@test.com');
        $this->assertSame('ok', $result['status']);
        $this->assertArrayHasKey('data', $result);
    }

    public function testValidateTokenRefuse(): void
    {
        $pdo = $this->db->getPdo();
        $row = $pdo->query("SELECT t.token, t.submission_id FROM tokens t JOIN submissions s ON s.id = t.submission_id WHERE s.status = 'en_cours' AND t.done_at IS NULL LIMIT 1")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No pending token on en_cours submission available');
        }

        $result = $this->workflow->validateToken($row['token'], 'refuser', 'Motif de refus');
        $this->assertSame('ok', $result['status']);

        // Verify submission is now refused
        $check = $pdo->prepare("SELECT status FROM submissions WHERE id = ?");
        $check->execute([$row['submission_id']]);
        $this->assertSame('refuse', $check->fetchColumn());
    }

    public function testValidateTokenTruncatesComment(): void
    {
        $pdo = $this->db->getPdo();
        $row = $pdo->query("SELECT t.token, t.submission_id FROM tokens t JOIN submissions s ON s.id = t.submission_id WHERE s.status = 'en_cours' AND t.done_at IS NULL LIMIT 1")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No pending token on en_cours submission available');
        }

        $longComment = str_repeat('x', 1500);
        $result = $this->workflow->validateToken($row['token'], 'valider', $longComment);
        $this->assertSame('ok', $result['status']);
        // The comment should be truncated to 1000 chars in the validation data
        $data = json_decode($result['data']['data'], true);
        $validation = end($data['validations']);
        $this->assertLessThanOrEqual(1000, strlen($validation['commentaire']));
    }

    // ── hasActiveSubmissions ─────────────────────────────────────

    public function testHasActiveSubmissionsReturnsInt(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $pdo->query("SELECT id FROM forms LIMIT 1")->fetchColumn();

        if (!$formId) {
            $this->markTestSkipped('No forms available');
        }

        $count = $this->workflow->hasActiveSubmissions($formId);
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testHasActiveSubmissionsReturnsZeroForNonexistentForm(): void
    {
        $count = $this->workflow->hasActiveSubmissions('nonexistent-form-id');
        $this->assertSame(0, $count);
    }

    // ── hasActiveStepSubmissions ─────────────────────────────────

    public function testHasActiveStepSubmissionsReturnsInt(): void
    {
        $pdo = $this->db->getPdo();
        $stepId = $pdo->query("SELECT id FROM steps LIMIT 1")->fetchColumn();

        if (!$stepId) {
            $this->markTestSkipped('No steps available');
        }

        $count = $this->workflow->hasActiveStepSubmissions($stepId);
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testHasActiveStepSubmissionsReturnsZeroForNonexistentStep(): void
    {
        $count = $this->workflow->hasActiveStepSubmissions('nonexistent-step-id');
        $this->assertSame(0, $count);
    }

    public function testHasActiveStepSubmissionsReturnsCountForStepWithPendingTokens(): void
    {
        $pdo = $this->db->getPdo();
        $stepId = $pdo->query("SELECT step_id FROM tokens t JOIN submissions s ON s.id = t.submission_id WHERE t.done_at IS NULL AND s.status = 'en_cours' LIMIT 1")->fetchColumn();

        if (!$stepId) {
            $this->markTestSkipped('No step with pending tokens available');
        }

        $count = $this->workflow->hasActiveStepSubmissions($stepId);
        $this->assertGreaterThan(0, $count);
    }

    // ── advanceWorkflow ──────────────────────────────────────────

    public function testAdvanceWorkflowReturnsEarlyForNonexistentSubmission(): void
    {
        // Should not throw — just returns early
        $this->workflow->advanceWorkflow('nonexistent-submission-id');
        $this->assertTrue(true);
    }

    public function testAdvanceWorkflowReturnsEarlyForClosedSubmission(): void
    {
        $pdo = $this->db->getPdo();
        $subId = $pdo->query("SELECT id FROM submissions WHERE closed_at IS NOT NULL LIMIT 1")->fetchColumn();

        if (!$subId) {
            $this->markTestSkipped('No closed submission available');
        }

        // Should return early without error
        $this->workflow->advanceWorkflow((string) $subId);
        $this->assertTrue(true);
    }

    public function testAdvanceWorkflowCreatesTokensForActiveSubmission(): void
    {
        $pdo = $this->db->getPdo();
        // Find an en_cours submission that has steps but no tokens yet (fresh submission)
        $row = $pdo->query("
            SELECT s.id as sub_id, s.form_id
            FROM submissions s
            WHERE s.status = 'en_cours' AND s.closed_at IS NULL
            AND NOT EXISTS (SELECT 1 FROM tokens t WHERE t.submission_id = s.id)
            LIMIT 1
        ")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No fresh en_cours submission without tokens available');
        }

        // Verify steps exist for this form
        $steps = $this->workflow->getWorkflowSteps((string) $row['form_id']);
        if (empty($steps)) {
            $this->markTestSkipped('No active steps for the form');
        }

        // Verify at least one step has recipients
        $hasRecipients = false;
        foreach ($steps as $step) {
            if (!empty(trim($step['recipient_emails'] ?? ''))) {
                $hasRecipients = true;
                break;
            }
        }
        if (!$hasRecipients) {
            $this->markTestSkipped('No steps with recipients for the form');
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ?");
        $countStmt->execute([$row['sub_id']]);
        $tokensBefore = $countStmt->fetchColumn();
        $this->workflow->advanceWorkflow((string) $row['sub_id']);
        $countStmt->execute([$row['sub_id']]);
        $tokensAfter = $countStmt->fetchColumn();

        $this->assertGreaterThan((int) $tokensBefore, (int) $tokensAfter, 'advanceWorkflow should create new tokens');
    }

    public function testAdvanceWorkflowSkipsInvalidEmailRecipients(): void
    {
        $pdo = $this->db->getPdo();
        // Find a step with an invalid (non-email) recipient
        $row = $pdo->query("
            SELECT st.id as step_id, st.form_id, st.ordre
            FROM steps st
            JOIN step_recipients sr ON sr.step_id = st.id
            WHERE st.actif = 1 AND sr.email NOT LIKE '%@%'
            LIMIT 1
        ")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No step with invalid email recipient available');
        }

        // Find a matching submission
        $stmt = $pdo->prepare("SELECT id FROM submissions WHERE form_id = ? AND status = 'en_cours' AND closed_at IS NULL LIMIT 1");
        $stmt->execute([$row['form_id']]);
        $subId = $stmt->fetchColumn();

        if (!$subId) {
            $this->markTestSkipped('No active submission for the form with invalid recipient');
        }

        // Should not throw even with invalid email recipients
        $this->workflow->advanceWorkflow((string) $subId);
        $this->assertTrue(true);
    }

    public function testAdvanceWorkflowSkipsConditionWhenNotMet(): void
    {
        $pdo = $this->db->getPdo();
        // Find a step with a condition that won't match
        $row = $pdo->query("
            SELECT st.id as step_id, st.form_id, st.`condition`
            FROM steps st
            WHERE st.actif = 1 AND st.`condition` IS NOT NULL AND st.`condition` != '' AND st.`condition` != 'null'
            LIMIT 1
        ")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No step with a condition available');
        }

        // Find a matching submission with empty data (condition won't match)
        $stmt = $pdo->prepare("SELECT id FROM submissions WHERE form_id = ? AND status = 'en_cours' AND closed_at IS NULL LIMIT 1");
        $stmt->execute([$row['form_id']]);
        $subId = $stmt->fetchColumn();

        if (!$subId) {
            $this->markTestSkipped('No active submission for the form with conditional step');
        }

        // Should not throw — condition is evaluated and step skipped
        $this->workflow->advanceWorkflow((string) $subId);
        $this->assertTrue(true);
    }

    // ── resolveDynamicRecipient edge cases ───────────────────────

    public function testResolveDynamicRecipientIgnoresNonLowercaseStart(): void
    {
        // Template regex requires [a-z] start — uppercase start should not match
        $result = $this->workflow->resolveDynamicRecipient('{{ManagerEmail}}', ['ManagerEmail' => 'test@example.com']);
        $this->assertSame('{{ManagerEmail}}', $result);
    }

    public function testResolveDynamicRecipientIgnoresNumericStart(): void
    {
        // Template regex requires [a-z] start — numeric start should not match
        $result = $this->workflow->resolveDynamicRecipient('{{1field}}', ['1field' => 'test@example.com']);
        $this->assertSame('{{1field}}', $result);
    }

    public function testResolveDynamicRecipientResolvesExactMatchFirst(): void
    {
        // Exact key match takes priority over case-insensitive fallback
        $formData = ['email' => 'exact@example.com', 'Email' => 'case@example.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        $this->assertSame('exact@example.com', $result);
    }

    public function testResolveDynamicRecipientResolvesCaseInsensitiveFallback(): void
    {
        // No exact match — falls back to case-insensitive comparison
        $formData = ['Email' => 'fallback@example.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        $this->assertSame('fallback@example.com', $result);
    }

    public function testResolveDynamicRecipientReturnsTemplateForNullFormDataValue(): void
    {
        $formData = ['email' => null];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        $this->assertSame('{{email}}', $result);
    }

    public function testResolveDynamicRecipientReturnsTemplateForWhitespaceOnlyEmail(): void
    {
        $formData = ['email' => '   '];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        $this->assertSame('{{email}}', $result);
    }

    public function testResolveDynamicRecipientWithOwnerAndNoSubmissionId(): void
    {
        // {{owner}} without submissionId — falls back to template
        $result = $this->workflow->resolveDynamicRecipient('{{owner}}', []);
        $this->assertSame('{{owner}}', $result);
    }

    public function testResolveDynamicRecipientWithOwnerAndNonexistentSubmission(): void
    {
        // {{owner}} with a submission that doesn't exist — falls back to template
        $result = $this->workflow->resolveDynamicRecipient('{{owner}}', [], '00000000-0000-0000-0000-000000000000');
        $this->assertSame('{{owner}}', $result);
    }

    // ── validateToken edge cases ─────────────────────────────────

    public function testValidateTokenReturnsExpiredForExpiredToken(): void
    {
        $pdo = $this->db->getPdo();
        $now = gmdate('Y-m-d H:i:s');
        $row = $pdo->prepare("
            SELECT t.token, t.submission_id
            FROM tokens t
            JOIN submissions s ON s.id = t.submission_id
            WHERE t.done_at IS NULL
            AND t.expires_at IS NOT NULL
            AND t.expires_at < ?
            AND s.status = 'en_cours'
            LIMIT 1
        ");
        $row->execute([$now]);
        $row = $row->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No expired token available');
        }

        $result = $this->workflow->validateToken($row['token']);
        $this->assertSame('expired', $result['status']);
    }

    public function testValidateTokenReturnsClosedForClosedSubmissionToken(): void
    {
        $pdo = $this->db->getPdo();
        $row = $pdo->query("
            SELECT t.token
            FROM tokens t
            JOIN submissions s ON s.id = t.submission_id
            WHERE t.done_at IS NULL
            AND s.closed_at IS NOT NULL
            LIMIT 1
        ")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No token on closed submission available');
        }

        $result = $this->workflow->validateToken($row['token']);
        $this->assertSame('closed', $result['status']);
    }

    public function testValidateTokenReturnsDataKeyInResult(): void
    {
        $pdo = $this->db->getPdo();
        $doneToken = $pdo->query("SELECT token FROM tokens WHERE done_at IS NOT NULL LIMIT 1")->fetchColumn();

        if (!$doneToken) {
            $this->markTestSkipped('No done token available');
        }

        $result = $this->workflow->validateToken($doneToken);
        $this->assertArrayHasKey('data', $result);
        $this->assertIsArray($result['data']);
    }

    public function testValidateTokenStoresDoneByField(): void
    {
        $pdo = $this->db->getPdo();
        $row = $pdo->query("
            SELECT t.token, t.submission_id, s.data
            FROM tokens t
            JOIN submissions s ON s.id = t.submission_id
            WHERE s.status = 'en_cours' AND t.done_at IS NULL
            LIMIT 1
        ")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No pending token on en_cours submission available');
        }

        $doneBy = 'verifier-' . uniqid() . '@test.com';
        $result = $this->workflow->validateToken($row['token'], 'valider', 'Test', $doneBy);
        $this->assertSame('ok', $result['status']);

        // Verify done_by is stored in the submission data
        $check = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $check->execute([$row['submission_id']]);
        $data = json_decode((string) $check->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame($doneBy, $validation['done_by']);
    }

    // ── getWorkflowSteps caching ─────────────────────────────────

    public function testGetWorkflowStepsReturnsConsistentResults(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $pdo->query("SELECT id FROM forms LIMIT 1")->fetchColumn();

        if (!$formId) {
            $this->markTestSkipped('No forms available');
        }

        // Call twice — static cache should return same results
        $first = $this->workflow->getWorkflowSteps((string) $formId);
        $second = $this->workflow->getWorkflowSteps((string) $formId);
        $this->assertSame($first, $second);
    }

    // ── hasActiveSubmissions consistency ─────────────────────────

    public function testHasActiveSubmissionsMatchesDirectQuery(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $pdo->query("SELECT id FROM forms LIMIT 1")->fetchColumn();

        if (!$formId) {
            $this->markTestSkipped('No forms available');
        }

        $methodResult = $this->workflow->hasActiveSubmissions((string) $formId);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE form_id = ? AND status = 'en_cours'");
        $stmt->execute([$formId]);
        $directCount = $stmt->fetchColumn();

        $this->assertSame((int) $directCount, $methodResult);
    }

    public function testHasActiveStepSubmissionsMatchesDirectQuery(): void
    {
        $pdo = $this->db->getPdo();
        $stepId = $pdo->query("SELECT id FROM steps LIMIT 1")->fetchColumn();

        if (!$stepId) {
            $this->markTestSkipped('No steps available');
        }

        $methodResult = $this->workflow->hasActiveStepSubmissions((string) $stepId);
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM tokens t
            JOIN submissions s ON s.id = t.submission_id
            WHERE t.step_id = ? AND t.done_at IS NULL AND s.status = 'en_cours'
        ");
        $stmt->execute([$stepId]);
        $directCount = $stmt->fetchColumn();

        $this->assertSame((int) $directCount, $methodResult);
    }
}
