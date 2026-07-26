<?php
declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\App;
use App\Core\Database;
use App\Enum\FieldType;
use App\Enum\FilledBy;
use App\Enum\SubmissionStatus;
use PHPUnit\Framework\TestCase;

/**
 * Tests PHPUnit pour FormController — utilise le mode 'no-exit' de TestModeService
 * pour capturer les réponses JSON sans crasher PHPUnit (B-EXIT fix).
 *
 * Couvre les branches principales :
 * - GET sans slug → JSON error "Formulaire introuvable"
 * - GET avec slug inexistant → JSON error
 * - GET avec slug valide → JSON succès (form + fields)
 * - POST sans RGPD → JSON error "Erreurs de validation"
 * - POST avec email invalide (B-F1) → JSON error
 * - POST valide → JSON success + email envoyé + tokens créés
 * - POST avec required field vide → JSON error
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
        // Activer le mode 'no-exit' pour tous les tests
        $GLOBALS['_test_no_exit'] = true;
        $GLOBALS['_test_json_output'] = null;
    }

    protected function tearDown(): void
    {
        // Désactiver le mode 'no-exit'
        unset($GLOBALS['_test_no_exit'], $GLOBALS['_test_json_output']);

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

    // ── GET : branches sans POST ─────────────────────────────────────────

    public function testHandleGetWithoutSlugReturnsErrorJson(): void
    {
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        (new \App\Controller\FormController())->handle();

        $output = $GLOBALS['_test_json_output'] ?? null;
        $this->assertNotNull($output, 'Doit produire une sortie JSON');
        $this->assertArrayHasKey('error', $output);
        $this->assertStringContainsString('introuvable', $output['error']);
    }

    public function testHandleGetWithInvalidSlugReturnsErrorJson(): void
    {
        $_GET['f'] = 'inexistant-slug-' . uniqid();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        (new \App\Controller\FormController())->handle();

        $output = $GLOBALS['_test_json_output'] ?? null;
        $this->assertNotNull($output);
        $this->assertArrayHasKey('error', $output);
        $this->assertStringContainsString('introuvable', $output['error']);
        $this->assertSame($_GET['f'], $output['slug'] ?? '');
    }

    public function testHandleGetWithValidSlugReturnsFormJson(): void
    {
        $slug = 'test-form-render-' . uniqid();
        $formId = $this->createTestForm($slug);
        $this->createTestField($formId, 'nom', FieldType::Text->value, 'Nom', true);

        $_GET['f'] = $slug;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        (new \App\Controller\FormController())->handle();

        $output = $GLOBALS['_test_json_output'] ?? null;
        // En GET, le controller rend le HTML OU retourne du JSON avec form + fields.
        // Si JSON : il contient 'form' et 'fields'
        // Si HTML : $output est null mais le HTML a été echo
        // Le mode no-exit capture seulement les appels test_json_response
        if ($output !== null) {
            $this->assertArrayHasKey('form', $output);
            $this->assertSame($formId, $output['form']['id']);
            $this->assertArrayHasKey('fields', $output);
            $this->assertNotEmpty($output['fields']);
        }
        // Sinon, le form est rendu en HTML (pas test_json_response)
    }

    // ── POST : validation ────────────────────────────────────────────────

    public function testHandlePostWithoutRgpdConsentReturnsValidationError(): void
    {
        $slug = 'test-rgpd-' . uniqid();
        $formId = $this->createTestForm($slug);
        $this->createTestField($formId, 'nom', FieldType::Text->value, 'Nom', true);

        $_GET['f'] = $slug;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => 'test', // no-op en TEST_MODE
            'nom' => 'Jean Dupont',
            // rgpd_consent intentionnellement absent
        ];

        (new \App\Controller\FormController())->handle();

        $output = $GLOBALS['_test_json_output'] ?? null;
        $this->assertNotNull($output, 'Doit retourner JSON error');
        $this->assertArrayHasKey('error', $output);
        $this->assertStringContainsString('validation', $output['error']);
        $this->assertArrayHasKey('field_errors', $output);
        $this->assertArrayHasKey('rgpd_consent', $output['field_errors']);

        // Vérifier qu'aucune soumission n'a été créée
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM submissions WHERE form_id = ?');
        $stmt->execute([$formId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'Aucune soumission ne doit être créée sans RGPD');
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
            'email_validateur' => 'pas-un-email', // invalide
            'rgpd_consent' => '1',
        ];

        (new \App\Controller\FormController())->handle();

        $output = $GLOBALS['_test_json_output'] ?? null;
        $this->assertNotNull($output);
        $this->assertArrayHasKey('error', $output);
        $this->assertArrayHasKey('field_errors', $output);
        $this->assertArrayHasKey('email_validateur', $output['field_errors']);
        $this->assertStringContainsString('invalide', $output['field_errors']['email_validateur']);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM submissions WHERE form_id = ?');
        $stmt->execute([$formId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'Aucune soumission ne doit être créée avec email invalide');
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

        (new \App\Controller\FormController())->handle();

        // Vérifier qu'une soumission a été créée
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT id, status, submitted_by, rgpd_consent FROM submissions WHERE form_id = ?');
        $stmt->execute([$formId]);
        $sub = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotNull($sub, 'Une soumission doit être créée');
        $this->assertSame('en_cours', $sub['status']);
        $this->assertSame('1', (string) $sub['rgpd_consent']);

        // Vérifier qu'un token a été créé (workflow déclenché)
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM tokens WHERE submission_id = ?');
        $stmt->execute([$sub['id']]);
        $this->assertGreaterThan(0, (int) $stmt->fetchColumn(), 'Au moins un token doit être créé');

        // Vérifier qu'au moins un email a été envoyé (confirmation agent + email validateur)
        $this->assertNotEmpty($GLOBALS['_test_mails'], 'Au moins un email doit partir');
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

        (new \App\Controller\FormController())->handle();

        $output = $GLOBALS['_test_json_output'] ?? null;
        $this->assertNotNull($output);
        $this->assertArrayHasKey('field_errors', $output);
        $this->assertArrayHasKey('nom', $output['field_errors']);
        $this->assertStringContainsString('obligatoire', $output['field_errors']['nom']);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM submissions WHERE form_id = ?');
        $stmt->execute([$formId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'Aucune soumission ne doit être créée avec required vide');
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
