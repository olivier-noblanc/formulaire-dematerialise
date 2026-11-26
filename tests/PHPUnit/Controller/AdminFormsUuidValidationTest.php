<?php
declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AdminFieldCrudHandler;
use App\Controller\AdminRecipientHandler;
use App\Core\App;
use App\Core\Database;
use App\Repository\FormRepository;
use PHPUnit\Framework\TestCase;

/**
 * Validation des identifiants UUID en POST sur admin_forms (audit 2026-11-26).
 *
 * `AdminFieldCrudHandler` et `AdminRecipientHandler` lisaient leurs identifiants
 * sans validation, contrairement aux handlers d'étapes
 * (`AdminFormsHandlers::postStepId()`). Un identifiant non UUID ne peut désigner
 * aucune ligne : il doit produire une erreur utilisateur structurée, pas une
 * requête SQL. Couvre aussi le retrait d'un propriétaire avec les paramètres
 * réellement postés par la page de confirmation (`owner_id` + `form_id`).
 */
final class AdminFormsUuidValidationTest extends TestCase
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
        $_POST = [];
    }

    // ── Champs ────────────────────────────────────────────────

    public function testAddFieldRejectsNonUuidFormId(): void
    {
        $_POST = [
            'form_id'   => 'pas-un-uuid',
            'ff_label'  => 'Champ piégé',
            'ff_ordre'  => '1',
        ];

        $result = AdminFieldCrudHandler::handleAddField();

        self::assertSame(['error' => 'Identifiant de formulaire invalide.'], $result);
    }

    public function testUpdateFieldRejectsNonUuidFieldId(): void
    {
        $formId = $this->createForm();
        $fieldId = $this->createField($formId, 'Original');

        $_POST = [
            'form_id'  => $formId,
            'field_id' => 'pas-un-uuid',
            'ff_label' => 'Renommé par un identifiant invalide',
            'ff_ordre' => '1',
        ];

        $result = AdminFieldCrudHandler::handleUpdateField();

        self::assertSame(['error' => 'Identifiant de champ invalide.'], $result);
        self::assertSame('Original', $this->fieldLabel($fieldId), 'aucune écriture ne doit avoir lieu.');
    }

    public function testUpdateFieldRejectsNonUuidFormId(): void
    {
        $formId = $this->createForm();
        $fieldId = $this->createField($formId, 'Original');

        $_POST = [
            'form_id'  => 'pas-un-uuid',
            'field_id' => $fieldId,
            'ff_label' => 'Renommé',
            'ff_ordre' => '1',
        ];

        $result = AdminFieldCrudHandler::handleUpdateField();

        self::assertSame(['error' => 'Identifiant de formulaire invalide.'], $result);
        self::assertSame('Original', $this->fieldLabel($fieldId));
    }

    public function testDeleteFieldRejectsNonUuidFieldId(): void
    {
        $formId = $this->createForm();
        $fieldId = $this->createField($formId, 'À garder');

        $_POST = ['form_id' => $formId, 'field_id' => 'pas-un-uuid'];

        $result = AdminFieldCrudHandler::handleDeleteField();

        self::assertIsArray($result);
        self::assertSame(['error' => 'Identifiant de champ invalide.'], $result);
        self::assertSame('À garder', $this->fieldLabel($fieldId), 'le champ doit survivre au refus.');
    }

    // ── Destinataires / propriétaires ─────────────────────────

    public function testDeleteRecipientRejectsNonUuidRecipientId(): void
    {
        $_POST = ['recipient_id' => 'pas-un-uuid'];

        $result = AdminRecipientHandler::handleDeleteRecipient('a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d');

        self::assertIsArray($result);
        self::assertSame(['error' => 'Identifiant de destinataire invalide.'], $result);
    }

    public function testAddOwnerRejectsNonUuidFormId(): void
    {
        $_POST = ['form_id' => 'pas-un-uuid', 'owner_email' => 'owner@exemple.invalid'];

        $result = AdminRecipientHandler::handleAddOwner();

        self::assertSame(['error' => 'Identifiant de formulaire invalide.'], $result);
    }

    /**
     * Contrat avec `confirm_action` : le formulaire poste `owner_id` + `form_id`
     * (params de `ConfirmActionController`). Le handler doit valider l'UUID et
     * supprimer la ligne.
     */
    public function testDeleteOwnerWithConfirmActionParameters(): void
    {
        $formId = $this->createForm();
        $repo = $this->formRepo();
        $ownerId = $repo->createOwnerById($formId, 'owner@exemple.invalid');
        self::assertCount(1, $repo->findOwnersByFormId($formId));

        $_POST = ['owner_id' => $ownerId, 'form_id' => $formId];

        $result = AdminRecipientHandler::handleDeleteOwner();

        self::assertArrayHasKey('redirect', $result);
        self::assertSame([], array_filter(
            $repo->findOwnersByFormId($formId),
            static fn(array $o): bool => $o['id'] === $ownerId
        ), 'le propriétaire doit être retiré.');
    }

    public function testDeleteOwnerRejectsNonUuidOwnerId(): void
    {
        $formId = $this->createForm();

        $_POST = ['owner_id' => 'pas-un-uuid', 'form_id' => $formId];

        $result = AdminRecipientHandler::handleDeleteOwner();

        self::assertSame(['error' => 'Identifiant de propriétaire invalide.'], $result);
    }

    // ── Helpers ───────────────────────────────────────────────

    private function formRepo(): FormRepository
    {
        return App::getInstance()->get(FormRepository::class);
    }

    private function createForm(): string
    {
        $repo = $this->formRepo();
        $slug = 'test-uuid-guard-' . bin2hex(random_bytes(6));
        $formId = $repo->create(['label' => 'UUID Guard', 'slug' => $slug, 'description' => '']);
        $this->createdFormIds[] = $formId;

        return $formId;
    }

    private function createField(string $formId, string $label): string
    {
        return $this->formRepo()->createField([
            'form_id'    => $formId,
            'label'      => $label,
            'field_name' => 'champ_' . bin2hex(random_bytes(4)),
            'field_type' => 'text',
            'ordre'      => 1,
        ]);
    }

    private function fieldLabel(string $fieldId): string
    {
        $stmt = $this->db->getPdo()->prepare('SELECT label FROM form_fields WHERE id = ?');
        $stmt->execute([$fieldId]);
        $label = $stmt->fetchColumn();
        $stmt = null;

        return (string) $label;
    }

    private function cleanup(): void
    {
        $pdo = $this->db->getPdo();
        foreach ($this->createdFormIds as $formId) {
            try {
                $pdo->prepare('DELETE FROM form_fields WHERE form_id = ?')->execute([$formId]);
                $pdo->prepare('DELETE FROM form_owners WHERE form_id = ?')->execute([$formId]);
                $pdo->prepare('DELETE FROM step_recipients WHERE step_id IN (SELECT id FROM steps WHERE form_id = ?)')->execute([$formId]);
                $pdo->prepare('DELETE FROM steps WHERE form_id = ?')->execute([$formId]);
                $pdo->prepare('DELETE FROM audit_log WHERE target = ?')->execute(['form:' . $formId]);
                $pdo->prepare('DELETE FROM forms WHERE id = ?')->execute([$formId]);
            } catch (\Throwable) {
            }
        }
    }
}