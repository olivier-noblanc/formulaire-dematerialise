<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Forms\FormJsonValidator;

/**
 * B8 — "fields" doit être une liste (array JSON), pas un objet.
 * R6 — validation symétrique des conditions : une condition fournie sous
 * forme de chaîne JSON doit être validée comme un objet (opérateur, champ).
 */
final class FormJsonValidatorTest extends TestCase
{
    public function testRejectsObjectForFields(): void
    {
        $result = FormJsonValidator::validate([
            'schema_version' => '1.0',
            'form' => ['label' => 'Test'],
            'fields' => ['a' => ['label' => 'Nom', 'field_type' => 'text']],
        ]);

        self::assertFalse($result['valid']);
        self::assertNotEmpty(array_filter(
            $result['errors'],
            static fn(string $e): bool => str_contains($e, '"fields" doit être un tableau')
        ));
    }

    public function testAcceptsListForFields(): void
    {
        $result = FormJsonValidator::validate([
            'schema_version' => '1.0',
            'form' => ['label' => 'Test'],
            'fields' => [['label' => 'Nom', 'field_type' => 'text', 'field_name' => 'nom']],
        ]);

        self::assertTrue($result['valid'], 'Une liste de champs valide doit être acceptée : ' . implode(' | ', $result['errors']));
    }

    /**
     * Payload minimal valide : 1 champ validateur (cible des conditions) et
     * 1 étape sans condition (remplie par chaque test R6).
     *
     * @return array<string, mixed>
     */
    private function validConditionPayload(): array
    {
        return [
            'schema_version' => '1.0',
            'form' => ['label' => 'Test'],
            'fields' => [
                ['label' => 'Type de demande', 'field_type' => 'text', 'field_name' => 'type_demande', 'filled_by' => 'validator'],
            ],
            'steps' => [
                [
                    'label' => 'Étape 1',
                    'ordre' => 1,
                    'actif' => 1,
                    'recipients' => ['manager@exemple.invalid'],
                ],
            ],
        ];
    }

    /**
     * D5 — un hint purement numérique reste une chaîne valide : il ne doit
     * plus bloquer la validation d'import (il était rejeté comme erreur).
     */
    public function testAcceptsNumericHint(): void
    {
        $payload = $this->validConditionPayload();
        $payload['fields'][0]['hint'] = '2';

        $result = FormJsonValidator::validate($payload);

        self::assertTrue(
            $result['valid'],
            'D5 : un hint numérique doit être accepté (préservé à l\'import) : ' . implode(' | ', $result['errors'])
        );
    }

    public function testRejectsStringConditionWithUnknownOp(): void
    {
        $payload = $this->validConditionPayload();
        // Condition sous forme de CHAÎNE JSON (et non d'objet) : contournait
        // auparavant la validation d'opérateur (R6, asymétrie).
        $payload['steps'][0]['condition'] = json_encode(['field' => 'type_demande', 'op' => 'bogus_op', 'value' => 'A']);

        $result = FormJsonValidator::validate($payload);

        self::assertFalse($result['valid'], 'Une condition chaîne JSON avec op inconnu doit être rejetée');
        self::assertNotEmpty(array_filter(
            $result['errors'],
            static fn(string $e): bool => str_contains($e, 'condition.op')
        ), 'L\'erreur doit porter sur condition.op : ' . implode(' | ', $result['errors']));
    }

    public function testRejectsArrayConditionWithUnknownOp(): void
    {
        $payload = $this->validConditionPayload();
        $payload['steps'][0]['condition'] = ['field' => 'type_demande', 'op' => 'bogus_op', 'value' => 'A'];

        $result = FormJsonValidator::validate($payload);

        self::assertFalse($result['valid'], 'Une condition objet avec op inconnu doit être rejetée');
        self::assertNotEmpty(array_filter(
            $result['errors'],
            static fn(string $e): bool => str_contains($e, 'condition.op')
        ));
    }

    public function testAcceptsStringConditionWithValidOp(): void
    {
        $payload = $this->validConditionPayload();
        $payload['steps'][0]['condition'] = json_encode(['field' => 'type_demande', 'op' => 'eq', 'value' => 'A']);

        $result = FormJsonValidator::validate($payload);

        self::assertTrue($result['valid'], 'Une condition chaîne JSON avec op valide doit rester acceptée : ' . implode(' | ', $result['errors']));
    }

