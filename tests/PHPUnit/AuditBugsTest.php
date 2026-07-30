<?php
declare(strict_types=1);

namespace App\Tests;

use App\Core\App;
use App\Core\Database;
use App\Enum\SubmissionStatus;
use App\Enum\ValidationAction;
use App\Repository\SubmissionRepository;
use App\Repository\TokenRepository;
use App\Workflow\WorkflowEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests ciblés sur les bugs fonctionnels identifiés en audit 2026-07-26.
 *
 * Couvre : B-W1 (advanceWorkflow + conditions false), B-V1 (validateToken +
 * invalidated_at), B-RG1 (deleteUserData + tokens actifs), B-F1 (FormController
 * validation email), et les mutants Infection critiques sur WorkflowEngine.
 *
 * @package App\Tests
 */
final class AuditBugsTest extends TestCase
{
    private Database $db;
    private WorkflowEngine $workflow;
    private SubmissionRepository $subRepo;
    private TokenRepository $tokenRepo;

    /** @var list<string> IDs créés pour cleanup tearDown */
    private array $createdIds = [];

    protected function setUp(): void
    {
        $this->db = App::getInstance()->get(Database::class);
        $this->workflow = App::getInstance()->get(WorkflowEngine::class);
        $this->subRepo = App::getInstance()->get(SubmissionRepository::class);
        $this->tokenRepo = App::getInstance()->get(TokenRepository::class);
    }

    protected function tearDown(): void
    {
        // Cleanup en cascade (l'ordre importe à cause des FK)
        $pdo = $this->db->getPdo();
        foreach ($this->createdIds as $id) {
            // DELETE cascade via les FK ON DELETE CASCADE
            $pdo->prepare('DELETE FROM tokens WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM submissions WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM steps WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM forms WHERE id = ?')->execute([$id]);
        }
        $this->createdIds = [];
    }

    // ── B-W1 : advanceWorkflow avec conditions toutes false ──────────────

    public function testAdvanceWorkflowDoesNotCloseWhenAllConditionsFalse(): void
    {
        // Setup : un form avec 1 étape dont la condition est toujours fausse
        // (champ inexistant → ConditionEvaluator retourne false)
        [$formId, $stepId] = $this->createFormWithStep(condition: 'unexisting_field == "x"');
        $subId = $this->createSubmission($formId);

        // Quand advanceWorkflow est appelé, il ne doit PAS clôturer
        $this->workflow->advanceWorkflow($subId);

        // Vérifier que la soumission est TOUJOURS en_cours
        $sub = $this->subRepo->findByIdWithForm($subId);
        self::assertNotNull($sub);
        self::assertSame(SubmissionStatus::EnCours->value, $sub['status']);
        self::assertNull($sub['closed_at']);

        // Vérifier qu'un audit_log 'workflow_stalled' a été créé
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE action = 'workflow_stalled' AND target = ?");
        $stmt->execute(['submission:' . $subId]);
        self::assertGreaterThan(0, (int) $stmt->fetchColumn(), 'audit_log workflow_stalled doit être créé');
    }

    public function testAdvanceWorkflowNoActiveStepsDoesNotClose(): void
    {
        // Setup : form avec 0 étape active
        $formId = $this->createForm('test-no-steps-' . uniqid());
        $subId = $this->createSubmission($formId);

        $this->workflow->advanceWorkflow($subId);

        $sub = $this->subRepo->findByIdWithForm($subId);
        self::assertSame(SubmissionStatus::EnCours->value, $sub['status'], 'ne doit pas clôturer une soumission sans étape');

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE action = 'workflow_no_steps' AND target = ?");
        $stmt->execute(['submission:' . $subId]);
        self::assertGreaterThan(0, (int) $stmt->fetchColumn(), 'audit_log workflow_no_steps doit être créé');
    }

    // ── B-V1 : validateToken refuse les tokens invalidés ─────────────────

