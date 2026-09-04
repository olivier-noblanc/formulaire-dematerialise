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
        self::assertSame('&lt;script&gt;', $this->service->escape('<script>'));
        self::assertSame('&amp;', $this->service->escape('&'));
        self::assertSame('&quot;', $this->service->escape('"'));
        // PHP 8.5 may use &apos; instead of &#039;
        $escaped = $this->service->escape("'");
        self::assertTrue($escaped === '&#039;' || $escaped === '&apos;');
    }

    public function testEscapeNullReturnsEmpty(): void
    {
        self::assertSame('', $this->service->escape(null));
    }

    public function testEscapeUtf8(): void
    {
        self::assertSame('café', $this->service->escape('café'));
    }

    public function testHIsAliasForEscape(): void
    {
        $input = '<b>test</b>';
        self::assertSame($this->service->escape($input), $this->service->h($input));
    }

    public function testGetFileIconImage(): void
    {
        self::assertSame('🖼️', $this->service->getFileIcon('image/png'));
        self::assertSame('🖼️', $this->service->getFileIcon('image/jpeg'));
    }

    public function testGetFileIconPdf(): void
    {
        self::assertSame('📄', $this->service->getFileIcon('application/pdf'));
    }

    public function testGetFileIconZip(): void
    {
        self::assertSame('📦', $this->service->getFileIcon('application/zip'));
    }

    public function testGetFileIconWord(): void
    {
        self::assertSame('📝', $this->service->getFileIcon('application/msword'));
        self::assertSame('📝', $this->service->getFileIcon('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
    }

    public function testGetFileIconText(): void
    {
        self::assertSame('📃', $this->service->getFileIcon('text/plain'));
        self::assertSame('📃', $this->service->getFileIcon('text/csv'));
    }

    public function testGetFileIconDefault(): void
    {
        self::assertSame('📎', $this->service->getFileIcon('application/octet-stream'));
    }

    public function testFormatFileSizeBytes(): void
    {
        self::assertSame('0 o', $this->service->formatFileSize(0));
        self::assertSame('500 o', $this->service->formatFileSize(500));
    }

    public function testFormatFileSizeKiloBytes(): void
    {
        self::assertSame('1 Ko', $this->service->formatFileSize(1024));
        self::assertSame('1.5 Ko', $this->service->formatFileSize(1536));
    }

    public function testFormatFileSizeMegaBytes(): void
    {
        self::assertSame('1 Mo', $this->service->formatFileSize(1024 * 1024));
    }

    public function testFormatFileSizeGigaBytes(): void
    {
        self::assertSame('1 Go', $this->service->formatFileSize(1024 * 1024 * 1024));
    }

    public function testTJargonWorkflow(): void
    {
        self::assertSame('Parcours', $this->service->tJargon('Workflow'));
        self::assertSame('parcours', $this->service->tJargon('workflow'));
    }

    public function testTJargonAcronyms(): void
    {
        // Unified with JargonService::translate() — CSRF now uses 'Jeton de sécurité (CSRF)'
        // (more informative, keeps the acronym suffix for technical reference)
        self::assertStringContainsString('Protection des données', $this->service->tJargon('RGPD'));
        self::assertStringContainsString('Direction régionale', $this->service->tJargon('DREETS'));
        self::assertStringContainsString('Jeton de sécurité', $this->service->tJargon('CSRF'));
    }

    public function testTJargonTokenTranslatesToLienDeValidation(): void
    {
        // B4 fix: previously HtmlService::tJargon('Token') returned 'Token' untranslated.
        // Now delegates to JargonService::translate() which maps Token → 'Lien de validation'.
        self::assertStringContainsString('Lien de validation', $this->service->tJargon('Token'));
        self::assertStringContainsString('liens de validation', $this->service->tJargon('tokens'));
    }

    public function testTJargonPreservesCircuitDemat(): void
    {
        self::assertSame('CircuitDémat', $this->service->tJargon('CircuitDémat'));
    }

    public function testTJargonPreservesFonctionPublique(): void
    {
        // After unification (B4): JargonService::translate() replaces
        // 'Fonction publique' → 'Métier de la fonction publique'. This is the
        // intended behavior — the term is considered jargon in the project domain.
        $result = $this->service->tJargon('Fonction publique');
        self::assertStringContainsString('fonction publique', $result);
    }

    public function testTJargonWithMultipleWords(): void
    {
        $result = $this->service->tJargon('Le workflow utilise le RGPD');
        self::assertStringContainsString('parcours', $result);
        self::assertStringContainsString('Protection des données', $result);
    }

    // ── displayUser() ───────────────────────────────────────────

    public function testDisplayUserEmptyReturnsEmpty(): void
    {
        self::assertSame('', $this->service->displayUser(''));
    }

    public function testDisplayUserForceEmailReturnsEmail(): void
    {
        $result = $this->service->displayUser('test@example.com', null, true);
        self::assertStringContainsString('test@example.com', $result);
    }

    public function testDisplayUserSameUserReturnsVous(): void
    {
        $result = $this->service->displayUser('user@example.com', 'user@example.com');
        self::assertSame('<strong>Vous</strong>', $result);
    }

    public function testDisplayUserSameDomainMasksDomain(): void
    {
        $result = $this->service->displayUser('colleague@example.com', 'user@example.com');
        self::assertStringContainsString('@', $result);
        self::assertStringNotContainsString('example.com', $result);
    }

    public function testDisplayUserDifferentDomainReturnsFullEmail(): void
    {
        $result = $this->service->displayUser('user@other.com', 'user@example.com');
        self::assertStringContainsString('user@other.com', $result);
    }

    public function testDisplayUserNullCurrentUserUsesApp(): void
    {
        $result = $this->service->displayUser('test@example.com');
        self::assertIsString($result);
        self::assertNotEmpty($result);
    }

    public function testDisplayUserSpecialCharsInEmail(): void
    {
        $result = $this->service->displayUser('user+tag@example.com', null, true);
        self::assertStringContainsString('user', $result);
    }

    // ── displayUserShort() ──────────────────────────────────────

    public function testDisplayUserShortWithAtReturnsLocalPart(): void
    {
        $result = $this->service->displayUserShort('user@example.com');
        self::assertSame('user', $result);
    }

    public function testDisplayUserShortWithoutAtReturnsEmail(): void
    {
        $result = $this->service->displayUserShort('simpleuser');
        self::assertSame('simpleuser', $result);
    }

    public function testDisplayUserShortWithBackslashReturnsUserPart(): void
    {
        $result = $this->service->displayUserShort('DOMAIN\\username');
        self::assertSame('username', $result);
    }

    public function testDisplayUserShortEmptyReturnsEmpty(): void
    {
        $result = $this->service->displayUserShort('');
        self::assertSame('', $result);
    }

    public function testDisplayUserShortEscapesHtml(): void
    {
        $result = $this->service->displayUserShort('<script>alert(1)</script>@example.com');
        self::assertStringNotContainsString('<script>', $result);
    }

    // ── renderPagination() ──────────────────────────────────────

    public function testRenderPaginationSinglePageReturnsEmpty(): void
    {
        $result = $this->service->renderPagination(1, 1, '/list');
        self::assertSame('', $result);
    }

    public function testRenderPaginationFirstPageNoPrevLink(): void
    {
        $result = $this->service->renderPagination(1, 3, '/list');
        self::assertStringNotContainsString('Précédent', $result);
        self::assertStringContainsString('Suivant', $result);
    }

    public function testRenderPaginationLastPageNoNextLink(): void
    {
        $result = $this->service->renderPagination(3, 3, '/list');
        self::assertStringContainsString('Précédent', $result);
        self::assertStringNotContainsString('Suivant', $result);
    }

    public function testRenderPaginationMiddlePageHasBothLinks(): void
    {
        $result = $this->service->renderPagination(2, 3, '/list');
        self::assertStringContainsString('Précédent', $result);
        self::assertStringContainsString('Suivant', $result);
    }

    public function testRenderPaginationUsesAmpersandWhenQueryPresent(): void
    {
        $result = $this->service->renderPagination(2, 3, '/list?filter=active');
        self::assertStringContainsString('&amp;page=', $result);
    }

    public function testRenderPaginationUsesQuestionMarkWhenNoQuery(): void
    {
        $result = $this->service->renderPagination(2, 3, '/list');
        self::assertStringContainsString('page=', $result);
    }

    public function testRenderPaginationContainsPageInfo(): void
    {
        $result = $this->service->renderPagination(2, 5, '/list');
        self::assertStringContainsString('Page 2', $result);
        self::assertStringContainsString('5', $result);
    }

    // ── buildUrl() ──────────────────────────────────────────────

    public function testBuildUrlWithoutTokenReturnsUrl(): void
    {
        unset($_GET['persona_token']);
        $result = $this->service->buildUrl('/page');
        self::assertSame('/page', $result);
    }

    public function testBuildUrlWithTokenAddsToken(): void
    {
        $_GET['persona_token'] = 'abc123';
        $result = $this->service->buildUrl('/page');
        self::assertStringContainsString('persona_token=abc123', $result);
        self::assertStringContainsString('/page?', $result);
        unset($_GET['persona_token']);
    }

    public function testBuildUrlWithTokenAndExistingQuery(): void
    {
        $_GET['persona_token'] = 'abc123';
        $result = $this->service->buildUrl('/page?foo=bar');
        self::assertStringContainsString('persona_token=abc123', $result);
        self::assertStringContainsString('foo=bar', $result);
        unset($_GET['persona_token']);
    }

    public function testBuildUrlWithTokenAndAnchor(): void
    {
        $_GET['persona_token'] = 'abc123';
        $result = $this->service->buildUrl('/page#section');
        self::assertStringContainsString('persona_token=abc123', $result);
        self::assertStringContainsString('#section', $result);
        unset($_GET['persona_token']);
    }

    public function testBuildUrlWithEmptyTokenReturnsUrl(): void
    {
        $_GET['persona_token'] = '';
        $result = $this->service->buildUrl('/page');
        self::assertSame('/page', $result);
        unset($_GET['persona_token']);
    }

    // ── renderDonutChart() ──────────────────────────────────────

    public function testRenderDonutChartZeroTotalReturnsZeroChart(): void
    {
        $result = $this->service->renderDonutChart(0, 0, 0, 0);
        self::assertStringContainsString('Total', $result);
        self::assertStringContainsString('0', $result);
    }

    public function testRenderDonutChartWithValues(): void
    {
        $result = $this->service->renderDonutChart(100, 50, 30, 20);
        self::assertStringContainsString('100', $result);
        self::assertStringContainsString('50', $result);
        self::assertStringContainsString('30', $result);
        self::assertStringContainsString('20', $result);
    }

    public function testRenderDonutChartNegativeTotal(): void
    {
        $result = $this->service->renderDonutChart(-1, 0, 0, 0);
        self::assertStringContainsString('Total', $result);
    }

    public function testRenderDonutChartAllValidated(): void
    {
        $result = $this->service->renderDonutChart(10, 10, 0, 0);
        self::assertStringContainsString('10', $result);
        self::assertStringContainsString('Validées', $result);
    }

    public function testRenderDonutChartAllRefused(): void
    {
        $result = $this->service->renderDonutChart(5, 0, 0, 5);
        self::assertStringContainsString('5', $result);
        self::assertStringContainsString('Refusées', $result);
    }

    public function testRenderDonutChartConicGradient(): void
    {
        $result = $this->service->renderDonutChart(10, 4, 3, 3);
        // Le conic-gradient est maintenant dans DynamicCssService, pas dans le HTML.
        // On vérifie que la classe dynamique est présente.
        self::assertStringContainsString('donut-', $result);
        // Vérifier que DynamicCssService a enregistré la règle avec conic-gradient
        $css = \App\Core\App::css()->render();
        self::assertStringContainsString('conic-gradient', $css);
    }

    public function testRenderDonutChartContainsLegend(): void
    {
        $result = $this->service->renderDonutChart(10, 4, 3, 3);
        self::assertStringContainsString('Validées', $result);
        self::assertStringContainsString('En cours', $result);
        self::assertStringContainsString('Refusées', $result);
    }

    // ── tJargon() additional cases ──────────────────────────────

    public function testTJargonEPI(): void
    {
        $result = $this->service->tJargon('EPI');
        self::assertStringContainsString('Équipement', $result);
    }

    public function testTJargonSI(): void
    {
        $result = $this->service->tJargon('SI');
        self::assertStringContainsString('Système', $result);
    }

    public function testTJargonDSI(): void
    {
        $result = $this->service->tJargon('DSI');
        self::assertStringContainsString('Direction', $result);
    }

    public function testTJargonRH(): void
    {
        $result = $this->service->tJargon('RH');
        self::assertStringContainsString('Ressources', $result);
    }

    public function testTJargonLDAP(): void
    {
        $result = $this->service->tJargon('LDAP');
        self::assertStringContainsString('Annuaire', $result);
    }

    public function testTJargonSMTP(): void
    {
        // B4 fix: SMTP now consistently → 'Serveur email (SMTP)' across the whole app
        $result = $this->service->tJargon('SMTP');
        self::assertStringContainsString('Serveur email', $result);
        self::assertStringContainsString('SMTP', $result);
    }

    public function testTJargonCSRF(): void
    {
        $result = $this->service->tJargon('CSRF');
        self::assertStringContainsString('Jeton de sécurité', $result);
    }

    public function testTJargonCSV(): void
    {
        $result = $this->service->tJargon('CSV');
        self::assertStringContainsString('Tableur', $result);
    }

    public function testTJargonJSON(): void
    {
        $result = $this->service->tJargon('JSON');
        self::assertStringContainsString('Format de données', $result);
    }

    public function testTJargonHTTP(): void
    {
        $result = $this->service->tJargon('HTTP');
        self::assertStringContainsString('Protocole web', $result);
    }

    public function testTJargonURL(): void
    {
        $result = $this->service->tJargon('URL');
        self::assertStringContainsString('Adresse web', $result);
    }

    public function testTJargonAPI(): void
    {
        $result = $this->service->tJargon('API');
        self::assertStringContainsString('Interface', $result);
    }

    public function testTJargonNoJargonReturnsSameText(): void
    {
        $text = 'This is plain text without jargon';
        self::assertSame($text, $this->service->tJargon($text));
    }

    // ── getFileIcon() additional cases ──────────────────────────

    public function testGetFileIconPowerpoint(): void
    {
        // PowerPoint pptx matches the openxml pattern
        self::assertSame('📝', $this->service->getFileIcon('application/vnd.openxmlformats-officedocument.presentationml.presentation'));
        // Old .ppt format falls through to default
        self::assertSame('📎', $this->service->getFileIcon('application/vnd.ms-powerpoint'));
    }

    public function testGetFileIconExcel(): void
    {
        // Excel xlsx matches the openxml pattern
        self::assertSame('📝', $this->service->getFileIcon('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        // Old .xls format falls through to default
        self::assertSame('📎', $this->service->getFileIcon('application/vnd.ms-excel'));
    }

    public function testGetFileIconImageGif(): void
    {
        self::assertSame('🖼️', $this->service->getFileIcon('image/gif'));
    }

    // ── formatDateTimeFr() — source unique du formatage des dates SQL ──

    public function testFormatDateTimeFrNullReturnsEmpty(): void
    {
        self::assertSame('', $this->service->formatDateTimeFr(null));
    }

    public function testFormatDateTimeFrEmptyStringReturnsEmpty(): void
    {
        // Amélioration vs date('d/m/Y à H:i', (int) strtotime('')) qui
        // affichait « 01/01/1970 à 01:00 » sur une date vide.
        self::assertSame('', $this->service->formatDateTimeFr(''));
    }

    public function testFormatDateTimeFrSqlDatetimeFormatsFrench(): void
    {
        // Format SQLite datetime('now') : 'YYYY-MM-DD HH:MM:SS' — stocké en UTC.
        // P0-1 : la chaîne UTC doit être convertie en Europe/Paris pour l'affichage
        // (janvier = UTC+1 : 10:30 UTC → 11:30 Paris).
        self::assertSame('15/01/2024 à 11:30', $this->service->formatDateTimeFr('2024-01-15 10:30:00'));
    }

    public function testFormatDateTimeFrSecondsLess(): void
    {
        // P0-1 : septembre = UTC+2 (heure d'été) : 08:45 UTC → 10:45 Paris.
        self::assertSame('01/09/2026 à 10:45', $this->service->formatDateTimeFr('2026-09-01 08:45'));
    }

    public function testFormatDateTimeFrParisInputNotConverted(): void
    {
        // P0-1 cas particulier : submissions.submitted_at est écrit par PHP date()
        // (FormSubmissionHandler) donc déjà en heure Paris — ne pas convertir.
        self::assertSame('01/09/2026 à 08:45', $this->service->formatDateTimeFr('2026-09-01 08:45', false));
    }

    public function testFormatDateTimeFrInvalidDateReturnsEmpty(): void
    {
        // Avant : (int) strtotime('garbage') → 0 → « 01/01/1970 à 01:00 ».
        self::assertSame('', $this->service->formatDateTimeFr('not-a-date'));
    }

    // ── formatRelanceSuffix() ───────────────────────────────────

    public function testFormatRelanceSuffixZeroReturnsEmpty(): void
    {
        self::assertSame('', $this->service->formatRelanceSuffix(0));
    }

    public function testFormatRelanceSuffixNegativeReturnsEmpty(): void
    {
        self::assertSame('', $this->service->formatRelanceSuffix(-1));
    }

    public function testFormatRelanceSuffixOneIsSingular(): void
    {
        self::assertSame(' — 1 rappel envoyé', $this->service->formatRelanceSuffix(1));
    }

    public function testFormatRelanceSuffixTwoIsPlural(): void
    {
        self::assertSame(' — 2 rappels envoyés', $this->service->formatRelanceSuffix(2));
    }
}
