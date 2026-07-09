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
        $this->assertSame('&lt;script&gt;', $this->html->escape('<script>'));
        $this->assertSame('&amp;', $this->html->escape('&'));
        $this->assertSame('&quot;', $this->html->escape('"'));
    }

    public function testHNullReturnsEmpty(): void
    {
        $this->assertSame('', $this->html->escape(null));
    }

    public function testHPreservesSafeText(): void
    {
        $this->assertSame('hello world', $this->html->escape('hello world'));
    }

    // ── displayUser() ───────────────────────────────────────────

    public function testDisplayUserSameUser(): void
    {
        $currentUser = 'admin.local@exemple.invalid';
        $this->assertSame(
            '<strong>Vous</strong>',
            $this->html->displayUser('admin.local@exemple.invalid', $currentUser)
        );
    }

    public function testDisplayUserSameDomain(): void
    {
        $currentUser = 'admin.local@exemple.invalid';
        $result = $this->html->displayUser('jean.dupont@exemple.invalid', $currentUser);
        $this->assertStringContainsString('jean.dupont', $result);
        $this->assertStringNotContainsString('@exemple.invalid', $result);
    }

    public function testDisplayUserDifferentDomain(): void
    {
        $currentUser = 'admin.local@exemple.invalid';
        $result = $this->html->displayUser('jean@externe.fr', $currentUser);
        $this->assertSame('jean@externe.fr', $result);
    }

    public function testDisplayUserEmpty(): void
    {
        $this->assertSame('', $this->html->displayUser('', 'test@test.com'));
    }

    public function testDisplayUserForceEmail(): void
    {
        $currentUser = 'admin.local@exemple.invalid';
        $result = $this->html->displayUser('admin.local@exemple.invalid', $currentUser, true);
        $this->assertSame('admin.local@exemple.invalid', $result);
    }

    // ── displayUserShort() ─────────────────────────────────────

    public function testDisplayUserShortEmail(): void
    {
        $this->assertSame('admin.local', $this->html->displayUserShort('admin.local@exemple.invalid'));
    }

    public function testDisplayUserShortNoAt(): void
    {
        $this->assertSame('admin.local', $this->html->displayUserShort('admin.local'));
    }

    public function testDisplayUserShortWindows(): void
    {
        $this->assertSame('admin.local', $this->html->displayUserShort('DREETS\\admin.local'));
    }

    public function testDisplayUserShortEmpty(): void
    {
        $this->assertSame('', $this->html->displayUserShort(''));
    }

    // ── formatFileSize() ───────────────────────────────────────

    public function testFormatFileSizeBytes(): void
    {
        $this->assertSame('0 o', $this->html->formatFileSize(0));
        $this->assertSame('500 o', $this->html->formatFileSize(500));
    }

    public function testFormatFileSizeKo(): void
    {
        $this->assertSame('1 Ko', $this->html->formatFileSize(1024));
    }

    public function testFormatFileSizeMo(): void
    {
        $this->assertSame('1 Mo', $this->html->formatFileSize(1024 * 1024));
    }

    public function testFormatFileSizeLarge(): void
    {
        $result = $this->html->formatFileSize(1024 * 1024 * 1024);
        $this->assertStringContainsString('Go', $result);
    }

    // ── getFileIcon() ──────────────────────────────────────────

    public function testGetFileIconImage(): void
    {
        $icon = $this->html->getFileIcon('image/png');
        $this->assertNotEmpty($icon);
    }

    public function testGetFileIconPdf(): void
    {
        $this->assertSame('📄', $this->html->getFileIcon('application/pdf'));
    }

    public function testGetFileIconZip(): void
    {
        $this->assertSame('📦', $this->html->getFileIcon('application/zip'));
    }

    public function testGetFileIconWord(): void
    {
        $this->assertSame('📝', $this->html->getFileIcon('application/msword'));
    }

    public function testGetFileIconText(): void
    {
        $this->assertSame('📃', $this->html->getFileIcon('text/plain'));
    }

    public function testGetFileIconDefault(): void
    {
        $this->assertSame('📎', $this->html->getFileIcon('application/octet-stream'));
    }
}
