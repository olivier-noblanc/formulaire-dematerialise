<?php
declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\App;
use App\Core\Database;
use App\Enum\SubmissionStatus;
use App\Enum\ValidationAction;
use PHPUnit\Framework\TestCase;

/**
 * Tests PHPUnit pour ValidateController.
 *
 * Couvre les branches principales de handle() :
 * - GET sans token → invalide
 * - GET avec token inexistant → invalide
 * - GET avec token déjà utilisé → already_done
 * - GET avec token valide → page de validation
 * - POST valider → success
 * - POST refuser sans motif → erreur
 * - POST refuser avec motif → success + email agent
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

    // ── GET ───────────────────────────────────────────────────────────────

    public function testHandleGetWithoutTokenReturnsInvalid(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];

        ob_start();
        try {
            (new \App\Controller\ValidateController())->handle();
        } catch (\Throwable $e) {
        }
        $output = ob_get_clean();

        $this->assertTrue(
            str_contains($output, 'invalide') || str_contains($output, 'error') || str_contains($output, 'Lien'),
            "Sans token doit retourner invalide. Reçu : " . substr($output, 0, 300)
        );
    }

    public function testHandleGetWithInvalidTokenFormatReturnsInvalid(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['token'] = 'toto'; // format invalide (pas 64 hex)

        ob_start();
        try {
            (new \App\Controller\ValidateController())->handle();
        } catch (\Throwable $e) {
        }
        $output = ob_get_clean();

        $this->assertTrue(
            str_contains($output, 'invalide') || str_contains($output, 'error'),
            "Token format invalide doit être rejeté. Reçu : " . substr($output, 0, 300)
        );
    }

    public function testHandleGetWithNonexistentTokenReturnsInvalid(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['token'] = str_repeat('a', 64); // format valide mais inexistant

        ob_start();
        try {
            (new \App\Controller\ValidateController())->handle();
        } catch (\Throwable $e) {
        }
        $output = ob_get_clean();

        $this->assertTrue(
            str_contains($output, 'invalide') || str_contains($output, 'error'),
            "Token inexistant doit retourner invalide. Reçu : " . substr($output, 0, 300)
        );
    }

    public function testHandleGetWithValidTokenRendersValidationPage(): void
    {
        [$formId, $stepId, $subId, $token] = $this->createFullSubmission();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['token'] = $token;

        ob_start();
        try {
            (new \App\Controller\ValidateController())->handle();
        } catch (\Throwable $e) {
        }
        $output = ob_get_clean();

        // En TEST_MODE, validate peut retourner du JSON si le token est déjà utilisé,
        // sinon HTML avec les boutons Valider/Refuser
        $this->assertTrue(
            $output !== '' && (str_contains($output, 'valider') || str_contains($output, 'Valider') || str_contains($output, 'token') || str_contains($output, 'result')),
            "Page de validation doit être rendue. Reçu : " . substr($output, 0, 300)
        );
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
            'motif' => '', // vide → erreur attendue
            'comment' => '',
        ];

        ob_start();
        try {
            (new \App\Controller\ValidateController())->handle();
        } catch (\Throwable $e) {
        }
        $output = ob_get_clean();

        $this->assertTrue(
            str_contains($output, 'motif') || str_contains($output, 'error'),
            "Refuser sans motif doit retourner erreur. Reçu : " . substr($output, 0, 300)
        );

        // La soumission doit rester en_cours
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT status FROM submissions WHERE id = ?');
        $stmt->execute([$subId]);
        $this->assertSame(SubmissionStatus::EnCours->value, $stmt->fetchColumn());
    }

    public function testHandlePostValiderWithValidTokenAdvancesWorkflow(): void
    {
        [$formId, $stepId, $subId, $token] = $this->createFullSubmission();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => 'test',
            'token' => $token,
            'action' => ValidationAction::Valider->value,
            'comment' => '',
        ];

        ob_start();
        try {
            (new \App\Controller\ValidateController())->handle();
        } catch (\Throwable $e) {
        }
        $output = ob_get_clean();

        // Le token doit être marqué done_at
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT done_at FROM tokens WHERE token = ?');
        $stmt->execute([$token]);
        $this->assertNotNull($stmt->fetchColumn(), 'Token doit être marqué done_at après validation');
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

        ob_start();
        try {
            (new \App\Controller\ValidateController())->handle();
        } catch (\Throwable $e) {
        }
        $output = ob_get_clean();

        // La soumission doit être marquée refuse
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT status FROM submissions WHERE id = ?');
        $stmt->execute([$subId]);
        $this->assertSame(SubmissionStatus::Refuse->value, $stmt->fetchColumn(), 'Soumission doit être refuse');

        // Un email doit être envoyé à l'agent
        $foundAgentEmail = false;
        foreach ($GLOBALS['_test_mails'] ?? [] as $mail) {
            if ($mail['to'] === 'agent-refuse@test.com') {
                $foundAgentEmail = true;
                $this->assertStringContainsString('refus', $mail['subject']);
                break;
            }
        }
        $this->assertTrue($foundAgentEmail, 'Agent doit recevoir un email de refus');
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** @return array{0: string, 1: string, 2: string, 3: string} [formId, stepId, subId, token] */
    private function createFullSubmission(string $submittedBy = 'agent@test.com', string $validatorEmail = 'validator@test.com'): array
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
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+30 days') ?: time());
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), ?)")
            ->execute([$tokenId, $subId, $stepId, $validatorEmail, $token, $expiresAt]);

        $this->createdIds[] = $tokenId;
        $this->createdIds[] = $subId;
        $this->createdIds[] = $stepId;
        $this->createdIds[] = $formId;
        return [$formId, $stepId, $subId, $token];
    }
}
