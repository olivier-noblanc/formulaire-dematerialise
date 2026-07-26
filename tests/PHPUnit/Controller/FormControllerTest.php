<?php
declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\FormController;
use App\Core\App;
use App\Core\Database;
use App\Enum\FilledBy;
use App\Enum\FieldType;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/_controller_overrides.php';

/**
 * Tests PHPUnit pour App\Controller\FormController.
 *
 * FormController est le point d'entrée de form.php?f=<slug> : il charge un
 * formulaire actif depuis la DB, valide les champs dynamiques sur POST,
 * crée une submission + déclenche le workflow + envoie un mail de
 * confirmation. En TEST_MODE, le controller appelle test_json_response()
 * (qui exit) à plusieurs endroits — nos overrides namespaced (cf.
 * _controller_overrides.php) capturent les payloads JSON à la place et
 * lèvent TestJsonCapturedException pour éviter les exit.
 *
 * Couverture visée :
 *   - GET ?screenshot=1 (skip du JSON test) → rendu HTML du formulaire
 *   - GET ?f=<slug inexistant> → JSON {error: 'Formulaire introuvable'}
 *   - POST avec données valides → JSON {success: true, submission_id, tokens}
 *     + side-effects DB (submissions row) + mail de confirmation envoyé
 *   - POST sans rgpd_consent → JSON {error: 'Erreurs de validation',
 *     field_errors: {rgpd_consent: '...'}}
 *   - POST avec champ required manquant → field_errors[field] = 'Ce champ est obligatoire'
 *   - POST avec email invalide (B-F1 fix) → field_errors[email] = 'Adresse email invalide'
 *   - GET avec soumission existante → encadré warning "Vous avez déjà une demande en cours"
 */
final class FormControllerTest extends TestCase
{
    private Database $db;

    /** @var list<string> UUIDs de forms créés par setUp / helpers — nettoyés dans tearDown */
    private array $createdFormIds = [];
    /** @var list<string> UUIDs de submissions créées — nettoyés dans tearDown */
    private array $createdSubmissionIds = [];

