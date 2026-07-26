<?php
declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ValidateController;
use App\Core\App;
use App\Core\Database;
use App\Enum\FilledBy;
use App\Enum\FieldType;
use App\Enum\ValidationAction;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/_controller_overrides.php';

/**
 * Tests PHPUnit pour App\Controller\ValidateController.
 *
 * ValidateController est le point d'entrée de ?token=XXX (page de validation) :
 *   - GET ?token=… → affiche le statut (invalid / pending / already_done /
 *     expired / closed) et, si pending, le formulaire d'action (Valider/Refuser)
 *   - POST action=valider|refuser → exécute l'action via WorkflowEngine et
 *     renvoie du JSON en TEST_MODE (test_json_response capture puis exit)
 *
 * Couverture visée :
 *   - GET sans token → page « Lien invalide »
 *   - GET avec token non-hexa → page « Lien invalide »
 *   - GET avec token hexa mais inexistant en DB → page « Lien invalide »
 *   - GET avec token pending → page « Action requise » + boutons Valider/Refuser
 *   - GET avec token déjà traité → page « Déjà validé »
 *   - GET avec token expiré → page « Lien expiré »
 *   - POST action=valider sur token pending → JSON {result: {status: ok}}
 *     + token.done_at setté en DB
 *   - POST action=valider avec champ validateur required manquant →
 *     JSON {error: 'Champs obligatoires manquants : …'}
 *   - POST action=refuser sans motif → page HTML d'erreur (pas de test_json_response)
 *   - POST action=refuser avec motif → JSON {result: {status: ok}}
 *     + submission.status = 'refuse' + mail de refus envoyé à l'agent
 */
final class ValidateControllerTest extends TestCase
{
    private Database $db;

    /** @var list<string> UUIDs de tokens créés */
    private array $createdTokenIds = [];
    /** @var list<string> UUIDs de submissions créées */
    private array $createdSubmissionIds = [];
    /** @var list<string> UUIDs de steps créées */
    private array $createdStepIds = [];
    /** @var list<string> UUIDs de forms créés */
    private array $createdFormIds = [];

