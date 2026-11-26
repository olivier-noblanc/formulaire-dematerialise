<?php
declare(strict_types=1);

namespace App\Tests\Workflow;

use App\Workflow\ConditionEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Source unique des opérateurs de condition (audit UI admin_forms, 2026-11-26).
 *
 * Le template `adminForms_workflowDiagramSection.php` ne doit plus recopier une
 * liste d'opérateurs : il itère `ConditionEvaluator::opChoices()`. Ces tests
 * garantissent que la liste proposée à l'utilisateur reste alignée sur la liste
 * réellement évaluée (`VALID_OPS`), et qu'une condition existante n'est jamais
 * réécrite silencieusement (`eq` → `equals` ou l'inverse).
 */
final class ConditionEvaluatorOpChoicesTest extends TestCase
{
    public function testEveryLabelKeyIsAValidOp(): void
    {
        foreach (array_keys(ConditionEvaluator::OP_LABELS) as $op) {
            self::assertContains(
                $op,
                ConditionEvaluator::VALID_OPS,
                "L'opérateur '$op' a un libellé UI mais n'est pas dans VALID_OPS."
            );
        }
    }

    public function testEveryAliasAndTargetIsAValidOp(): void
    {
        foreach (ConditionEvaluator::OP_ALIASES as $alias => $canonical) {
            self::assertContains($alias, ConditionEvaluator::VALID_OPS, "L'alias '$alias' n'est pas dans VALID_OPS.");
            self::assertContains($canonical, ConditionEvaluator::VALID_OPS, "La cible '$canonical' de l'alias '$alias' n'est pas dans VALID_OPS.");
            self::assertArrayHasKey($canonical, ConditionEvaluator::OP_LABELS, "La cible '$canonical' de l'alias '$alias' n'a pas de libellé UI.");
        }
    }

    /**
     * Aucun opérateur accepté par l'évaluateur ne doit être inaffichable dans
     * l'UI : sinon il serait perdu au premier enregistrement de l'étape.
     */
    public function testEveryValidOpIsRepresentableInTheSelect(): void
    {
        $representable = array_merge(
            array_keys(ConditionEvaluator::OP_LABELS),
            array_keys(ConditionEvaluator::OP_ALIASES)
        );

        foreach (ConditionEvaluator::VALID_OPS as $op) {
            self::assertContains($op, $representable, "L'opérateur '$op' n'a aucune représentation UI.");
        }
    }

    public function testOpChoicesWithoutCurrentOpReturnsCanonicalLabels(): void
    {
        self::assertSame(ConditionEvaluator::OP_LABELS, ConditionEvaluator::opChoices());
        self::assertSame(ConditionEvaluator::OP_LABELS, ConditionEvaluator::opChoices(''));
    }

    public function testOpChoicesDoesNotDuplicateCanonicalOps(): void
    {
        $choices = ConditionEvaluator::opChoices('eq');

        self::assertArrayNotHasKey('equals', $choices, 'Un opérateur canonique ne doit pas faire apparaître son alias.');
        self::assertCount(count(ConditionEvaluator::OP_LABELS), $choices);
    }

    /**
     * Une condition enregistrée en `equals` (alias historique) doit rester
     * sélectionnable telle quelle : le navigateur ne doit pas retomber sur la
     * première option du select.
     */
    public function testOpChoicesPreservesHistoricalAlias(): void
    {
        $choices = ConditionEvaluator::opChoices('equals');

        self::assertArrayHasKey('equals', $choices);
        self::assertStringContainsString('Égal à', $choices['equals']);
        self::assertStringContainsString('alias', $choices['equals']);

        $choices = ConditionEvaluator::opChoices('not_equals');
        self::assertArrayHasKey('not_equals', $choices);
        self::assertStringContainsString('Différent de', $choices['not_equals']);
    }

    public function testOpChoicesIgnoresUnknownOp(): void
    {
        self::assertSame(ConditionEvaluator::OP_LABELS, ConditionEvaluator::opChoices('bogus'));
    }

    public function testCanonicalOp(): void
    {
        self::assertSame('eq', ConditionEvaluator::canonicalOp('equals'));
        self::assertSame('neq', ConditionEvaluator::canonicalOp('not_equals'));
        self::assertSame('eq', ConditionEvaluator::canonicalOp('eq'));
        self::assertSame('contains', ConditionEvaluator::canonicalOp('contains'));
        self::assertSame('bogus', ConditionEvaluator::canonicalOp('bogus'));
    }
}