<?php
declare(strict_types=1);
namespace App\Tests\WorkflowEngineTest;

final class AdvanceWorkflowTest extends Base
{
    public function testAdvanceWorkflowReturnsEarlyForNonexistentSubmission(): void
    {
        $pdo = $this->db->getPdo();
        $tokensBefore = (int) $pdo->query('SELECT COUNT(*) FROM tokens')->fetchColumn();

        $this->workflow->advanceWorkflow('nonexistent-submission-id');

        $tokensAfter = (int) $pdo->query('SELECT COUNT(*) FROM tokens')->fetchColumn();
        self::assertSame($tokensBefore, $tokensAfter, 'Aucun token ne doit être créé pour une soumission inexistante');

        $audit = $pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE target = ?');
        $audit->execute(['submission:nonexistent-submission-id']);
        self::assertSame(0, (int) $audit->fetchColumn(), 'Aucune action ne doit être auditée pour une soumission inexistante');
    }
    public function testAdvanceWorkflowReturnsEarlyForClosedSubmission(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId, closedAtOffset: '-0 seconds');

        $this->workflow->advanceWorkflow($subId);

        $countStmt = $this->db->getPdo()->prepare('SELECT COUNT(*) FROM tokens WHERE submission_id = ?');
        $countStmt->execute([$subId]);
        self::assertSame(0, (int) $countStmt->fetchColumn(), 'Aucun token ne doit être créé pour une soumission clôturée');
    }
    public function testAdvanceWorkflowReturnsEarlyForEmptySubmissionId(): void
    {
        $pdo = $this->db->getPdo();
        $tokensBefore = (int) $pdo->query('SELECT COUNT(*) FROM tokens')->fetchColumn();

        $this->workflow->advanceWorkflow('');

        $tokensAfter = (int) $pdo->query('SELECT COUNT(*) FROM tokens')->fetchColumn();
        self::assertSame($tokensBefore, $tokensAfter, 'Aucun token ne doit être créé pour un identifiant vide');
    }
    public function testAdvanceWorkflowCreatesTokensForActiveSubmission(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        $pdo = $this->db->getPdo();
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ?");
        $countStmt->execute([$subId]);
        self::assertSame(0, (int) $countStmt->fetchColumn(), 'Aucun token ne doit préexister');
        $this->workflow->advanceWorkflow($subId);
        $countStmt->execute([$subId]);
        self::assertSame(1, (int) $countStmt->fetchColumn(), 'advanceWorkflow doit créer exactement un token pour l\'étape');
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

        $check = $pdo->prepare("SELECT email FROM tokens WHERE submission_id = ? ORDER BY email");
        $check->execute([$subId]);
        $emails = $check->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['validator@test.com'], $emails, 'Seul le destinataire valide doit recevoir un token');
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
        $check = $this->db->getPdo()->prepare("SELECT closed_at, status FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $row = $check->fetch(\PDO::FETCH_ASSOC);
        self::assertNotEmpty($row['closed_at'], 'La soumission doit être clôturée quand toutes les étapes sont validées');
        self::assertSame('valide', $row['status'], 'La soumission clôturée doit passer au statut valide');
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
        $before = $pdo->prepare("SELECT closed_at, status FROM submissions WHERE id = ?");
        $before->execute([$subId]);
        $rowBefore = $before->fetch(\PDO::FETCH_ASSOC);
        self::assertNull($rowBefore['closed_at'], 'La soumission ne doit pas être clôturée avant l\'avancement');

        $this->workflow->advanceWorkflow($subId);

        $after = $pdo->prepare("SELECT closed_at, status FROM submissions WHERE id = ?");
        $after->execute([$subId]);
        $rowAfter = $after->fetch(\PDO::FETCH_ASSOC);
        self::assertNull($rowAfter['closed_at'], 'La soumission ne doit pas être clôturée tant qu\'un token reste en attente');
        self::assertSame('en_cours', $rowAfter['status'], 'La soumission doit rester en_cours');
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
        self::assertSame(1, $before, 'L\'étape doit préexister avec un token');
        $this->workflow->advanceWorkflow($subId);
        $countAfter = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ?");
        $countAfter->execute([$subId]);
        self::assertSame($before, (int) $countAfter->fetchColumn(), 'advanceWorkflow ne doit pas créer de doublon pour une étape déjà démarrée');
    }
    public function testAdvanceWorkflowClosesAndNotifiesAgentWhenAllDone(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        $this->createTestToken($subId, $stepId, doneAtOffset: '-1 minute');

        $GLOBALS['_test_mails'] = [];
        $this->workflow->advanceWorkflow($subId);

        $check = $this->db->getPdo()->prepare("SELECT closed_at, status FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $row = $check->fetch(\PDO::FETCH_ASSOC);
        self::assertNotEmpty($row['closed_at'], 'La soumission doit être clôturée');
        self::assertSame('valide', $row['status']);

        $mails = $GLOBALS['_test_mails'];
        self::assertCount(1, $mails, 'L\'agent doit être notifié par un unique email de clôture');
        self::assertSame('agent@test.com', $mails[0]['to'], 'L\'email de clôture doit être adressé à l\'agent qui a soumis');
        self::assertStringContainsString('Demande validée', (string) $mails[0]['subject']);
    }
    public function testAdvanceWorkflowCreatesTokensForFirstGroup(): void
    {
        [$formId, $step1Id] = $this->createTestForm();
        $pdo = $this->db->getPdo();

        // Second groupe séquentiel (ordre 2) — ne doit pas démarrer tant que le premier n'est pas validé.
        $step2Id = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation 2', 2, 1, '')")->execute([$step2Id, $formId]);
        $this->createdIds['steps'][] = $step2Id;
        $sr2Id = \generate_uuid();
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, 'second-group@test.com')")->execute([$sr2Id, $step2Id]);
        $this->createdIds['step_recipients'][] = $sr2Id;

        $subId = $this->createTestSubmission($formId);
        $this->workflow->advanceWorkflow($subId);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND step_id = ?");
        $countStmt->execute([$subId, $step1Id]);
        self::assertSame(1, (int) $countStmt->fetchColumn(), 'Le premier groupe doit créer son token');

        $countStmt->execute([$subId, $step2Id]);
        self::assertSame(0, (int) $countStmt->fetchColumn(), 'Le second groupe ne doit pas démarrer avant la validation du premier');
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
        $closed = $pdo->prepare("SELECT closed_at, status FROM submissions WHERE id = ?");
        $closed->execute([$subId]);
        $row = $closed->fetch(\PDO::FETCH_ASSOC);
        self::assertNotEmpty($row['closed_at'], 'La soumission doit être clôturée quand tous les groupes sont validés');
        self::assertSame('valide', $row['status'], 'La soumission doit être clôturée au statut valide');
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
        self::assertSame(1, (int) $check->fetchColumn(), 'Un token doit être créé quand la condition est vraie');
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
        $ownerEmail = 'owner-' . uniqid() . '@test.com';
        $this->createFormOwner($formId, $ownerEmail);
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
        self::assertSame($ownerEmail, $tokenEmail, 'Le token doit être créé pour l\'email du propriétaire {{owner}}');
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
        $check = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ?");
        $check->execute([$subId]);
        self::assertSame(1, (int) $check->fetchColumn(), 'advanceWorkflow doit créer un token indépendamment du contenu de data');
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
        $check = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ?");
        $check->execute([$subId]);
        self::assertSame(1, (int) $check->fetchColumn(), 'advanceWorkflow doit créer un token indépendamment du contenu de data');
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
        $check = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ?");
        $check->execute([$subId]);
        self::assertSame(1, (int) $check->fetchColumn(), 'advanceWorkflow doit créer un token indépendamment du contenu de data');
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
        $check = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ?");
        $check->execute([$subId]);
        self::assertSame(1, (int) $check->fetchColumn(), 'advanceWorkflow doit créer un token indépendamment du contenu de data');
    }
    public function testAdvanceWorkflowCalledTwiceDoesNotDuplicateTokens(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        $pdo = $this->db->getPdo();
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ?");
        $this->workflow->advanceWorkflow($subId);
        $countStmt->execute([$subId]);
        $countAfterFirst = (int) $countStmt->fetchColumn();
        self::assertSame(1, $countAfterFirst, 'Le premier appel doit créer exactement un token');
        $this->workflow->advanceWorkflow($subId);
        $countStmt->execute([$subId]);
        $countAfterSecond = (int) $countStmt->fetchColumn();
        self::assertSame($countAfterFirst, $countAfterSecond, 'Un second appel ne doit pas créer de token dupliqué');
    }

    public function testAdvanceWorkflowRecreatesTokenAfterInvalidation(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        // Token RGPD : invalidé, jamais validé (done_at NULL).
        $pdo = $this->db->getPdo();
        $invalidatedId = \generate_uuid();
        $pdo->prepare(
            "INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at, invalidated_at, expires_at)
             VALUES (?, ?, ?, 'validator@test.com', ?, datetime('now'), NULL, datetime('now'), ?)"
        )->execute([$invalidatedId, $subId, $stepId, bin2hex(random_bytes(32)), gmdate('Y-m-d H:i:s', strtotime('+30 days'))]);
        $this->createdIds['tokens'][] = $invalidatedId;

        $GLOBALS['_test_mails'] = [];
        $this->workflow->advanceWorkflow($subId);

        // Un token actif a été recréé.
        $active = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND step_id = ? AND done_at IS NULL AND invalidated_at IS NULL");
        $active->execute([$subId, $stepId]);
        self::assertSame(1, (int) $active->fetchColumn(), 'Un nouveau token actif doit être recréé après invalidation.');

        // L'email du nouveau lien a été (re)envoyé.
        $mails = $GLOBALS['_test_mails'];
        self::assertNotEmpty($mails, 'Le nouveau lien doit être renvoyé par email.');
        self::assertSame('validator@test.com', $mails[count($mails) - 1]['to']);

        // L'audit explicite de recréation est journalisé.
        $audit = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE action = 'workflow_step_recreated_after_invalidation' AND target = ?");
        $audit->execute(['submission:' . $subId]);
        self::assertGreaterThan(0, (int) $audit->fetchColumn(), 'La recréation doit être auditée explicitement.');
    }
}