    protected function setUp(): void
    {
        $this->db = App::getInstance()->get(Database::class);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_X_TEST_MODE'] = '1';
        $_SERVER['HTTP_X_TEST_USER'] = 'validator@e2e.test';
        $_SERVER['AUTH_USER'] = 'DREETS\validator';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['HTTPS'] = '';
        $_SERVER['REQUEST_URI'] = '/';
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $GLOBALS['_test_mails'] = [];
        $GLOBALS['_test_captured_json'] = null;

        // Nettoyer les reliquats de tests précédents
        $pdo = $this->db->getPdo();
        $pdo->exec("DELETE FROM submission_validator_data WHERE submission_id IN (SELECT id FROM submissions WHERE submitted_by LIKE 'test-%')");
        $pdo->exec("DELETE FROM tokens WHERE email LIKE 'test-%' OR email = 'validator@e2e.test'");
        $pdo->exec("DELETE FROM submissions WHERE submitted_by LIKE 'test-%'");
        $pdo->exec("DELETE FROM step_recipients WHERE step_id IN (SELECT id FROM steps WHERE form_id IN (SELECT id FROM forms WHERE slug LIKE 'test-vc-%'))");
        $pdo->exec("DELETE FROM form_fields WHERE form_id IN (SELECT id FROM forms WHERE slug LIKE 'test-vc-%')");
        $pdo->exec("DELETE FROM steps WHERE form_id IN (SELECT id FROM forms WHERE slug LIKE 'test-vc-%')");
        $pdo->exec("DELETE FROM forms WHERE slug LIKE 'test-vc-%'");
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->getPdo();
        foreach ($this->createdTokenIds as $id) {
            try { $pdo->prepare("DELETE FROM tokens WHERE id = ?")->execute([$id]); } catch (\Throwable) {}
        }
        foreach ($this->createdSubmissionIds as $id) {
            try {
                $pdo->prepare("DELETE FROM submission_validator_data WHERE submission_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$id]);
            } catch (\Throwable) {}
        }
        foreach ($this->createdStepIds as $id) {
            try {
                $pdo->prepare("DELETE FROM step_recipients WHERE step_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$id]);
            } catch (\Throwable) {}
        }
        foreach ($this->createdFormIds as $id) {
            try {
                $pdo->prepare("DELETE FROM form_fields WHERE form_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$id]);
            } catch (\Throwable) {}
        }
        $this->createdTokenIds = [];
        $this->createdSubmissionIds = [];
        $this->createdStepIds = [];
        $this->createdFormIds = [];
        $GLOBALS['_test_mails'] = [];
        $GLOBALS['_test_captured_json'] = null;
    }

    // ── Tests GET (rendu HTML via ?screenshot=1 pour bypass JSON test_mode) ──

    public function testHandleGetWithoutTokenRendersInvalidPage(): void
    {
        // Pas de ?token= dans la query string
        $_GET['screenshot'] = '1';

        $output = $this->captureOutput(fn() => (new ValidateController())->handle());

        $this->assertStringContainsString('Lien invalide', $output);
        $this->assertStringContainsString('Ce lien est introuvable ou expiré', $output);
    }

    public function testHandleGetWithNonHexTokenRendersInvalidPage(): void
    {
        $_GET['token'] = 'toto';  // pas un hex 64
        $_GET['screenshot'] = '1';

        $output = $this->captureOutput(fn() => (new ValidateController())->handle());

        $this->assertStringContainsString('Lien invalide', $output);
        $this->assertStringContainsString('Ce lien est introuvable ou expiré', $output);
    }

    public function testHandleGetWithValidHexTokenNotInDbRendersInvalidPage(): void
    {
        // Token 64-char hex mais inexistant en DB
        $_GET['token'] = str_repeat('a', 64);
        $_GET['screenshot'] = '1';

        $output = $this->captureOutput(fn() => (new ValidateController())->handle());

        $this->assertStringContainsString('Lien invalide', $output);
        // L'audit log doit avoir enregistré la consultation
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE action = 'token_view' AND target LIKE 'token:aaaaaaaa%'");
        $stmt->execute();
        $this->assertGreaterThanOrEqual(1, (int) $stmt->fetchColumn(), 'L\'audit log doit enregistrer la consultation');
    }

    public function testHandleGetWithPendingTokenRendersActionRequiredPage(): void
    {
        $tokenVal = $this->createPendingToken();

        $_GET['token'] = $tokenVal;
        $_GET['screenshot'] = '1';

        $output = $this->captureOutput(fn() => (new ValidateController())->handle());

        $this->assertStringContainsString('Action requise', $output);
        $this->assertStringContainsString('valider', strtolower($output));
        $this->assertStringContainsString('refuser', strtolower($output));
        $this->assertStringContainsString('name="token" value="' . $tokenVal . '"', $output);
        // Boutons Valider / Refuser présents
        $this->assertStringContainsString('value="' . ValidationAction::Valider->value . '"', $output);
        $this->assertStringContainsString('value="' . ValidationAction::Refuser->value . '"', $output);
    }

    public function testHandleGetWithAlreadyDoneTokenRendersAlreadyValidatedPage(): void
    {
        $tokenVal = $this->createDoneToken();

        $_GET['token'] = $tokenVal;
        $_GET['screenshot'] = '1';

        $output = $this->captureOutput(fn() => (new ValidateController())->handle());

        $this->assertStringContainsString('Déjà validé', $output);
        $this->assertStringContainsString('Tâche validée le', $output);
    }

    public function testHandleGetWithExpiredTokenRendersExpiredPage(): void
    {
        $tokenVal = $this->createExpiredToken();

        $_GET['token'] = $tokenVal;
        $_GET['screenshot'] = '1';

        $output = $this->captureOutput(fn() => (new ValidateController())->handle());

        $this->assertStringContainsString('Lien expiré', $output);
        $this->assertStringContainsString('a expiré', $output);
    }

    // ── Tests POST (test_json_response capturé par l'override namespaced) ──

    /**
     * POST action=valider sur token pending doit :
     *   - appeler test_json_response avec result.status = 'ok'
     *   - setté token.done_at en DB
     */
    public function testHandlePostValiderWithPendingTokenReturnsOkJson(): void
    {
        $tokenVal = $this->createPendingToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => 'test-csrf',
            'token'      => $tokenVal,
            'action'     => ValidationAction::Valider->value,
        ];

        $this->captureOutput(
            fn() => (new ValidateController())->handle(),
            expectJsonCapture: true
        );

        $this->assertNotNull($GLOBALS['_test_captured_json'], 'test_json_response doit être appelée sur POST valider');
        $this->assertSame(ValidationAction::Valider->value, $GLOBALS['_test_captured_json']['action']);
        $this->assertIsArray($GLOBALS['_test_captured_json']['result']);
        $this->assertSame('ok', $GLOBALS['_test_captured_json']['result']['status']);

        // Side-effect DB : token.done_at est setté
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT done_at FROM tokens WHERE token = ?");
        $stmt->execute([$tokenVal]);
        $this->assertNotNull($stmt->fetchColumn(), 'token.done_at doit être setté après validation');
    }

    /**
     * POST action=valider sur un form qui a des champs validateur required
     * doit retourner test_json_response avec error='Champs obligatoires manquants : …'.
     */
    public function testHandlePostValiderWithMissingValidatorFieldReturnsErrorJson(): void
    {
        $tokenVal = $this->createPendingTokenWithValidatorFields();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token'      => 'test-csrf',
            'token'           => $tokenVal,
            'action'          => ValidationAction::Valider->value,
            // decision_validation manquant (champ validateur required)
        ];

        $this->captureOutput(
            fn() => (new ValidateController())->handle(),
            expectJsonCapture: true
        );

        $this->assertNotNull($GLOBALS['_test_captured_json']);
        $this->assertSame('Champs obligatoires manquants :', substr($GLOBALS['_test_captured_json']['error'] ?? '', 0, strlen('Champs obligatoires manquants :')));
        $this->assertIsArray($GLOBALS['_test_captured_json']['missing'] ?? null);
        $this->assertNotEmpty($GLOBALS['_test_captured_json']['missing'], 'La liste des champs manquants ne doit pas être vide');
        $this->assertStringContainsString('Décision', $GLOBALS['_test_captured_json']['missing'][0]);
    }

    /**
     * POST action=refuser SANS motif doit afficher la page HTML avec le
     * message d'erreur « Veuillez sélectionner un motif de refus. ».
     * Ce chemin n'appelle PAS test_json_response — le controller tombe
     * directement dans le rendu HTML avec $error setté.
     */
    public function testHandlePostRefuserWithoutMotifRendersErrorHtml(): void
    {
        $tokenVal = $this->createPendingToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => 'test-csrf',
            'token'      => $tokenVal,
            'action'     => ValidationAction::Refuser->value,
            // Pas de motif
        ];

        $output = $this->captureOutput(fn() => (new ValidateController())->handle());

        // Pas de test_json_response sur ce chemin
        $this->assertNull($GLOBALS['_test_captured_json']);
        // Le HTML contient le message d'erreur attendu
        $this->assertStringContainsString('Erreur', $output);
        $this->assertStringContainsString('Veuillez sélectionner un motif de refus', $output);
    }

