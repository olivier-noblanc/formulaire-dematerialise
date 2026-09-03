<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Controller\FormValidationHandler;

/**
 * Tests régression FormValidationHandler.
 *
 * Bugs Oracle (2026-09-01) :
 * - B-FIX1 : un champ requis rempli avec la valeur '0' était rejeté à tort
 *   (in_array($value, ['', '0'], true)) alors que '0' est une valeur légitime
 *   (option de select, quantité, etc.).
 * - B-FIX2 : les champs conditionnels masqués côté client (data-condition non
 *   satisfaite) étaient validés comme requis → soumission bloquée par des
 *   champs invisibles. filterConditionallyHidden() filtre ces champs avant
 *   validation en réutilisant ConditionEvaluator (source unique de vérité).
 */
final class FormValidationHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        $_POST = [];
    }

    /**
     * Fabrique une entrée au format attendu par getFormFields().
     *
     * @return array{id: string, form_id: string, label: string, field_type: string, field_name: string, options: string|null, hint: string, required: int, ordre: int, card_group: string, filled_by: string, validator_step: string, visibility: string, condition: string}
     */
    private function makeField(string $name, string $type = 'text', int $required = 1, string $condition = ''): array
    {
        return [
            'id' => 'ff-' . uniqid(),
            'form_id' => 'form-test',
            'label' => 'Champ ' . $name,
            'field_type' => $type,
            'field_name' => $name,
            'options' => null,
            'hint' => '',
            'required' => $required,
            'ordre' => 1,
            'card_group' => 'Général',
            'filled_by' => 'demandeur',
            'validator_step' => '',
            'visibility' => 'all',
            'condition' => $condition,
        ];
    }

    // ── B-FIX1 : valeur '0' sur champ requis ──────────────────

    public function testRequiredFieldWithValueZeroStringPasses(): void
    {
        $_POST['x'] = '0';
        $fields = [$this->makeField('x', 'text', 1)];
        self::assertSame([], FormValidationHandler::validateFields($fields));
    }

    public function testRequiredFieldWithEmptyValueStillFails(): void
    {
        $_POST['x'] = '';
        $fields = [$this->makeField('x', 'text', 1)];
        self::assertSame(['x' => 'Ce champ est obligatoire'], FormValidationHandler::validateFields($fields));
    }

    public function testRequiredFieldWithMissingPostKeyStillFails(): void
    {
        // checkbox requise décochée : clé absente du POST
        $fields = [$this->makeField('x', 'checkbox', 1)];
        self::assertSame(['x' => 'Ce champ est obligatoire'], FormValidationHandler::validateFields($fields));
    }

    public function testRequiredFieldWithWhitespaceOnlyStillFails(): void
    {
        $_POST['x'] = '   ';
        $fields = [$this->makeField('x', 'text', 1)];
        self::assertSame(['x' => 'Ce champ est obligatoire'], FormValidationHandler::validateFields($fields));
    }

    // ── B-FIX2 : champs conditionnels masqués ─────────────────

    public function testConditionallyHiddenRequiredFieldIsSkipped(): void
    {
        // condition : le champ "type" doit valoir "1" — absent du POST →
        // le champ est masqué côté client (JS), il ne doit pas être requis
        $hidden = $this->makeField('hidden_req', 'text', 1, '{"field":"type","op":"eq","value":"1"}');
        $visible = $this->makeField('nom', 'text', 1);
        $_POST['nom'] = 'Dupont';

        $visible_fields = FormValidationHandler::filterConditionallyHidden([$hidden, $visible], $_POST);
        self::assertSame([], FormValidationHandler::validateFields($visible_fields));
    }

    public function testConditionallyVisibleRequiredFieldIsStillValidated(): void
    {
        $field = $this->makeField('hidden_req', 'text', 1, '{"field":"type","op":"eq","value":"1"}');
        $_POST['type'] = '1';

        $visible_fields = FormValidationHandler::filterConditionallyHidden([$field], $_POST);
        self::assertSame(['hidden_req' => 'Ce champ est obligatoire'], FormValidationHandler::validateFields($visible_fields));
    }

    public function testFieldWithoutConditionIsAlwaysKept(): void
    {
        $field = $this->makeField('nom', 'text', 1);

        $kept = FormValidationHandler::filterConditionallyHidden([$field], $_POST);
        self::assertCount(1, $kept);
        self::assertSame('nom', $kept[0]['field_name']);
    }

    public function testFilterSupportsInOperatorWithArrayValue(): void
    {
        $field = $this->makeField('hidden_req', 'text', 1, '{"field":"type","op":"in","value":["A","B"]}');

        // condition satisfaite ('A' ∈ ["A","B"]) → champ visible → reste requis
        $_POST['type'] = 'A';
        $kept = FormValidationHandler::filterConditionallyHidden([$field], $_POST);
        self::assertCount(1, $kept);
        self::assertSame(['hidden_req' => 'Ce champ est obligatoire'], FormValidationHandler::validateFields($kept));

        // condition non satisfaite → champ masqué → filtré
        $_POST['type'] = 'Z';
        $kept = FormValidationHandler::filterConditionallyHidden([$field], $_POST);
        self::assertCount(0, $kept);
    }

    public function testFilterIsListPreserving(): void
    {
        $a = $this->makeField('a', 'text', 1);
        $b = $this->makeField('b', 'text', 1, '{"field":"type","op":"eq","value":"1"}');
        $_POST['type'] = '2'; // condition de b non satisfaite

        $kept = FormValidationHandler::filterConditionallyHidden([$a, $b], $_POST);
        // array_values reséquence : le résultat reste une liste contiguë
        self::assertSame([0], array_keys($kept));
        self::assertCount(1, $kept);
        self::assertSame('a', $kept[0]['field_name']);
    }
}