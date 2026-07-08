<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Workflow\WorkflowEngine;
use App\Workflow\ConditionEvaluator;
use App\Core\Database;
use App\Settings\SettingsService;
use App\Mail\MailService;
use App\Forms\FieldService;

final class WorkflowEngineTest extends TestCase
{
    private WorkflowEngine $workflow;
    private Database $db;

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(\App\Core\Database::class);
        $settings = new SettingsService($this->db);
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
}
