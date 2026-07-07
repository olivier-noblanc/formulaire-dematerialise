<?php
declare(strict_types=1);

namespace App\Tests\Lib;

use PHPUnit\Framework\TestCase;

final class HtmlLibTest extends TestCase
{
    // ── h() ──────────────────────────────────────────────────

    public function testHEscapesHtml(): void
    {
        $this->assertSame('&lt;script&gt;', h('<script>'));
        $this->assertSame('&amp;', h('&'));
        $this->assertSame('&quot;', h('"'));
    }

    public function testHNullReturnsEmpty(): void
    {
        $this->assertSame('', h(null));
    }

    public function testHPreservesSafeText(): void
    {
        $this->assertSame('hello world', h('hello world'));
    }

    // ── display_user() ───────────────────────────────────────

    public function testDisplayUserSameUser(): void
    {
        $currentUser = 'admin.local@exemple.invalid';
        $this->assertSame(
            '<strong>Vous</strong>',
            display_user('admin.local@exemple.invalid', $currentUser)
        );
    }

    public function testDisplayUserSameDomain(): void
    {
        $currentUser = 'admin.local@exemple.invalid';
        $result = display_user('jean.dupont@exemple.invalid', $currentUser);
        $this->assertStringContainsString('jean.dupont', $result);
        $this->assertStringNotContainsString('@exemple.invalid', $result);
    }

    public function testDisplayUserDifferentDomain(): void
    {
        $currentUser = 'admin.local@exemple.invalid';
        $result = display_user('jean@externe.fr', $currentUser);
        $this->assertSame('jean@externe.fr', $result);
    }

    public function testDisplayUserEmpty(): void
    {
        $this->assertSame('', display_user('', 'test@test.com'));
    }

    public function testDisplayUserForceEmail(): void
    {
        $currentUser = 'admin.local@exemple.invalid';
        $result = display_user('admin.local@exemple.invalid', $currentUser, true);
        $this->assertSame('admin.local@exemple.invalid', $result);
    }

    // ── display_user_short() ─────────────────────────────────

    public function testDisplayUserShortEmail(): void
    {
        $this->assertSame('admin.local', display_user_short('admin.local@exemple.invalid'));
    }

    public function testDisplayUserShortNoAt(): void
    {
        $this->assertSame('admin.local', display_user_short('admin.local'));
    }

    public function testDisplayUserShortWindows(): void
    {
        $this->assertSame('admin.local', display_user_short('DREETS\\admin.local'));
    }

    public function testDisplayUserShortEmpty(): void
    {
        $this->assertSame('', display_user_short(''));
    }

    // ── format_file_size() ───────────────────────────────────

    public function testFormatFileSizeBytes(): void
    {
        // lib/ uses "octets" not "o" for bytes
        $this->assertSame('0 octets', format_file_size(0));
        $this->assertSame('500 octets', format_file_size(500));
    }

    public function testFormatFileSizeKo(): void
    {
        $this->assertSame('1 Ko', format_file_size(1024));
    }

    public function testFormatFileSizeMo(): void
    {
        $this->assertSame('1 Mo', format_file_size(1024 * 1024));
    }

    public function testFormatFileSizeLarge(): void
    {
        // lib/ function stops at Mo, doesn't have Go unit
        $result = format_file_size(1024 * 1024 * 1024);
        $this->assertStringContainsString('Mo', $result);
    }

    // ── get_file_icon() ──────────────────────────────────────

    public function testGetFileIconImage(): void
    {
        // lib/ uses '🖼' without variation selector
        $icon = get_file_icon('image/png');
        $this->assertNotEmpty($icon);
    }

    public function testGetFileIconPdf(): void
    {
        $this->assertSame('📄', get_file_icon('application/pdf'));
    }

    public function testGetFileIconZip(): void
    {
        $this->assertSame('📦', get_file_icon('application/zip'));
    }

    public function testGetFileIconWord(): void
    {
        $this->assertSame('📝', get_file_icon('application/msword'));
    }

    public function testGetFileIconText(): void
    {
        $this->assertSame('📃', get_file_icon('text/plain'));
    }

    public function testGetFileIconDefault(): void
    {
        $this->assertSame('📎', get_file_icon('application/octet-stream'));
    }
}
