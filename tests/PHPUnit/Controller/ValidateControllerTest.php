<?php
declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\App;
use App\Core\Database;
use App\Enum\SubmissionStatus;
use App\Enum\ValidationAction;
use PHPUnit\Framework\TestCase;

/**
 * Tests PHPUnit pour ValidateController — couvre les branches VALIDABLES sans exit.
 *
 * Note : ValidateController::handle() appelle test_json_response() (qui exit) en TEST_MODE
 * sur les chemins succès. On teste donc les branches d'ERREUR (pas d'exit) + le service
 * WorkflowEngine::validateToken() directement pour les chemins succès.
 *
 * @package App\Tests\Controller
 */
final class ValidateControllerTest extends TestCase
{
    private Database $db;

    /** @var list<string> IDs créés pour cleanup */
    private array $createdIds = [];

    protected function setUp(): void
    {
        $this->db = App::getInstance()->get(Database::class);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];
        $_POST = [];
        $GLOBALS['_test_mails'] = [];
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->getPdo();
        foreach ($this->createdIds as $id) {
            $pdo->prepare('DELETE FROM tokens WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM submissions WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM steps WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM forms WHERE id = ?')->execute([$id]);
        }
        $this->createdIds = [];
    }

    // ── Validation du format token (sans exit) ───────────────────────────

    public function testTokenFormatRegexRejectsInvalidFormat(): void
    {
        // Le controller utilise preg_match('/^[a-f0-9]{64}$/', $token)
        $invalidTokens = ['', 'toto', 'XYZ123', str_repeat('g', 64), str_repeat('a', 63)];
        foreach ($invalidTokens as $t) {
            $this->assertSame(0, preg_match('/^[a-f0-9]{64}$/', $t), "Token '{$t}' doit être rejeté");
        }
    }

    public function testTokenFormatRegexAcceptsValidFormat(): void
    {
        $validToken = str_repeat('a', 64);
        $this->assertSame(1, preg_match('/^[a-f0-9]{64}$/', $validToken), 'Token 64 hex chars doit passer');
    }

    // ── validateToken directement via WorkflowEngine ─────────────────────

    public function testValidateTokenRejectsInvalidFormatToken(): void
    {
        $result = App::workflow()->validateToken('toto', ValidationAction::Valider->value);
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenRejectsNonexistentToken(): void
    {
        $token = str_repeat('a', 64); // format valide mais inexistant
        $result = App::workflow()->validateToken($token, ValidationAction::Valider->value);
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenRejectsInvalidAction(): void
    {
        $token = str_repeat('a', 64);
        $result = App::workflow()->validateToken($token, 'invalid_action');
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenValiderActionMarksTokenDone(): void
    {
        [$formId, $stepId, $subId, $token] = $this->createFullSubmission();

        $result = App::workflow()->validateToken($token, ValidationAction::Valider->value);
        $this->assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT done_at FROM tokens WHERE token = ?');
        $stmt->execute([$token]);
        $this->assertNotNull($stmt->fetchColumn(), 'Token doit être marqué done_at');
    }

    public function testValidateTokenRefuserActionClosesSubmissionAsRefuse(): void
    {
        [$formId, $stepId, $subId, $token] = $this->createFullSubmission(submittedBy: 'agent-refuse@test.com');

        $result = App::workflow()->validateToken($token, ValidationAction::Refuser->value, 'Motif test');
        $this->assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT status FROM submissions WHERE id = ?');
        $stmt->execute([$subId]);
        $this->assertSame(SubmissionStatus::Refuse->value, $stmt->fetchColumn());
    }

    public function testValidateTokenRefuserSendsRefusedEmailToAgent(): void
    {
        [$formId, $stepId, $subId, $token] = $this->createFullSubmission(submittedBy: 'agent-refused@test.com');

        $beforeMails = count($GLOBALS['_test_mails'] ?? []);
        App::workflow()->validateToken($token, ValidationAction::Refuser->value, 'Motif test');

        $afterMails = $GLOBALS['_test_mails'] ?? [];
        $this->assertGreaterThan($beforeMails, count($afterMails), 'Un email doit partir');

        $foundAgentEmail = false;
        foreach ($afterMails as $mail) {
            if ($mail['to'] === 'agent-refused@test.com') {
                $foundAgentEmail = true;
                $this->assertStringContainsString('refus', $mail['subject']);
                break;
            }
        }
        $this->assertTrue($foundAgentEmail, 'Agent doit recevoir un email de refus');
    }

    public function testValidateTokenWithAlreadyDoneTokenReturnsAlreadyDone(): void
    {
        [$formId, $stepId, $subId, $token] = $this->createFullSubmission();

        // Premier appel → ok
        $r1 = App::workflow()->validateToken($token, ValidationAction::Valider->value);
        $this->assertSame('ok', $r1['status']);

        // Deuxième appel → already_done
        $r2 = App::workflow()->validateToken($token, ValidationAction::Valider->value);
        $this->assertSame('already_done', $r2['status']);
    }

    public function testValidateTokenWithExpiredTokenReturnsExpired(): void
    {
        [$formId, $stepId, $subId, $token] = $this->createFullSubmission(expiresInDays: -1);

        $result = App::workflow()->validateToken($token, ValidationAction::Valider->value);
        $this->assertSame('expired', $result['status']);
    }

    public function testValidateTokenRefusesInvalidatedToken(): void
    {
        // B-V1 fix : token invalidé (par cancel/regenerate/delegate) doit être refusé
        [$formId, $stepId, $subId, $token] = $this->createFullSubmission();

        // Invalider le token
        $pdo = $this->db->getPdo();
        $now = gmdate('Y-m-d H:i:s');
        $pdo->prepare('UPDATE tokens SET invalidated_at = ? WHERE token = ?')
            ->execute([$now, $token]);

        $result = App::workflow()->validateToken($token, ValidationAction::Valider->value);
        $this->assertContains($result['status'], ['already_done', 'invalid'], "Token invalidé doit être refusé");
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** @return array{0: string, 1: string, 2: string, 3: string} [formId, stepId, subId, token] */
    private function createFullSubmission(string $submittedBy = 'agent@test.com', string $validatorEmail = 'validator@test.com', int $expiresInDays = 30): array
    {
        $formId = \generate_uuid();
        $stepId = \generate_uuid();
        $subId = \generate_uuid();
        $tokenId = \generate_uuid();
        $token = \generate_token();

        $pdo = $this->db->getPdo();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at, deadline_field) VALUES (?, ?, 'Test Validate', '', 1, datetime('now'), '')")
            ->execute([$formId, 'test-validate-' . substr($formId, 0, 8)]);
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation', 1, 1, '')")
            ->execute([$stepId, $formId]);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at, closed_at, rgpd_consent) VALUES (?, ?, '{}', ?, 'en_cours', datetime('now'), NULL, 1)")
            ->execute([$subId, $formId, $submittedBy]);
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime("+{$expiresInDays} days") ?: time());
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), ?)")
            ->execute([$tokenId, $subId, $stepId, $validatorEmail, $token, $expiresAt]);

        $this->createdIds[] = $tokenId;
        $this->createdIds[] = $subId;
        $this->createdIds[] = $stepId;
        $this->createdIds[] = $formId;
        return [$formId, $stepId, $subId, $token];
    }
}
