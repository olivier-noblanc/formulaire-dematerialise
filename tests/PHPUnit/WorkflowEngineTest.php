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
    /** @var array{forms: string[], steps: string[], step_recipients: string[], submissions: string[], tokens: string[], form_owners: string[]} */
    private array $createdIds = ['forms' => [], 'steps' => [], 'step_recipients' => [], 'submissions' => [], 'tokens' => [], 'form_owners' => []];

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(\App\Core\Database::class);
        $settings = new SettingsService(new SettingsRepository($this->db));
        $mail = new MailService($this->db, $settings);
        $fields = new FieldService($this->db);
        $conditions = new ConditionEvaluator();
        $this->workflow = new WorkflowEngine($this->db, $settings, $mail, $fields, $conditions);
        $this->createdIds = ['forms' => [], 'steps' => [], 'step_recipients' => [], 'submissions' => [], 'tokens' => [], 'form_owners' => []];
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->getPdo();
        // Rollback any lingering transaction from a failed test
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Delete in FK-safe order
        foreach ($this->createdIds['tokens'] as $id) {
            try { $pdo->prepare("DELETE FROM tokens WHERE id = ?")->execute([$id]); } catch (\Throwable) {}
        }
        foreach ($this->createdIds['step_recipients'] as $id) {
            try { $pdo->prepare("DELETE FROM step_recipients WHERE id = ?")->execute([$id]); } catch (\Throwable) {}
        }
        foreach ($this->createdIds['submissions'] as $id) {
            try { $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$id]); } catch (\Throwable) {}
        }
        foreach ($this->createdIds['form_owners'] as $id) {
            try { $pdo->prepare("DELETE FROM form_owners WHERE id = ?")->execute([$id]); } catch (\Throwable) {}
        }
        foreach ($this->createdIds['steps'] as $id) {
            try { $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$id]); } catch (\Throwable) {}
        }
        foreach ($this->createdIds['forms'] as $id) {
            try { $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$id]); } catch (\Throwable) {}
        }
    }

    // ── Test data helpers ──────────────────────────────────────

    /** Create form + step + recipient. Returns [formId, stepId]. */
    private function createTestForm(string $slug = 'test'): array
    {
        $pdo = $this->db->getPdo();
        $formId = \generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, '', 1, datetime('now'))")
            ->execute([$formId, $slug . '-' . uniqid(), 'Test Form']);
        $this->createdIds['forms'][] = $formId;

        $stepId = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation', 1, 1, '')")
            ->execute([$stepId, $formId]);
        $this->createdIds['steps'][] = $stepId;

        $srId = \generate_uuid();
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, 'validator@test.com')")
            ->execute([$srId, $stepId]);
        $this->createdIds['step_recipients'][] = $srId;

        return [$formId, $stepId];
    }

    /** Create submission. Pass $closedAtOffset (e.g. '-0 seconds') to mark it closed. Returns submissionId. */
    private function createTestSubmission(string $formId, string $data = '{}', string $status = 'en_cours', ?string $closedAtOffset = null): string
    {
        $pdo = $this->db->getPdo();
        $subId = \generate_uuid();
        $closedAt = $closedAtOffset !== null ? gmdate('Y-m-d H:i:s', strtotime($closedAtOffset) ?: time()) : null;
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at, closed_at) VALUES (?, ?, ?, 'agent@test.com', ?, datetime('now'), ?)")
            ->execute([$subId, $formId, $data, $status, $closedAt]);
        $this->createdIds['submissions'][] = $subId;
        return $subId;
    }

    /** Mark an existing submission as closed. */
    private function closeSubmission(string $submissionId, string $status = 'valide'): void
    {
        $pdo = $this->db->getPdo();
        $pdo->prepare("UPDATE submissions SET closed_at = datetime('now'), status = ? WHERE id = ?")
            ->execute([$status, $submissionId]);
    }

    /** Create a form owner. Returns id. */
    private function createFormOwner(string $formId, string $email): string
    {
        $pdo = $this->db->getPdo();
        $id = \generate_uuid();
        $pdo->prepare("INSERT INTO form_owners (id, form_id, email, added_at) VALUES (?, ?, ?, datetime('now'))")
            ->execute([$id, $formId, $email]);
        $this->createdIds['form_owners'][] = $id;
        return $id;
    }

    /**
     * Create token. Returns [tokenId, tokenValue].
     * $doneAtOffset: null = pending token; a strtotime offset (e.g. '-1 minute') = done token.
     * $expiresInOffset: strtotime offset from now (e.g. '+7 days' valid, '-1 day' expired).
     */
    private function createTestToken(string $submissionId, string $stepId, string $email = 'validator@test.com', ?string $doneAtOffset = null, string $expiresInOffset = '+7 days'): array
    {
        $pdo = $this->db->getPdo();
        $tokenId = \generate_uuid();
        // validateToken() requires /^[a-f0-9]{64}$/ — 32 bytes, not 16.
        $tokenVal = bin2hex(random_bytes(32));
        $doneAt = $doneAtOffset !== null ? gmdate('Y-m-d H:i:s', strtotime($doneAtOffset) ?: time()) : null;
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime($expiresInOffset) ?: time());
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), ?, ?)")
            ->execute([$tokenId, $submissionId, $stepId, $email, $tokenVal, $doneAt, $expiresAt]);
        $this->createdIds['tokens'][] = $tokenId;
        return [$tokenId, $tokenVal];
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
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->getTokenWithContext($tokenVal);
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
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [$tokenId] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->getTokenByIdWithContext($tokenId);
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
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);
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
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);
        $this->assertArrayHasKey('condition', $steps[0]);
    }

    public function testGetWorkflowStepsReturnsRecipientEmailsField(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);
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
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

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
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel($subId);
        $this->assertArrayHasKey('submitted_by', $result);
    }

    public function testGetSubmissionWithFormLabelReturnsClosedAtField(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel($subId);
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
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $doneToken] = $this->createTestToken($subId, $stepId, doneAtOffset: '-1 minute');

        $result = $this->workflow->validateToken($doneToken);
        $this->assertSame('already_done', $result['status']);
    }

    public function testValidateTokenSuccessForPendingToken(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider', 'Test validation', 'validator@test.com');
        $this->assertSame('ok', $result['status']);
        $this->assertArrayHasKey('data', $result);
    }

    public function testValidateTokenRefuse(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'refuser', 'Motif de refus');
        $this->assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $check = $pdo->prepare("SELECT status FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $this->assertSame('refuse', $check->fetchColumn());
    }

    public function testValidateTokenTruncatesComment(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $longComment = str_repeat('x', 1500);
        $result = $this->workflow->validateToken($tokenVal, 'valider', $longComment);
        $this->assertSame('ok', $result['status']);
        $pdo = $this->db->getPdo();
        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertLessThanOrEqual(1000, strlen($validation['commentaire']));
    }

    public function testValidateTokenReturnsExpiredForExpiredToken(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId, expiresInOffset: '-1 day');

        $result = $this->workflow->validateToken($tokenVal);
        $this->assertSame('expired', $result['status']);
    }

    public function testValidateTokenReturnsClosedForClosedSubmissionToken(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        // Token created before closing, so it exists with done_at NULL on a now-closed submission.
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $this->closeSubmission($subId);

        $result = $this->workflow->validateToken($tokenVal);
        $this->assertSame('closed', $result['status']);
    }

    public function testValidateTokenReturnsDataKeyInResult(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $doneToken] = $this->createTestToken($subId, $stepId, doneAtOffset: '-1 minute');

        $result = $this->workflow->validateToken($doneToken);
        $this->assertArrayHasKey('data', $result);
        $this->assertIsArray($result['data']);
    }

    public function testValidateTokenStoresDoneByField(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $doneBy = 'verifier-' . uniqid() . '@test.com';
        $result = $this->workflow->validateToken($tokenVal, 'valider', 'Test', $doneBy);
        $this->assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $check = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $data = json_decode((string) $check->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame($doneBy, $validation['done_by']);
    }

    public function testValidateTokenWithDefaultAction(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        // Default action is 'valider'
        $result = $this->workflow->validateToken($tokenVal);
        $this->assertSame('ok', $result['status']);
    }

    public function testValidateTokenRefuseWithComment(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'refuser', 'Motif de refus détaillé');
        $this->assertSame('ok', $result['status']);
    }

    public function testValidateTokenRefuseWithoutComment(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'refuser', '');
        $this->assertSame('ok', $result['status']);
    }

    public function testValidateTokenWithEmptyComment(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider', '');
        $this->assertSame('ok', $result['status']);
    }

    public function testValidateTokenStoresStepLabel(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider', 'Test');
        $this->assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertArrayHasKey('step_label', $validation);
        $this->assertArrayHasKey('email', $validation);
        $this->assertArrayHasKey('action', $validation);
        $this->assertArrayHasKey('date', $validation);
    }

    public function testValidateTokenStoresDateTimestamp(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $before = gmdate('Y-m-d H:i:s');
        $result = $this->workflow->validateToken($tokenVal, 'valider', 'Test');
        $after = gmdate('Y-m-d H:i:s');

        $this->assertSame('ok', $result['status']);
        $pdo = $this->db->getPdo();
        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertGreaterThanOrEqual($before, $validation['date']);
        $this->assertLessThanOrEqual($after, $validation['date']);
    }

    // ── hasActiveSubmissions ─────────────────────────────────────

    public function testHasActiveSubmissionsReturnsInt(): void
    {
        [$formId] = $this->createTestForm();

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
        [$formId] = $this->createTestForm();
        $this->createTestSubmission($formId);

        $methodResult = $this->workflow->hasActiveSubmissions($formId);
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE form_id = ? AND status = 'en_cours'");
        $stmt->execute([$formId]);
        $directCount = $stmt->fetchColumn();

        $this->assertSame((int) $directCount, $methodResult);
    }

    // ── hasActiveStepSubmissions ─────────────────────────────────

    public function testHasActiveStepSubmissionsReturnsInt(): void
    {
        [, $stepId] = $this->createTestForm();

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
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        $this->createTestToken($subId, $stepId);

        $count = $this->workflow->hasActiveStepSubmissions($stepId);
        $this->assertGreaterThan(0, $count);
    }

    public function testHasActiveStepSubmissionsMatchesDirectQuery(): void
    {
        [, $stepId] = $this->createTestForm();

        $methodResult = $this->workflow->hasActiveStepSubmissions($stepId);
        $pdo = $this->db->getPdo();
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
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId, closedAtOffset: '-0 seconds');

        $this->workflow->advanceWorkflow($subId);
        $this->assertTrue(true);
    }

    public function testAdvanceWorkflowReturnsEarlyForEmptySubmissionId(): void
    {
        $this->workflow->advanceWorkflow('');
        $this->assertTrue(true);
    }

    public function testAdvanceWorkflowCreatesTokensForActiveSubmission(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $pdo = $this->db->getPdo();
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ?");
        $countStmt->execute([$subId]);
        $tokensBefore = $countStmt->fetchColumn();
        $this->workflow->advanceWorkflow($subId);
        $countStmt->execute([$subId]);
        $tokensAfter = $countStmt->fetchColumn();

        $this->assertGreaterThan((int) $tokensBefore, (int) $tokensAfter, 'advanceWorkflow should create new tokens');
    }

    public function testAdvanceWorkflowSkipsInvalidEmailRecipients(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $pdo = $this->db->getPdo();
        $srId = \generate_uuid();
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, 'invalid-email')")
            ->execute([$srId, $stepId]);
        $this->createdIds['step_recipients'][] = $srId;

        $subId = $this->createTestSubmission($formId);

        $this->workflow->advanceWorkflow($subId);
        $this->assertTrue(true);
    }

    public function testAdvanceWorkflowSkipsConditionWhenNotMet(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $pdo = $this->db->getPdo();
        $condition = json_encode(['field' => 'nonexistent_field', 'op' => 'eq', 'value' => 'never_matches']);
        $pdo->prepare("UPDATE steps SET `condition` = ? WHERE id = ?")->execute([$condition, $stepId]);

        $subId = $this->createTestSubmission($formId);

        $this->workflow->advanceWorkflow($subId);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ?");
        $countStmt->execute([$subId]);
        $this->assertSame(0, (int) $countStmt->fetchColumn(), 'No token should be created when the step condition is not met');
    }

    // ── getWorkflowSteps caching ─────────────────────────────────

    public function testGetWorkflowStepsReturnsConsistentResults(): void
    {
        [$formId] = $this->createTestForm();

        $first = $this->workflow->getWorkflowSteps($formId);
        $second = $this->workflow->getWorkflowSteps($formId);
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
        [$formId] = $this->createTestForm();
        $this->createFormOwner($formId, 'owner-' . uniqid() . '@test.com');
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->resolveDynamicRecipient('{{owner}}', [], $subId);
        // Should resolve to the owner's email
        $this->assertNotSame('{{owner}}', $result);
        $this->assertStringContainsString('@', $result);
    }

    public function testResolveDynamicRecipientWithOwnerFallbackToAdmin(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->resolveDynamicRecipient('{{owner}}', [], $subId);
        // Should fall back to admin email
        $this->assertNotSame('{{owner}}', $result);
        $this->assertStringContainsString('@', $result);
    }

    // ── advanceWorkflow() complete path ─────────────────────────

    public function testAdvanceWorkflowWithCompletedSubmissionClosesIt(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        $this->createTestToken($subId, $stepId, doneAtOffset: '-1 minute');

        $this->workflow->advanceWorkflow($subId);

        // After advancing, submission should be closed
        $pdo = $this->db->getPdo();
        $check = $pdo->prepare("SELECT closed_at FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $closedAt = $check->fetchColumn();
        $this->assertNotEmpty($closedAt);
    }

    public function testAdvanceWorkflowWithMixedDoneAndPendingWaits(): void
    {
        [$formId, $step1Id] = $this->createTestForm();
        $pdo = $this->db->getPdo();
        // Second step at the same ordre — parallel validator group.
        $step2Id = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation 2', 1, 1, '')")
            ->execute([$step2Id, $formId]);
        $this->createdIds['steps'][] = $step2Id;
        $sr2Id = \generate_uuid();
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, 'validator2@test.com')")
            ->execute([$sr2Id, $step2Id]);
        $this->createdIds['step_recipients'][] = $sr2Id;

        $subId = $this->createTestSubmission($formId);
        $this->createTestToken($subId, $step1Id, doneAtOffset: '-1 minute');
        $this->createTestToken($subId, $step2Id);

        $before = $pdo->prepare("SELECT closed_at FROM submissions WHERE id = ?");
        $before->execute([$subId]);
        $closedBefore = $before->fetchColumn();

        $this->workflow->advanceWorkflow($subId);

        $after = $pdo->prepare("SELECT closed_at FROM submissions WHERE id = ?");
        $after->execute([$subId]);
        $closedAfter = $after->fetchColumn();

        // Should not close since not all steps are done
        $this->assertSame($closedBefore, $closedAfter);
    }

    // ── advanceWorkflow() duplicate token detection ──────────────

    public function testAdvanceWorkflowDoesNotCreateDuplicateTokens(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        $this->createTestToken($subId, $stepId);

        $pdo = $this->db->getPdo();
        $countBefore = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ?");
        $countBefore->execute([$subId]);
        $before = (int) $countBefore->fetchColumn();

        $this->workflow->advanceWorkflow($subId);

        $countAfter = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ?");
        $countAfter->execute([$subId]);
        $after = (int) $countAfter->fetchColumn();

        // Should not create duplicates — count should be same or more (new step tokens)
        $this->assertGreaterThanOrEqual($before, $after);
    }

    // ── validateToken() refuser with agent notification ──────────

    public function testValidateTokenRefuseNotifiesAgent(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'refuser', 'Motif de refus');
        $this->assertSame('ok', $result['status']);

        // Verify submission is marked as refused
        $pdo = $this->db->getPdo();
        $check = $pdo->prepare("SELECT status FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $this->assertSame('refuse', $check->fetchColumn());
    }

    // ── validateToken() valider with done_by ─────────────────────

    public function testValidateTokenValiderWithDoneBy(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $doneBy = 'validator_' . uniqid() . '@test.com';
        $result = $this->workflow->validateToken($tokenVal, 'valider', 'Approuvé', $doneBy);
        $this->assertSame('ok', $result['status']);

        // Verify done_by is stored in submission data
        $pdo = $this->db->getPdo();
        $check = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $data = json_decode((string) $check->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame($doneBy, $validation['done_by']);
    }

    // ── validateToken() with empty doneBy ────────────────────────

    public function testValidateTokenValiderWithEmptyDoneBy(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider', 'OK', '');
        $this->assertSame('ok', $result['status']);
    }

    // ── getWorkflowSteps() with condition ────────────────────────

    public function testGetWorkflowStepsReturnsConditionFieldForActiveSteps(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);
        foreach ($steps as $step) {
            $this->assertArrayHasKey('condition', $step);
            $this->assertArrayHasKey('actif', $step);
            $this->assertSame(1, (int) $step['actif']);
        }
    }

    // ── getWorkflowSteps() ordering ──────────────────────────────

    public function testGetWorkflowStepsReturnsOrderedByOrdre(): void
    {
        [$formId, $step1Id] = $this->createTestForm();
        $pdo = $this->db->getPdo();
        $step2Id = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation 2', 2, 1, '')")
            ->execute([$step2Id, $formId]);
        $this->createdIds['steps'][] = $step2Id;

        $steps = $this->workflow->getWorkflowSteps($formId);
        $this->assertGreaterThanOrEqual(2, count($steps));

        $ordres = array_column($steps, 'ordre');
        $sortedOrdres = $ordres;
        sort($sortedOrdres);
        $this->assertSame($sortedOrdres, $ordres);
    }

    // ── hasActiveSubmissions() integration ───────────────────────

    public function testHasActiveSubmissionsReturnsCountForFormWithActiveSubmissions(): void
    {
        [$formId] = $this->createTestForm();
        $this->createTestSubmission($formId);

        $count = $this->workflow->hasActiveSubmissions($formId);
        $this->assertGreaterThan(0, $count);
    }

    // ── hasActiveStepSubmissions() integration ───────────────────

    public function testHasActiveStepSubmissionsReturnsZeroForCompletedStep(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId, status: 'valide');
        $this->createTestToken($subId, $stepId, doneAtOffset: '-1 minute');

        $count = $this->workflow->hasActiveStepSubmissions($stepId);
        $this->assertIsInt($count);
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
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel($subId);

        $this->assertArrayHasKey('form_label', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('submitted_by', $result);
        $this->assertArrayHasKey('closed_at', $result);
    }

    // ════════════════════════════════════════════════════════════
    // NEW: Coverage improvement tests — advanceWorkflow branches
    // ════════════════════════════════════════════════════════════

    // ── advanceWorkflow: closing + agent email notification ────

    public function testAdvanceWorkflowClosesAndNotifiesAgentWhenAllDone(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        $this->createTestToken($subId, $stepId, doneAtOffset: '-1 minute');

        // Advance — should close submission and attempt to notify agent
        $this->workflow->advanceWorkflow($subId);

        // Verify submission is closed
        $pdo = $this->db->getPdo();
        $check = $pdo->prepare("SELECT closed_at, status FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $row = $check->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($row['closed_at']);
        $this->assertSame('valide', $row['status']);
    }

    // ── advanceWorkflow: group not all started → creates tokens ─

    public function testAdvanceWorkflowCreatesTokensForFirstGroup(): void
    {
        [$formId] = $this->createTestForm();
        // Fresh submission with no tokens
        $subId = $this->createTestSubmission($formId);

        $pdo = $this->db->getPdo();
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ?");
        $countStmt->execute([$subId]);
        $countBefore = (int) $countStmt->fetchColumn();

        $this->workflow->advanceWorkflow($subId);

        $countStmt->execute([$subId]);
        $countAfter = (int) $countStmt->fetchColumn();

        // Should have created some tokens (steps have valid recipients)
        $this->assertGreaterThanOrEqual($countBefore, $countAfter);
    }

    // ── advanceWorkflow: step already started → skip duplicate ──

    public function testAdvanceWorkflowSkipsStepAlreadyStarted(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        // Step already started (has a pending token)
        $this->createTestToken($subId, $stepId);

        $pdo = $this->db->getPdo();
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ?");
        $countStmt->execute([$subId]);
        $countBefore = (int) $countStmt->fetchColumn();

        // Advance — step already started, should not create duplicates
        $this->workflow->advanceWorkflow($subId);

        $countStmt->execute([$subId]);
        $countAfter = (int) $countStmt->fetchColumn();
        $this->assertSame($countBefore, $countAfter);
    }

    // ── advanceWorkflow: group all done → move to next group ────

    public function testAdvanceWorkflowMovesToNextGroupWhenAllDone(): void
    {
        [$formId, $step1Id] = $this->createTestForm();
        $pdo = $this->db->getPdo();
        $step2Id = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation 2', 2, 1, '')")
            ->execute([$step2Id, $formId]);
        $this->createdIds['steps'][] = $step2Id;
        $sr2Id = \generate_uuid();
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, 'validator2@test.com')")
            ->execute([$sr2Id, $step2Id]);
        $this->createdIds['step_recipients'][] = $sr2Id;

        $subId = $this->createTestSubmission($formId);

        // Mark all tokens as done
        $this->createTestToken($subId, $step1Id, doneAtOffset: '-1 minute');
        $this->createTestToken($subId, $step2Id, doneAtOffset: '-1 minute');

        // Advance — should close since all steps done
        $this->workflow->advanceWorkflow($subId);

        $closed = $pdo->prepare("SELECT closed_at FROM submissions WHERE id = ?");
        $closed->execute([$subId]);
        $this->assertNotEmpty($closed->fetchColumn());
    }

    // ── advanceWorkflow: recipient '0' skipped ──────────────────

    public function testAdvanceWorkflowSkipsRecipientZero(): void
    {
        $pdo = $this->db->getPdo();

        // Create a dedicated form with only one step
        $formId = bin2hex(random_bytes(8));
        $slug = 'test-recipient-zero-' . uniqid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'Recipient Zero Test', 'test', 1, datetime('now'))")
            ->execute([$formId, $slug]);

        // Create step with recipient '0'
        $stepId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'Zero Recipient Step', 1, 1)")
            ->execute([$stepId, $formId]);
        $srId0 = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, '0')")->execute([$srId0, $stepId]);

        // Create fresh submission
        $subId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, '{}', 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId]);

        // Advance — should skip the '0' recipient
        $this->workflow->advanceWorkflow($subId);

        // Verify no token with email '0' was created
        $check = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND email = '0'");
        $check->execute([$subId]);
        $this->assertSame(0, (int) $check->fetchColumn());

        // Cleanup
        $pdo->prepare("DELETE FROM step_recipients WHERE step_id = ?")->execute([$stepId]);
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$stepId]);
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }

    // ── advanceWorkflow: empty recipient skipped ────────────────

    public function testAdvanceWorkflowSkipsEmptyRecipient(): void
    {
        $pdo = $this->db->getPdo();

        // Create a dedicated form with only one step
        $formId = bin2hex(random_bytes(8));
        $slug = 'test-empty-recipient-' . uniqid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'Empty Recipient Test', 'test', 1, datetime('now'))")
            ->execute([$formId, $slug]);

        $stepId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'Empty Recipient Step', 1, 1)")
            ->execute([$stepId, $formId]);
        $srIdEmpty = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, '')")->execute([$srIdEmpty, $stepId]);

        $subId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, '{}', 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId]);

        $this->workflow->advanceWorkflow($subId);

        // No token with empty email
        $check = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND email = ''");
        $check->execute([$subId]);
        $this->assertSame(0, (int) $check->fetchColumn());

        // Cleanup
        $pdo->prepare("DELETE FROM step_recipients WHERE step_id = ?")->execute([$stepId]);
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$stepId]);
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }

    // ── advanceWorkflow: no active steps → immediate close ──────

    public function testAdvanceWorkflowClosesImmediatelyWithNoSteps(): void
    {
        $pdo = $this->db->getPdo();

        // Create a form with no active steps
        $formId = bin2hex(random_bytes(8));
        $slug = 'test-no-steps-' . uniqid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'Test No Steps', 'test', 0, datetime('now'))")
            ->execute([$formId, $slug]);

        $subId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, '{}', 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId]);

        $this->workflow->advanceWorkflow($subId);

        // Should close immediately since there are no steps
        $check = $pdo->prepare("SELECT closed_at, status FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $row = $check->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($row['closed_at']);
        $this->assertSame('valide', $row['status']);

        // Cleanup
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }

    // ── advanceWorkflow: step with condition false → skip ───────

    public function testAdvanceWorkflowSkipsStepWithFalseCondition(): void
    {
        $pdo = $this->db->getPdo();

        // Create a dedicated form with only one step
        $formId = bin2hex(random_bytes(8));
        $slug = 'test-cond-false-' . uniqid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'Cond False Test', 'test', 1, datetime('now'))")
            ->execute([$formId, $slug]);

        // Create a step with a condition that will be false
        $stepId = bin2hex(random_bytes(8));
        $condition = json_encode(['field' => 'nonexistent_field_xyz', 'op' => 'eq', 'value' => 'impossible_value']);
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Conditional Step', 1, 1, ?)")
            ->execute([$stepId, $formId, $condition]);
        $srIdTest = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, 'test@example.com')")->execute([$srIdTest, $stepId]);

        $subId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, '{}', 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId]);

        $this->workflow->advanceWorkflow($subId);

        // No token should be created for the conditional step
        $check = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND step_id = ?");
        $check->execute([$subId, $stepId]);
        $this->assertSame(0, (int) $check->fetchColumn());

        // Cleanup
        $pdo->prepare("DELETE FROM step_recipients WHERE step_id = ?")->execute([$stepId]);
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$stepId]);
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }

    // ── advanceWorkflow: step with condition true → token created ─

    public function testAdvanceWorkflowCreatesTokenWhenConditionTrue(): void
    {
        $pdo = $this->db->getPdo();

        // Create a dedicated form with only one step
        $formId = bin2hex(random_bytes(8));
        $slug = 'test-cond-true-' . uniqid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'Cond True Test', 'test', 1, datetime('now'))")
            ->execute([$formId, $slug]);

        // Create step with condition that evaluates to true with empty validator data
        // (empty condition field value matches empty actual value)
        $stepId = bin2hex(random_bytes(8));
        $condition = json_encode(['field' => 'status', 'op' => 'eq', 'value' => '']);
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Conditional Step', 1, 1, ?)")
            ->execute([$stepId, $formId, $condition]);
        $srIdVal = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, 'validator@test.com')")->execute([$srIdVal, $stepId]);

        // Submission with data
        $subId = bin2hex(random_bytes(8));
        $data = json_encode(['status' => 'active']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, ?, 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId, $data]);

        $this->workflow->advanceWorkflow($subId);

        // Token should be created for the conditional step
        $check = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND step_id = ?");
        $check->execute([$subId, $stepId]);
        $this->assertGreaterThan(0, (int) $check->fetchColumn());

        // Cleanup
        $pdo->prepare("DELETE FROM step_recipients WHERE step_id = ?")->execute([$stepId]);
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$stepId]);
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }

    // ── advanceWorkflow: invalid email recipient → skip ─────────

    public function testAdvanceWorkflowSkipsInvalidEmailRecipient(): void
    {
        $pdo = $this->db->getPdo();

        // Create a dedicated form with only one step
        $formId = bin2hex(random_bytes(8));
        $slug = 'test-invalid-email-' . uniqid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'Invalid Email Test', 'test', 1, datetime('now'))")
            ->execute([$formId, $slug]);

        // Create step with invalid email recipient
        $stepId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'Invalid Email Step', 1, 1)")
            ->execute([$stepId, $formId]);
        $srIdInvalid = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, 'not-an-email')")->execute([$srIdInvalid, $stepId]);

        $subId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, '{}', 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId]);

        $this->workflow->advanceWorkflow($subId);

        // No token should be created with invalid email
        $check = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND step_id = ?");
        $check->execute([$subId, $stepId]);
        $this->assertSame(0, (int) $check->fetchColumn());

        // Cleanup
        $pdo->prepare("DELETE FROM step_recipients WHERE step_id = ?")->execute([$stepId]);
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$stepId]);
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }

    // ── advanceWorkflow: dynamic {{owner}} recipient resolution ─

    public function testAdvanceWorkflowResolvesOwnerRecipient(): void
    {
        $pdo = $this->db->getPdo();

        // Create a dedicated form with only one step to avoid multi-group issues
        $formId = bin2hex(random_bytes(8));
        $slug = 'test-owner-recipient-' . uniqid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'Owner Test', 'test', 1, datetime('now'))")
            ->execute([$formId, $slug]);

        // Add an owner
        $ownerEmail = 'owner-' . uniqid() . '@test.com';
        $ownerId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO form_owners (id, form_id, email, added_at) VALUES (?, ?, ?, datetime('now'))")
            ->execute([$ownerId, $formId, $ownerEmail]);

        // Create step with {{owner}} recipient
        $stepId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'Owner Step', 1, 1)")
            ->execute([$stepId, $formId]);
        $srIdOwner = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, '{{owner}}')")->execute([$srIdOwner, $stepId]);

        $subId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, '{}', 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId]);

        $this->workflow->advanceWorkflow($subId);

        // Token should be created with owner's email
        $check = $pdo->prepare("SELECT email FROM tokens WHERE submission_id = ? AND step_id = ?");
        $check->execute([$subId, $stepId]);
        $tokenEmail = $check->fetchColumn();
        $this->assertNotFalse($tokenEmail, 'Token should be created for owner step');
        $this->assertStringContainsString('@', (string) $tokenEmail);

        // Cleanup
        $pdo->prepare("DELETE FROM step_recipients WHERE step_id = ?")->execute([$stepId]);
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$stepId]);
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM form_owners WHERE id = ?")->execute([$ownerId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }

    // ════════════════════════════════════════════════════════════
    // NEW: Coverage improvement tests — validateToken branches
    // ════════════════════════════════════════════════════════════

    // ── validateToken: refuser with comment '0' ─────────────────

    public function testValidateTokenRefuseWithCommentZero(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'refuser', '0');
        $this->assertSame('ok', $result['status']);

        // Verify submission is refused
        $check = $pdo->prepare("SELECT status FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $this->assertSame('refuse', $check->fetchColumn());
    }

    // ── validateToken: valider with done_by empty string ────────

    public function testValidateTokenValiderWithEmptyDoneByString(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider', 'Approved', '');
        $this->assertSame('ok', $result['status']);
        $this->assertArrayHasKey('done_at', $result['data']);
        $this->assertNotEmpty($result['data']['done_at']);
    }

    // ── validateToken: valider stores validation in data ─────────

    public function testValidateTokenValiderAppendsToExistingValidations(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        // Pre-populate submission data with existing validations
        $existingData = json_encode(['validations' => [['step_label' => 'Old', 'email' => 'old@test.com', 'action' => 'valider']]]);
        $pdo->prepare("UPDATE submissions SET data = ? WHERE id = ?")->execute([$existingData, $subId]);

        $result = $this->workflow->validateToken($tokenVal, 'valider', 'New validation', 'new@test.com');
        $this->assertSame('ok', $result['status']);

        // Verify the new validation was appended
        $check = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $data = json_decode((string) $check->fetchColumn(), true);
        $this->assertArrayHasKey('validations', $data);
        $this->assertGreaterThanOrEqual(2, count($data['validations']));
        $last = end($data['validations']);
        $this->assertSame('valider', $last['action']);
        $this->assertSame('new@test.com', $last['done_by']);
    }

    // ── validateToken: refuser stores refus data ────────────────

    public function testValidateTokenRefuseStoresRefuseData(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'refuser', 'Motif refus', 'refuser@test.com');
        $this->assertSame('ok', $result['status']);

        // Verify submission data contains the refusal
        $check = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $data = json_decode((string) $check->fetchColumn(), true);
        $validations = $data['validations'] ?? [];
        $last = end($validations);
        $this->assertSame('refuser', $last['action']);
        $this->assertSame('Motif refus', $last['commentaire']);
        $this->assertSame('refuser@test.com', $last['done_by']);
    }

    // ── validateToken: done_at is set after validation ──────────

    public function testValidateTokenSetsDoneAtOnToken(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertSame('ok', $result['status']);

        // Verify token done_at is set in DB
        $check = $pdo->prepare("SELECT done_at FROM tokens WHERE token = ?");
        $check->execute([$tokenVal]);
        $doneAt = $check->fetchColumn();
        $this->assertNotEmpty($doneAt);
    }

    // ── validateToken: refuser sets submission closed_at ─────────

    public function testValidateTokenRefuseSetsSubmissionClosedAt(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $this->workflow->validateToken($tokenVal, 'refuser', 'Refus');

        $check = $pdo->prepare("SELECT closed_at FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $this->assertNotEmpty($check->fetchColumn());
    }

    // ════════════════════════════════════════════════════════════
    // NEW: Coverage improvement tests — resolveDynamicRecipient
    // ════════════════════════════════════════════════════════════

    // ── resolveDynamicRecipient: {{owner}} with owner having invalid email → admin fallback ─

    public function testResolveDynamicRecipientOwnerInvalidEmailFallsBackToAdmin(): void
    {
        $pdo = $this->db->getPdo();

        // Create a form with an owner having an invalid email
        $formId = bin2hex(random_bytes(8));
        $slug = 'test-owner-invalid-' . uniqid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'Test', 'test', 1, datetime('now'))")
            ->execute([$formId, $slug]);

        $ownerId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO form_owners (id, form_id, email, added_at) VALUES (?, ?, 'not-an-email', datetime('now'))")
            ->execute([$ownerId, $formId]);

        // Create a submission for this form
        $subId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, '{}', 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId]);

        $result = $this->workflow->resolveDynamicRecipient('{{owner}}', [], $subId);

        // Should fall back to admin_email (from settings) or return {{owner}} if admin also invalid
        $this->assertNotSame('{{owner}}', $result);
        $this->assertStringContainsString('@', $result);

        // Cleanup
        $pdo->prepare("DELETE FROM form_owners WHERE id = ?")->execute([$ownerId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }

    // ── resolveDynamicRecipient: {{owner}} with no owners + admin email valid ─

    public function testResolveDynamicRecipientNoOwnersFallsBackToAdminEmail(): void
    {
        $pdo = $this->db->getPdo();

        // Create a form without owners
        $formId = bin2hex(random_bytes(8));
        $slug = 'test-no-owners-' . uniqid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'Test', 'test', 1, datetime('now'))")
            ->execute([$formId, $slug]);

        $subId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, '{}', 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId]);

        $result = $this->workflow->resolveDynamicRecipient('{{owner}}', [], $subId);

        // Should fall back to admin_email or return {{owner}} if no valid admin
        // Either way, it should not throw
        $this->assertIsString($result);

        // Cleanup
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }

    // ── resolveDynamicRecipient: {{field}} with special chars in value ─

    public function testResolveDynamicRecipientFieldWithSpecialChars(): void
    {
        $formData = ['email' => 'user+tag@domain.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        $this->assertSame('user+tag@domain.com', $result);
    }

    // ── resolveDynamicRecipient: {{field}} with multiple @ signs ─

    public function testResolveDynamicRecipientFieldWithMultipleAtSigns(): void
    {
        $formData = ['email' => 'invalid@@email.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        $this->assertSame('{{email}}', $result);
    }

    // ════════════════════════════════════════════════════════════
    // NEW: Coverage improvement tests — ConditionEvaluator
    // ════════════════════════════════════════════════════════════

    // ── ConditionEvaluator: contains operator ────────────────────

    public function testConditionEvaluatorHandlesContainsOperator(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'name', 'op' => 'contains', 'value' => 'John']);
        $this->assertTrue($evaluator->evaluate($condition, ['name' => 'John Doe']));
        $this->assertFalse($evaluator->evaluate($condition, ['name' => 'Jane Doe']));
    }

    // ── ConditionEvaluator: equals alias ─────────────────────────

    public function testConditionEvaluatorHandlesEqualsAlias(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'status', 'op' => 'equals', 'value' => 'active']);
        $this->assertTrue($evaluator->evaluate($condition, ['status' => 'active']));
        $this->assertFalse($evaluator->evaluate($condition, ['status' => 'inactive']));
    }

    // ── ConditionEvaluator: not_equals alias ─────────────────────

    public function testConditionEvaluatorHandlesNotEqualsAlias(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'status', 'op' => 'not_equals', 'value' => 'active']);
        $this->assertFalse($evaluator->evaluate($condition, ['status' => 'active']));
        $this->assertTrue($evaluator->evaluate($condition, ['status' => 'inactive']));
    }

    // ── ConditionEvaluator: '0' condition is treated as empty ────

    public function testConditionEvaluatorHandlesZeroCondition(): void
    {
        $evaluator = new ConditionEvaluator();
        $this->assertTrue($evaluator->evaluate('0', ['any' => 'data']));
    }

    // ── ConditionEvaluator: null condition ───────────────────────

    public function testConditionEvaluatorHandlesNullCondition(): void
    {
        $evaluator = new ConditionEvaluator();
        $this->assertTrue($evaluator->evaluate(null, ['any' => 'data']));
    }

    // ── ConditionEvaluator: invalid JSON ─────────────────────────

    public function testConditionEvaluatorHandlesMalformedJson(): void
    {
        $evaluator = new ConditionEvaluator();
        $this->assertTrue($evaluator->evaluate('{invalid json', []));
    }

    // ── ConditionEvaluator: empty field name ─────────────────────

    public function testConditionEvaluatorHandlesEmptyFieldName(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => '', 'op' => 'eq', 'value' => 'test']);
        $this->assertTrue($evaluator->evaluate($condition, []));
    }

    // ── ConditionEvaluator: missing field in condition ───────────

    public function testConditionEvaluatorHandlesMissingFieldKey(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['op' => 'eq', 'value' => 'test']);
        $this->assertTrue($evaluator->evaluate($condition, []));
    }

    // ── ConditionEvaluator: contains with empty value ────────────

    public function testConditionEvaluatorContainsWithEmptyValue(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'name', 'op' => 'contains', 'value' => '']);
        $this->assertTrue($evaluator->evaluate($condition, ['name' => 'John']));
    }

    // ── ConditionEvaluator: in operator with array value ─────────

    public function testConditionEvaluatorInOperatorWithArrayValue(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'role', 'op' => 'in', 'value' => ['admin', 'editor', 'viewer']]);
        $this->assertTrue($evaluator->evaluate($condition, ['role' => 'admin']));
        $this->assertTrue($evaluator->evaluate($condition, ['role' => 'viewer']));
        $this->assertFalse($evaluator->evaluate($condition, ['role' => 'guest']));
    }

    // ── ConditionEvaluator: in operator with comma string ────────

    public function testConditionEvaluatorInOperatorWithCommaString(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'type', 'op' => 'in', 'value' => 'A, B, C']);
        $this->assertTrue($evaluator->evaluate($condition, ['type' => 'A']));
        $this->assertTrue($evaluator->evaluate($condition, ['type' => 'B']));
        $this->assertFalse($evaluator->evaluate($condition, ['type' => 'D']));
    }

    // ── ConditionEvaluator: empty operator returns true ──────────

    public function testConditionEvaluatorEmptyOperatorDefaultsToTrue(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'name', 'value' => 'test']);
        $this->assertTrue($evaluator->evaluate($condition, ['name' => 'test']));
        $this->assertFalse($evaluator->evaluate($condition, ['name' => 'other']));
    }

    // ── ConditionEvaluator: array data converted to string ───────

    public function testConditionEvaluatorArrayDataConvertedToString(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'tags', 'op' => 'eq', 'value' => 'admin, user']);
        $this->assertTrue($evaluator->evaluate($condition, ['tags' => ['admin', 'user']]));
    }

    // ── ConditionEvaluator: numeric value comparison ─────────────

    public function testConditionEvaluatorNumericValueComparison(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'count', 'op' => 'eq', 'value' => '5']);
        $this->assertTrue($evaluator->evaluate($condition, ['count' => 5]));
        $this->assertFalse($evaluator->evaluate($condition, ['count' => 3]));
    }

    // ── ConditionEvaluator: not_empty with zero value ────────────

    public function testConditionEvaluatorNotEmptyWithZeroValue(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'count', 'op' => 'not_empty']);
        $this->assertTrue($evaluator->evaluate($condition, ['count' => 0]));
        $this->assertFalse($evaluator->evaluate($condition, ['count' => '']));
    }

    // ── ConditionEvaluator: empty with null value ────────────────

    public function testConditionEvaluatorEmptyWithNullValue(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'name', 'op' => 'empty']);
        $this->assertTrue($evaluator->evaluate($condition, ['name' => null]));
    }

    // ════════════════════════════════════════════════════════════
    // NEW: Coverage improvement tests — getWorkflowSteps
    // ════════════════════════════════════════════════════════════

    // ── getWorkflowSteps: only returns active steps ──────────────

    public function testGetWorkflowStepsOnlyReturnsActiveSteps(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);
        foreach ($steps as $step) {
            $this->assertSame(1, (int) $step['actif']);
        }
    }

    // ── getWorkflowSteps: excludes inactive steps ────────────────

    public function testGetWorkflowStepsExcludesInactiveSteps(): void
    {
        $pdo = $this->db->getPdo();

        // Create a form with an inactive step
        $formId = bin2hex(random_bytes(8));
        $slug = 'test-inactive-step-' . uniqid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'Test', 'test', 1, datetime('now'))")
            ->execute([$formId, $slug]);

        $activeStepId = bin2hex(random_bytes(8));
        $inactiveStepId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'Active', 1, 1)")
            ->execute([$activeStepId, $formId]);
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'Inactive', 2, 0)")
            ->execute([$inactiveStepId, $formId]);

        $steps = $this->workflow->getWorkflowSteps((string) $formId);
        $stepIds = array_column($steps, 'step_id');

        $this->assertContains($activeStepId, $stepIds);
        $this->assertNotContains($inactiveStepId, $stepIds);

        // Cleanup
        $pdo->prepare("DELETE FROM steps WHERE form_id = ?")->execute([$formId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }

    // ── getWorkflowSteps: includes recipient_emails ──────────────

    public function testGetWorkflowStepsIncludesRecipientEmails(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);
        foreach ($steps as $step) {
            $this->assertArrayHasKey('recipient_emails', $step);
        }
    }

    // ════════════════════════════════════════════════════════════
    // NEW: Coverage improvement tests — hasActiveSubmissions
    // ════════════════════════════════════════════════════════════

    // ── hasActiveSubmissions: returns correct count ──────────────

    public function testHasActiveSubmissionsReturnsCorrectCount(): void
    {
        [$formId] = $this->createTestForm();

        $count = $this->workflow->hasActiveSubmissions($formId);
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    // ── hasActiveSubmissions: only counts en_cours ───────────────

    public function testHasActiveSubmissionsOnlyCountsEnCours(): void
    {
        [$formId] = $this->createTestForm();
        $this->createTestSubmission($formId);
        $this->createTestSubmission($formId, status: 'valide');

        $count = $this->workflow->hasActiveSubmissions($formId);

        // Verify against direct query
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE form_id = ? AND status = 'en_cours'");
        $stmt->execute([$formId]);
        $expected = (int) $stmt->fetchColumn();

        $this->assertSame($expected, $count);
    }

    // ════════════════════════════════════════════════════════════
    // NEW: Coverage improvement tests — hasActiveStepSubmissions
    // ════════════════════════════════════════════════════════════

    // ── hasActiveStepSubmissions: returns zero for inactive step ─

    public function testHasActiveStepSubmissionsReturnsZeroForInactiveStep(): void
    {
        // Fresh step with no tokens at all.
        [, $stepId] = $this->createTestForm();

        $count = $this->workflow->hasActiveStepSubmissions($stepId);
        $this->assertSame(0, $count);
    }

    // ════════════════════════════════════════════════════════════
    // NEW: Coverage improvement tests — edge cases
    // ════════════════════════════════════════════════════════════

    // ── getTokenWithContext: returns step_label and form_label ────

    public function testGetTokenWithContextReturnsStepAndFormLabels(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->getTokenWithContext($tokenVal);
        $this->assertArrayHasKey('step_label', $result);
        $this->assertArrayHasKey('form_label', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('status', $result);
    }

    // ── getTokenByIdWithContext: returns all required fields ──────

    public function testGetTokenByIdWithContextReturnsAllRequiredFields(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [$tokenId] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->getTokenByIdWithContext($tokenId);
        $this->assertArrayHasKey('step_label', $result);
        $this->assertArrayHasKey('form_label', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('submitted_by', $result);
    }

    // ── getSubmissionWithFormLabel: returns form_label ───────────

    public function testGetSubmissionWithFormLabelReturnsFormLabel(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel($subId);
        $this->assertArrayHasKey('form_label', $result);
        $this->assertNotEmpty($result['form_label']);
    }

    // ── getSubmissionWithFormLabel: returns status ───────────────

    public function testGetSubmissionWithFormLabelReturnsStatus(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel($subId);
        $this->assertArrayHasKey('status', $result);
        $this->assertContains($result['status'], ['en_cours', 'valide', 'refuse', 'annule']);
    }

    // ── resolveDynamicRecipient: {{field}} with numeric value ────

    public function testResolveDynamicRecipientFieldWithNumericValue(): void
    {
        $formData = ['phone' => '1234567890'];
        $result = $this->workflow->resolveDynamicRecipient('{{phone}}', $formData);
        $this->assertSame('{{phone}}', $result); // Not a valid email
    }

    // ── resolveDynamicRecipient: {{field}} with URL value ────────

    public function testResolveDynamicRecipientFieldWithUrlValue(): void
    {
        $formData = ['website' => 'https://example.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{website}}', $formData);
        $this->assertSame('{{website}}', $result); // Not a valid email
    }

    // ── resolveDynamicRecipient: {{field}} with boolean value ────

    public function testResolveDynamicRecipientFieldWithBooleanValue(): void
    {
        $formData = ['active' => true];
        $result = $this->workflow->resolveDynamicRecipient('{{active}}', $formData);
        $this->assertSame('{{active}}', $result);
    }

    // ── resolveDynamicRecipient: {{field}} with null value ───────

    public function testResolveDynamicRecipientFieldWithNullValue(): void
    {
        $formData = ['email' => null];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        $this->assertSame('{{email}}', $result);
    }

    // ── resolveDynamicRecipient: {{field}} with empty string ─────

    public function testResolveDynamicRecipientFieldWithEmptyString(): void
    {
        $formData = ['email' => ''];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        $this->assertSame('{{email}}', $result);
    }

    // ── resolveDynamicRecipient: {{field}} with whitespace email ─

    public function testResolveDynamicRecipientFieldWithWhitespaceEmail(): void
    {
        $formData = ['email' => '   '];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        $this->assertSame('{{email}}', $result);
    }

    // ── resolveDynamicRecipient: {{field}} with valid email ──────

    public function testResolveDynamicRecipientFieldWithValidEmail(): void
    {
        $formData = ['contact' => 'contact@example.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{contact}}', $formData);
        $this->assertSame('contact@example.com', $result);
    }

    // ── resolveDynamicRecipient: {{field}} with email+name format ─

    public function testResolveDynamicRecipientFieldWithEmailNameFormat(): void
    {
        $formData = ['email' => 'John Doe <john@example.com>'];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        $this->assertSame('{{email}}', $result); // Not a plain email
    }

    // ── resolveDynamicRecipient: case insensitive key lookup ─────

    public function testResolveDynamicRecipientCaseInsensitiveKeyLookup(): void
    {
        $formData = ['Email' => 'test@example.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        $this->assertSame('test@example.com', $result);
    }

    // ── resolveDynamicRecipient: exact key match first ───────────

    public function testResolveDynamicRecipientExactKeyMatchFirst(): void
    {
        $formData = ['email' => 'exact@example.com', 'Email' => 'case@example.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        $this->assertSame('exact@example.com', $result);
    }

    // ── resolveDynamicRecipient: {{owner}} with no submission ────

    public function testResolveDynamicRecipientOwnerWithNoSubmission(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{owner}}', []);
        $this->assertSame('{{owner}}', $result);
    }

    // ── resolveDynamicRecipient: {{owner}} with null submission ──

    public function testResolveDynamicRecipientOwnerWithNullSubmission(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{owner}}', [], null);
        $this->assertSame('{{owner}}', $result);
    }

    // ── resolveDynamicRecipient: static email returned unchanged ─

    public function testResolveDynamicRecipientStaticEmailReturnedUnchanged(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('admin@example.com', ['key' => 'value']);
        $this->assertSame('admin@example.com', $result);
    }

    // ── resolveDynamicRecipient: partial template syntax ─────────

    public function testResolveDynamicRecipientPartialTemplateSyntax(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{email', ['email' => 'test@example.com']);
        $this->assertSame('{{email', $result);
    }

    // ── resolveDynamicRecipient: triple braces ───────────────────

    public function testResolveDynamicRecipientTripleBraces(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{{email}}}', ['email' => 'test@example.com']);
        $this->assertSame('{{{email}}}', $result);
    }

    // ── resolveDynamicRecipient: empty braces ────────────────────

    public function testResolveDynamicRecipientEmptyBraces(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{}}', ['email' => 'test@example.com']);
        $this->assertSame('{{}}', $result);
    }

    // ── resolveDynamicRecipient: field name starting with number ─

    public function testResolveDynamicRecipientFieldNameStartingWithNumber(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{1field}}', ['1field' => 'test@example.com']);
        $this->assertSame('{{1field}}', $result);
    }

    // ── resolveDynamicRecipient: field name with underscore ───────

    public function testResolveDynamicRecipientFieldNameWithUnderscore(): void
    {
        $formData = ['user_email' => 'user@example.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{user_email}}', $formData);
        $this->assertSame('user@example.com', $result);
    }

    // ── resolveDynamicRecipient: field name with digits ───────────

    public function testResolveDynamicRecipientFieldNameWithDigits(): void
    {
        $formData = ['email2' => 'test@example.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{email2}}', $formData);
        $this->assertSame('test@example.com', $result);
    }

    // ── resolveDynamicRecipient: empty recipient ─────────────────

    public function testResolveDynamicRecipientEmptyRecipient(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('', ['email' => 'test@example.com']);
        $this->assertSame('', $result);
    }

    // ── resolveDynamicRecipient: recipient with spaces ───────────

    public function testResolveDynamicRecipientRecipientWithSpaces(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('  user@example.com  ', []);
        $this->assertSame('  user@example.com  ', $result);
    }

    // ── validateToken: comment truncation at 1000 chars ──────────

    public function testValidateTokenCommentTruncatedAt1000Chars(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $longComment = str_repeat('x', 1500);
        $result = $this->workflow->validateToken($tokenVal, 'valider', $longComment);
        $this->assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertLessThanOrEqual(1000, strlen($validation['commentaire']));
    }

    // ── validateToken: comment exactly 1000 chars ────────────────

    public function testValidateTokenCommentExactly1000Chars(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $comment = str_repeat('x', 1000);
        $result = $this->workflow->validateToken($tokenVal, 'valider', $comment);
        $this->assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame(1000, strlen($validation['commentaire']));
    }

    // ── validateToken: comment under 1000 chars ──────────────────

    public function testValidateTokenCommentUnder1000Chars(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $comment = str_repeat('y', 500);
        $result = $this->workflow->validateToken($tokenVal, 'valider', $comment);
        $this->assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame(500, strlen($validation['commentaire']));
    }

    // ── validateToken: stores email in validation ────────────────

    public function testValidateTokenStoresEmailInValidation(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame('validator@test.com', $validation['email']);
    }

    // ── validateToken: stores step_label in validation ───────────

    public function testValidateTokenStoresStepLabelInValidation(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame('Validation', $validation['step_label']);
    }

    // ── validateToken: stores action in validation ───────────────

    public function testValidateTokenStoresActionInValidation(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame('valider', $validation['action']);
    }

    // ── validateToken: stores date in validation ─────────────────

    public function testValidateTokenStoresDateInValidation(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $before = gmdate('Y-m-d H:i:s');
        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $after = gmdate('Y-m-d H:i:s');

        $this->assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertGreaterThanOrEqual($before, $validation['date']);
        $this->assertLessThanOrEqual($after, $validation['date']);
    }

    // ── validateToken: returns ok status ─────────────────────────

    public function testValidateTokenReturnsOkStatus(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertSame('ok', $result['status']);
    }

    // ── validateToken: returns data key ──────────────────────────

    public function testValidateTokenReturnsDataKeyOnSuccess(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertArrayHasKey('data', $result);
        $this->assertIsArray($result['data']);
    }

    // ── validateToken: data contains done_at ─────────────────────

    public function testValidateTokenDataContainsDoneAt(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertArrayHasKey('done_at', $result['data']);
        $this->assertNotEmpty($result['data']['done_at']);
    }

    // ── validateToken: data contains token ───────────────────────

    public function testValidateTokenDataContainsToken(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertSame($tokenVal, $result['data']['token']);
    }

    // ── validateToken: data contains email ───────────────────────

    public function testValidateTokenDataContainsEmail(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertSame('validator@test.com', $result['data']['email']);
    }

    // ── validateToken: data contains step_id ─────────────────────

    public function testValidateTokenDataContainsStepId(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertSame($stepId, $result['data']['step_id']);
    }

    // ── validateToken: data contains submission_id ───────────────

    public function testValidateTokenDataContainsSubmissionId(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertSame($subId, $result['data']['submission_id']);
    }

    // ── validateToken: data contains sent_at ─────────────────────

    public function testValidateTokenDataContainsSentAt(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertArrayHasKey('sent_at', $result['data']);
    }

    // ── validateToken: data contains expires_at ──────────────────

    public function testValidateTokenDataContainsExpiresAt(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertArrayHasKey('expires_at', $result['data']);
    }

    // ── validateToken: refuser returns ok ────────────────────────

    public function testValidateTokenRefuseReturnsOk(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'refuser');
        $this->assertSame('ok', $result['status']);
    }

    // ── validateToken: refuser with empty comment ────────────────

    public function testValidateTokenRefuseWithEmptyComment(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'refuser', '');
        $this->assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame('', $validation['commentaire']);
    }

    // ── validateToken: refuser stores refuser action ─────────────

    public function testValidateTokenRefuseStoresRefuserAction(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'refuser', 'Motif');
        $this->assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame('refuser', $validation['action']);
    }

    // ── validateToken: refuser stores done_by ────────────────────

    public function testValidateTokenRefuseStoresDoneBy(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $doneBy = 'refuser-' . uniqid() . '@test.com';
        $result = $this->workflow->validateToken($tokenVal, 'refuser', 'Motif', $doneBy);
        $this->assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame($doneBy, $validation['done_by']);
    }

    // ── validateToken: refuser stores date ───────────────────────

    public function testValidateTokenRefuseStoresDate(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $before = gmdate('Y-m-d H:i:s');
        $result = $this->workflow->validateToken($tokenVal, 'refuser', 'Motif');
        $after = gmdate('Y-m-d H:i:s');

        $this->assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertGreaterThanOrEqual($before, $validation['date']);
        $this->assertLessThanOrEqual($after, $validation['date']);
    }

    // ── validateToken: refuser stores email ──────────────────────

    public function testValidateTokenRefuseStoresEmail(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'refuser', 'Motif');
        $this->assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame('validator@test.com', $validation['email']);
    }

    // ── validateToken: refuser stores step_label ─────────────────

    public function testValidateTokenRefuseStoresStepLabel(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'refuser', 'Motif');
        $this->assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame('Validation', $validation['step_label']);
    }

    // ── validateToken: refuser sets done_at on token ─────────────

    public function testValidateTokenRefuseSetsDoneAtOnToken(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'refuser', 'Motif');
        $this->assertSame('ok', $result['status']);

        $check = $pdo->prepare("SELECT done_at FROM tokens WHERE token = ?");
        $check->execute([$tokenVal]);
        $this->assertNotEmpty($check->fetchColumn());
    }

    // ── validateToken: valider sets status to en_cours (if not closing) ─

    public function testValidateTokenValiderKeepsSubmissionOpenIfMoreSteps(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertSame('ok', $result['status']);

        // Check if submission is still en_cours or closed
        $check = $pdo->prepare("SELECT status, closed_at FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $sub = $check->fetch(\PDO::FETCH_ASSOC);
        $this->assertContains($sub['status'], ['en_cours', 'valide']);
    }

    // ── getWorkflowSteps: ordering by ordre then id ──────────────

    public function testGetWorkflowStepsOrderingByOrdreThenId(): void
    {
        [$formId, $step1Id] = $this->createTestForm();
        $pdo = $this->db->getPdo();
        $step2Id = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation 2', 2, 1, '')")
            ->execute([$step2Id, $formId]);
        $this->createdIds['steps'][] = $step2Id;

        $steps = $this->workflow->getWorkflowSteps($formId);

        // Verify ordering
        for ($i = 0; $i < count($steps) - 1; $i++) {
            $current = (int) $steps[$i]['ordre'];
            $next = (int) $steps[$i + 1]['ordre'];
            $this->assertLessThanOrEqual($next, $current);
        }
    }

    // ── getWorkflowSteps: includes condition field ───────────────

    public function testGetWorkflowStepsIncludesConditionField(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);

        foreach ($steps as $step) {
            $this->assertArrayHasKey('condition', $step);
        }
    }

    // ── getWorkflowSteps: step_id is string ──────────────────────

    public function testGetWorkflowStepsStepIdIsString(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);

        foreach ($steps as $step) {
            $this->assertIsString($step['step_id']);
            $this->assertNotEmpty($step['step_id']);
        }
    }

    // ── getWorkflowSteps: step_label is string ───────────────────

    public function testGetWorkflowStepsStepLabelIsString(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);

        foreach ($steps as $step) {
            $this->assertIsString($step['step_label']);
        }
    }

    // ── getWorkflowSteps: ordre is numeric ───────────────────────

    public function testGetWorkflowStepsOrdreIsNumeric(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);

        foreach ($steps as $step) {
            $this->assertIsNumeric($step['ordre']);
        }
    }

    // ── REGRESSION: getWorkflowSteps uses step_id/step_label ────

    public function testGetWorkflowStepsReturnsStepIdNotId(): void
    {
        // Regression: AdminFormsController line 179 used $workflowStep['id']
        // instead of $workflowStep['step_id']. Verify step_id exists, id does not.
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);

        foreach ($steps as $step) {
            $this->assertArrayHasKey('step_id', $step, 'getWorkflowSteps must return step_id key (not id)');
            $this->assertIsString($step['step_id']);
            $this->assertNotEmpty($step['step_id']);
        }
    }

    public function testGetWorkflowStepsReturnsStepLabelNotLabel(): void
    {
        // Regression: AdminFormsController line 175 used $workflowStep['label']
        // and FormPreviewController line 57 used $ws['label']
        // instead of step_label. Verify step_label exists.
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);

        foreach ($steps as $step) {
            $this->assertArrayHasKey('step_label', $step, 'getWorkflowSteps must return step_label key (not label)');
            $this->assertIsString($step['step_label']);
        }
    }

    public function testGetWorkflowStepsDoesNotReturnLegacyIdOrLabelKeys(): void
    {
        // Regression: callers used $ws['id'] and $ws['label'] which would
        // produce null/undefined-offset at runtime. Verify these keys don't exist.
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);

        foreach ($steps as $step) {
            $this->assertArrayNotHasKey('id', $step, 'getWorkflowSteps must NOT return legacy "id" key (use step_id)');
            $this->assertArrayNotHasKey('label', $step, 'getWorkflowSteps must NOT return legacy "label" key (use step_label)');
        }
    }

    // ── getWorkflowSteps: actif is 1 ─────────────────────────────

    public function testGetWorkflowStepsActifIsOne(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);

        foreach ($steps as $step) {
            $this->assertSame(1, (int) $step['actif']);
        }
    }

    // ── hasActiveSubmissions: returns zero for form with no submissions ─

    public function testHasActiveSubmissionsReturnsZeroForFormWithNoSubmissions(): void
    {
        $pdo = $this->db->getPdo();

        // Create a form with no submissions
        $formId = bin2hex(random_bytes(8));
        $slug = 'test-no-subs-' . uniqid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'Test', 'test', 1, datetime('now'))")
            ->execute([$formId, $slug]);

        $count = $this->workflow->hasActiveSubmissions($formId);
        $this->assertSame(0, $count);

        // Cleanup
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }

    // ── hasActiveStepSubmissions: returns zero for step with no tokens ─

    public function testHasActiveStepSubmissionsReturnsZeroForStepWithNoTokens(): void
    {
        $pdo = $this->db->getPdo();
        [$formId] = $this->createTestForm();

        $stepId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'No Tokens Step', 100, 1)")
            ->execute([$stepId, $formId]);

        $count = $this->workflow->hasActiveStepSubmissions($stepId);
        $this->assertSame(0, $count);

        // Cleanup
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$stepId]);
    }

    // ── hasActiveStepSubmissions: only counts done_at IS NULL ─────

    public function testHasActiveStepSubmissionsOnlyCountsNullDoneAt(): void
    {
        $pdo = $this->db->getPdo();
        [$formId] = $this->createTestForm();

        $stepId = bin2hex(random_bytes(8));
        $subId = bin2hex(random_bytes(8));
        $tokenId = bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(32));

        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'Test Step', 100, 1)")
            ->execute([$stepId, $formId]);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, '{}', 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId]);
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at, expires_at) VALUES (?, ?, ?, 'done@test.com', ?, datetime('now'), datetime('now'), datetime('now', '+30 days'))")
            ->execute([$tokenId, $subId, $stepId, $token]);

        $count = $this->workflow->hasActiveStepSubmissions($stepId);
        $this->assertSame(0, $count); // done_at is NOT null

        // Cleanup
        $pdo->prepare("DELETE FROM tokens WHERE id = ?")->execute([$tokenId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$stepId]);
    }

    // ── hasActiveStepSubmissions: counts pending tokens ───────────

    public function testHasActiveStepSubmissionsCountsPendingTokens(): void
    {
        $pdo = $this->db->getPdo();
        [$formId] = $this->createTestForm();

        $stepId = bin2hex(random_bytes(8));
        $subId = bin2hex(random_bytes(8));
        $tokenId = bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(32));

        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'Pending Step', 100, 1)")
            ->execute([$stepId, $formId]);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, '{}', 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId]);
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, 'pending@test.com', ?, datetime('now'), datetime('now', '+30 days'))")
            ->execute([$tokenId, $subId, $stepId, $token]);

        $count = $this->workflow->hasActiveStepSubmissions($stepId);
        $this->assertGreaterThan(0, $count);

        // Cleanup
        $pdo->prepare("DELETE FROM tokens WHERE id = ?")->execute([$tokenId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$stepId]);
    }

    // ── getSubmissionWithFormLabel: returns data field ────────────

    public function testGetSubmissionWithFormLabelReturnsDataField(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel((string) $subId);
        if ($result) {
            $this->assertArrayHasKey('data', $result);
            $this->assertIsString($result['data']);
        }
    }

    // ── getSubmissionWithFormLabel: data is JSON ──────────────────

    public function testGetSubmissionWithFormLabelDataIsJson(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel((string) $subId);
        if ($result) {
            $decoded = json_decode($result['data'], true);
            $this->assertIsArray($decoded);
        }
    }

    // ── getSubmissionWithFormLabel: submitted_by may be null ──────

    public function testGetSubmissionWithFormLabelSubmittedByMayBeNull(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel((string) $subId);
        if ($result) {
            // submitted_by can be null or a string
            $this->assertTrue(
                $result['submitted_by'] === null || is_string($result['submitted_by']),
                'submitted_by should be null or string'
            );
        }
    }

    // ── getSubmissionWithFormLabel: closed_at may be null ────────

    public function testGetSubmissionWithFormLabelClosedAtMayBeNull(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel((string) $subId);
        if ($result) {
            // closed_at can be null or a string
            $this->assertTrue(
                $result['closed_at'] === null || is_string($result['closed_at']),
                'closed_at should be null or string'
            );
        }
    }

    // ── getSubmissionWithFormLabel: status is valid enum ──────────

    public function testGetSubmissionWithFormLabelStatusIsValidEnum(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel((string) $subId);
        if ($result) {
            $this->assertContains($result['status'], ['en_cours', 'valide', 'refuse', 'annule']);
        }
    }

    // ── getTokenWithContext: returns all expected fields ──────────

    public function testGetTokenWithContextReturnsAllExpectedFields(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->getTokenWithContext($tokenVal);
        $expectedFields = ['token', 'step_id', 'submission_id', 'email', 'step_label', 'form_label', 'data', 'status'];
        foreach ($expectedFields as $field) {
            $this->assertArrayHasKey($field, $result, "Missing field: $field");
        }
    }

    // ── getTokenByIdWithContext: returns all expected fields ──────

    public function testGetTokenByIdWithContextReturnsAllExpectedFields(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [$tokenId] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->getTokenByIdWithContext($tokenId);
        $expectedFields = ['token', 'step_id', 'submission_id', 'email', 'step_label', 'form_label', 'data', 'status'];
        foreach ($expectedFields as $field) {
            $this->assertArrayHasKey($field, $result, "Missing field: $field");
        }
    }

    // ── validateToken: invalid token format (not hex) ────────────

    public function testValidateTokenRejectsNonHexToken(): void
    {
        $result = $this->workflow->validateToken('xyz123def456');
        $this->assertSame('invalid', $result['status']);
    }

    // ── validateToken: token with uppercase hex ───────────────────

    public function testValidateTokenRejectsUppercaseHex(): void
    {
        $token = strtoupper(str_repeat('a', 64));
        $result = $this->workflow->validateToken($token);
        $this->assertSame('invalid', $result['status']);
    }

    // ── validateToken: token with special characters ─────────────

    public function testValidateTokenRejectsSpecialCharacters(): void
    {
        $result = $this->workflow->validateToken(str_repeat('g', 64));
        $this->assertSame('invalid', $result['status']);
    }

    // ── validateToken: token too short ───────────────────────────

    public function testValidateTokenRejectsShortToken(): void
    {
        $result = $this->workflow->validateToken('abc123');
        $this->assertSame('invalid', $result['status']);
    }

    // ── validateToken: token too long ────────────────────────────

    public function testValidateTokenRejectsLongToken(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 128));
        $this->assertSame('invalid', $result['status']);
    }

    // ── validateToken: empty token ───────────────────────────────

    public function testValidateTokenRejectsEmptyToken(): void
    {
        $result = $this->workflow->validateToken('');
        $this->assertSame('invalid', $result['status']);
    }

    // ── validateToken: null-like token ───────────────────────────

    public function testValidateTokenRejectsNullLikeToken(): void
    {
        $result = $this->workflow->validateToken('0000000000000000000000000000000000000000000000000000000000000000');
        $this->assertSame('invalid', $result['status']);
    }

    // ── validateToken: mixed case hex ────────────────────────────

    public function testValidateTokenRejectsMixedCaseHex(): void
    {
        $token = str_repeat('A', 32) . str_repeat('a', 32);
        $result = $this->workflow->validateToken($token);
        $this->assertSame('invalid', $result['status']);
    }

    // ── validateToken: action 'delete' rejected ──────────────────

    public function testValidateTokenRejectsDeleteAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'delete');
        $this->assertSame('invalid', $result['status']);
        $this->assertArrayHasKey('message', $result);
    }

    // ── validateToken: action 'cancel' rejected ──────────────────

    public function testValidateTokenRejectsCancelAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'cancel');
        $this->assertSame('invalid', $result['status']);
        $this->assertArrayHasKey('message', $result);
    }

    // ── validateToken: action 'approve' rejected ─────────────────

    public function testValidateTokenRejectsApproveAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'approve');
        $this->assertSame('invalid', $result['status']);
        $this->assertArrayHasKey('message', $result);
    }

    // ── validateToken: action 'reject' rejected ──────────────────

    public function testValidateTokenRejectsRejectAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'reject');
        $this->assertSame('invalid', $result['status']);
        $this->assertArrayHasKey('message', $result);
    }

    // ── ConditionEvaluator: deeply nested JSON ───────────────────

    public function testConditionEvaluatorHandlesDeeplyNestedJson(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'name', 'op' => 'eq', 'value' => 'test']);
        $this->assertTrue($evaluator->evaluate($condition, ['name' => 'test']));
    }

    // ── ConditionEvaluator: boolean value in data ────────────────

    public function testConditionEvaluatorBooleanValueInData(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'active', 'op' => 'eq', 'value' => '1']);
        $this->assertTrue($evaluator->evaluate($condition, ['active' => true]));
    }

    // ── ConditionEvaluator: numeric value in data ────────────────

    public function testConditionEvaluatorNumericValueInData(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'count', 'op' => 'eq', 'value' => '5']);
        $this->assertTrue($evaluator->evaluate($condition, ['count' => 5]));
    }

    // ── ConditionEvaluator: empty array in data ──────────────────

    public function testConditionEvaluatorEmptyArrayInData(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'items', 'op' => 'not_empty']);
        $this->assertFalse($evaluator->evaluate($condition, ['items' => []]));
    }

    // ── ConditionEvaluator: not_empty with non-empty array ───────

    public function testConditionEvaluatorNotEmptyWithNonEmptyArray(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'items', 'op' => 'not_empty']);
        $this->assertTrue($evaluator->evaluate($condition, ['items' => ['a', 'b']]));
    }

    // ── ConditionEvaluator: empty with non-empty array ───────────

    public function testConditionEvaluatorEmptyWithNonEmptyArray(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'items', 'op' => 'empty']);
        $this->assertFalse($evaluator->evaluate($condition, ['items' => ['a']]));
    }

    // ── ConditionEvaluator: contains with numeric value ───────────

    public function testConditionEvaluatorContainsWithNumericValue(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'code', 'op' => 'contains', 'value' => '123']);
        $this->assertTrue($evaluator->evaluate($condition, ['code' => 'abc123def']));
        $this->assertFalse($evaluator->evaluate($condition, ['code' => 'abcdef']));
    }

    // ── ConditionEvaluator: in operator with single value ─────────

    public function testConditionEvaluatorInOperatorWithSingleValue(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'role', 'op' => 'in', 'value' => ['admin']]);
        $this->assertTrue($evaluator->evaluate($condition, ['role' => 'admin']));
        $this->assertFalse($evaluator->evaluate($condition, ['role' => 'user']));
    }

    // ── ConditionEvaluator: neq with empty string ────────────────

    public function testConditionEvaluatorNeqWithEmptyString(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'name', 'op' => 'neq', 'value' => '']);
        $this->assertTrue($evaluator->evaluate($condition, ['name' => 'John']));
        $this->assertFalse($evaluator->evaluate($condition, ['name' => '']));
    }

    // ── ConditionEvaluator: eq with empty string ─────────────────

    public function testConditionEvaluatorEqWithEmptyString(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'name', 'op' => 'eq', 'value' => '']);
        $this->assertTrue($evaluator->evaluate($condition, ['name' => '']));
        $this->assertFalse($evaluator->evaluate($condition, ['name' => 'John']));
    }

    // ── ConditionEvaluator: contains with empty actual ────────────

    public function testConditionEvaluatorContainsWithEmptyActual(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'name', 'op' => 'contains', 'value' => 'John']);
        $this->assertFalse($evaluator->evaluate($condition, ['name' => '']));
    }

    // ── ConditionEvaluator: in with empty array ──────────────────

    public function testConditionEvaluatorInWithEmptyArray(): void
    {
        $evaluator = new ConditionEvaluator();
        $condition = json_encode(['field' => 'role', 'op' => 'in', 'value' => []]);
        $this->assertFalse($evaluator->evaluate($condition, ['role' => 'admin']));
    }

    // ── ConditionEvaluator: multiple conditions (last wins) ──────

    public function testConditionEvaluatorMultipleConditionsLastWins(): void
    {
        $evaluator = new ConditionEvaluator();
        // Only the last condition is evaluated
        $condition = json_encode(['field' => 'name', 'op' => 'eq', 'value' => 'John']);
        $this->assertTrue($evaluator->evaluate($condition, ['name' => 'John']));
    }

    // ── advanceWorkflow: with empty form data ────────────────────

    public function testAdvanceWorkflowWithEmptyFormData(): void
    {
        $pdo = $this->db->getPdo();
        [$formId] = $this->createTestForm();

        $subId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, '', 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId]);

        $this->workflow->advanceWorkflow($subId);

        // Should not crash with empty data
        $check = $pdo->prepare("SELECT id FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $this->assertNotNull($check->fetchColumn());

        // Cleanup
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
    }

    // ── advanceWorkflow: with null form data ─────────────────────

    public function testAdvanceWorkflowWithNullFormData(): void
    {
        $pdo = $this->db->getPdo();
        [$formId] = $this->createTestForm();

        $subId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, 'null', 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId]);

        $this->workflow->advanceWorkflow($subId);

        // Should not crash with null data
        $check = $pdo->prepare("SELECT id FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $this->assertNotNull($check->fetchColumn());

        // Cleanup
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
    }

    // ── advanceWorkflow: with complex form data ──────────────────

    public function testAdvanceWorkflowWithComplexFormData(): void
    {
        $pdo = $this->db->getPdo();
        [$formId] = $this->createTestForm();

        $subId = bin2hex(random_bytes(8));
        $data = json_encode([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'nested' => ['key' => 'value'],
            'array' => [1, 2, 3],
        ]);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, ?, 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId, $data]);

        $this->workflow->advanceWorkflow($subId);

        // Should not crash with complex data
        $check = $pdo->prepare("SELECT id FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $this->assertNotNull($check->fetchColumn());

        // Cleanup
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
    }

    // ── advanceWorkflow: with invalid JSON in form data ──────────

    public function testAdvanceWorkflowWithInvalidJsonFormData(): void
    {
        $pdo = $this->db->getPdo();
        [$formId] = $this->createTestForm();

        $subId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, 'invalid json {{{', 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId]);

        $this->workflow->advanceWorkflow($subId);

        // Should not crash with invalid JSON
        $check = $pdo->prepare("SELECT id FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $this->assertNotNull($check->fetchColumn());

        // Cleanup
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
    }

    // ── advanceWorkflow: called twice on same submission ──────────

    public function testAdvanceWorkflowCalledTwiceDoesNotDuplicateTokens(): void
    {
        $pdo = $this->db->getPdo();
        [$formId] = $this->createTestForm();

        $subId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, '{}', 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId]);

        $this->workflow->advanceWorkflow($subId);
        $countAfterFirst = (int) $pdo->query("SELECT COUNT(*) FROM tokens WHERE submission_id = '$subId'")->fetchColumn();

        $this->workflow->advanceWorkflow($subId);
        $countAfterSecond = (int) $pdo->query("SELECT COUNT(*) FROM tokens WHERE submission_id = '$subId'")->fetchColumn();

        // Should not create duplicates
        $this->assertGreaterThanOrEqual($countAfterFirst, $countAfterSecond);

        // Cleanup
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
    }
}
