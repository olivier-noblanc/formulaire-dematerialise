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
                    'actif' => true,
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
}