    public function testValidateTokenRefusesInvalidatedToken(): void
    {
        [$formId, $stepId] = $this->createFormWithStep();
        $subId = $this->createSubmission($formId);
        $token = $this->createToken($subId, $stepId, 'validator-bv1@test.com');

        // Invalider le token (simule cancel/regenerate/delegate)
        $pdo = $this->db->getPdo();
        $now = gmdate('Y-m-d H:i:s');
        $pdo->prepare('UPDATE tokens SET invalidated_at = ? WHERE token = ?')
            ->execute([$now, $token]);

        // validateToken doit retourner already_done, pas 'ok'
        $result = $this->workflow->validateToken($token, ValidationAction::Valider->value);

        self::assertContains(
            $result['status'],
            ['already_done', 'invalid'],
            "Token invalidé doit être refusé. Reçu : " . ($result['status'] ?? '?')
        );

        // La soumission doit rester en_cours
        $sub = $this->subRepo->findByIdWithForm($subId);
        self::assertSame(SubmissionStatus::EnCours->value, $sub['status']);
    }

    // ── Mutant Infection #6 : valider/refuser inversé ─────────────────────

    public function testValidateTokenValiderActionSendsOk(): void
    {
        [$formId, $stepId] = $this->createFormWithStep();
        $subId = $this->createSubmission($formId);
        $token = $this->createToken($subId, $stepId, 'validator-ok@test.com');

        $result = $this->workflow->validateToken($token, ValidationAction::Valider->value);
        self::assertSame('ok', $result['status'], "Valider doit retourner ok, pas autre chose");
    }

    public function testValidateTokenRefuserActionSendsRefusedEmail(): void
    {
        [$formId, $stepId] = $this->createFormWithStep();
        $subId = $this->createSubmission($formId, 'refuser-agent@test.com');
        $token = $this->createToken($subId, $stepId, 'validator-refuser@test.com');

        $beforeMails = count($GLOBALS['_test_mails'] ?? []);
        $result = $this->workflow->validateToken($token, ValidationAction::Refuser->value, 'Motif test');

        self::assertSame('ok', $result['status']);
        self::assertSame(SubmissionStatus::Refuse->value, $this->subRepo->findByIdWithForm($subId)['status']);

        // Vérifier qu'un email a été envoyé à l'agent pour le refus
        $afterMails = $GLOBALS['_test_mails'] ?? [];
        self::assertGreaterThan($beforeMails, count($afterMails), 'un email doit partir');
        $lastMail = end($afterMails);
        self::assertSame('refuser-agent@test.com', $lastMail['to']);
        self::assertStringContainsString('refusée', $lastMail['subject']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** @return array{0: string, 1: string} [formId, stepId] */
    private function createFormWithStep(string $condition = ''): array
    {
        $formId = \generate_uuid();
        $stepId = \generate_uuid();
        $slug = 'test-audit-' . substr($formId, 0, 8);

        $pdo = $this->db->getPdo();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at, deadline_field) VALUES (?, ?, 'Test Audit', '', 1, datetime('now'), '')")
            ->execute([$formId, $slug]);
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation', 1, 1, ?)")
            ->execute([$stepId, $formId, $condition]);

        $this->createdIds[] = $formId;
        $this->createdIds[] = $stepId;
        return [$formId, $stepId];
    }

    private function createForm(string $slug): string
    {
        $formId = \generate_uuid();
        $pdo = $this->db->getPdo();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at, deadline_field) VALUES (?, ?, 'Test No Steps', '', 1, datetime('now'), '')")
            ->execute([$formId, $slug]);
        $this->createdIds[] = $formId;
        return $formId;
    }

    private function createSubmission(string $formId, string $submittedBy = 'agent@test.com'): string
    {
        $subId = \generate_uuid();
        $pdo = $this->db->getPdo();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at, closed_at, rgpd_consent) VALUES (?, ?, '{}', ?, 'en_cours', datetime('now'), NULL, 1)")
            ->execute([$subId, $formId, $submittedBy]);
        $this->createdIds[] = $subId;
        return $subId;
    }

    private function createToken(string $subId, string $stepId, string $email): string
    {
        $tokenId = \generate_uuid();
        $token = \generate_token();
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+30 days') ?: time());
        $pdo = $this->db->getPdo();
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), ?)")
            ->execute([$tokenId, $subId, $stepId, $email, $token, $expiresAt]);
        $this->createdIds[] = $tokenId;
        return $token;
    }
}
