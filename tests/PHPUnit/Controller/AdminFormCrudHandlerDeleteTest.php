<?php
declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AdminFormCrudHandler;
use App\Core\App;
use App\Core\Database;
use App\Enum\SubmissionStatus;
use App\Repository\FormRepository;
use PHPUnit\Framework\TestCase;

/**
 * TOCTOU handleDeleteForm (audit 2026-09-17).
 *
 * Vérifie le refus (message + audit) quand des soumissions sont en cours, et
 * la suppression nominale (redirect + audit) sinon. La re-vérification
 * atomique sous verrou est couverte de façon déterministe par
 * App\Tests\Repository\FormRepositoryDeleteCascadeGuardTest ; ce fichier
 * couvre l'intégration handler (message utilisateur, audit, oracle).
 */
final class AdminFormCrudHandlerDeleteTest extends TestCase
{
    private Database $db;
    /** @var list<string> */
    private array $createdFormIds = [];

    protected function setUp(): void
    {
        $this->db = App::getInstance()->get(Database::class);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_TEST_MODE'] = '1';
        $_SERVER['HTTP_X_TEST_USER'] = 'testeur@e2e.test';
        $_POST = [];
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->createdFormIds = [];
    }

    public function testDeleteFormRefusesAndAuditsWhenActiveSubmissionExists(): void
    {
        $formId = $this->createOwnedForm();
        $this->createActiveSubmission($formId);

        $_POST['form_id'] = $formId;
        $result = AdminFormCrudHandler::handleDeleteForm();

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('soumission(s) en cours', (string) $result['error']);
        self::assertNotNull($this->formRepo()->findById($formId), 'le formulaire doit survivre au refus.');

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM submissions WHERE form_id = ?');
        $stmt->execute([$formId]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'la soumission en cours doit survivre.');

        self::assertSame(1, $this->countAudit('form_delete_refused', $formId), 'le refus doit être audité.');
        self::assertSame(0, $this->countAudit('form_delete', $formId), 'aucune suppression ne doit être auditée.');
    }

    public function testDeleteFormRemovesFormAndAuditsWhenNoActiveSubmission(): void
    {
        $formId = $this->createOwnedForm();

        $_POST['form_id'] = $formId;
        $result = AdminFormCrudHandler::handleDeleteForm();

        self::assertArrayHasKey('redirect', $result);
        self::assertNull($this->formRepo()->findById($formId), 'le formulaire doit être supprimé.');
        self::assertSame(1, $this->countAudit('form_delete', $formId), 'la suppression doit être auditée.');
        self::assertSame(0, $this->countAudit('form_delete_refused', $formId));

        // Le cascade a supprimé le formulaire : rien à nettoyer.
        $this->createdFormIds = array_values(array_diff($this->createdFormIds, [$formId]));
    }

    // ── Helpers ───────────────────────────────────────────────

    private function formRepo(): FormRepository
    {
        return App::getInstance()->get(FormRepository::class);
    }

    private function createOwnedForm(): string
    {
        $repo = $this->formRepo();
        $slug = 'test-del-guard-' . bin2hex(random_bytes(6));
        $formId = $repo->create(['label' => 'Delete Guard', 'slug' => $slug, 'description' => '']);
        $repo->createOwnerById($formId, 'testeur@e2e.test');
        $this->createdFormIds[] = $formId;

        return $formId;
    }

    private function createActiveSubmission(string $formId): void
    {
        $pdo = $this->db->getPdo();
        $pdo->prepare(
            "INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) "
            . "VALUES (?, ?, '{}', 'guard@test.invalid', datetime('now'), ?)"
        )->execute([\generate_uuid(), $formId, SubmissionStatus::EnCours->value]);
    }

    private function countAudit(string $action, string $formId): int
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE action = ? AND target = ?');
        $stmt->execute([$action, 'form:' . $formId]);

        return (int) $stmt->fetchColumn();
    }

    private function cleanup(): void
    {
        $pdo = $this->db->getPdo();
        foreach ($this->createdFormIds as $formId) {
            try {
                $pdo->prepare('DELETE FROM submission_validator_data WHERE submission_id IN (SELECT id FROM submissions WHERE form_id = ?)')->execute([$formId]);
                $pdo->prepare('DELETE FROM submissions WHERE form_id = ?')->execute([$formId]);
                $pdo->prepare('DELETE FROM form_owners WHERE form_id = ?')->execute([$formId]);
                $pdo->prepare('DELETE FROM forms WHERE id = ?')->execute([$formId]);
                $pdo->prepare("DELETE FROM audit_log WHERE target = ? AND action IN ('form_delete', 'form_delete_refused')")->execute(['form:' . $formId]);
            } catch (\Throwable) {
            }
        }
    }
}