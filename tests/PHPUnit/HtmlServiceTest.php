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

    // ── displayUser() ───────────────────────────────────────────

    public function testDisplayUserEmptyReturnsEmpty(): void
    {
        $this->assertSame('', $this->service->displayUser(''));
    }

    public function testDisplayUserForceEmailReturnsEmail(): void
    {
        $result = $this->service->displayUser('test@example.com', null, true);
        $this->assertStringContainsString('test@example.com', $result);
    }

    public function testDisplayUserSameUserReturnsVous(): void
    {
        $result = $this->service->displayUser('user@example.com', 'user@example.com');
        $this->assertSame('<strong>Vous</strong>', $result);
    }

    public function testDisplayUserSameDomainMasksDomain(): void
    {
        $result = $this->service->displayUser('colleague@example.com', 'user@example.com');
        $this->assertStringContainsString('@', $result);
        $this->assertStringNotContainsString('example.com', $result);
    }

    public function testDisplayUserDifferentDomainReturnsFullEmail(): void
    {
        $result = $this->service->displayUser('user@other.com', 'user@example.com');
        $this->assertStringContainsString('user@other.com', $result);
    }

    public function testDisplayUserNullCurrentUserUsesApp(): void
    {
        $result = $this->service->displayUser('test@example.com');
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testDisplayUserSpecialCharsInEmail(): void
    {
        $result = $this->service->displayUser('user+tag@example.com', null, true);
        $this->assertStringContainsString('user', $result);
    }

    // ── displayUserShort() ──────────────────────────────────────

    public function testDisplayUserShortWithAtReturnsLocalPart(): void
    {
        $result = $this->service->displayUserShort('user@example.com');
        $this->assertSame('user', $result);
    }

    public function testDisplayUserShortWithoutAtReturnsEmail(): void
    {
        $result = $this->service->displayUserShort('simpleuser');
        $this->assertSame('simpleuser', $result);
    }

    public function testDisplayUserShortWithBackslashReturnsUserPart(): void
    {
        $result = $this->service->displayUserShort('DOMAIN\\username');
        $this->assertSame('username', $result);
    }

    public function testDisplayUserShortEmptyReturnsEmpty(): void
    {
        $result = $this->service->displayUserShort('');
        $this->assertSame('', $result);
    }

    public function testDisplayUserShortEscapesHtml(): void
    {
        $result = $this->service->displayUserShort('<script>alert(1)</script>@example.com');
        $this->assertStringNotContainsString('<script>', $result);
    }

    // ── renderPagination() ──────────────────────────────────────

    public function testRenderPaginationSinglePageReturnsEmpty(): void
    {
        $result = $this->service->renderPagination(1, 1, '/list');
        $this->assertSame('', $result);
    }

    public function testRenderPaginationFirstPageNoPrevLink(): void
    {
        $result = $this->service->renderPagination(1, 3, '/list');
        $this->assertStringNotContainsString('Précédent', $result);
        $this->assertStringContainsString('Suivant', $result);
    }

    public function testRenderPaginationLastPageNoNextLink(): void
    {
        $result = $this->service->renderPagination(3, 3, '/list');
        $this->assertStringContainsString('Précédent', $result);
        $this->assertStringNotContainsString('Suivant', $result);
    }

    public function testRenderPaginationMiddlePageHasBothLinks(): void
    {
        $result = $this->service->renderPagination(2, 3, '/list');
        $this->assertStringContainsString('Précédent', $result);
        $this->assertStringContainsString('Suivant', $result);
    }

    public function testRenderPaginationUsesAmpersandWhenQueryPresent(): void
    {
        $result = $this->service->renderPagination(2, 3, '/list?filter=active');
        $this->assertStringContainsString('&amp;page=', $result);
    }

    public function testRenderPaginationUsesQuestionMarkWhenNoQuery(): void
    {
        $result = $this->service->renderPagination(2, 3, '/list');
        $this->assertStringContainsString('page=', $result);
    }

    public function testRenderPaginationContainsPageInfo(): void
    {
        $result = $this->service->renderPagination(2, 5, '/list');
        $this->assertStringContainsString('Page 2', $result);
        $this->assertStringContainsString('5', $result);
    }

    // ── buildUrl() ──────────────────────────────────────────────

    public function testBuildUrlWithoutTokenReturnsUrl(): void
    {
        unset($_GET['persona_token']);
        $result = $this->service->buildUrl('/page');
        $this->assertSame('/page', $result);
    }

    public function testBuildUrlWithTokenAddsToken(): void
    {
        $_GET['persona_token'] = 'abc123';
        $result = $this->service->buildUrl('/page');
        $this->assertStringContainsString('persona_token=abc123', $result);
        $this->assertStringContainsString('/page?', $result);
        unset($_GET['persona_token']);
    }

    public function testBuildUrlWithTokenAndExistingQuery(): void
    {
        $_GET['persona_token'] = 'abc123';
        $result = $this->service->buildUrl('/page?foo=bar');
        $this->assertStringContainsString('persona_token=abc123', $result);
        $this->assertStringContainsString('foo=bar', $result);
        unset($_GET['persona_token']);
    }

    public function testBuildUrlWithTokenAndAnchor(): void
    {
        $_GET['persona_token'] = 'abc123';
        $result = $this->service->buildUrl('/page#section');
        $this->assertStringContainsString('persona_token=abc123', $result);
        $this->assertStringContainsString('#section', $result);
        unset($_GET['persona_token']);
    }

    public function testBuildUrlWithEmptyTokenReturnsUrl(): void
    {
        $_GET['persona_token'] = '';
        $result = $this->service->buildUrl('/page');
        $this->assertSame('/page', $result);
        unset($_GET['persona_token']);
    }

    // ── renderDonutChart() ──────────────────────────────────────

    public function testRenderDonutChartZeroTotalReturnsZeroChart(): void
    {
        $result = $this->service->renderDonutChart(0, 0, 0, 0);
        $this->assertStringContainsString('Total', $result);
        $this->assertStringContainsString('0', $result);
    }

    public function testRenderDonutChartWithValues(): void
    {
        $result = $this->service->renderDonutChart(100, 50, 30, 20);
        $this->assertStringContainsString('100', $result);
        $this->assertStringContainsString('50', $result);
        $this->assertStringContainsString('30', $result);
        $this->assertStringContainsString('20', $result);
    }

    public function testRenderDonutChartNegativeTotal(): void
    {
        $result = $this->service->renderDonutChart(-1, 0, 0, 0);
        $this->assertStringContainsString('Total', $result);
    }

    public function testRenderDonutChartAllValidated(): void
    {
        $result = $this->service->renderDonutChart(10, 10, 0, 0);
        $this->assertStringContainsString('10', $result);
        $this->assertStringContainsString('Validées', $result);
    }

    public function testRenderDonutChartAllRefused(): void
    {
        $result = $this->service->renderDonutChart(5, 0, 0, 5);
        $this->assertStringContainsString('5', $result);
        $this->assertStringContainsString('Refusées', $result);
    }

    public function testRenderDonutChartConicGradient(): void
    {
        $result = $this->service->renderDonutChart(10, 4, 3, 3);
        $this->assertStringContainsString('conic-gradient', $result);
    }

    public function testRenderDonutChartContainsLegend(): void
    {
        $result = $this->service->renderDonutChart(10, 4, 3, 3);
        $this->assertStringContainsString('Validées', $result);
        $this->assertStringContainsString('En cours', $result);
        $this->assertStringContainsString('Refusées', $result);
    }

    // ── tJargon() additional cases ──────────────────────────────

    public function testTJargonEPI(): void
    {
        $result = $this->service->tJargon('EPI');
        $this->assertStringContainsString('Équipement', $result);
    }

    public function testTJargonSI(): void
    {
        $result = $this->service->tJargon('SI');
        $this->assertStringContainsString('Système', $result);
    }

    public function testTJargonDSI(): void
    {
        $result = $this->service->tJargon('DSI');
        $this->assertStringContainsString('Direction', $result);
    }

    public function testTJargonRH(): void
    {
        $result = $this->service->tJargon('RH');
        $this->assertStringContainsString('Ressources', $result);
    }

    public function testTJargonLDAP(): void
    {
        $result = $this->service->tJargon('LDAP');
        $this->assertStringContainsString('Annuaire', $result);
    }

    public function testTJargonSMTP(): void
    {
        $result = $this->service->tJargon('SMTP');
        $this->assertStringContainsString('Serveur mail', $result);
    }

    public function testTJargonCSRF(): void
    {
        $result = $this->service->tJargon('CSRF');
        $this->assertStringContainsString('Jeton de sécurité', $result);
    }

    public function testTJargonCSV(): void
    {
        $result = $this->service->tJargon('CSV');
        $this->assertStringContainsString('Tableur', $result);
    }

    public function testTJargonJSON(): void
    {
        $result = $this->service->tJargon('JSON');
        $this->assertStringContainsString('Format de données', $result);
    }

    public function testTJargonHTTP(): void
    {
        $result = $this->service->tJargon('HTTP');
        $this->assertStringContainsString('Protocole web', $result);
    }

    public function testTJargonURL(): void
    {
        $result = $this->service->tJargon('URL');
        $this->assertStringContainsString('Adresse web', $result);
    }

    public function testTJargonAPI(): void
    {
        $result = $this->service->tJargon('API');
        $this->assertStringContainsString('Interface', $result);
    }

    public function testTJargonNoJargonReturnsSameText(): void
    {
        $text = 'This is plain text without jargon';
        $this->assertSame($text, $this->service->tJargon($text));
    }

    // ── getFileIcon() additional cases ──────────────────────────

    public function testGetFileIconPowerpoint(): void
    {
        // PowerPoint pptx matches the openxml pattern
        $this->assertSame('📝', $this->service->getFileIcon('application/vnd.openxmlformats-officedocument.presentationml.presentation'));
        // Old .ppt format falls through to default
        $this->assertSame('📎', $this->service->getFileIcon('application/vnd.ms-powerpoint'));
    }

    public function testGetFileIconExcel(): void
    {
        // Excel xlsx matches the openxml pattern
        $this->assertSame('📝', $this->service->getFileIcon('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        // Old .xls format falls through to default
        $this->assertSame('📎', $this->service->getFileIcon('application/vnd.ms-excel'));
    }

    public function testGetFileIconImageGif(): void
    {
        $this->assertSame('🖼️', $this->service->getFileIcon('image/gif'));
    }
}
