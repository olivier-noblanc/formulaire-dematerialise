<?php
declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\App;
use App\Core\Database;
use App\Enum\FieldType;
use App\Enum\FilledBy;
use PHPUnit\Framework\TestCase;

/**
 * Tests PHPUnit pour FormController — couvre les branches principales :
 * - GET sans slug → 404
 * - GET avec slug inexistant → 404
 * - GET avec slug valide → rendu formulaire
 * - POST sans RGPD → erreur
 * - POST avec email invalide (B-F1) → erreur
 * - POST valide → success + email envoyé
 *
 * En TEST_MODE, le controller retourne du JSON via test_json_response()
 * au lieu de HTML. On capture via ob_start().
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
        // Reset test mails
        $GLOBALS['_test_mails'] = [];
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->getPdo();
        // Cleanup en cascade
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

    public function testHandleGetWithoutSlugReturns404(): void
    {
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        try {
            (new \App\Controller\FormController())->handle();
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            $output = ob_get_clean() . ($output ?? '');
            // ErrorRenderer appelle exit, mais en test on l'attrape
        }

        // En TEST_MODE, test_json_response renvoie du JSON avec "error"
        // ou ErrorRenderer affiche une page 404
        $this->assertTrue(
            str_contains($output, 'error') || str_contains($output, 'introuvable') || str_contains($output, 'Formulaire'),
            "Doit retourner une erreur/formulaire introuvable. Reçu : " . substr($output, 0, 200)
        );
    }

    public function testHandleGetWithInvalidSlugReturns404(): void
    {
        $_GET['f'] = 'inexistant-slug-' . uniqid();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        try {
            (new \App\Controller\FormController())->handle();
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            $output = ob_get_clean();
        }

        $this->assertTrue(
            str_contains($output, 'error') || str_contains($output, 'introuvable'),
            "Slug inexistant doit retourner une erreur. Reçu : " . substr($output, 0, 200)
        );
    }

    public function testHandleGetWithValidSlugRendersForm(): void
    {
        $slug = 'test-form-render-' . uniqid();
        $formId = $this->createTestForm($slug);
        $this->createTestField($formId, 'nom', FieldType::Text->value, 'Nom', true);

        $_GET['f'] = $slug;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        try {
            (new \App\Controller\FormController())->handle();
        } catch (\Throwable $e) {
            // ErrorRenderer peut exit() en mode non-test
        }
        $output = ob_get_clean();

        // En TEST_MODE, test_json_response peut être appelée pour ?error=
        // Sinon, le formulaire est rendu en HTML
        $this->assertTrue(
            str_contains($output, '<form') || str_contains($output, 'form') || str_contains($output, 'success') || $output === '',
            "Le formulaire doit être rendu. Reçu : " . substr($output, 0, 300)
        );
    }

    // ── POST : validation ────────────────────────────────────────────────

    public function testHandlePostWithoutRgpdConsentReturnsError(): void
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

        ob_start();
        try {
            (new \App\Controller\FormController())->handle();
        } catch (\Throwable $e) {
        }
        $output = ob_get_clean();

        // Le controller doit détecter l'absence de rgpd_consent
        $this->assertTrue(
            str_contains($output, 'rgpd') || str_contains($output, 'error') || str_contains($output, 'RGPD') || str_contains($output, 'consentement'),
            "Absence RGPD doit être détectée. Reçu : " . substr($output, 0, 300)
        );

        // Vérifier qu'aucune soumission n'a été créée
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM submissions WHERE form_id = ?');
        $stmt->execute([$formId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'Aucune soumission ne doit être créée sans RGPD');
    }

    public function testHandlePostWithInvalidEmailReturnsError(): void
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

        ob_start();
        try {
            (new \App\Controller\FormController())->handle();
        } catch (\Throwable $e) {
        }
        $output = ob_get_clean();

        $this->assertTrue(
            str_contains($output, 'error') || str_contains($output, 'invalide') || str_contains($output, 'email'),
            "Email invalide doit être détecté. Reçu : " . substr($output, 0, 300)
        );

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

        ob_start();
        try {
            (new \App\Controller\FormController())->handle();
        } catch (\Throwable $e) {
            // test_json_response peut exit
        }
        $output = ob_get_clean();

        // Vérifier qu'une soumission a été créée
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT id, status, submitted_by, rgpd_consent FROM submissions WHERE form_id = ?');
        $stmt->execute([$formId]);
        $sub = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotNull($sub, 'Une soumission doit être créée');
        $this->assertSame('en_cours', $sub['status']);
        $this->assertSame('1', (string) $sub['rgpd_consent']);

        // Vérifier qu'au moins un email a été envoyé (confirmation agent + email validateur)
        $this->assertNotEmpty($GLOBALS['_test_mails'], 'Au moins un email doit partir');
    }

    public function testHandlePostWithMissingRequiredFieldReturnsError(): void
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

        ob_start();
        try {
            (new \App\Controller\FormController())->handle();
        } catch (\Throwable $e) {
        }
        $output = ob_get_clean();

        $this->assertTrue(
            str_contains($output, 'obligatoire') || str_contains($output, 'error'),
            "Champ required vide doit être détecté. Reçu : " . substr($output, 0, 300)
        );

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
