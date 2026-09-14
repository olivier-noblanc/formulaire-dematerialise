<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Forms\FormJsonValidator;

/**
 * B8 — "fields" doit être une liste (array JSON), pas un objet.
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
}