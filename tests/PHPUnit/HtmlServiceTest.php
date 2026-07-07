<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Render\HtmlService;

final class HtmlServiceTest extends TestCase
{
    private HtmlService $service;

    protected function setUp(): void
    {
        $this->service = new HtmlService();
    }

    public function testEscapeHtmlSpecialChars(): void
    {
        $this->assertSame('&lt;script&gt;', $this->service->escape('<script>'));
        $this->assertSame('&amp;', $this->service->escape('&'));
        $this->assertSame('&quot;', $this->service->escape('"'));
        // PHP 8.5 may use &apos; instead of &#039;
        $escaped = $this->service->escape("'");
        $this->assertTrue($escaped === '&#039;' || $escaped === '&apos;');
    }

    public function testEscapeNullReturnsEmpty(): void
    {
        $this->assertSame('', $this->service->escape(null));
    }

    public function testEscapeUtf8(): void
    {
        $this->assertSame('café', $this->service->escape('café'));
    }

    public function testHIsAliasForEscape(): void
    {
        $input = '<b>test</b>';
        $this->assertSame($this->service->escape($input), $this->service->h($input));
    }

    public function testGetFileIconImage(): void
    {
        $this->assertSame('🖼️', $this->service->getFileIcon('image/png'));
        $this->assertSame('🖼️', $this->service->getFileIcon('image/jpeg'));
    }

    public function testGetFileIconPdf(): void
    {
        $this->assertSame('📄', $this->service->getFileIcon('application/pdf'));
    }

    public function testGetFileIconZip(): void
    {
        $this->assertSame('📦', $this->service->getFileIcon('application/zip'));
    }

    public function testGetFileIconWord(): void
    {
        $this->assertSame('📝', $this->service->getFileIcon('application/msword'));
        $this->assertSame('📝', $this->service->getFileIcon('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
    }

    public function testGetFileIconText(): void
    {
        $this->assertSame('📃', $this->service->getFileIcon('text/plain'));
        $this->assertSame('📃', $this->service->getFileIcon('text/csv'));
    }

    public function testGetFileIconDefault(): void
    {
        $this->assertSame('📎', $this->service->getFileIcon('application/octet-stream'));
    }

    public function testFormatFileSizeBytes(): void
    {
        $this->assertSame('0 o', $this->service->formatFileSize(0));
        $this->assertSame('500 o', $this->service->formatFileSize(500));
    }

    public function testFormatFileSizeKiloBytes(): void
    {
        $this->assertSame('1 Ko', $this->service->formatFileSize(1024));
        $this->assertSame('1.5 Ko', $this->service->formatFileSize(1536));
    }

    public function testFormatFileSizeMegaBytes(): void
    {
        $this->assertSame('1 Mo', $this->service->formatFileSize(1024 * 1024));
    }

    public function testFormatFileSizeGigaBytes(): void
    {
        $this->assertSame('1 Go', $this->service->formatFileSize(1024 * 1024 * 1024));
    }

    public function testTJargonWorkflow(): void
    {
        $this->assertSame('Parcours', $this->service->tJargon('Workflow'));
        $this->assertSame('parcours', $this->service->tJargon('workflow'));
    }

    public function testTJargonAcronyms(): void
    {
        $this->assertStringContainsString('Protection des données', $this->service->tJargon('RGPD'));
        $this->assertStringContainsString('Direction régionale', $this->service->tJargon('DREETS'));
        $this->assertStringContainsString('Jeton de sécurité', $this->service->tJargon('CSRF'));
    }

    public function testTJargonPreservesCircuitDemat(): void
    {
        $this->assertSame('CircuitDémat', $this->service->tJargon('CircuitDémat'));
    }

    public function testTJargonPreservesFonctionPublique(): void
    {
        $this->assertSame('Fonction publique', $this->service->tJargon('Fonction publique'));
    }

    public function testTJargonWithMultipleWords(): void
    {
        $result = $this->service->tJargon('Le workflow utilise le RGPD');
        $this->assertStringContainsString('parcours', $result);
        $this->assertStringContainsString('Protection des données', $result);
    }
}
