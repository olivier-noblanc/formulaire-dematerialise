<?php
declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AdminStepCrudHandler;
use App\Core\App;
use App\Core\Database;
use App\Repository\FormRepository;
use PHPUnit\Framework\TestCase;

/**
 * Garde anti-perte de condition d'étape (audit 2026-11-26).
 *
 * Avant : un opérateur absent/inconnu était remplacé par `''` dans
 * `handleUpdateStep()`, ce qui supprimait la condition enregistrée. Une étape
 * sans condition s'exécute inconditionnellement — la perte changeait donc le
 * circuit de validation en silence. Désormais l'enregistrement est refusé avec
 * un message explicite, et un opérateur valide est stocké tel quel (`eq` ne
 * doit pas devenir `equals`).
 */
final class AdminStepConditionGuardTest extends TestCase
{
    private Database $db;
    private string $formId = '';
    private string $stepId = '';

    protected function setUp(): void
    {
        $this->db = App::getInstance()->get(Database::class);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_TEST_MODE'] = '1';
        $_SERVER['HTTP_X_TEST_USER'] = 'testeur@e2e.test';
        $_POST = [];

        $repo = $this->formRepo();
        $this->formId = $repo->create([
            'label'       => 'Condition Guard',
            'slug'        => 'test-step-cond-' . bin2hex(random_bytes(6)),
            'description' => '',
        ]);
        $this->stepId = $repo->createStep([
            'form_id'   => $this->formId,
            'label'     => 'Validation',
            'ordre'     => 2,
            'condition' => '{"field":"montant","op":"eq","value":"1000"}',
        ]);
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->getPdo();
        try {
            $pdo->prepare('DELETE FROM step_recipients WHERE step_id = ?')->execute([$this->stepId]);
            $pdo->prepare('DELETE FROM steps WHERE form_id = ?')->execute([$this->formId]);
            $pdo->prepare('DELETE FROM audit_log WHERE target IN (?, ?)')->execute(['form:' . $this->formId, 'step:' . $this->stepId]);
            $pdo->prepare('DELETE FROM forms WHERE id = ?')->execute([$this->formId]);
        } catch (\Throwable) {
        }
        $_POST = [];
    }

    public function testMissingOperatorIsRefusedInsteadOfDroppingTheCondition(): void
    {
        $_POST = [
            'step_id'         => $this->stepId,
            'label'           => 'Validation',
            'ordre'           => '2',
            'actif'           => '1',
            'condition_field' => 'montant',
            'condition_op'    => '',
            'condition_value' => '1000',
        ];

        $result = AdminStepCrudHandler::handleUpdateStep($this->formId);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Condition d\'exécution incomplète', (string) $result['error']);
        self::assertSame(
            '{"field":"montant","op":"eq","value":"1000"}',
            $this->storedCondition(),
            'la condition enregistrée ne doit pas être supprimée.'
        );
    }

    public function testUnknownOperatorIsRefusedWithExplicitMessage(): void
    {
        $_POST = [
            'step_id'         => $this->stepId,
            'label'           => 'Validation',
            'ordre'           => '2',
            'actif'           => '1',
            'condition_field' => 'montant',
            'condition_op'    => 'plus_grand_que',
            'condition_value' => '1000',
        ];

        $result = AdminStepCrudHandler::handleUpdateStep($this->formId);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Opérateur de condition inconnu', (string) $result['error']);
        self::assertStringContainsString('eq', (string) $result['error'], 'le message doit lister les opérateurs acceptés.');
        self::assertSame('{"field":"montant","op":"eq","value":"1000"}', $this->storedCondition());
    }

    /**
     * `eq` est un opérateur canonique : il doit être enregistré tel quel, sans
     * être réécrit en `equals` (alias historique équivalent, mais réécriture
     * silencieuse du contenu métier).
     */
    public function testCanonicalOperatorIsStoredUnchanged(): void
    {
        $_POST = [
            'step_id'         => $this->stepId,
            'label'           => 'Validation',
            'ordre'           => '2',
            'actif'           => '1',
            'condition_field' => 'montant',
            'condition_op'    => 'eq',
            'condition_value' => '2000',
        ];

        $result = AdminStepCrudHandler::handleUpdateStep($this->formId);

        self::assertArrayHasKey('redirect', $result);
        self::assertSame('{"field":"montant","op":"eq","value":"2000"}', $this->storedCondition());
    }

    /**
     * Un champ hors liste des champs validateurs est conservé : le select du
     * template renvoie la valeur existante, le handler ne la filtre pas selon
     * la liste des validateurs.
     */
    public function testConditionOnOrphanFieldIsPersisted(): void
    {
        $_POST = [
            'step_id'         => $this->stepId,
            'label'           => 'Validation',
            'ordre'           => '2',
            'actif'           => '1',
            'condition_field' => 'champ_supprime',
            'condition_op'    => 'not_empty',
            'condition_value' => '',
        ];

        $result = AdminStepCrudHandler::handleUpdateStep($this->formId);

        self::assertArrayHasKey('redirect', $result);
        self::assertSame('{"field":"champ_supprime","op":"not_empty","value":""}', $this->storedCondition());
    }

    /**
     * Choisir explicitement « — Toujours exécuter — » reste la seule façon de
     * supprimer une condition.
     */
    public function testExplicitEmptyFieldRemovesCondition(): void
    {
        $_POST = [
            'step_id'         => $this->stepId,
            'label'           => 'Validation',
            'ordre'           => '2',
            'actif'           => '1',
            'condition_field' => '',
            'condition_op'    => 'eq',
            'condition_value' => '',
        ];

        $result = AdminStepCrudHandler::handleUpdateStep($this->formId);

        self::assertArrayHasKey('redirect', $result);
        self::assertSame('', $this->storedCondition());
    }

    // ── Helpers ───────────────────────────────────────────────

    private function formRepo(): FormRepository
    {
        return App::getInstance()->get(FormRepository::class);
    }

    private function storedCondition(): string
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT `condition` FROM steps WHERE id = ?');
        $stmt->execute([$this->stepId]);
        $condition = $stmt->fetchColumn();
        $stmt = null;

        return (string) $condition;
    }
}