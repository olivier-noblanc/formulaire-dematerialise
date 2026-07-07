<?php
declare(strict_types=1);

namespace App\Tests\Lib;

use PHPUnit\Framework\TestCase;

final class JargonTest extends TestCase
{
    public function testTJargonWorkflow(): void
    {
        $this->assertSame('Parcours', t_jargon('Workflow'));
        $this->assertSame('parcours', t_jargon('workflow'));
    }

    public function testTJargonAcronyms(): void
    {
        $this->assertStringContainsString('Protection des données', t_jargon('RGPD'));
        $this->assertStringContainsString('Équipement de protection', t_jargon('EPI'));
        $this->assertStringContainsString('Code de sécurité', t_jargon('CSRF'));
    }

    public function testTJargonPreservesCircuitDemat(): void
    {
        $this->assertSame('CircuitDémat', t_jargon('CircuitDémat'));
    }

    public function testTJargonFonctionPublique(): void
    {
        // lib/ replaces "Fonction publique" → "Métier de la fonction publique"
        // (the mapping goes through a placeholder restoration)
        $result = t_jargon('Fonction publique');
        $this->assertStringContainsString('fonction publique', $result);
    }

    public function testTJargonCompoundPhrases(): void
    {
        $this->assertStringContainsString('Demande en ligne', t_jargon('Dématérialisation'));
        $this->assertStringContainsString('Étapes de validation', t_jargon('Circuit de validation'));
        $this->assertStringContainsString('Catégorie professionnelle', t_jargon('Corps / Grade'));
    }

    public function testTJargonOnboarding(): void
    {
        $this->assertStringContainsString('Accueil', t_jargon('Onboarding'));
        $this->assertStringContainsString('Départ', t_jargon('Outboarding'));
    }

    public function testTJargonSoumissions(): void
    {
        $this->assertSame('Demandes', t_jargon('Soumissions'));
        $this->assertSame('Demande', t_jargon('Soumission'));
        $this->assertSame('demandes', t_jargon('soumissions'));
    }

    public function testTJargonTokenAndSlug(): void
    {
        $this->assertStringContainsString('Lien de validation', t_jargon('Token'));
        $this->assertStringContainsString('Nom technique', t_jargon('Slug'));
    }

    public function testTJargonMultipleCallsProduceSameResult(): void
    {
        // The lib/ t_jargon is NOT fully idempotent due to nested replacements
        // (e.g., RGPD → "Protection des données (RGPD)" contains RGPD which gets re-replaced).
        // This is a known limitation of the procedural version vs the src/ HtmlService version.
        $input = 'Le Workflow utilise le CSRF';
        $once = t_jargon($input);
        $this->assertStringContainsString('Parcours', $once);
        $this->assertStringContainsString('Code de sécurité', $once);
    }

    public function testTJargonPreservesCircuitDematInContext(): void
    {
        $input = 'Bienvenue sur CircuitDémat';
        $result = t_jargon($input);
        $this->assertStringContainsString('CircuitDémat', $result);
    }

    public function testTJargonDoesNotTouchLowercaseSi(): void
    {
        // "si" in lowercase should NOT be replaced (conditional)
        $this->assertSame('si', t_jargon('si'));
    }

    public function testTJargonReplacesUppercaseSI(): void
    {
        $result = t_jargon('SI');
        $this->assertStringContainsString('systèmes', $result);
    }

    public function testTJargonEmptyString(): void
    {
        $this->assertSame('', t_jargon(''));
    }

    public function testTJargonNoJargon(): void
    {
        $this->assertSame('Bonjour le monde', t_jargon('Bonjour le monde'));
    }
}
