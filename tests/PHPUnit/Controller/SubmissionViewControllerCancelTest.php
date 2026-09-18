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
 * P0-B (audit 2026-09-18) — l'annulation depuis SubmissionViewController doit
 * passer par TokenService::cancel() et non plus par
 * SubmissionRepository::cancelById().
 *
 * Couverture exigée par l'audit :
 *  - les tokens actifs passent à invalidated_at (et pas done_at) ;
 *  - l'entrée JSON 'Annulation' est ajoutée à submissions.data.validations ;
 *  - l'audit 'submission_cancel' n'est écrit qu'en cas de succès réel ;
 *  - une soumission déjà clôturée ne produit AUCUN audit mensonger ;
 *  - un non-auteur est refusé (access_denied) sans modifier l'état ;
 *  - le message retourné est affiché une seule fois (flash PRG).
 *
 * @package App\Tests\Controller
 */
final class SubmissionViewControllerCancelTest extends TestCase
{
    private Database $db;

    /** @var list<string> */
    private array $createdIds = [];

    /** @var list<string> */
    private array $createdAuditTargets = [];

    /**
     * Utilisateur de test global restauré en tearDown : sans cela, le dernier
     * HTTP_X_TEST_USER posé ici (« agent-cancel@test.com ») fuit vers les tests
     * suivants (App::auth()->getUser() masque alors le domaine des emails
     * affichés — échecs de test selon l'ordre de découverte Windows).
     */
    private ?string $savedTestUser = null;

    protected function setUp(): void
    {
        $this->db = App::getInstance()->get(Database::class);
        $this->savedTestUser = $_SERVER['HTTP_X_TEST_USER'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];
        $_POST = [];
        $GLOBALS['_test_mails'] = [];
        $GLOBALS['_test_no_exit'] = true;
        $GLOBALS['_test_captured_json'] = null;
        unset($GLOBALS['_test_redirect']);
        unset($_SESSION['_flash_action']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_test_no_exit'], $GLOBALS['_test_redirect'], $GLOBALS['_test_captured_json'], $_SESSION['_flash_action']);

        if ($this->savedTestUser === null) {
            unset($_SERVER['HTTP_X_TEST_USER']);
        } else {
            $_SERVER['HTTP_X_TEST_USER'] = $this->savedTestUser;
        }

        $pdo = $this->db->getPdo();
        foreach ($this->createdIds as $id) {
            $pdo->prepare('DELETE FROM tokens WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM submissions WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM steps WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM forms WHERE id = ?')->execute([$id]);
        }
        foreach ($this->createdAuditTargets as $target) {
            $pdo->prepare('DELETE FROM audit_log WHERE target = ?')->execute([$target]);
        }
        $this->createdIds = [];
        $this->createdAuditTargets = [];
    }

    // ── Succès auteur ─────────────────────────────────────────────────────

    public function testCancelByAuthorInvalidatesTokensJournalsJsonAndAudits(): void
    {
        [$formId, $stepId, $subId, $tokenId] = $this->createSubmission('agent-cancel@test.com');

        $_SERVER['HTTP_X_TEST_USER'] = 'agent-cancel@test.com';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = ['id' => $subId];
        $_POST = ['csrf_token' => 'test', 'action' => 'cancel_submission'];

        $this->runController();

        // PRG : redirection vers la même page + message flash.
        self::assertStringContainsString('p=submission_view', (string) ($GLOBALS['_test_redirect'] ?? ''));
        self::assertArrayHasKey('_flash_action', $_SESSION);
        self::assertStringContainsString('annulée', (string) $_SESSION['_flash_action']);

        $pdo = $this->db->getPdo();

        // Statut clôturé en 'annule'.
        $stmt = $pdo->prepare('SELECT status, closed_at FROM submissions WHERE id = ?');
        $stmt->execute([$subId]);
        $subRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(SubmissionStatus::Annule->value, $subRow['status'] ?? null);
        self::assertNotNull($subRow['closed_at'] ?? null);

        // Token invalidé (invalidated_at), jamais marqué 'done_at' (faux validé).
        $stmt = $pdo->prepare('SELECT done_at, invalidated_at FROM tokens WHERE id = ?');
        $stmt->execute([$tokenId]);
        $tokRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertNotNull($tokRow['invalidated_at'] ?? null, 'Le token actif doit être invalidé');
        self::assertNull($tokRow['done_at'] ?? null, 'invalidated_at ne doit pas polluer done_at');

        // Entrée JSON 'Annulation' ajoutée à l'historique des validations.
        $data = $this->submissionData($subId);
        $validations = $data['validations'] ?? [];
        self::assertIsArray($validations);
        $found = false;
        foreach ($validations as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['step_label'] ?? '') === 'Annulation'
                && ($entry['action'] ?? '') === ValidationAction::Annule->value) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'L\'entrée JSON "Annulation" doit être journalisée');

