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

    /**
     * Régression — bug confirmé par StructuralHtmlTest (règle S2, route
     * /index.php?p=form&f=onboarding) : quand une carte mélange un champ
     * classique et une checkbox, le wrapper des checkboxes produisait
     * DEUX attributs class (`<div class="checkboxes" class="u-mt-1">`),
     * un HTML invalide (attribut dupliqué, document mal formé).
     */
    public function testCheckboxWrapperHasSingleClassAttributeWhenMixedFields(): void
    {
        $grouped = [
            'Carte mixte' => [
                [
                    'field_name' => 'nom',
                    'label' => 'Nom',
                    'field_type' => 'text',
                    'required' => 0,
                    'hint' => '',
                    'options' => null,
                ],
                [
                    'field_name' => 'badge_acces',
                    'label' => 'Badge d\'accès',
                    'field_type' => 'checkbox',
                    'required' => 0,
                    'hint' => '',
                    'options' => null,
                ],
            ],
        ];
        $html = $this->renderer->formContent(
            ['label' => 'Formulaire test', 'description' => 'Test de régression'],
            'Agent Test',
            null,
            false,
            '',
            $grouped,
            [],
            [],
            [],
            '',
            '',
            'formulaire-test'
        );

        $wrapper = $this->extractCheckboxesWrapper($html);
        self::assertSame(
            1,
            substr_count($wrapper, 'class="'),
            'Un seul attribut class attendu sur le wrapper checkboxes, obtenu : ' . $wrapper
        );
        self::assertStringContainsString('checkboxes', $wrapper);
        self::assertStringContainsString(
            'u-mt-1',
            $wrapper,
            'Le wrapper doit conserver u-mt-1 (carte mixte : espacement au-dessus des checkboxes)'
        );
    }

    /**
     * Cas symétrique : carte ne contenant QUE des checkboxes — le wrapper
     * doit garder un seul attribut class="checkboxes", sans u-mt-1.
     */
    public function testCheckboxWrapperHasNoMarginClassWhenOnlyCheckboxes(): void
    {
        $grouped = [
            'Carte checkboxes' => [
                [
                    'field_name' => 'badge_acces',
                    'label' => 'Badge d\'accès',
                    'field_type' => 'checkbox',
                    'required' => 0,
                    'hint' => '',
                    'options' => null,
                ],
            ],
        ];
        $html = $this->renderer->formContent(
            ['label' => 'Formulaire test', 'description' => 'Test de régression'],
            'Agent Test',
            null,
            false,
            '',
            $grouped,
            [],
            [],
            [],
            '',
            '',
            'formulaire-test'
        );

        $wrapper = $this->extractCheckboxesWrapper($html);
        self::assertSame(
            1,
            substr_count($wrapper, 'class="'),
            'Un seul attribut class attendu sur le wrapper checkboxes, obtenu : ' . $wrapper
        );
        self::assertStringContainsString('checkboxes', $wrapper);
        self::assertStringNotContainsString('u-mt-1', $wrapper);
    }

    /**
     * Extrait la balise ouvrante <div ...> dont l'attribut class contient
     * la classe "checkboxes" (le wrapper produit par form_content.php).
     * Les champs individuels sont des <label class="checkbox-item"> et ne
     * correspondent pas — seul le wrapper porte "checkboxes".
     */
    private function extractCheckboxesWrapper(string $html): string
    {
        preg_match_all('/<div\b[^>]*>/', $html, $tags);
        $wrappers = array_values(array_filter(
            $tags[0],
            fn(string $t): bool => preg_match('/class="[^"]*\bcheckboxes\b/', $t) === 1
        ));
        self::assertCount(
            1,
            $wrappers,
            'Exactement un wrapper <div class="checkboxes"> attendu. Balises trouvées : ' . implode(' | ', $wrappers)
        );
        return $wrappers[0];
    }
}