    public function testRejectsStringConditionReferencingNonValidatorField(): void
    {
        $payload = $this->validConditionPayload();
        // Le champ existe mais est rempli par le demandeur : la validation du
        // champ doit s'appliquer aussi au format chaîne JSON.
        $payload['fields'][] = ['label' => 'Nom', 'field_type' => 'text', 'field_name' => 'nom', 'filled_by' => 'demandeur'];
        $payload['steps'][0]['condition'] = json_encode(['field' => 'nom', 'op' => 'eq', 'value' => 'A']);

        $result = FormJsonValidator::validate($payload);

        self::assertFalse($result['valid'], 'Une condition chaîne JSON ciblant un champ non-validateur doit être rejetée');
        self::assertNotEmpty(array_filter(
            $result['errors'],
            static fn(string $e): bool => str_contains($e, 'condition.field')
        ));
    }

    // ── Audit 2026-11-26 : whitelist stricte et contrôles croisés ──────

    /**
     * @param array<string, mixed> $payload
     */
    private function assertHasError(array $payload, string $needle): void
    {
        $result = FormJsonValidator::validate($payload);
        self::assertFalse($result['valid'], 'Le JSON devait être rejeté. Erreurs : ' . implode(' | ', $result['errors']));
        self::assertNotEmpty(
            array_filter($result['errors'], static fn(string $e): bool => str_contains($e, $needle)),
            'Aucune erreur ne contient "' . $needle . '" : ' . implode(' | ', $result['errors'])
        );
    }

    public function testRejectsUnknownTopLevelKey(): void
    {
        $payload = $this->validConditionPayload();
        $payload['submission'] = ['x' => 1];

        $this->assertHasError($payload, 'propriété inconnue "submission"');
    }

    public function testRejectsUnknownFormKey(): void
    {
        $payload = $this->validConditionPayload();
        $payload['form']['color'] = 'red';

        $this->assertHasError($payload, 'propriété inconnue "color"');
    }

    public function testRejectsUnknownFieldKey(): void
    {
        $payload = $this->validConditionPayload();
        $payload['fields'][0]['maxlength'] = 10;

        $this->assertHasError($payload, 'propriété inconnue "maxlength"');
    }

    public function testRejectsUnknownStepKey(): void
    {
        $payload = $this->validConditionPayload();
        $payload['steps'][0]['sla'] = 2;

        $this->assertHasError($payload, 'propriété inconnue "sla"');
    }

    public function testRejectsUnknownConditionKey(): void
    {
        $payload = $this->validConditionPayload();
        $payload['steps'][0]['condition'] = ['field' => 'type_demande', 'op' => 'eq', 'value' => 'A', 'bogus' => 1];

        $this->assertHasError($payload, 'propriété inconnue "bogus"');
    }

    public function testRejectsNonBooleanRequiredInsteadOfSilentCoercion(): void
    {
        $payload = $this->validConditionPayload();
        $payload['fields'][0]['required'] = 21;

        $this->assertHasError($payload, 'required doit être true/false, 0 ou 1');
    }

    public function testAcceptsExplicitRequiredFlags(): void
    {
        foreach ([true, false, 0, 1, '0', '1'] as $flag) {
            $payload = $this->validConditionPayload();
            $payload['fields'][0]['required'] = $flag;
            $result = FormJsonValidator::validate($payload);
            self::assertTrue($result['valid'], 'required = ' . json_encode($flag) . ' doit être accepté : ' . implode(' | ', $result['errors']));
        }
    }

    public function testAcceptsBooleanActifFlag(): void
    {
        $payload = $this->validConditionPayload();
        $payload['form']['actif'] = true;
        $payload['steps'][0]['actif'] = false;

        $result = FormJsonValidator::validate($payload);
        self::assertTrue($result['valid'], 'actif booléen doit être accepté : ' . implode(' | ', $result['errors']));
    }

    public function testRejectsStepsObject(): void
    {
        $payload = $this->validConditionPayload();
        $payload['steps'] = ['a' => $payload['steps'][0]];

        $this->assertHasError($payload, '"steps" doit être un tableau');
    }

    public function testRejectsRecipientsObject(): void
    {
        $payload = $this->validConditionPayload();
        $payload['steps'][0]['recipients'] = ['first' => 'manager@exemple.invalid'];

        $this->assertHasError($payload, 'recipients est requis et doit être un tableau');
    }

    public function testRejectsNegativeFieldOrdreBeforeDatabase(): void
    {
        $payload = $this->validConditionPayload();
        $payload['fields'][0]['ordre'] = -1;

        $this->assertHasError($payload, 'négatif');
    }