        // Audit unique et véridique.
        self::assertSame(1, $this->auditCount('submission_cancel', 'submission:' . $subId));
    }

    // ── Déjà clôturée : aucun audit mensonger ─────────────────────────────

    public function testCancelAlreadyClosedDoesNotWriteMisleadingAuditOrChangeState(): void
    {
        $closedAt = gmdate('Y-m-d H:i:s');
        [$formId, $stepId, $subId, $tokenId] = $this->createSubmission(
            'agent-cancel@test.com',
            SubmissionStatus::Valide->value,
            $closedAt
        );

        $_SERVER['HTTP_X_TEST_USER'] = 'agent-cancel@test.com';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = ['id' => $subId];
        $_POST = ['csrf_token' => 'test', 'action' => 'cancel_submission'];

        $this->runController();

        // Le message explique le refus, il n'y a pas de faux « annulée ».
        self::assertArrayHasKey('_flash_action', $_SESSION);
        self::assertStringContainsString('en cours', (string) $_SESSION['_flash_action']);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT status, closed_at FROM submissions WHERE id = ?');
        $stmt->execute([$subId]);
        $subRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(SubmissionStatus::Valide->value, $subRow['status'] ?? null);
        self::assertSame($closedAt, $subRow['closed_at'] ?? null);

        // Aucun token touché, aucun audit 'submission_cancel' (le bug P0-B).
        $stmt = $pdo->prepare('SELECT invalidated_at FROM tokens WHERE id = ?');
        $stmt->execute([$tokenId]);
        self::assertNull($stmt->fetchColumn());

        self::assertSame(0, $this->auditCount('submission_cancel', 'submission:' . $subId));
    }

    // ── Non-auteur refusé ─────────────────────────────────────────────────

    public function testCancelByNonAuthorValidatorIsRefused(): void
    {
        // Le validateur a un token (il peut VOIR la soumission) mais n'en est pas l'auteur.
        [$formId, $stepId, $subId, $tokenId] = $this->createSubmission(
            'agent-cancel@test.com',
            SubmissionStatus::EnCours->value,
            null,
            'validator-cancel@test.com'
        );

        $_SERVER['HTTP_X_TEST_USER'] = 'validator-cancel@test.com';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = ['id' => $subId];
        $_POST = ['csrf_token' => 'test', 'action' => 'cancel_submission'];

        $this->runController();

        self::assertArrayHasKey('_flash_action', $_SESSION);
        self::assertStringContainsString('autorisé', (string) $_SESSION['_flash_action']);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT status, closed_at FROM submissions WHERE id = ?');
        $stmt->execute([$subId]);
        $subRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(SubmissionStatus::EnCours->value, $subRow['status'] ?? null);
        self::assertNull($subRow['closed_at'] ?? null);

        $stmt = $pdo->prepare('SELECT invalidated_at FROM tokens WHERE id = ?');
        $stmt->execute([$tokenId]);
        self::assertNull($stmt->fetchColumn());

        // Pas d'audit de succès ; une trace access_denied est posée.
        self::assertSame(0, $this->auditCount('submission_cancel', 'submission:' . $subId));
        self::assertSame(1, $this->auditCount('access_denied', 'submission:' . $subId));
    }

    // ── Affichage one-shot du message (PRG) ───────────────────────────────

    public function testFlashMessageIsDisplayedOnceOnGet(): void
    {
        [$formId, $stepId, $subId] = $this->createSubmission('agent-cancel@test.com');

        $_SERVER['HTTP_X_TEST_USER'] = 'agent-cancel@test.com';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['id' => $subId];
        $_POST = [];
        $_SESSION['_flash_action'] = 'Soumission annulée avec succès.';

        $output = $this->runController();

        self::assertNotNull($output);
        self::assertStringContainsString('Soumission annulée avec succès.', $output);
        // Le flash est consommé : pas de ré-affichage au prochain GET.
        self::assertArrayNotHasKey('_flash_action', $_SESSION);
    }

    // ─ Helpers ───────────────────────────────────────────────────────────

    /**
     * Exécute le contrôleur en capturant sa sortie HTML/echo.
     */
    private function runController(): ?string
    {
        ob_start();
        try {
            new \App\Controller\SubmissionViewController()->handle();
            return (string) ob_get_clean();
        } catch (\App\Tests\Controller\TestJsonCapturedException) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            return null;
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string} [formId, stepId, subId, tokenId]
     */
    private function createSubmission(
        string $submittedBy,
        string $status = 'en_cours',
        ?string $closedAt = null,
        string $validatorEmail = 'validator-cancel@test.com'
    ): array {
        $formId = \generate_uuid();
        $stepId = \generate_uuid();
        $subId = \generate_uuid();
        $tokenId = \generate_uuid();
        $token = \generate_token();

        $pdo = $this->db->getPdo();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at, deadline_field) VALUES (?, ?, 'Test Cancel', '', 1, datetime('now'), '')")
            ->execute([$formId, 'test-cancel-' . substr($formId, 0, 8)]);
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation', 1, 1, '')")
            ->execute([$stepId, $formId]);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at, closed_at, rgpd_consent) VALUES (?, ?, '{}', ?, ?, datetime('now'), ?, 1)")
            ->execute([$subId, $formId, $submittedBy, $status, $closedAt]);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 2592000);
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), ?)")
            ->execute([$tokenId, $subId, $stepId, $validatorEmail, $token, $expiresAt]);

        $this->createdIds[] = $tokenId;
        $this->createdIds[] = $subId;
        $this->createdIds[] = $stepId;
        $this->createdIds[] = $formId;
        $this->createdAuditTargets[] = 'submission:' . $subId;

        return [$formId, $stepId, $subId, $tokenId];
    }

    /** @return array<string, mixed> */
    private function submissionData(string $subId): array
    {
        $stmt = $this->db->getPdo()->prepare('SELECT data FROM submissions WHERE id = ?');
        $stmt->execute([$subId]);
        $decoded = json_decode((string) $stmt->fetchColumn(), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function auditCount(string $action, string $target): int
    {
        $stmt = $this->db->getPdo()->prepare('SELECT COUNT(*) FROM audit_log WHERE action = ? AND target = ?');
        $stmt->execute([$action, $target]);
        return (int) $stmt->fetchColumn();
    }
}