    protected function setUp(): void
    {
        $this->db = App::getInstance()->get(Database::class);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_X_TEST_MODE'] = '1';
        $_SERVER['HTTP_X_TEST_USER'] = 'testeur@e2e.test';
        $_SERVER['AUTH_USER'] = 'DREETS\testeur';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['HTTPS'] = '';
        $_SERVER['REQUEST_URI'] = '/';
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $GLOBALS['_test_mails'] = [];
        $GLOBALS['_test_captured_json'] = null;

        // Nettoyer les reliquats de tests précédents (slug / submitted_by
        // préfixés par 'test-'). Idempotent.
        $pdo = $this->db->getPdo();
        $pdo->exec("DELETE FROM submission_validator_data WHERE submission_id IN (SELECT id FROM submissions WHERE submitted_by LIKE 'test-%')");
        $pdo->exec("DELETE FROM tokens WHERE submission_id IN (SELECT id FROM submissions WHERE submitted_by LIKE 'test-%')");
        $pdo->exec("DELETE FROM attachments WHERE submission_id IN (SELECT id FROM submissions WHERE submitted_by LIKE 'test-%')");
        $pdo->exec("DELETE FROM submissions WHERE submitted_by LIKE 'test-%'");
        $pdo->exec("DELETE FROM form_fields WHERE form_id IN (SELECT id FROM forms WHERE slug LIKE 'test-%')");
        $pdo->exec("DELETE FROM steps WHERE form_id IN (SELECT id FROM forms WHERE slug LIKE 'test-%')");
        $pdo->exec("DELETE FROM forms WHERE slug LIKE 'test-%'");
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->getPdo();
        foreach ($this->createdSubmissionIds as $id) {
            try {
                $pdo->prepare("DELETE FROM submission_validator_data WHERE submission_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM attachments WHERE submission_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$id]);
            } catch (\Throwable) {
                // Best-effort cleanup
            }
        }
        foreach ($this->createdFormIds as $id) {
            try {
                $pdo->prepare("DELETE FROM form_fields WHERE form_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM step_recipients WHERE step_id IN (SELECT id FROM steps WHERE form_id = ?)")->execute([$id]);
                $pdo->prepare("DELETE FROM steps WHERE form_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$id]);
            } catch (\Throwable) {
                // Best-effort cleanup
            }
        }
        $this->createdFormIds = [];
        $this->createdSubmissionIds = [];
        $GLOBALS['_test_mails'] = [];
        $GLOBALS['_test_captured_json'] = null;
    }

    // ── Tests ─────────────────────────────────────────────────

    /**
     * GET ?screenshot=1 sur un formulaire actif doit rendre le HTML
     * (skipping le branch JSON test_mode). Le HTML doit contenir :
     *   - la balise <form method="POST"
     *   - le champ CSRF (csrf_token)
     *   - les labels des champs dynamiques
     *   - la checkbox rgpd_consent
     *   - le bouton submit « Envoyer ma demande »
     */
    public function testHandleGetWithScreenshotRendersFormHtml(): void
    {
        $slug = 'test-form-render-' . uniqid();
        $formId = $this->createTestForm($slug, 'Test Render', [
            ['name' => 'nom', 'label' => 'Nom', 'type' => FieldType::Text->value, 'required' => true],
            ['name' => 'email', 'label' => 'Email pro', 'type' => FieldType::Email->value, 'required' => true],
        ]);
        $_GET['f'] = $slug;
        $_GET['screenshot'] = '1';

        $output = $this->captureOutput(fn() => (new FormController())->handle());

        $this->assertStringContainsString('<form', $output);
        $this->assertStringContainsString('method="POST"', $output);
        $this->assertStringContainsString('name="csrf_token"', $output);
        $this->assertStringContainsString('Nom', $output);
        $this->assertStringContainsString('Email pro', $output);
        $this->assertStringContainsString('name="rgpd_consent"', $output);
        $this->assertStringContainsString('Envoyer ma demande', $output);
    }

    /**
     * GET ?f=<slug inexistant> doit déclencher test_json_response avec
     * error='Formulaire introuvable'. Notre override capture le payload
     * et lève TestJsonCapturedException (donc ErrorRenderer::errorPage
     * n'est jamais atteint, pas d'exit).
     */
    public function testHandleGetWithUnknownSlugReturnsNotFoundErrorJson(): void
    {
        $_GET['f'] = 'does-not-exist-' . uniqid();

        $output = $this->captureOutput(
            fn() => (new FormController())->handle(),
            expectJsonCapture: true
        );

        $this->assertNotNull($GLOBALS['_test_captured_json'] ?? null, 'test_json_response doit être appelée');
        $this->assertSame('Formulaire introuvable', $GLOBALS['_test_captured_json']['error']);
        $this->assertSame($_GET['f'], $GLOBALS['_test_captured_json']['slug'] ?? null, 'Le slug doit être renvoyé dans le JSON');
        // L'output contient aussi le JSON echo'ed par notre override
        $this->assertStringContainsString('Formulaire introuvable', $output);
        $this->assertStringContainsString('_test_mode', $output);
    }

    /**
     * POST avec tous les champs requis + rgpd_consent doit :
     *   - appeler test_json_response avec success=true
     *   - créer une row dans submissions (avec rgpd_consent=1)
     *   - déclencher advanceWorkflow → créer des tokens
     *   - envoyer un mail de confirmation à l'agent
     */
    public function testHandlePostWithValidDataCreatesSubmissionAndTriggersWorkflow(): void
    {
        $slug = 'test-form-post-' . uniqid();
        $formId = $this->createTestForm($slug, 'Test Post', [
            ['name' => 'nom', 'label' => 'Nom', 'type' => FieldType::Text->value, 'required' => true],
            ['name' => 'email', 'label' => 'Email pro', 'type' => FieldType::Email->value, 'required' => true],
        ]);
        // Créer une étape + recipient pour que advanceWorkflow génère un token
        $this->createTestStep($formId, 'Validation manager', 1, ['manager@test.local']);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET['f'] = $slug;
        $_POST = [
            'csrf_token'    => 'test-csrf-token',
            'nom'           => 'Testeur PHP',
            'email'         => 'testeur@dreets.gouv.fr',
            'rgpd_consent'  => '1',
        ];

        $output = $this->captureOutput(
            fn() => (new FormController())->handle(),
            expectJsonCapture: true
        );

        // Assertions sur le JSON capturé
        $this->assertNotNull($GLOBALS['_test_captured_json'], 'test_json_response doit être appelée sur POST succès');
        $this->assertTrue($GLOBALS['_test_captured_json']['success'] ?? false, 'success=true attendu');
        $submissionId = $GLOBALS['_test_captured_json']['submission_id'] ?? '';
        $this->assertNotSame('', $submissionId, 'submission_id non vide');
        $this->assertSame($slug, $GLOBALS['_test_captured_json']['form_slug'] ?? null);
        $this->assertStringContainsString('testeur@e2e.test', $output);

        // Side-effect DB : la submission existe avec rgpd_consent=1
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT form_id, submitted_by, rgpd_consent, status FROM submissions WHERE id = ?');
        $stmt->execute([$submissionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, 'La submission doit exister en DB');
        $this->assertSame($formId, $row['form_id']);
        $this->assertSame('testeur@e2e.test', $row['submitted_by']);
        $this->assertSame('1', (string) $row['rgpd_consent']);

        // Side-effect DB : au moins 1 token créé pour l'étape de validation
        $tokenStmt = $pdo->prepare('SELECT COUNT(*) FROM tokens WHERE submission_id = ?');
        $tokenStmt->execute([$submissionId]);
        $this->assertGreaterThanOrEqual(1, (int) $tokenStmt->fetchColumn(), 'advanceWorkflow doit créer au moins 1 token');

        // Side-effect : au moins 1 mail de confirmation à l'agent (+ mails aux validateurs)
        $this->assertGreaterThanOrEqual(1, count($GLOBALS['_test_mails']), 'Au moins un mail doit être envoyé');
        $agentMails = array_filter($GLOBALS['_test_mails'], fn($m) => $m['to'] === 'testeur@e2e.test');
        $this->assertCount(1, $agentMails, 'Exactement 1 mail de confirmation à l\'agent');
        $agentMail = array_values($agentMails)[0];
        $this->assertStringContainsString('Demande enregistrée', $agentMail['subject']);

        // Nettoyer pour tearDown
        $this->createdSubmissionIds[] = $submissionId;
    }

    /**
     * POST sans rgpd_consent doit retourner une erreur de validation
     * sur le champ rgpd_consent. La submission NE doit PAS être créée.
     */
    public function testHandlePostWithoutRgpdConsentReturnsValidationError(): void
    {
        $slug = 'test-form-norgpd-' . uniqid();
        $this->createTestForm($slug, 'Test No RGPD', [
            ['name' => 'nom', 'label' => 'Nom', 'type' => FieldType::Text->value, 'required' => true],
        ]);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET['f'] = $slug;
        $_POST = [
            'csrf_token'   => 'test-csrf-token',
            'nom'          => 'Testeur',
            // Pas de rgpd_consent
        ];

        $this->captureOutput(
            fn() => (new FormController())->handle(),
            expectJsonCapture: true
        );

        $this->assertNotNull($GLOBALS['_test_captured_json']);
        $this->assertSame('Erreurs de validation', $GLOBALS['_test_captured_json']['error']);
        $this->assertArrayHasKey('rgpd_consent', $GLOBALS['_test_captured_json']['field_errors']);
        $this->assertStringContainsString('données', $GLOBALS['_test_captured_json']['field_errors']['rgpd_consent']);
        $this->assertStringContainsString('accepter', $GLOBALS['_test_captured_json']['field_errors']['rgpd_consent']);

        // Aucune submission créée
        $pdo = $this->db->getPdo();
        $count = (int) $pdo->query("SELECT COUNT(*) FROM submissions WHERE submitted_by = 'testeur@e2e.test'")->fetchColumn();
        $this->assertSame(0, $count, 'Aucune submission ne doit exister en cas d\'erreur de validation');
    }

    /**
     * POST avec un champ required manquant doit retourner
     * field_errors[field_name] = 'Ce champ est obligatoire'.
     */
    public function testHandlePostWithMissingRequiredFieldReturnsFieldError(): void
    {
        $slug = 'test-form-missing-' . uniqid();
        $this->createTestForm($slug, 'Test Missing', [
            ['name' => 'nom', 'label' => 'Nom', 'type' => FieldType::Text->value, 'required' => true],
            ['name' => 'prenom', 'label' => 'Prénom', 'type' => FieldType::Text->value, 'required' => true],
        ]);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET['f'] = $slug;
        $_POST = [
            'csrf_token'   => 'test-csrf-token',
            'nom'          => 'Testeur',
            // prenom manquant
            'rgpd_consent' => '1',
        ];

        $this->captureOutput(
            fn() => (new FormController())->handle(),
            expectJsonCapture: true
        );

        $this->assertNotNull($GLOBALS['_test_captured_json']);
        $this->assertSame('Erreurs de validation', $GLOBALS['_test_captured_json']['error']);
        $this->assertArrayHasKey('prenom', $GLOBALS['_test_captured_json']['field_errors']);
        $this->assertSame('Ce champ est obligatoire', $GLOBALS['_test_captured_json']['field_errors']['prenom']);
    }

    /**
     * B-F1 fix (audit 2026-07-26) : POST avec un email invalide sur un
     * champ field_type=email doit retourner field_errors[email] =
     * 'Adresse email invalide'. Avant le fix, 'toto' était accepté et
     * stocké en DB — dégradation silencieuse de la qualité des données.
     */
    public function testHandlePostWithInvalidEmailReturnsEmailFormatError(): void
    {
        $slug = 'test-form-bademail-' . uniqid();
        $this->createTestForm($slug, 'Test Bad Email', [
            ['name' => 'email_pro', 'label' => 'Email pro', 'type' => FieldType::Email->value, 'required' => true],
        ]);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET['f'] = $slug;
        $_POST = [
            'csrf_token'   => 'test-csrf-token',
            'email_pro'    => 'toto',  // pas un email valide
            'rgpd_consent' => '1',
        ];

        $this->captureOutput(
            fn() => (new FormController())->handle(),
            expectJsonCapture: true
        );

        $this->assertNotNull($GLOBALS['_test_captured_json']);
        $this->assertSame('Erreurs de validation', $GLOBALS['_test_captured_json']['error']);
        $this->assertArrayHasKey('email_pro', $GLOBALS['_test_captured_json']['field_errors']);
        $this->assertSame('Adresse email invalide', $GLOBALS['_test_captured_json']['field_errors']['email_pro']);
    }

    /**
     * GET ?screenshot=1 sur un formulaire pour lequel l'agent a déjà une
     * soumission en_cours doit afficher l'encadré warning "Vous avez déjà
     * une demande en cours" (avec un lien vers la submission existante).
     */
    public function testHandleGetWithExistingSubmissionDisplaysWarningBox(): void
    {
        $slug = 'test-form-existing-' . uniqid();
        $formId = $this->createTestForm($slug, 'Test Existing', [
            ['name' => 'nom', 'label' => 'Nom', 'type' => FieldType::Text->value, 'required' => true],
        ]);

        // Pré-créer une submission en_cours pour cet agent
        $existingId = $this->createTestSubmission($formId, 'testeur@e2e.test');

        $_GET['f'] = $slug;
        $_GET['screenshot'] = '1';

        $output = $this->captureOutput(fn() => (new FormController())->handle());

        $this->assertStringContainsString('Vous avez déjà une demande en cours', $output);
        // Le lien vers la submission existante contient l'ID (urlencode non-escaping sur &)
        $this->assertStringContainsString('submission_view&id=' . $existingId, $output);
        $this->assertStringContainsString('Voir la demande existante', $output);
    }

    /**
     * POST succès doit inclure mails_count dans le JSON — le controller
     * attend exactement count($GLOBALS['_test_mails']) mails au moment
     * du test_json_response. Vérifie que le compteur est cohérent.
     */
    public function testHandlePostSuccessJsonIncludesMailsCount(): void
    {
        $slug = 'test-form-mailscount-' . uniqid();
        $formId = $this->createTestForm($slug, 'Test Mails Count', [
            ['name' => 'nom', 'label' => 'Nom', 'type' => FieldType::Text->value, 'required' => true],
        ]);
        $this->createTestStep($formId, 'Validation', 1, ['validator@test.local']);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET['f'] = $slug;
        $_POST = [
            'csrf_token'   => 'test-csrf-token',
            'nom'          => 'Testeur',
            'rgpd_consent' => '1',
        ];

        $this->captureOutput(
            fn() => (new FormController())->handle(),
            expectJsonCapture: true
        );

        $this->assertNotNull($GLOBALS['_test_captured_json']);
        $this->assertTrue($GLOBALS['_test_captured_json']['success'] ?? false);
        // 1 mail de confirmation à l'agent (le mail au validateur est aussi envoyé par advanceWorkflow)
        $this->assertGreaterThanOrEqual(1, $GLOBALS['_test_captured_json']['mails_count'] ?? 0);
        $this->assertSame(count($GLOBALS['_test_mails']), $GLOBALS['_test_captured_json']['mails_count']);

        // Nettoyer
        if (isset($GLOBALS['_test_captured_json']['submission_id'])) {
            $this->createdSubmissionIds[] = $GLOBALS['_test_captured_json']['submission_id'];
        }
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Crée un formulaire actif + N champs filled_by='demandeur'.
     *
     * @param list<array{name: string, label: string, type: string, required: bool}> $fields
     */
    private function createTestForm(string $slug, string $label, array $fields = []): string
    {
        $pdo = $this->db->getPdo();
        $formId = \generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, '', 1, datetime('now'))")
            ->execute([$formId, $slug, $label]);
        $this->createdFormIds[] = $formId;

        $ordre = 0;
        foreach ($fields as $f) {
            $fieldId = \generate_uuid();
            $pdo->prepare(
                'INSERT INTO form_fields (id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group, filled_by, validator_step, visibility) '
                . 'VALUES (?, ?, ?, ?, ?, NULL, "", ?, ?, "Général", ?, "", "all")'
            )->execute([
                $fieldId, $formId, $f['label'], $f['type'], $f['name'],
                $f['required'] ? 1 : 0, $ordre++,
                FilledBy::Demandeur->value,
            ]);
        }

        return $formId;
    }

    /**
     * Crée une étape de validation + ses recipients (pour que advanceWorkflow génère des tokens).
     *
     * @param list<string> $recipientEmails
     */
    private function createTestStep(string $formId, string $label, int $ordre, array $recipientEmails): void
    {
        $pdo = $this->db->getPdo();
        $stepId = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, ?, ?, 1, '')")
            ->execute([$stepId, $formId, $label, $ordre]);
        foreach ($recipientEmails as $email) {
            $srId = \generate_uuid();
            $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)")
                ->execute([$srId, $stepId, $email]);
        }
    }

    /**
     * Crée une submission en_cours pour le form + l'agent donnés.
     */
    private function createTestSubmission(string $formId, string $submittedBy, string $status = 'en_cours'): string
    {
        $pdo = $this->db->getPdo();
        $subId = \generate_uuid();
        $pdo->prepare(
            "INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status, rgpd_consent) "
            . "VALUES (?, ?, '{}', ?, datetime('now'), ?, 1)"
        )->execute([$subId, $formId, $submittedBy, $status]);
        $this->createdSubmissionIds[] = $subId;
        return $subId;
    }

    /**
     * Exécute un callable en capturant stdout via ob_start. Si le callable
     * appelle test_json_response (override namespaced), TestJsonCapturedException
     * est levitée puis attrapée ici — le test peut continuer.
     *
     * @param callable(): void $callable
     */
    private function captureOutput(callable $callable, bool $expectJsonCapture = false): string
    {
        ob_start();
        try {
            $callable();
        } catch (TestJsonCapturedException $e) {
            // JSON capturé dans $GLOBALS['_test_captured_json'] — on continue
        } finally {
            $output = ob_get_clean();
        }
        return (string) $output;
    }
}
