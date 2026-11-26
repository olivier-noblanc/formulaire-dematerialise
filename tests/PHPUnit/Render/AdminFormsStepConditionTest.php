<?php
declare(strict_types=1);

namespace App\Tests\Render;

use App\Render\AdminFormsContext;
use App\Render\AdminFormsRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Rendu de la condition d'exécution d'une étape (admin_forms&form_id).
 *
 * Deux pertes de données étaient possibles à l'enregistrement (audit
 * contrôleurs, 2026-11-26) :
 *  1. le select d'opérateurs ne proposait que 5 valeurs sur les 8 de
 *     `ConditionEvaluator::VALID_OPS` : une condition `eq`/`neq`/`in` importée
 *     était réécrite en `equals` (ou vers la première option du select) ;
 *  2. le select de champ ne listait que les champs validateurs : une condition
 *     portant sur un autre champ était vidée, rendant l'étape inconditionnelle.
 *
 * Ces tests vérifient le HTML produit par le template (aucune écriture en base).
 */
final class AdminFormsStepConditionTest extends TestCase
{
    private const STEP_ID = '5f0c1f4e-9d1a-4c7b-8e2d-3a6b5c4d2e1f';

    /**
     * @param array<string, mixed>|null $condition Condition décodée (field/op/value)
     * @return array<string, mixed>
     */
    private function stepWithCondition(?array $condition): array
    {
        return [
            'id'         => self::STEP_ID,
            'label'      => 'Validation RH',
            'ordre'      => 2,
            'actif'      => true,
            'recipients' => [],
            'condition'  => $condition === null ? '' : (string) json_encode($condition),
        ];
    }

    /**
     * @param array<string, mixed> $step
     */
    private function renderEditStep(array $step): string
    {
        $ctx = new AdminFormsContext(
            form_id: 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
            form: null,
            forms: [],
            error_msg: '',
            success_msg: '',
            preserved_json: '',
            validation_html: '',
            owners: [],
            steps: [$step],
            steps_by_ordre: [2 => [$step]],
            edit_step_id: self::STEP_ID,
            form_fields: [],
            edit_field_id: '',
            existing_groups: [],
        );

        return AdminFormsRenderer::getInstance()->renderWorkflowDiagramSection($ctx);
    }

    /**
     * Les 6 opérateurs canoniques doivent être proposés.
     */
    public function testAllCanonicalOperatorsAreOffered(): void
    {
        $html = $this->renderEditStep($this->stepWithCondition(null));

        foreach (['eq', 'neq', 'contains', 'in', 'not_empty', 'empty'] as $op) {
            self::assertStringContainsString(
                'value="' . $op . '"',
                $html,
                "L'opérateur '$op' doit être proposé dans le select."
            );
        }
    }

    /**
     * Condition importée avec l'alias `eq` : la valeur doit rester `eq`
     * (elle ne doit pas être réécrite en `equals`).
     */
    public function testExistingEqConditionStaysSelectedAsEq(): void
    {
        $html = $this->renderEditStep($this->stepWithCondition(['field' => 'montant', 'op' => 'eq', 'value' => '1000']));

        self::assertMatchesRegularExpression('/value="eq"\s+selected/', $html);
        self::assertStringNotContainsString('value="equals"', $html, 'Un opérateur canonique ne doit pas faire apparaître son alias.');
    }

    /**
     * Condition historique en `equals` : l'option doit rester présente et
     * sélectionnée, sinon le navigateur retomberait sur la première option.
     */
    public function testHistoricalEqualsConditionIsPreserved(): void
    {
        $html = $this->renderEditStep($this->stepWithCondition(['field' => 'montant', 'op' => 'equals', 'value' => '1000']));

        self::assertMatchesRegularExpression('/value="equals"\s+selected/', $html);
        self::assertStringContainsString('alias', $html);
    }

    /**
     * Le champ testé n'est pas un champ validateur du formulaire : il doit
     * apparaître comme option sélectionnée, jamais disparaître du select.
     */
    public function testConditionOnUnknownFieldIsPreservedWithWarning(): void
    {
        $html = $this->renderEditStep($this->stepWithCondition(['field' => 'montant', 'op' => 'eq', 'value' => '1000']));

        self::assertMatchesRegularExpression('/value="montant"\s+selected/', $html);
        self::assertStringContainsString('absent des champs validateurs', $html);
        self::assertStringContainsString('la condition est conservée', $html);
    }

    /**
     * Sans condition, les options de repli ne doivent pas apparaître
     * (aucune option fantôme dans le select).
     */
    public function testNoOrphanOptionWithoutExistingCondition(): void
    {
        $html = $this->renderEditStep($this->stepWithCondition(null));

        self::assertStringNotContainsString('absent des champs validateurs', $html);
        self::assertStringContainsString('— Toujours exécuter (pas de condition) —', $html);
    }

    /**
     * La condition n'est proposée qu'à partir de l'ordre 2 : à l'ordre 1 le
     * bloc est remplacé par le message d'information (comportement inchangé).
     */
    public function testFirstStepHasNoConditionBlock(): void
    {
        $step = $this->stepWithCondition(['field' => 'montant', 'op' => 'eq', 'value' => '1000']);
        $step['ordre'] = 1;
        $html = $this->renderEditStep($step);

        self::assertStringNotContainsString('name="condition_op"', $html);
        self::assertStringContainsString('disponible qu\'à partir de l\'ordre 2', $html);
    }
}