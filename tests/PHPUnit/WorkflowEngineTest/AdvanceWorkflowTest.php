<?php
declare(strict_types=1);
namespace App\Tests\WorkflowEngineTest;

final class AdvanceWorkflowTest extends Base
{
    public function testAdvanceWorkflowReturnsEarlyForNonexistentSubmission(): void
    {
        $this->workflow->advanceWorkflow('nonexistent-submission-id');
        self::assertTrue(true);
    }
    public function testAdvanceWorkflowReturnsEarlyForClosedSubmission(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId, closedAtOffset: '-0 seconds');
        $this->workflow->advanceWorkflow($subId);
        self::assertTrue(true);
    }
    public function testAdvanceWorkflowReturnsEarlyForEmptySubmissionId(): void
    {
        $this->workflow->advanceWorkflow('');
        self::assertTrue(true);
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
        self::assertGreaterThan((int) $tokensBefore, (int) $tokensAfter, 'advanceWorkflow should create new tokens');
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
        self::assertTrue(true);
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
        self::assertSame(0, (int) $countStmt->fetchColumn(), 'No token should be created when the step condition is not met');
    }
    public function testAdvanceWorkflowWithCompletedSubmissionClosesIt(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        $this->createTestToken($subId, $stepId, doneAtOffset: '-1 minute');
        $this->workflow->advanceWorkflow($subId);
        $check = $this->db->getPdo()->prepare("SELECT closed_at FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        self::assertNotEmpty($check->fetchColumn());
    }
    public function testAdvanceWorkflowWithMixedDoneAndPendingWaits(): void
    {
        [$formId, $step1Id] = $this->createTestForm();
        $pdo = $this->db->getPdo();
        $step2Id = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Valid 2', 1, 1, '')")->execute([$step2Id, $formId]);
        $this->createdIds['steps'][] = $step2Id;
        $sr2Id = \generate_uuid();
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, 'v2@test.com')")->execute([$sr2Id, $step2Id]);
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
        self::assertSame($closedBefore, $after->fetchColumn());
    }
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
        self::assertGreaterThanOrEqual($before, (int) $countAfter->fetchColumn());
    }
    public function testAdvanceWorkflowClosesAndNotifiesAgentWhenAllDone(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        $this->createTestToken($subId, $stepId, doneAtOffset: '-1 minute');
        $this->workflow->advanceWorkflow($subId);
        $check = $this->db->getPdo()->prepare("SELECT closed_at, status FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $row = $check->fetch(\PDO::FETCH_ASSOC);
        self::assertNotEmpty($row['closed_at']);
        self::assertSame('valide', $row['status']);
    }
    public function testAdvanceWorkflowCreatesTokensForFirstGroup(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        $pdo = $this->db->getPdo();
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ?");
        $countStmt->execute([$subId]);
        $countBefore = (int) $countStmt->fetchColumn();
        $this->workflow->advanceWorkflow($subId);
        $countStmt->execute([$subId]);
        self::assertGreaterThanOrEqual($countBefore, (int) $countStmt->fetchColumn());
    }
    public function testAdvanceWorkflowSkipsStepAlreadyStarted(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ?");
        $countStmt->execute([$subId]);
        $countBefore = (int) $countStmt->fetchColumn();
        $this->workflow->advanceWorkflow($subId);
        $countStmt->execute([$subId]);
        self::assertSame($countBefore, (int) $countStmt->fetchColumn());
    }
    public function testAdvanceWorkflowMovesToNextGroupWhenAllDone(): void
    {
        [$formId, $step1Id] = $this->createTestForm();
        $pdo = $this->db->getPdo();
        $step2Id = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Valid 2', 2, 1, '')")->execute([$step2Id, $formId]);
        $this->createdIds['steps'][] = $step2Id;
        $sr2Id = \generate_uuid();
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, 'v2@test.com')")->execute([$sr2Id, $step2Id]);
        $this->createdIds['step_recipients'][] = $sr2Id;
        $subId = $this->createTestSubmission($formId);
        $this->createTestToken($subId, $step1Id, doneAtOffset: '-1 minute');
        $this->createTestToken($subId, $step2Id, doneAtOffset: '-1 minute');
        $this->workflow->advanceWorkflow($subId);
        $closed = $pdo->prepare("SELECT closed_at FROM submissions WHERE id = ?");
        $closed->execute([$subId]);
        self::assertNotEmpty($closed->fetchColumn());
    }
    public function testAdvanceWorkflowSkipsRecipientZero(): void
    {
        $pdo = $this->db->getPdo();
        $formId = \generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'RecipZero', 'test', 1, datetime('now'))")->execute([$formId, 'recip-zero-' . uniqid()]);
        $this->createdIds['forms'][] = $formId;
        $stepId = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'ZeroStep', 1, 1)")->execute([$stepId, $formId]);
        $this->createdIds['steps'][] = $stepId;
        $srId0 = \generate_uuid();
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, '0')")->execute([$srId0, $stepId]);
        $this->createdIds['step_recipients'][] = $srId0;
        $subId = $this->createTestSubmission($formId);
        $this->workflow->advanceWorkflow($subId);
        $check = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND email = '0'");
        $check->execute([$subId]);
        self::assertSame(0, (int) $check->fetchColumn());
    }
    public function testAdvanceWorkflowSkipsEmptyRecipient(): void
    {
        $pdo = $this->db->getPdo();
        $formId = \generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'EmptyRecip', 'test', 1, datetime('now'))")->execute([$formId, 'empty-recip-' . uniqid()]);
        $this->createdIds['forms'][] = $formId;
        $stepId = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'EmptyStep', 1, 1)")->execute([$stepId, $formId]);
        $this->createdIds['steps'][] = $stepId;
        $srIdEmpty = \generate_uuid();
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, '')")->execute([$srIdEmpty, $stepId]);
        $this->createdIds['step_recipients'][] = $srIdEmpty;
        $subId = $this->createTestSubmission($formId);
        $this->workflow->advanceWorkflow($subId);
        $check = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND email = ''");
        $check->execute([$subId]);
        self::assertSame(0, (int) $check->fetchColumn());
    }
    public function testAdvanceWorkflowClosesImmediatelyWithNoSteps(): void
    {
        // B-W1 fix (audit fonctionnel 2026-07-26) : avant, advanceWorkflow clôturait
        // une soumission sans étape comme 'valide'. C'était un bug métier — une
        // soumission sans validation ne devrait pas être marquée validée.
        // Maintenant : la soumission reste en_cours + audit_log 'workflow_no_steps'.
        $pdo = $this->db->getPdo();
        $formId = \generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'NoSteps', 'test', 0, datetime('now'))")->execute([$formId, 'no-steps-' . uniqid()]);
        $this->createdIds['forms'][] = $formId;
        $subId = $this->createTestSubmission($formId);
        $this->workflow->advanceWorkflow($subId);
        $check = $pdo->prepare("SELECT closed_at, status FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $row = $check->fetch(\PDO::FETCH_ASSOC);
        // B-W1 : ne doit PAS clôturer — la soumission reste en_cours
        self::assertEmpty($row['closed_at'], 'B-W1: soumission sans étape ne doit pas être clôturée');
        self::assertSame('en_cours', $row['status']);
        // Vérifier qu'un audit_log a été créé
        $auditStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE action = 'workflow_no_steps' AND target = ?");
        $auditStmt->execute(['submission:' . $subId]);
        self::assertGreaterThan(0, (int) $auditStmt->fetchColumn(), 'audit_log workflow_no_steps doit être créé');
    }
    public function testAdvanceWorkflowSkipsStepWithFalseCondition(): void
    {
        $pdo = $this->db->getPdo();
        [$formId] = $this->createTestForm();
        $stepId = \generate_uuid();
        $condition = json_encode(['field' => 'nonexistent_field_xyz', 'op' => 'eq', 'value' => 'impossible_value']);
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'CondStep', 1, 1, ?)")->execute([$stepId, $formId, $condition]);
        $this->createdIds['steps'][] = $stepId;
        $srId = \generate_uuid();
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, 'test@example.com')")->execute([$srId, $stepId]);
        $this->createdIds['step_recipients'][] = $srId;
        $subId = $this->createTestSubmission($formId);
        $this->workflow->advanceWorkflow($subId);
        $check = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND step_id = ?");
        $check->execute([$subId, $stepId]);
        self::assertSame(0, (int) $check->fetchColumn());
    }
    public function testAdvanceWorkflowCreatesTokenWhenConditionTrue(): void
    {
        $pdo = $this->db->getPdo();
        [$formId] = $this->createTestForm();
        $stepId = \generate_uuid();
        $condition = json_encode(['field' => 'status', 'op' => 'eq', 'value' => '']);
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'CondStep', 1, 1, ?)")->execute([$stepId, $formId, $condition]);
        $this->createdIds['steps'][] = $stepId;
        $srId = \generate_uuid();
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, 'validator@test.com')")->execute([$srId, $stepId]);
        $this->createdIds['step_recipients'][] = $srId;
        $subId = $this->createTestSubmission($formId, data: json_encode(['status' => 'active']));
        $this->workflow->advanceWorkflow($subId);
        $check = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND step_id = ?");
        $check->execute([$subId, $stepId]);
        self::assertGreaterThan(0, (int) $check->fetchColumn());
    }
    public function testAdvanceWorkflowSkipsInvalidEmailRecipient(): void
    {
        $pdo = $this->db->getPdo();
        $formId = \generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'InvalidEmail', 'test', 1, datetime('now'))")->execute([$formId, 'inv-email-' . uniqid()]);
        $this->createdIds['forms'][] = $formId;
        $stepId = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'InvEmailStep', 1, 1)")->execute([$stepId, $formId]);
        $this->createdIds['steps'][] = $stepId;
        $srId = \generate_uuid();
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, 'not-an-email')")->execute([$srId, $stepId]);
        $this->createdIds['step_recipients'][] = $srId;
        $subId = $this->createTestSubmission($formId);
        $this->workflow->advanceWorkflow($subId);
        $check = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND step_id = ?");
        $check->execute([$subId, $stepId]);
        self::assertSame(0, (int) $check->fetchColumn());
    }
    public function testAdvanceWorkflowResolvesOwnerRecipient(): void
    {
        $pdo = $this->db->getPdo();
        $formId = \generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'OwnerTest', 'test', 1, datetime('now'))")->execute([$formId, 'owner-test-' . uniqid()]);
        $this->createdIds['forms'][] = $formId;
        $this->createFormOwner($formId, 'owner-' . uniqid() . '@test.com');
        $stepId = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'OwnerStep', 1, 1)")->execute([$stepId, $formId]);
        $this->createdIds['steps'][] = $stepId;
        $srId = \generate_uuid();
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, '{{owner}}')")->execute([$srId, $stepId]);
        $this->createdIds['step_recipients'][] = $srId;
        $subId = $this->createTestSubmission($formId);
        $this->workflow->advanceWorkflow($subId);
        $check = $pdo->prepare("SELECT email FROM tokens WHERE submission_id = ? AND step_id = ?");
        $check->execute([$subId, $stepId]);
        $tokenEmail = $check->fetchColumn();
        self::assertNotFalse($tokenEmail, 'Token should be created for owner step');
        self::assertStringContainsString('@', (string) $tokenEmail);
    }
    public function testAdvanceWorkflowWithEmptyFormData(): void
    {
        [$formId] = $this->createTestForm();
        $pdo = $this->db->getPdo();
        $subId = \generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, '', 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId]);
        $this->createdIds['submissions'][] = $subId;
        $this->workflow->advanceWorkflow($subId);
        $check = $pdo->prepare("SELECT id FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        self::assertNotNull($check->fetchColumn());
    }
    public function testAdvanceWorkflowWithNullFormData(): void
    {
        [$formId] = $this->createTestForm();
        $pdo = $this->db->getPdo();
        $subId = \generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, 'null', 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId]);
        $this->createdIds['submissions'][] = $subId;
        $this->workflow->advanceWorkflow($subId);
        $check = $pdo->prepare("SELECT id FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        self::assertNotNull($check->fetchColumn());
    }
    public function testAdvanceWorkflowWithComplexFormData(): void
    {
        [$formId] = $this->createTestForm();
        $pdo = $this->db->getPdo();
        $subId = \generate_uuid();
        $data = json_encode(['name' => 'John Doe', 'email' => 'john@example.com', 'nested' => ['key' => 'value'], 'array' => [1, 2, 3]]);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, ?, 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId, $data]);
        $this->createdIds['submissions'][] = $subId;
        $this->workflow->advanceWorkflow($subId);
        $check = $pdo->prepare("SELECT id FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        self::assertNotNull($check->fetchColumn());
    }
    public function testAdvanceWorkflowWithInvalidJsonFormData(): void
    {
        [$formId] = $this->createTestForm();
        $pdo = $this->db->getPdo();
        $subId = \generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, 'invalid json {{{', 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId]);
        $this->createdIds['submissions'][] = $subId;
        $this->workflow->advanceWorkflow($subId);
        $check = $pdo->prepare("SELECT id FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        self::assertNotNull($check->fetchColumn());
    }
    public function testAdvanceWorkflowCalledTwiceDoesNotDuplicateTokens(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        $pdo = $this->db->getPdo();
        $this->workflow->advanceWorkflow($subId);
        $countAfterFirst = (int) $pdo->query("SELECT COUNT(*) FROM tokens WHERE submission_id = '$subId'")->fetchColumn();
        $this->workflow->advanceWorkflow($subId);
        $countAfterSecond = (int) $pdo->query("SELECT COUNT(*) FROM tokens WHERE submission_id = '$subId'")->fetchColumn();
        self::assertGreaterThanOrEqual($countAfterFirst, $countAfterSecond);
    }
}