    public function testRejectsNonIntegerFieldOrdre(): void
    {
        $payload = $this->validConditionPayload();
        $payload['fields'][0]['ordre'] = '1.5';

        $this->assertHasError($payload, 'ordre doit être un entier');
    }

    public function testRejectsStepOrdreZero(): void
    {
        $payload = $this->validConditionPayload();
        $payload['steps'][0]['ordre'] = 0;

        $this->assertHasError($payload, 'steps.ordre doit être >= 1');
    }

    public function testRejectsUnknownValidatorStep(): void
    {
        $payload = $this->validConditionPayload();
        $payload['fields'][0]['validator_step'] = 'Validation inconnue';

        $this->assertHasError($payload, 'ne correspond à aucune étape');
    }

    public function testAcceptsExistingValidatorStepLabel(): void
    {
        $payload = $this->validConditionPayload();
        $payload['fields'][0]['validator_step'] = 'Étape 1';

        $result = FormJsonValidator::validate($payload);
        self::assertTrue($result['valid'], 'validator_step = label existant doit être accepté : ' . implode(' | ', $result['errors']));
    }

    public function testRejectsValidatorStepOnDemandeurField(): void
    {
        $payload = $this->validConditionPayload();
        $payload['fields'][0]['filled_by'] = 'demandeur';
        $payload['fields'][0]['validator_step'] = 'Étape 1';

        $this->assertHasError($payload, 'filled_by = "demandeur" mais validator_step');
    }

    public function testRejectsUnknownDeadlineField(): void
    {
        $payload = $this->validConditionPayload();
        $payload['form']['deadline_field'] = 'date_absente';

        $this->assertHasError($payload, 'deadline_field = "date_absente"');
    }

    public function testAcceptsExistingDeadlineField(): void
    {
        $payload = $this->validConditionPayload();
        $payload['form']['deadline_field'] = 'type_demande';

        $result = FormJsonValidator::validate($payload);
        self::assertTrue($result['valid'], 'deadline_field existant doit être accepté : ' . implode(' | ', $result['errors']));
    }

    public function testRejectsDuplicateExplicitFieldName(): void
    {
        $payload = $this->validConditionPayload();
        $payload['fields'][] = ['label' => 'Doublon', 'field_type' => 'text', 'field_name' => 'type_demande'];

        $this->assertHasError($payload, 'est en doublon');
    }

    public function testRejectsDuplicateGeneratedFieldName(): void
    {
        $payload = $this->validConditionPayload();
        // Deux champs sans field_name et de même libellé génèrent le même nom.
        $payload['fields'][] = ['label' => 'Adresse postale', 'field_type' => 'text'];
        $payload['fields'][] = ['label' => 'Adresse postale', 'field_type' => 'text'];

        $this->assertHasError($payload, 'est en doublon');
    }

    public function testRejectsTooManyFields(): void
    {
        $fields = [];
        for ($i = 0; $i < FormJsonValidator::MAX_FIELDS + 1; $i++) {
            $fields[] = ['label' => 'Champ ' . $i, 'field_type' => 'text', 'field_name' => 'champ_' . $i];
        }
        $payload = $this->validConditionPayload();
        $payload['fields'] = $fields;

        $this->assertHasError($payload, 'Maximum : ' . FormJsonValidator::MAX_FIELDS);
    }

    public function testRejectsInvalidConditionJsonString(): void
    {
        $payload = $this->validConditionPayload();
        $payload['steps'][0]['condition'] = '{"field": "type_demande",';

        $this->assertHasError($payload, "n'est pas un JSON valide");
    }

    public function testAcceptsExportedRoundTripShape(): void
    {
        // Forme exacte produite par handleExportForm (exported_at + slug inclus).
        $payload = $this->validConditionPayload();
        $payload['exported_at'] = '2026-11-26T10:00:00+01:00';
        $payload['form']['slug'] = 'test';
        $payload['form']['deadline_field'] = '';
        $payload['form']['relance_delai_h'] = 48;
        $payload['form']['relance_max'] = 3;
        $payload['fields'][0]['options'] = null;
        $payload['fields'][0]['required'] = 0;
        $payload['fields'][0]['ordre'] = 1;
        $payload['fields'][0]['card_group'] = 'Général';
        $payload['fields'][0]['hint'] = '';
        $payload['fields'][0]['visibility'] = 'all';
        $payload['steps'][0]['condition'] = null;

        $result = FormJsonValidator::validate($payload);
        self::assertTrue($result['valid'], 'La forme exportée doit rester importable : ' . implode(' | ', $result['errors']));
    }
}
