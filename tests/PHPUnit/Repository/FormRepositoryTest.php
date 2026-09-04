<?php
declare(strict_types=1);

namespace App\Tests\Repository;

use PHPUnit\Framework\TestCase;
use App\Repository\FormRepository;
use App\Core\Database;

final class FormRepositoryTest extends TestCase
{
    private FormRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new FormRepository(\App\Core\App::getInstance()->get(Database::class));
    }

    public function testFindByIdReturnsNullForNonexistent(): void
    {
        $result = $this->repo->findById('nonexistent');
        self::assertNull($result);
    }

    public function testFindAllReturnsArray(): void
    {
        $result = $this->repo->findAll();
        self::assertIsArray($result);
    }

    public function testGetFieldsReturnsArray(): void
    {
        $result = $this->repo->getFields('nonexistent');
        self::assertIsArray($result);
        self::assertEmpty($result);
    }

    // ── findAll() with activeOnly ───────────────────────────────

    public function testFindAllActiveOnlyReturnsArray(): void
    {
        $result = $this->repo->findAll(true);
        self::assertIsArray($result);
    }

    public function testFindAllActiveOnlyReturnsOnlyActiveForms(): void
    {
        $result = $this->repo->findAll(true);
        foreach ($result as $form) {
            self::assertSame(1, (int)$form['actif']);
        }
    }

    // ── getSteps() ──────────────────────────────────────────────

    public function testGetStepsReturnsArray(): void
    {
        $result = $this->repo->getSteps('nonexistent');
        self::assertIsArray($result);
        self::assertEmpty($result);
    }

    // ── getStepsWithRecipientObjects() ──────────────────────────
    // Régression admin_forms : le refactor 828a54f a vidé les sections
    // steps/champs/owners de la page Gestion des formulaires. Cette
    // méthode fournit les steps avec recipients en objets {id, email}
    // comme attendu par les templates admin (workflowDiagramSection,
    // formFieldsSection).

    private array $createdFormIds = [];
    private array $createdStepIds = [];
    private array $createdRecipientIds = [];

    protected function tearDown(): void
    {
        foreach ($this->createdRecipientIds as $id) {
            try { $this->repo->execute('DELETE FROM step_recipients WHERE id = ?', [$id]); } catch (\Throwable) {}
        }
        foreach ($this->createdStepIds as $id) {
            try { $this->repo->execute('DELETE FROM steps WHERE id = ?', [$id]); } catch (\Throwable) {}
        }
        foreach ($this->createdFormIds as $id) {
            try { $this->repo->execute('DELETE FROM forms WHERE id = ?', [$id]); } catch (\Throwable) {}
        }
    }

    private function createTestFormWithSteps(): array
    {
        $formId = \generate_uuid();
        $this->repo->execute(
            "INSERT INTO forms (id, slug, label, description, actif) VALUES (?, ?, ?, '', 1)",
            [$formId, 'test-recipient-objects-' . uniqid(), 'Test Recipient Objects']
        );
        $this->createdFormIds[] = $formId;

        $stepIds = [];
        foreach ([1, 2] as $ordre) {
            $stepId = \generate_uuid();
            $this->repo->execute(
                "INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, ?, ?, 1, '')",
                [$stepId, $formId, 'Step ' . $ordre, $ordre]
            );
            $this->createdStepIds[] = $stepId;
            $stepIds[$ordre] = $stepId;
        }
        // Deux destinataires sur l'étape 1, aucun sur l'étape 2.
        foreach (['validateur.a@test.com', 'validateur.b@test.com'] as $email) {
            $recipientId = \generate_uuid();
            $this->repo->execute(
                'INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)',
                [$recipientId, $stepIds[1], $email]
            );
            $this->createdRecipientIds[] = $recipientId;
        }
        return [$formId, $stepIds];
    }

    public function testGetStepsWithRecipientObjectsReturnsRecipientObjects(): void
    {
        [$formId, $stepIds] = $this->createTestFormWithSteps();

        $steps = $this->repo->getStepsWithRecipientObjects($formId);

        self::assertCount(2, $steps);
        self::assertSame(['id', 'form_id', 'label', 'ordre', 'actif', 'condition', 'recipients'], array_keys($steps[0]));
        self::assertSame($stepIds[1], $steps[0]['id']);
        self::assertCount(2, $steps[0]['recipients']);
        self::assertSame('validateur.a@test.com', $steps[0]['recipients'][0]['email']);
        self::assertSame('validateur.b@test.com', $steps[0]['recipients'][1]['email']);
        foreach ($steps[0]['recipients'] as $rcpt) {
            self::assertSame(['id', 'email'], array_keys($rcpt));
        }
        // Étape sans destinataire → liste vide, pas d'erreur.
        self::assertSame($stepIds[2], $steps[1]['id']);
        self::assertSame([], $steps[1]['recipients']);
    }

    public function testGetStepsWithRecipientObjectsReturnsEmptyForNonexistent(): void
    {
        $result = $this->repo->getStepsWithRecipientObjects('nonexistent');
        self::assertSame([], $result);
    }

    // ── duplicate() ─────────────────────────────────────────────

    /**
     * P0-5 (2026-09-03) : la copie d'un formulaire doit conserver la config
     * de relance (relance_delai_h / relance_max — sinon elle repartait sur
     * les DEFAULT 48/3) et la colonne `condition` des champs (sinon les
     * champs conditionnels de la copie devenaient inconditionnels).
     */
    public function testDuplicatePreservesRelanceConfigAndFieldConditions(): void
    {
        $srcId = \generate_uuid();
        $this->repo->execute(
            "INSERT INTO forms (id, slug, label, description, actif, deadline_field, relance_delai_h, relance_max) VALUES (?, ?, ?, '', 1, 'date_limite', 72, 5)",
            [$srcId, 'test-dup-src-' . uniqid(), 'Test Dup Source']
        );
        $this->createdFormIds[] = $srcId;
        $this->repo->execute(
            "INSERT INTO form_fields (id, form_id, label, field_type, field_name, ordre, condition) VALUES (?, ?, 'Décision', 'select', 'decision', 1, 'decision == Refusé')",
            [\generate_uuid(), $srcId]
        );

        $newId = \generate_uuid();
        $srcForm = $this->repo->findById($srcId);
        self::assertNotNull($srcForm);
        $this->repo->duplicate($srcId, $newId, 'Test Dup Copie', 'test-dup-copy-' . uniqid(), $srcForm);
        $this->createdFormIds[] = $newId;

        $copy = $this->repo->findById($newId);
        self::assertNotNull($copy);
        self::assertSame(72, (int) $copy['relance_delai_h'], 'P0-5 : relance_delai_h doit être conservé par la copie');
        self::assertSame(5, (int) $copy['relance_max'], 'P0-5 : relance_max doit être conservé par la copie');

        $fields = $this->repo->getFields($newId);
        self::assertCount(1, $fields);
        self::assertSame('decision == Refusé', $fields[0]['condition'], 'P0-5 : la condition du champ doit être conservée par la copie');
    }
}