    /**
     * POST action=refuser AVEC motif doit :
     *   - appeler test_json_response avec result.status = 'ok'
     *   - setté submission.status = 'refuse' + token.done_at
     *   - envoyer un mail de refus à l'agent
     */
    public function testHandlePostRefuserWithMotifReturnsOkJsonAndNotifiesAgent(): void
    {
        $tokenVal = $this->createPendingToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => 'test-csrf',
            'token'      => $tokenVal,
            'action'     => ValidationAction::Refuser->value,
            'motif'      => 'Hors périmètre',
            'comment'    => 'Demande hors périmètre service',
        ];

        $this->captureOutput(
            fn() => (new ValidateController())->handle(),
            expectJsonCapture: true
        );

        $this->assertNotNull($GLOBALS['_test_captured_json']);
        $this->assertSame(ValidationAction::Refuser->value, $GLOBALS['_test_captured_json']['action']);
        $this->assertSame('ok', $GLOBALS['_test_captured_json']['result']['status']);
        // Le comment est composé : motif + ' — ' + comment
        $this->assertStringContainsString('Hors périmètre', $GLOBALS['_test_captured_json']['comment']);
        $this->assertStringContainsString('Demande hors périmètre service', $GLOBALS['_test_captured_json']['comment']);

        // Side-effect DB : submission.status = 'refuse'
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT s.status FROM tokens t JOIN submissions s ON s.id = t.submission_id WHERE t.token = ?");
        $stmt->execute([$tokenVal]);
        $this->assertSame('refuse', $stmt->fetchColumn(), 'La submission doit être marquée refusée');

