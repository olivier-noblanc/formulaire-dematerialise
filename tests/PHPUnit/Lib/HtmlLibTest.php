<?php
declare(strict_types=1);

namespace App\Tests\Lib;

use PHPUnit\Framework\TestCase;
use App\Render\HtmlService;

final class HtmlLibTest extends TestCase
{
    private HtmlService $html;

    protected function setUp(): void
    {
        $this->html = new HtmlService();
    }

    // ── h() / escape() ──────────────────────────────────────────

    public function testHEscapesHtml(): void
    {
        self::assertSame('&lt;script&gt;', $this->html->escape('<script>'));
        self::assertSame('&amp;', $this->html->escape('&'));
        self::assertSame('&quot;', $this->html->escape('"'));
    }

    public function testHNullReturnsEmpty(): void
    {
        self::assertSame('', $this->html->escape(null));
    }

    public function testHPreservesSafeText(): void
    {
        self::assertSame('hello world', $this->html->escape('hello world'));
    }

    // ── displayUser() ───────────────────────────────────────────

    public function testDisplayUserSameUser(): void
    {
        $currentUser = 'olivier.noblanc@dreets.gouv.fr';
        self::assertSame(
            '<strong>Vous</strong>',
            $this->html->displayUser('olivier.noblanc@dreets.gouv.fr', $currentUser)
        );
    }

    public function testDisplayUserSameDomain(): void
    {
        $currentUser = 'olivier.noblanc@dreets.gouv.fr';
        $result = $this->html->displayUser('jean.dupont@dreets.gouv.fr', $currentUser);
        self::assertStringContainsString('jean.dupont', $result);
        self::assertStringNotContainsString('@dreets.gouv.fr', $result);
    }

    public function testDisplayUserDifferentDomain(): void
    {
        $currentUser = 'olivier.noblanc@dreets.gouv.fr';
        $result = $this->html->displayUser('jean@externe.fr', $currentUser);
        self::assertSame('jean@externe.fr', $result);
    }

    public function testDisplayUserEmpty(): void
    {
        self::assertSame('', $this->html->displayUser('', 'test@test.com'));
    }

    public function testDisplayUserForceEmail(): void
    {
        $currentUser = 'olivier.noblanc@dreets.gouv.fr';
        $result = $this->html->displayUser('olivier.noblanc@dreets.gouv.fr', $currentUser, true);
        self::assertSame('olivier.noblanc@dreets.gouv.fr', $result);
    }

    // ── displayUserShort() ─────────────────────────────────────

    public function testDisplayUserShortEmail(): void
    {
        self::assertSame('olivier.noblanc', $this->html->displayUserShort('olivier.noblanc@dreets.gouv.fr'));
    }

    public function testDisplayUserShortNoAt(): void
    {
        self::assertSame('olivier.noblanc', $this->html->displayUserShort('olivier.noblanc'));
    }

    public function testDisplayUserShortWindows(): void
    {
        self::assertSame('olivier.noblanc', $this->html->displayUserShort('DREETS\\olivier.noblanc'));
    }

    public function testDisplayUserShortEmpty(): void
    {
        self::assertSame('', $this->html->displayUserShort(''));
    }

    // ── formatFileSize() ───────────────────────────────────────

    public function testFormatFileSizeBytes(): void
    {
        self::assertSame('0 o', $this->html->formatFileSize(0));
        self::assertSame('500 o', $this->html->formatFileSize(500));
    }

    public function testFormatFileSizeKo(): void
    {
        self::assertSame('1 Ko', $this->html->formatFileSize(1024));
    }

    public function testFormatFileSizeMo(): void
    {
        self::assertSame('1 Mo', $this->html->formatFileSize(1024 * 1024));
    }

    public function testFormatFileSizeLarge(): void
    {
        $result = $this->html->formatFileSize(1024 * 1024 * 1024);
        self::assertStringContainsString('Go', $result);
    }

    // ── getFileIcon() ──────────────────────────────────────────

    public function testGetFileIconImage(): void
    {
        $icon = $this->html->getFileIcon('image/png');
        self::assertNotEmpty($icon);
    }

    public function testGetFileIconPdf(): void
    {
        self::assertSame('📄', $this->html->getFileIcon('application/pdf'));
    }

    public function testGetFileIconZip(): void
    {
        self::assertSame('📦', $this->html->getFileIcon('application/zip'));
    }

    public function testGetFileIconWord(): void
    {
        self::assertSame('📝', $this->html->getFileIcon('application/msword'));
    }

    public function testGetFileIconText(): void
    {
        self::assertSame('📃', $this->html->getFileIcon('text/plain'));
    }

    public function testGetFileIconDefault(): void
    {
        self::assertSame('📎', $this->html->getFileIcon('application/octet-stream'));
    }
}
