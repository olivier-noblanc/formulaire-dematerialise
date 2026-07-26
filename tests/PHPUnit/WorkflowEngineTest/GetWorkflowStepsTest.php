<?php
declare(strict_types=1);

namespace App\Tests\WorkflowEngineTest;

/**
 * @covers \App\Workflow\WorkflowEngine::getWorkflowSteps
 */
final class GetWorkflowStepsTest extends Base
{
    // ── getWorkflowSteps: basic ─────────────────────────────────

    public function testGetWorkflowStepsReturnsArray(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $pdo->query("SELECT id FROM forms LIMIT 1")->fetchColumn();

        if ($formId) {
            $steps = $this->workflow->getWorkflowSteps($formId);
            $this->assertIsArray($steps);
        }
    }

    public function testGetWorkflowStepsReturnsStepDetails(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);
        $this->assertArrayHasKey('step_id', $steps[0]);
        $this->assertArrayHasKey('step_label', $steps[0]);
        $this->assertArrayHasKey('ordre', $steps[0]);
        $this->assertArrayHasKey('actif', $steps[0]);
    }

    public function testGetWorkflowStepsReturnsEmptyForNonexistentForm(): void
    {
        $steps = $this->workflow->getWorkflowSteps('nonexistent-form-id');
        $this->assertIsArray($steps);
        $this->assertEmpty($steps);
    }

    public function testGetWorkflowStepsReturnsEmptyForEmptyFormId(): void
    {
        $steps = $this->workflow->getWorkflowSteps('');
        $this->assertIsArray($steps);
        $this->assertEmpty($steps);
    }

    public function testGetWorkflowStepsReturnsConditionField(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);
        $this->assertArrayHasKey('condition', $steps[0]);
    }

    public function testGetWorkflowStepsReturnsRecipientEmailsField(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);
        $this->assertArrayHasKey('recipient_emails', $steps[0]);
    }

    // ── getWorkflowSteps caching ─────────────────────────────────

    public function testGetWorkflowStepsReturnsConsistentResults(): void
    {
        [$formId] = $this->createTestForm();

        $first = $this->workflow->getWorkflowSteps($formId);
        $second = $this->workflow->getWorkflowSteps($formId);
        $this->assertSame($first, $second);
    }

    // ── getWorkflowSteps: ordering ──────────────────────────────

    public function testGetWorkflowStepsReturnsOrderedByOrdre(): void
    {
        [$formId, $step1Id] = $this->createTestForm();
        $pdo = $this->db->getPdo();
        $step2Id = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation 2', 2, 1, '')")
            ->execute([$step2Id, $formId]);
        $this->createdIds['steps'][] = $step2Id;

        $steps = $this->workflow->getWorkflowSteps($formId);
        $this->assertGreaterThanOrEqual(2, count($steps));

        $ordres = array_column($steps, 'ordre');
        $sortedOrdres = $ordres;
        sort($sortedOrdres);
        $this->assertSame($sortedOrdres, $ordres);
    }

    public function testGetWorkflowStepsOrderingByOrdreThenId(): void
    {
        [$formId, $step1Id] = $this->createTestForm();
        $pdo = $this->db->getPdo();
        $step2Id = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation 2', 2, 1, '')")
            ->execute([$step2Id, $formId]);
        $this->createdIds['steps'][] = $step2Id;

        $steps = $this->workflow->getWorkflowSteps($formId);

        for ($i = 0; $i < count($steps) - 1; $i++) {
            $current = (int) $steps[$i]['ordre'];
            $next = (int) $steps[$i + 1]['ordre'];
            $this->assertLessThanOrEqual($next, $current);
        }
    }

    // ── getWorkflowSteps: only returns active steps ──────────────

    public function testGetWorkflowStepsReturnsConditionFieldForActiveSteps(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);
        foreach ($steps as $step) {
            $this->assertArrayHasKey('condition', $step);
            $this->assertArrayHasKey('actif', $step);
            $this->assertSame(1, (int) $step['actif']);
        }
    }

    public function testGetWorkflowStepsOnlyReturnsActiveSteps(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);
        foreach ($steps as $step) {
            $this->assertSame(1, (int) $step['actif']);
        }
    }

    public function testGetWorkflowStepsExcludesInactiveSteps(): void
    {
        $pdo = $this->db->getPdo();

        $formId = bin2hex(random_bytes(8));
        $slug = 'test-inactive-step-' . uniqid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'Test', 'test', 1, datetime('now'))")
            ->execute([$formId, $slug]);

        $activeStepId = bin2hex(random_bytes(8));
        $inactiveStepId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'Active', 1, 1)")
            ->execute([$activeStepId, $formId]);
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'Inactive', 2, 0)")
            ->execute([$inactiveStepId, $formId]);

        $steps = $this->workflow->getWorkflowSteps((string) $formId);
        $stepIds = array_column($steps, 'step_id');

        $this->assertContains($activeStepId, $stepIds);
        $this->assertNotContains($inactiveStepId, $stepIds);

        $pdo->prepare("DELETE FROM steps WHERE form_id = ?")->execute([$formId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }

    // ── getWorkflowSteps: includes fields ────────────────────────

    public function testGetWorkflowStepsIncludesConditionField(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);

        foreach ($steps as $step) {
            $this->assertArrayHasKey('condition', $step);
        }
    }

    public function testGetWorkflowStepsIncludesRecipientEmails(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);
        foreach ($steps as $step) {
            $this->assertArrayHasKey('recipient_emails', $step);
        }
    }

    // ── getWorkflowSteps: field types ────────────────────────────

    public function testGetWorkflowStepsStepIdIsString(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);

        foreach ($steps as $step) {
            $this->assertIsString($step['step_id']);
            $this->assertNotEmpty($step['step_id']);
        }
    }

    public function testGetWorkflowStepsStepLabelIsString(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);

        foreach ($steps as $step) {
            $this->assertIsString($step['step_label']);
        }
    }

    public function testGetWorkflowStepsOrdreIsNumeric(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);

        foreach ($steps as $step) {
            $this->assertIsNumeric($step['ordre']);
        }
    }

    // ── getWorkflowSteps: regression: step_id/step_label keys ───

    public function testGetWorkflowStepsReturnsStepIdNotId(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);

        foreach ($steps as $step) {
            $this->assertArrayHasKey('step_id', $step, 'getWorkflowSteps must return step_id key (not id)');
            $this->assertIsString($step['step_id']);
            $this->assertNotEmpty($step['step_id']);
        }
    }

    public function testGetWorkflowStepsReturnsStepLabelNotLabel(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);

        foreach ($steps as $step) {
            $this->assertArrayHasKey('step_label', $step, 'getWorkflowSteps must return step_label key (not label)');
            $this->assertIsString($step['step_label']);
        }
    }

    public function testGetWorkflowStepsDoesNotReturnLegacyIdOrLabelKeys(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);

        foreach ($steps as $step) {
            $this->assertArrayNotHasKey('id', $step, 'getWorkflowSteps must NOT return legacy "id" key (use step_id)');
            $this->assertArrayNotHasKey('label', $step, 'getWorkflowSteps must NOT return legacy "label" key (use step_label)');
        }
    }

    // ── getWorkflowSteps: actif is 1 ─────────────────────────────

    public function testGetWorkflowStepsActifIsOne(): void
    {
        [$formId] = $this->createTestForm();

        $steps = $this->workflow->getWorkflowSteps($formId);

        foreach ($steps as $step) {
            $this->assertSame(1, (int) $step['actif']);
        }
    }
}
