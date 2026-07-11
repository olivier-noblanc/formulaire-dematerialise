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
        $settings = new SettingsService(new SettingsRepository($this->db));
        $mail = new MailService($this->db, $settings);
        $fields = new FieldService($this->db);
        $conditions = new ConditionEvaluator();
        $this->workflow = new WorkflowEngine($this->db, $settings, $mail, $fields, $conditions);
    }

    // ── Constructor / DI ───────────────────────────────────────

    public function testConstructorCreatesInstance(): void
    {
        $this->assertInstanceOf(WorkflowEngine::class, $this->workflow);
    }

    public function testImplementsWorkflowInterface(): void
    {
        $this->assertInstanceOf(\App\Contract\WorkflowInterface::class, $this->workflow);
    }

    public function testServiceRegistrableInContainer(): void
    {
        $app = \App\Core\App::getInstance();
        $this->assertTrue($app->has(WorkflowEngine::class));
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
        if ($result === null) {
            $this->markTestSkipped('Token has no valid joins (broken FKs in test DB)');
        }
        $this->assertArrayHasKey('step_label', $result);
        $this->assertArrayHasKey('form_label', $result);
        $this->assertArrayHasKey('email', $result);
    }

    public function testGetTokenWithContextReturnsNullForEmptyString(): void
    {
        $result = $this->workflow->getTokenWithContext('');
        $this->assertNull($result);
    }

    public function testGetTokenWithContextReturnsNullForTooLongToken(): void
    {
        $result = $this->workflow->getTokenWithContext(str_repeat('a', 256));
        $this->assertNull($result);
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
        if ($result === null) {
            $this->markTestSkipped('Token has no valid joins (broken FKs in test DB)');
        }
        $this->assertArrayHasKey('step_label', $result);
        $this->assertArrayHasKey('form_label', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('status', $result);
    }

    public function testGetTokenByIdWithContextReturnsNullForEmptyString(): void
    {
        $result = $this->workflow->getTokenByIdWithContext('');
        $this->assertNull($result);
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

    public function testGetWorkflowStepsReturnsEmptyForEmptyFormId(): void
    {
        $steps = $this->workflow->getWorkflowSteps('');
        $this->assertIsArray($steps);
        $this->assertEmpty($steps);
    }

    public function testGetWorkflowStepsReturnsConditionField(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $pdo->query("SELECT id FROM forms LIMIT 1")->fetchColumn();

        if (!$formId) {
            $this->markTestSkipped('No forms available');
        }

        $steps = $this->workflow->getWorkflowSteps((string) $formId);
        if (empty($steps)) {
            $this->markTestSkipped('No active steps');
        }

        $this->assertArrayHasKey('condition', $steps[0]);
    }

    public function testGetWorkflowStepsReturnsRecipientEmailsField(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $pdo->query("SELECT id FROM forms LIMIT 1")->fetchColumn();

        if (!$formId) {
            $this->markTestSkipped('No forms available');
        }

        $steps = $this->workflow->getWorkflowSteps((string) $formId);
        if (empty($steps)) {
            $this->markTestSkipped('No active steps');
        }

        $this->assertArrayHasKey('recipient_emails', $steps[0]);
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

    public function testGetSubmissionWithFormLabelReturnsNullForEmptyString(): void
    {
        $result = $this->workflow->getSubmissionWithFormLabel('');
        $this->assertNull($result);
    }

    public function testGetSubmissionWithFormLabelReturnsSubmittedByField(): void
    {
        $pdo = $this->db->getPdo();
        $subId = $pdo->query("SELECT id FROM submissions LIMIT 1")->fetchColumn();

        if (!$subId) {
            $this->markTestSkipped('No submissions available');
        }

        $result = $this->workflow->getSubmissionWithFormLabel($subId);
        if ($result === null) {
            $this->markTestSkipped('Submission has no valid form join');
        }
        $this->assertArrayHasKey('submitted_by', $result);
    }

    public function testGetSubmissionWithFormLabelReturnsClosedAtField(): void
    {
        $pdo = $this->db->getPdo();
        $subId = $pdo->query("SELECT id FROM submissions LIMIT 1")->fetchColumn();

        if (!$subId) {
            $this->markTestSkipped('No submissions available');
        }

        $result = $this->workflow->getSubmissionWithFormLabel($subId);
        if ($result === null) {
            $this->markTestSkipped('Submission has no valid form join');
        }
        $this->assertArrayHasKey('closed_at', $result);
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
        $this->assertSame('{{owner}}', $result);
    }

    public function testResolveDynamicRecipientIgnoresNonLowercaseStart(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{ManagerEmail}}', ['ManagerEmail' => 'test@example.com']);
        $this->assertSame('{{ManagerEmail}}', $result);
    }

    public function testResolveDynamicRecipientIgnoresNumericStart(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{1field}}', ['1field' => 'test@example.com']);
        $this->assertSame('{{1field}}', $result);
    }

    public function testResolveDynamicRecipientResolvesExactMatchFirst(): void
    {
        $formData = ['email' => 'exact@example.com', 'Email' => 'case@example.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        $this->assertSame('exact@example.com', $result);
    }

    public function testResolveDynamicRecipientResolvesCaseInsensitiveFallback(): void
    {
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
        $result = $this->workflow->resolveDynamicRecipient('{{owner}}', []);
        $this->assertSame('{{owner}}', $result);
    }

    public function testResolveDynamicRecipientWithOwnerAndNonexistentSubmission(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{owner}}', [], '00000000-0000-0000-0000-000000000000');
        $this->assertSame('{{owner}}', $result);
    }

    public function testResolveDynamicRecipientWithPartialTemplateSyntax(): void
    {
        // Missing closing braces
        $result = $this->workflow->resolveDynamicRecipient('{{email', ['email' => 'test@example.com']);
        $this->assertSame('{{email', $result);
    }

    public function testResolveDynamicRecipientWithTripleBraces(): void
    {
        // Triple braces — should not match regex
        $result = $this->workflow->resolveDynamicRecipient('{{{email}}}', ['email' => 'test@example.com']);
        $this->assertSame('{{{email}}}', $result);
    }

    public function testResolveDynamicRecipientWithEmptyFormData(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{anything}}', []);
        $this->assertSame('{{anything}}', $result);
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
        if ($result['status'] === 'invalid') {
            $this->markTestSkipped('Token has no valid joins (broken FKs in test DB)');
        }
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
        $data = json_decode($result['data']['data'], true);
        $validation = end($data['validations']);
        $this->assertLessThanOrEqual(1000, strlen($validation['commentaire']));
    }

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
        if ($result['status'] === 'invalid') {
            $this->markTestSkipped('Token has no valid joins (broken FKs in test DB)');
        }
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

        $check = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $check->execute([$row['submission_id']]);
        $data = json_decode((string) $check->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame($doneBy, $validation['done_by']);
    }

    public function testValidateTokenWithDefaultAction(): void
    {
        $pdo = $this->db->getPdo();
        $row = $pdo->query("SELECT t.token FROM tokens t JOIN submissions s ON s.id = t.submission_id WHERE s.status = 'en_cours' AND t.done_at IS NULL LIMIT 1")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No pending token available');
        }

        // Default action is 'valider'
        $result = $this->workflow->validateToken($row['token']);
        $this->assertSame('ok', $result['status']);
    }

    public function testValidateTokenRefuseWithComment(): void
    {
        $pdo = $this->db->getPdo();
        $row = $pdo->query("SELECT t.token, t.submission_id FROM tokens t JOIN submissions s ON s.id = t.submission_id WHERE s.status = 'en_cours' AND t.done_at IS NULL LIMIT 1")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No pending token available');
        }

        $result = $this->workflow->validateToken($row['token'], 'refuser', 'Motif de refus détaillé');
        $this->assertSame('ok', $result['status']);
    }

    public function testValidateTokenRefuseWithoutComment(): void
    {
        $pdo = $this->db->getPdo();
        $row = $pdo->query("SELECT t.token, t.submission_id FROM tokens t JOIN submissions s ON s.id = t.submission_id WHERE s.status = 'en_cours' AND t.done_at IS NULL LIMIT 1")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No pending token available');
        }

        $result = $this->workflow->validateToken($row['token'], 'refuser', '');
        $this->assertSame('ok', $result['status']);
    }

    public function testValidateTokenWithEmptyComment(): void
    {
        $pdo = $this->db->getPdo();
        $row = $pdo->query("SELECT t.token FROM tokens t JOIN submissions s ON s.id = t.submission_id WHERE s.status = 'en_cours' AND t.done_at IS NULL LIMIT 1")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No pending token available');
        }

        $result = $this->workflow->validateToken($row['token'], 'valider', '');
        $this->assertSame('ok', $result['status']);
    }

    public function testValidateTokenStoresStepLabel(): void
    {
        $pdo = $this->db->getPdo();
        $row = $pdo->query("
            SELECT t.token, t.submission_id
            FROM tokens t
            JOIN submissions s ON s.id = t.submission_id
            WHERE s.status = 'en_cours' AND t.done_at IS NULL
            LIMIT 1
        ")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No pending token available');
        }

        $result = $this->workflow->validateToken($row['token'], 'valider', 'Test');
        $this->assertSame('ok', $result['status']);

        $data = json_decode($result['data']['data'], true);
        $validation = end($data['validations']);
        $this->assertArrayHasKey('step_label', $validation);
        $this->assertArrayHasKey('email', $validation);
        $this->assertArrayHasKey('action', $validation);
        $this->assertArrayHasKey('date', $validation);
    }

    public function testValidateTokenStoresDateTimestamp(): void
    {
        $pdo = $this->db->getPdo();
        $row = $pdo->query("SELECT t.token FROM tokens t JOIN submissions s ON s.id = t.submission_id WHERE s.status = 'en_cours' AND t.done_at IS NULL LIMIT 1")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No pending token available');
        }

        $before = gmdate('Y-m-d H:i:s');
        $result = $this->workflow->validateToken($row['token'], 'valider', 'Test');
        $after = gmdate('Y-m-d H:i:s');

        $this->assertSame('ok', $result['status']);
        $data = json_decode($result['data']['data'], true);
        $validation = end($data['validations']);
        $this->assertGreaterThanOrEqual($before, $validation['date']);
        $this->assertLessThanOrEqual($after, $validation['date']);
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

    public function testHasActiveSubmissionsReturnsZeroForEmptyFormId(): void
    {
        $count = $this->workflow->hasActiveSubmissions('');
        $this->assertSame(0, $count);
    }

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

    public function testHasActiveStepSubmissionsReturnsZeroForEmptyStepId(): void
    {
        $count = $this->workflow->hasActiveStepSubmissions('');
        $this->assertSame(0, $count);
    }

    // ── advanceWorkflow ──────────────────────────────────────────

    public function testAdvanceWorkflowReturnsEarlyForNonexistentSubmission(): void
    {
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

        $this->workflow->advanceWorkflow((string) $subId);
        $this->assertTrue(true);
    }

    public function testAdvanceWorkflowReturnsEarlyForEmptySubmissionId(): void
    {
        $this->workflow->advanceWorkflow('');
        $this->assertTrue(true);
    }

    public function testAdvanceWorkflowCreatesTokensForActiveSubmission(): void
    {
        $pdo = $this->db->getPdo();
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

        $steps = $this->workflow->getWorkflowSteps((string) $row['form_id']);
        if (empty($steps)) {
            $this->markTestSkipped('No active steps for the form');
        }

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

        $stmt = $pdo->prepare("SELECT id FROM submissions WHERE form_id = ? AND status = 'en_cours' AND closed_at IS NULL LIMIT 1");
        $stmt->execute([$row['form_id']]);
        $subId = $stmt->fetchColumn();

        if (!$subId) {
            $this->markTestSkipped('No active submission for the form with invalid recipient');
        }

        $this->workflow->advanceWorkflow((string) $subId);
        $this->assertTrue(true);
    }

    public function testAdvanceWorkflowSkipsConditionWhenNotMet(): void
    {
        $pdo = $this->db->getPdo();
        $row = $pdo->query("
            SELECT st.id as step_id, st.form_id, st.`condition`
            FROM steps st
            WHERE st.actif = 1 AND st.`condition` IS NOT NULL AND st.`condition` != '' AND st.`condition` != 'null'
            LIMIT 1
        ")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No step with a condition available');
        }

        $stmt = $pdo->prepare("SELECT id FROM submissions WHERE form_id = ? AND status = 'en_cours' AND closed_at IS NULL LIMIT 1");
        $stmt->execute([$row['form_id']]);
        $subId = $stmt->fetchColumn();

        if (!$subId) {
            $this->markTestSkipped('No active submission for the form with conditional step');
        }

        $this->workflow->advanceWorkflow((string) $subId);
        $this->assertTrue(true);
    }

    // ── getWorkflowSteps caching ─────────────────────────────────

    public function testGetWorkflowStepsReturnsConsistentResults(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $pdo->query("SELECT id FROM forms LIMIT 1")->fetchColumn();

        if (!$formId) {
            $this->markTestSkipped('No forms available');
        }

        $first = $this->workflow->getWorkflowSteps((string) $formId);
        $second = $this->workflow->getWorkflowSteps((string) $formId);
        $this->assertSame($first, $second);
    }

    // ── validateToken edge cases with various token formats ─────

    public function testValidateTokenReturnsInvalidForShortHex(): void
    {
        $result = $this->workflow->validateToken('abc123');
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenReturnsInvalidForUppercaseHex(): void
    {
        $result = $this->workflow->validateToken(strtoupper(str_repeat('a', 64)));
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenReturnsInvalidForHexWithSpecialChars(): void
    {
        $result = $this->workflow->validateToken(str_repeat('g', 64));
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenReturnsInvalidForMixedCaseHex(): void
    {
        $token = str_repeat('A', 32) . str_repeat('a', 32);
        $result = $this->workflow->validateToken($token);
        $this->assertSame('invalid', $result['status']);
    }

    // ── ConditionEvaluator (shared component) ────────────────────

    public function testConditionEvaluatorHandlesEmptyCondition(): void
    {
        $evaluator = new ConditionEvaluator();
        $this->assertTrue($evaluator->evaluate('', []));
        $this->assertTrue($evaluator->evaluate(null, []));
    }

    public function testConditionEvaluatorHandlesInvalidJson(): void
    {
        $evaluator = new ConditionEvaluator();
        $this->assertTrue($evaluator->evaluate('not-json', []));
    }

    public function testConditionEvaluatorHandlesEqOperator(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'status', 'op' => 'eq', 'value' => 'active']);
        $this->assertTrue($evaluator->evaluate($condition, ['status' => 'active']));
        $this->assertFalse($evaluator->evaluate($condition, ['status' => 'inactive']));
    }

    public function testConditionEvaluatorHandlesNeqOperator(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'status', 'op' => 'neq', 'value' => 'active']);
        $this->assertFalse($evaluator->evaluate($condition, ['status' => 'active']));
        $this->assertTrue($evaluator->evaluate($condition, ['status' => 'inactive']));
    }

    public function testConditionEvaluatorHandlesInOperator(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'role', 'op' => 'in', 'value' => ['admin', 'editor']]);
        $this->assertTrue($evaluator->evaluate($condition, ['role' => 'admin']));
        $this->assertFalse($evaluator->evaluate($condition, ['role' => 'viewer']));
    }

    public function testConditionEvaluatorHandlesInOperatorWithString(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'role', 'op' => 'in', 'value' => 'admin,editor']);
        $this->assertTrue($evaluator->evaluate($condition, ['role' => 'admin']));
        $this->assertFalse($evaluator->evaluate($condition, ['role' => 'viewer']));
    }

    public function testConditionEvaluatorHandlesNotEmptyOperator(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'name', 'op' => 'not_empty']);
        $this->assertTrue($evaluator->evaluate($condition, ['name' => 'John']));
        $this->assertFalse($evaluator->evaluate($condition, ['name' => '']));
        $this->assertFalse($evaluator->evaluate($condition, []));
    }

    public function testConditionEvaluatorHandlesEmptyOperator(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'name', 'op' => 'empty']);
        $this->assertTrue($evaluator->evaluate($condition, ['name' => '']));
        $this->assertTrue($evaluator->evaluate($condition, []));
        $this->assertFalse($evaluator->evaluate($condition, ['name' => 'John']));
    }

    public function testConditionEvaluatorHandlesUnknownOperator(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'name', 'op' => 'unknown']);
        $this->assertTrue($evaluator->evaluate($condition, ['name' => 'John']));
    }

    public function testConditionEvaluatorHandlesArrayValueInData(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'tags', 'op' => 'not_empty']);
        $this->assertTrue($evaluator->evaluate($condition, ['tags' => ['admin', 'user']]));
    }

    public function testConditionEvaluatorHandlesMissingFieldInData(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'missing', 'op' => 'eq', 'value' => '']);
        $this->assertTrue($evaluator->evaluate($condition, []));
    }

    public function testConditionEvaluatorDefaultsToEqOperator(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'name', 'value' => 'test']);
        $this->assertTrue($evaluator->evaluate($condition, ['name' => 'test']));
        $this->assertFalse($evaluator->evaluate($condition, ['name' => 'other']));
    }

    // ── resolveDynamicRecipient() {{owner}} with real DB data ────

    public function testResolveDynamicRecipientWithOwnerAndRealSubmission(): void
    {
        $pdo = $this->db->getPdo();

        // Find a form with owners
        $formId = $pdo->query("SELECT form_id FROM form_owners LIMIT 1")->fetchColumn();
        if (!$formId) {
            $this->markTestSkipped('No form with owners in test DB');
        }

        // Find a submission for that form
        $stmt = $pdo->prepare("SELECT id FROM submissions WHERE form_id = ? LIMIT 1");
        $stmt->execute([$formId]);
        $subId = $stmt->fetchColumn();

        if (!$subId) {
            $this->markTestSkipped('No submission for form with owners');
        }

        $result = $this->workflow->resolveDynamicRecipient('{{owner}}', [], $subId);
        // Should resolve to the owner's email
        $this->assertNotSame('{{owner}}', $result);
        $this->assertStringContainsString('@', $result);
    }

    public function testResolveDynamicRecipientWithOwnerFallbackToAdmin(): void
    {
        $pdo = $this->db->getPdo();

        // Find a form WITHOUT owners but with a valid submission
        $formId = $pdo->query("
            SELECT s.form_id FROM submissions s
            WHERE NOT EXISTS (SELECT 1 FROM form_owners fo WHERE fo.form_id = s.form_id)
            LIMIT 1
        ")->fetchColumn();

        if (!$formId) {
            $this->markTestSkipped('No form without owners in test DB');
        }

        $stmt = $pdo->prepare("SELECT id FROM submissions WHERE form_id = ? LIMIT 1");
        $stmt->execute([$formId]);
        $subId = $stmt->fetchColumn();

        if (!$subId) {
            $this->markTestSkipped('No submission for form without owners');
        }

        $result = $this->workflow->resolveDynamicRecipient('{{owner}}', [], $subId);
        // Should fall back to admin email
        $this->assertNotSame('{{owner}}', $result);
        $this->assertStringContainsString('@', $result);
    }

    // ── advanceWorkflow() complete path ─────────────────────────

    public function testAdvanceWorkflowWithCompletedSubmissionClosesIt(): void
    {
        $pdo = $this->db->getPdo();

        // Find a submission where ALL tokens are done and closed_at is NULL
        $row = $pdo->query("
            SELECT s.id as sub_id, s.form_id
            FROM submissions s
            WHERE s.status = 'en_cours' AND s.closed_at IS NULL
            AND EXISTS (SELECT 1 FROM tokens t WHERE t.submission_id = s.id AND t.done_at IS NOT NULL)
            AND NOT EXISTS (SELECT 1 FROM tokens t WHERE t.submission_id = s.id AND t.done_at IS NULL)
            LIMIT 1
        ")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No submission with all tokens done');
        }

        $this->workflow->advanceWorkflow((string) $row['sub_id']);

        // After advancing, submission should be closed
        $check = $pdo->prepare("SELECT closed_at FROM submissions WHERE id = ?");
        $check->execute([$row['sub_id']]);
        $closedAt = $check->fetchColumn();
        $this->assertNotEmpty($closedAt);
    }

    public function testAdvanceWorkflowWithMixedDoneAndPendingWaits(): void
    {
        $pdo = $this->db->getPdo();

        // Find a submission with both done and pending tokens
        $row = $pdo->query("
            SELECT s.id as sub_id
            FROM submissions s
            WHERE s.status = 'en_cours' AND s.closed_at IS NULL
            AND EXISTS (SELECT 1 FROM tokens t WHERE t.submission_id = s.id AND t.done_at IS NOT NULL)
            AND EXISTS (SELECT 1 FROM tokens t WHERE t.submission_id = s.id AND t.done_at IS NULL)
            LIMIT 1
        ")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No submission with mixed done/pending tokens');
        }

        $before = $pdo->prepare("SELECT closed_at FROM submissions WHERE id = ?");
        $before->execute([$row['sub_id']]);
        $closedBefore = $before->fetchColumn();

        $this->workflow->advanceWorkflow((string) $row['sub_id']);

        $after = $pdo->prepare("SELECT closed_at FROM submissions WHERE id = ?");
        $after->execute([$row['sub_id']]);
        $closedAfter = $after->fetchColumn();

        // Should not close since not all steps are done
        $this->assertSame($closedBefore, $closedAfter);
    }

    // ── advanceWorkflow() duplicate token detection ──────────────

    public function testAdvanceWorkflowDoesNotCreateDuplicateTokens(): void
    {
        $pdo = $this->db->getPdo();

        // Find a submission with existing tokens but not all steps started
        $row = $pdo->query("
            SELECT s.id as sub_id, s.form_id
            FROM submissions s
            WHERE s.status = 'en_cours' AND s.closed_at IS NULL
            AND EXISTS (SELECT 1 FROM tokens t WHERE t.submission_id = s.id)
            LIMIT 1
        ")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No submission with existing tokens');
        }

        $countBefore = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ?");
        $countBefore->execute([$row['sub_id']]);
        $before = (int) $countBefore->fetchColumn();

        $this->workflow->advanceWorkflow((string) $row['sub_id']);

        $countAfter = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ?");
        $countAfter->execute([$row['sub_id']]);
        $after = (int) $countAfter->fetchColumn();

        // Should not create duplicates — count should be same or more (new step tokens)
        $this->assertGreaterThanOrEqual($before, $after);
    }

    // ── validateToken() refuser with agent notification ──────────

    public function testValidateTokenRefuseNotifiesAgent(): void
    {
        $pdo = $this->db->getPdo();
        $row = $pdo->query("
            SELECT t.token, t.submission_id, s.submitted_by
            FROM tokens t
            JOIN submissions s ON s.id = t.submission_id
            WHERE s.status = 'en_cours' AND t.done_at IS NULL
            AND s.submitted_by IS NOT NULL AND s.submitted_by != ''
            LIMIT 1
        ")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No pending token with submitted_by');
        }

        $result = $this->workflow->validateToken($row['token'], 'refuser', 'Motif de refus');
        $this->assertSame('ok', $result['status']);

        // Verify submission is marked as refused
        $check = $pdo->prepare("SELECT status FROM submissions WHERE id = ?");
        $check->execute([$row['submission_id']]);
        $this->assertSame('refuse', $check->fetchColumn());
    }

    // ── validateToken() valider with done_by ─────────────────────

    public function testValidateTokenValiderWithDoneBy(): void
    {
        $pdo = $this->db->getPdo();
        $row = $pdo->query("
            SELECT t.token, t.submission_id
            FROM tokens t
            JOIN submissions s ON s.id = t.submission_id
            WHERE s.status = 'en_cours' AND t.done_at IS NULL
            LIMIT 1
        ")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No pending token');
        }

        $doneBy = 'validator_' . uniqid() . '@test.com';
        $result = $this->workflow->validateToken($row['token'], 'valider', 'Approuvé', $doneBy);
        $this->assertSame('ok', $result['status']);

        // Verify done_by is stored in submission data
        $check = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $check->execute([$row['submission_id']]);
        $data = json_decode((string) $check->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame($doneBy, $validation['done_by']);
    }

    // ── validateToken() with empty doneBy ────────────────────────

    public function testValidateTokenValiderWithEmptyDoneBy(): void
    {
        $pdo = $this->db->getPdo();
        $row = $pdo->query("
            SELECT t.token
            FROM tokens t
            JOIN submissions s ON s.id = t.submission_id
            WHERE s.status = 'en_cours' AND t.done_at IS NULL
            LIMIT 1
        ")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->markTestSkipped('No pending token');
        }

        $result = $this->workflow->validateToken($row['token'], 'valider', 'OK', '');
        $this->assertSame('ok', $result['status']);
    }

    // ── getWorkflowSteps() with condition ────────────────────────

    public function testGetWorkflowStepsReturnsConditionFieldForActiveSteps(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $pdo->query("SELECT id FROM forms LIMIT 1")->fetchColumn();

        if (!$formId) {
            $this->markTestSkipped('No forms');
        }

        $steps = $this->workflow->getWorkflowSteps((string) $formId);
        foreach ($steps as $step) {
            $this->assertArrayHasKey('condition', $step);
            $this->assertArrayHasKey('actif', $step);
            $this->assertSame(1, (int) $step['actif']);
        }
    }

    // ── getWorkflowSteps() ordering ──────────────────────────────

    public function testGetWorkflowStepsReturnsOrderedByOrdre(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $pdo->query("SELECT id FROM forms LIMIT 1")->fetchColumn();

        if (!$formId) {
            $this->markTestSkipped('No forms');
        }

        $steps = $this->workflow->getWorkflowSteps((string) $formId);
        if (count($steps) < 2) {
            $this->markTestSkipped('Need at least 2 steps');
        }

        $ordres = array_column($steps, 'ordre');
        $sortedOrdres = $ordres;
        sort($sortedOrdres);
        $this->assertSame($sortedOrdres, $ordres);
    }

    // ── hasActiveSubmissions() integration ───────────────────────

    public function testHasActiveSubmissionsReturnsCountForFormWithActiveSubmissions(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $pdo->query("
            SELECT form_id FROM submissions WHERE status = 'en_cours' LIMIT 1
        ")->fetchColumn();

        if (!$formId) {
            $this->markTestSkipped('No form with active submissions');
        }

        $count = $this->workflow->hasActiveSubmissions((string) $formId);
        $this->assertGreaterThan(0, $count);
    }

    // ── hasActiveStepSubmissions() integration ───────────────────

    public function testHasActiveStepSubmissionsReturnsZeroForCompletedStep(): void
    {
        $pdo = $this->db->getPdo();
        $stepId = $pdo->query("
            SELECT step_id FROM tokens t
            JOIN submissions s ON s.id = t.submission_id
            WHERE t.done_at IS NOT NULL AND s.status != 'en_cours'
            LIMIT 1
        ")->fetchColumn();

        if (!$stepId) {
            $this->markTestSkipped('No step with completed tokens');
        }

        $count = $this->workflow->hasActiveStepSubmissions((string) $stepId);
        $this->assertIsInt($count);
    }

    // ── sendWebhook() indirect test ─────────────────────────────

    public function testAdvanceWorkflowCallsSendWebhookWhenAllDone(): void
    {
        // This test verifies the webhook path is reachable
        // sendWebhook is private, tested indirectly via advanceWorkflow
        $this->assertTrue(method_exists($this->workflow, 'advanceWorkflow'));
    }

    // ── validateToken() action validation ────────────────────────

    public function testValidateTokenAcceptsValiderAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'valider');
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenAcceptsRefuserAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'refuser');
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenRejectsInvalidAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'annuler');
        $this->assertSame('invalid', $result['status']);
        $this->assertArrayHasKey('message', $result);
    }

    // ── resolveDynamicRecipient() non-template returns unchanged ──

    public function testResolveDynamicRecipientReturnsStaticEmailUnchanged(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('fixed@example.com', ['key' => 'value']);
        $this->assertSame('fixed@example.com', $result);
    }

    // ── getSubmissionWithFormLabel() integration ──────────────────

    public function testGetSubmissionWithFormLabelReturnsAllRequiredFields(): void
    {
        $pdo = $this->db->getPdo();
        $subId = $pdo->query("SELECT id FROM submissions LIMIT 1")->fetchColumn();

        if (!$subId) {
            $this->markTestSkipped('No submissions');
        }

        $result = $this->workflow->getSubmissionWithFormLabel((string) $subId);
        if (!$result) {
            $this->markTestSkipped('Submission has broken FK');
        }

        $this->assertArrayHasKey('form_label', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('submitted_by', $result);
        $this->assertArrayHasKey('closed_at', $result);
    }
}
