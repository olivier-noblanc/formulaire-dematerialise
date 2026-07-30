<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Render\FormRenderer;

/**
 * Tests unitaires pour FormRenderer.
 *
 * Vérifie que les champs obligatoires (y compris checkboxes) reçoivent
 * l'attribut HTML5 required pour la validation côté client.
 */
final class FormRendererTest extends TestCase
{
    private FormRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new FormRenderer();
    }

    public function testRequiredCheckboxHasRequiredAttribute(): void
    {
        $field = [
            'field_name' => 'badge_acces',
            'label' => 'Badge d\'accès',
            'field_type' => 'checkbox',
            'required' => 1,
            'hint' => '',
            'options' => null,
        ];
        $html = $this->renderer->field($field, null, [], '', false);
        self::assertStringContainsString('required', $html);
        self::assertStringContainsString('aria-required="true"', $html);
        self::assertStringContainsString('class="req"', $html);
    }

    public function testOptionalCheckboxHasNoRequiredAttribute(): void
    {
        $field = [
            'field_name' => 'badge_acces',
            'label' => 'Badge d\'accès',
            'field_type' => 'checkbox',
            'required' => 0,
            'hint' => '',
            'options' => null,
        ];
        $html = $this->renderer->field($field, null, [], '', false);
        self::assertStringNotContainsString('required', $html);
        self::assertStringNotContainsString('aria-required', $html);
        self::assertStringNotContainsString('class="req"', $html);
    }

    public function testDisabledCheckboxHasNoRequiredAttribute(): void
    {
        $field = [
            'field_name' => 'badge_acces',
            'label' => 'Badge d\'accès',
            'field_type' => 'checkbox',
            'required' => 1,
            'hint' => '',
            'options' => null,
        ];
        $html = $this->renderer->field($field, null, [], '', true);
        self::assertStringNotContainsString('required', $html);
        self::assertStringContainsString('disabled', $html);
    }

    public function testRequiredSelectHasRequiredAttribute(): void
    {
        $field = [
            'field_name' => 'type_materiel',
            'label' => 'Type de matériel',
            'field_type' => 'select',
            'required' => 1,
            'hint' => '',
            'options' => '["PC portable","Écran"]',
        ];
        $html = $this->renderer->field($field, null, [], '', false);
        self::assertStringContainsString('required', $html);
        self::assertStringContainsString('aria-required="true"', $html);
    }

    public function testRequiredTextHasRequiredAttribute(): void
    {
        $field = [
            'field_name' => 'nom',
            'label' => 'Nom',
            'field_type' => 'text',
            'required' => 1,
            'hint' => '',
            'options' => null,
        ];
        $html = $this->renderer->field($field, null, [], '', false);
        self::assertStringContainsString('required', $html);
        self::assertStringContainsString('aria-required="true"', $html);
    }
}