        // Side-effect : mail de refus envoyé à l'agent
        $refusMails = array_filter($GLOBALS['_test_mails'], fn($m) => str_contains($m['subject'], 'refusée'));
        $this->assertNotEmpty($refusMails, 'Un mail « Demande refusée » doit être envoyé à l\'agent');
        $mail = array_values($refusMails)[0];
        $this->assertSame('test-agent@e2e.test', $mail['to']);
        $this->assertStringContainsString('Hors périmètre', $mail['body']);
    }

    /**
     * POST avec action inconnue doit retourner test_json_response
     * error='Données invalides'.
     */
    public function testHandlePostWithInvalidActionReturnsErrorJson(): void
    {
        $tokenVal = $this->createPendingToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => 'test-csrf',
            'token'      => $tokenVal,
            'action'     => 'invalid_action_xyz',
        ];

        $this->captureOutput(
            fn() => (new ValidateController())->handle(),
            expectJsonCapture: true
        );

        $this->assertNotNull($GLOBALS['_test_captured_json']);
        $this->assertSame('Données invalides', $GLOBALS['_test_captured_json']['error']);
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Crée un formulaire + étape + submission + token pending (done_at NULL,
     * expires_at +7 days). Retourne la valeur du token (64 hex chars).
     */
    private function createPendingToken(): string
    {
        $pdo = $this->db->getPdo();
        $formId = \generate_uuid();
        $slug = 'test-vc-form-' . uniqid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'VC Test Form', '', 1, datetime('now'))")
            ->execute([$formId, $slug]);
        $this->createdFormIds[] = $formId;

        $stepId = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation', 1, 1, '')")
            ->execute([$stepId, $formId]);
        $this->createdStepIds[] = $stepId;

        $subId = \generate_uuid();
        $pdo->prepare(
            "INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status, rgpd_consent) "
            . "VALUES (?, ?, '{\"nom\":\"Test\"}', 'test-agent@e2e.test', datetime('now'), 'en_cours', 1)"
        )->execute([$subId, $formId]);
        $this->createdSubmissionIds[] = $subId;

        $tokenId = \generate_uuid();
        $tokenVal = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+7 days'));
        $pdo->prepare(
            "INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at, expires_at) "
            . "VALUES (?, ?, ?, 'validator@e2e.test', ?, datetime('now'), NULL, ?)"
        )->execute([$tokenId, $subId, $stepId, $tokenVal, $expiresAt]);
        $this->createdTokenIds[] = $tokenId;

        return $tokenVal;
    }

    /**
     * Comme createPendingToken, mais le form a en plus un champ validateur required
     * (filled_by='validator') — pour tester le POST valider avec champ manquant.
     */
    private function createPendingTokenWithValidatorFields(): string
    {
        $tokenVal = $this->createPendingToken();
        $formId = end($this->createdFormIds);

        // Ajouter un champ validateur required au formulaire
        $pdo = $this->db->getPdo();
        $fieldId = \generate_uuid();
        $pdo->prepare(
            'INSERT INTO form_fields (id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group, filled_by, validator_step, visibility) '
            . 'VALUES (?, ?, "Décision de validation", "select", "decision_validation", ?, "", 1, 100, "Validation", ?, ?, "all")'
        )->execute([
            $fieldId, $formId,
            json_encode(['Approuvé', 'Refusé']),
            FilledBy::Validator->value,
            '',  // validator_step vide = toutes les étapes
        ]);
        return $tokenVal;
    }

    /**
     * Crée un token déjà validé (done_at dans le passé).
     */
    private function createDoneToken(): string
    {
        $pdo = $this->db->getPdo();
        $formId = \generate_uuid();
        $slug = 'test-vc-done-' . uniqid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'VC Done Form', '', 1, datetime('now'))")
            ->execute([$formId, $slug]);
        $this->createdFormIds[] = $formId;

        $stepId = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation', 1, 1, '')")
            ->execute([$stepId, $formId]);
        $this->createdStepIds[] = $stepId;

        $subId = \generate_uuid();
        $pdo->prepare(
            "INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status, rgpd_consent) "
            . "VALUES (?, ?, '{\"nom\":\"Test\"}', 'test-agent@e2e.test', datetime('now'), 'en_cours', 1)"
        )->execute([$subId, $formId]);
        $this->createdSubmissionIds[] = $subId;

        $tokenId = \generate_uuid();
        $tokenVal = bin2hex(random_bytes(32));
        $doneAt = gmdate('Y-m-d H:i:s', strtotime('-1 hour'));
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+7 days'));
        $pdo->prepare(
            "INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at, expires_at) "
            . "VALUES (?, ?, ?, 'validator@e2e.test', ?, datetime('now'), ?, ?)"
        )->execute([$tokenId, $subId, $stepId, $tokenVal, $doneAt, $expiresAt]);
        $this->createdTokenIds[] = $tokenId;

        return $tokenVal;
    }

    /**
     * Crée un token expiré (expires_at dans le passé).
     */
    private function createExpiredToken(): string
    {
        $pdo = $this->db->getPdo();
        $formId = \generate_uuid();
        $slug = 'test-vc-exp-' . uniqid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'VC Expired Form', '', 1, datetime('now'))")
            ->execute([$formId, $slug]);
        $this->createdFormIds[] = $formId;

        $stepId = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation', 1, 1, '')")
            ->execute([$stepId, $formId]);
        $this->createdStepIds[] = $stepId;

        $subId = \generate_uuid();
        $pdo->prepare(
            "INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status, rgpd_consent) "
            . "VALUES (?, ?, '{\"nom\":\"Test\"}', 'test-agent@e2e.test', datetime('now'), 'en_cours', 1)"
        )->execute([$subId, $formId]);
        $this->createdSubmissionIds[] = $subId;

        $tokenId = \generate_uuid();
        $tokenVal = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime('-1 day'));
        $pdo->prepare(
            "INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at, expires_at) "
            . "VALUES (?, ?, ?, 'validator@e2e.test', ?, datetime('now'), NULL, ?)"
        )->execute([$tokenId, $subId, $stepId, $tokenVal, $expiresAt]);
        $this->createdTokenIds[] = $tokenId;

        return $tokenVal;
    }

    /**
     * Exécute un callable en capturant stdout. Attrape TestJsonCapturedException
     * (levée par notre override de test_json_response) pour permettre au test
     * de continuer et d'inspecter $GLOBALS['_test_captured_json'].
     *
     * @param callable(): void $callable
     */
    private function captureOutput(callable $callable, bool $expectJsonCapture = false): string
    {
        ob_start();
        try {
            $callable();
        } catch (TestJsonCapturedException $e) {
            // JSON capturé — on continue
        } finally {
            $output = ob_get_clean();
        }
        return (string) $output;
    }
}
