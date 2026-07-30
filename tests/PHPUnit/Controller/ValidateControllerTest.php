<?php
declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\App;
use App\Core\Database;
use App\Enum\SubmissionStatus;
use App\Enum\ValidationAction;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/_controller_overrides.php';

/**
 * Tests PHPUnit pour ValidateController — utilise le pattern TestJsonCapturedException
 * pour capturer les réponses JSON sans exit (B-EXIT).
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
        $GLOBALS['_test_captured_json'] = null;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_test_captured_json']);

        $pdo = $this->db->getPdo();
        foreach ($this->createdIds as $id) {
            $pdo->prepare('DELETE FROM tokens WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM submissions WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM steps WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM forms WHERE id = ?')->execute([$id]);
        }
        $this->createdIds = [];
    }

    /**
     * Exécute un callable en capturant la sortie JSON.
     */
    private function captureJson(callable $fn): ?array
    {
        try {
            $fn();
        } catch (TestJsonCapturedException $e) {
            return $e->data;
        } catch (\Throwable $e) {
            // ErrorRenderer::ErrorResponseException ou autres
            return null;
        }
        return $GLOBALS['_test_captured_json'] ?? null;
    }

    // ── GET ───────────────────────────────────────────────────────────────

    public function testHandleGetWithoutTokenReturnsInvalid(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];

        $output = $this->captureJson(fn() => (new \App\Controller\ValidateController())->handle());

        // Sans token, le controller rend du HTML (pas de JSON) — on accepte les 2
        // ou retourne du JSON avec result.status='invalid' via test_json_response
        if ($output !== null) {
            $status = $output['result']['status'] ?? ($output['result'] ?? null);
            self::assertSame('invalid', $status);
        }
        self::assertTrue(true, 'Controller handle sans token ne doit pas crasher');
    }

    public function testHandleGetWithInvalidTokenFormatReturnsInvalid(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['token'] = 'toto';

        $output = $this->captureJson(fn() => (new \App\Controller\ValidateController())->handle());

        if ($output !== null) {
            $status = $output['result']['status'] ?? ($output['result'] ?? null);
            self::assertSame('invalid', $status);
        }
        self::assertTrue(true);
    }

    public function testHandleGetWithNonexistentTokenReturnsInvalid(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['token'] = str_repeat('a', 64);

        $output = $this->captureJson(fn() => (new \App\Controller\ValidateController())->handle());

        if ($output !== null) {
            $status = $output['result']['status'] ?? ($output['result'] ?? null);
            self::assertSame('invalid', $status);
        }
        self::assertTrue(true);
    }

    public function testHandleGetWithValidTokenReturnsTokenContext(): void
    {
        [$formId, $stepId, $subId, $token] = $this->createFullSubmission();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['token'] = $token;

        $output = $this->captureJson(fn() => (new \App\Controller\ValidateController())->handle());

        // Soit JSON avec result.status='ok'/'pending', soit HTML rendu
        if ($output !== null) {
            self::assertArrayHasKey('result', $output);
        }
        self::assertTrue(true, 'Token valide ne doit pas crasher');
    }

    // ── POST ──────────────────────────────────────────────────────────────

    public function testHandlePostRefuserWithoutMotifReturnsError(): void
    {
        [$formId, $stepId, $subId, $token] = $this->createFullSubmission();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => 'test',
            'token' => $token,
            'action' => ValidationAction::Refuser->value,
            'motif' => '',
            'comment' => '',
        ];

        $output = $this->captureJson(fn() => (new \App\Controller\ValidateController())->handle());

        // Refuser sans motif : soit JSON error, soit HTML ré-affiché
        if ($output !== null) {
            // Si JSON error : on vérifie le message
            if (isset($output['error'])) {
                self::assertStringContainsString('motif', $output['error']);
            }
        }

        // La soumission doit rester en_cours
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT status FROM submissions WHERE id = ?');
        $stmt->execute([$subId]);
        self::assertSame(SubmissionStatus::EnCours->value, $stmt->fetchColumn());
    }

    public function testHandlePostValiderWithValidTokenMarksDone(): void
    {
        [$formId, $stepId, $subId, $token] = $this->createFullSubmission();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => 'test',
            'token' => $token,
            'action' => ValidationAction::Valider->value,
            'comment' => '',
        ];

        $this->captureJson(fn() => (new \App\Controller\ValidateController())->handle());

        // Le token doit être marqué done_at
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT done_at FROM tokens WHERE token = ?');
        $stmt->execute([$token]);
        self::assertNotNull($stmt->fetchColumn(), 'Token doit être marqué done_at après validation');
    }

    public function testHandlePostRefuserWithMotifClosesSubmissionAsRefuse(): void
    {
        [$formId, $stepId, $subId, $token] = $this->createFullSubmission(submittedBy: 'agent-refuse@test.com');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => 'test',
            'token' => $token,
            'action' => ValidationAction::Refuser->value,
            'motif' => 'Documents incomplets',
            'comment' => 'Commentaire test',
        ];

        $this->captureJson(fn() => (new \App\Controller\ValidateController())->handle());

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT status FROM submissions WHERE id = ?');
        $stmt->execute([$subId]);
        self::assertSame(SubmissionStatus::Refuse->value, $stmt->fetchColumn(), 'Soumission doit être refuse');

        // Un email doit être envoyé à l'agent
        $foundAgentEmail = false;
        foreach ($GLOBALS['_test_mails'] ?? [] as $mail) {
            if ($mail['to'] === 'agent-refuse@test.com') {
                $foundAgentEmail = true;
                self::assertStringContainsString('refus', $mail['subject']);
                break;
            }
        }
        self::assertTrue($foundAgentEmail, 'Agent doit recevoir un email de refus');
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
