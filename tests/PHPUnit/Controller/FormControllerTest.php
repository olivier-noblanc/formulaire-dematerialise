<?php
declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\App;
use App\Core\Database;
use App\Enum\FieldType;
use App\Enum\FilledBy;
use App\Enum\SubmissionStatus;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/_controller_overrides.php';

/**
 * Tests PHPUnit pour FormController — utilise le pattern TestJsonCapturedException
 * pour capturer les réponses JSON sans exit (B-EXIT).
 *
 * @package App\Tests\Controller
 */
final class FormControllerTest extends TestCase
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
        $_FILES = [];
        $GLOBALS['_test_mails'] = [];
        $GLOBALS['_test_captured_json'] = null;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_test_captured_json']);

        $pdo = $this->db->getPdo();
        foreach ($this->createdIds as $id) {
            $pdo->prepare('DELETE FROM tokens WHERE submission_id IN (SELECT id FROM submissions WHERE form_id = ?)')->execute([$id]);
            $pdo->prepare('DELETE FROM submissions WHERE form_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM form_fields WHERE form_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM steps WHERE form_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM forms WHERE id = ?')->execute([$id]);
        }
        $this->createdIds = [];
    }

    /**
     * Exécute un callable en capturant la sortie JSON (TestJsonCapturedException).
     * Retourne le JSON capturé ou null si non capturé.
     */
    private function captureJson(callable $fn): ?array
    {
        try {
            $fn();
        } catch (TestJsonCapturedException $e) {
            return $e->data;
        } catch (\Throwable $e) {
            // ErrorRenderer peut throw ErrorResponseException — on ignore
            return null;
        }
        return $GLOBALS['_test_captured_json'] ?? null;
    }

    // ── GET ───────────────────────────────────────────────────────────────

    public function testHandleGetWithoutSlugReturnsErrorJson(): void
    {
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $output = $this->captureJson(fn() => (new \App\Controller\FormController())->handle());

        self::assertNotNull($output, 'Doit produire une sortie JSON');
        self::assertArrayHasKey('error', $output);
        self::assertStringContainsString('introuvable', $output['error']);
    }

    public function testHandleGetWithInvalidSlugReturnsErrorJson(): void
    {
        $_GET['f'] = 'inexistant-slug-' . uniqid();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $output = $this->captureJson(fn() => (new \App\Controller\FormController())->handle());

        self::assertNotNull($output);
        self::assertArrayHasKey('error', $output);
        self::assertStringContainsString('introuvable', $output['error']);
    }

    public function testHandleGetWithValidSlugReturnsFormJson(): void
    {
        $slug = 'test-form-render-' . uniqid();
        $formId = $this->createTestForm($slug);
        $this->createTestField($formId, 'nom', FieldType::Text->value, 'Nom', true);

        $_GET['f'] = $slug;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $output = $this->captureJson(fn() => (new \App\Controller\FormController())->handle());

        self::assertNotNull($output, 'GET valide doit retourner JSON avec form + fields');
        self::assertArrayHasKey('form', $output);
        self::assertSame($formId, $output['form']['id']);
        self::assertArrayHasKey('fields', $output);
        self::assertNotEmpty($output['fields']);
        self::assertSame('nom', $output['fields'][0]['field_name']);
    }

    // ── POST ──────────────────────────────────────────────────────────────

    public function testHandlePostWithoutRgpdConsentReturnsValidationError(): void
    {
        $slug = 'test-rgpd-' . uniqid();
        $formId = $this->createTestForm($slug);
        $this->createTestField($formId, 'nom', FieldType::Text->value, 'Nom', true);

        $_GET['f'] = $slug;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => 'test',
            'nom' => 'Jean Dupont',
        ];

        $output = $this->captureJson(fn() => (new \App\Controller\FormController())->handle());

        self::assertNotNull($output, 'Doit retourner JSON error validation');
        self::assertArrayHasKey('error', $output);
        self::assertArrayHasKey('field_errors', $output);
        self::assertArrayHasKey('rgpd_consent', $output['field_errors']);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM submissions WHERE form_id = ?');
        $stmt->execute([$formId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'Aucune soumission ne doit être créée sans RGPD');
    }

    public function testHandlePostWithInvalidEmailReturnsValidationError(): void
    {
        // B-F1 : le champ field_type=email doit être validé
        $slug = 'test-email-' . uniqid();
        $formId = $this->createTestForm($slug);
        $this->createTestField($formId, 'email_validateur', FieldType::Email->value, 'Email validateur', true);

        $_GET['f'] = $slug;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => 'test',
            'email_validateur' => 'pas-un-email',
            'rgpd_consent' => '1',
        ];

        $output = $this->captureJson(fn() => (new \App\Controller\FormController())->handle());

        self::assertNotNull($output);
        self::assertArrayHasKey('field_errors', $output);
        self::assertArrayHasKey('email_validateur', $output['field_errors']);
        self::assertStringContainsString('invalide', $output['field_errors']['email_validateur']);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM submissions WHERE form_id = ?');
        $stmt->execute([$formId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'Aucune soumission avec email invalide');
    }

    public function testHandlePostWithValidDataCreatesSubmissionAndSendsEmail(): void
    {
        $slug = 'test-success-' . uniqid();
        $formId = $this->createTestForm($slug);
        $this->createTestField($formId, 'nom', FieldType::Text->value, 'Nom', true);
        $this->createTestStep($formId, 'Validation', 'test-validator@test.com');

        $_GET['f'] = $slug;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => 'test',
            'nom' => 'Alice Test',
            'rgpd_consent' => '1',
        ];

        $output = $this->captureJson(fn() => (new \App\Controller\FormController())->handle());

        self::assertNotNull($output, 'POST valide doit retourner JSON success');
        self::assertTrue($output['success'] ?? false, 'success doit être true');
        self::assertArrayHasKey('submission_id', $output);

        // Vérifier qu'une soumission a été créée
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT id, status, submitted_by, rgpd_consent FROM submissions WHERE form_id = ?');
        $stmt->execute([$formId]);
        $sub = $stmt->fetch(\PDO::FETCH_ASSOC);

        self::assertNotNull($sub, 'Une soumission doit être créée');
        self::assertSame('en_cours', $sub['status']);
        self::assertSame('1', (string) $sub['rgpd_consent']);

        // Vérifier qu'un token a été créé (workflow déclenché)
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM tokens WHERE submission_id = ?');
        $stmt->execute([$sub['id']]);
        self::assertGreaterThan(0, (int) $stmt->fetchColumn(), 'Au moins un token doit être créé');

        // Vérifier qu'au moins un email a été envoyé
        self::assertNotEmpty($GLOBALS['_test_mails'], 'Au moins un email doit partir');
    }

    public function testHandlePostWithMissingRequiredFieldReturnsValidationError(): void
    {
        $slug = 'test-required-' . uniqid();
        $formId = $this->createTestForm($slug);
        $this->createTestField($formId, 'nom', FieldType::Text->value, 'Nom', true);

        $_GET['f'] = $slug;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => 'test',
            'nom' => '', // required vide
            'rgpd_consent' => '1',
        ];

        $output = $this->captureJson(fn() => (new \App\Controller\FormController())->handle());

        self::assertNotNull($output);
        self::assertArrayHasKey('field_errors', $output);
        self::assertArrayHasKey('nom', $output['field_errors']);
        self::assertStringContainsString('obligatoire', $output['field_errors']['nom']);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM submissions WHERE form_id = ?');
        $stmt->execute([$formId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'Aucune soumission avec required vide');
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function createTestForm(string $slug): string
    {
        $formId = \generate_uuid();
        $pdo = $this->db->getPdo();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at, deadline_field) VALUES (?, ?, 'Test Form', '', 1, datetime('now'), '')")
            ->execute([$formId, $slug]);
        $this->createdIds[] = $formId;
        return $formId;
    }

    private function createTestField(string $formId, string $name, string $type, string $label, bool $required): void
    {
        $fieldId = \generate_uuid();
        $pdo = $this->db->getPdo();
        $pdo->prepare("INSERT INTO form_fields (id, form_id, label, field_type, field_name, required, ordre, filled_by, visibility, `condition`) VALUES (?, ?, ?, ?, ?, ?, 1, ?, 'all', '')")
            ->execute([$fieldId, $formId, $label, $type, $name, $required ? 1 : 0, FilledBy::Demandeur->value]);
    }

    private function createTestStep(string $formId, string $label, string $recipientEmail): void
    {
        $stepId = \generate_uuid();
        $pdo = $this->db->getPdo();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, ?, 1, 1, '')")
            ->execute([$stepId, $formId, $label]);
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)")
            ->execute([\generate_uuid(), $stepId, $recipientEmail]);
    }
}
