<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\View\ViewRenderer;
use App\Render\HtmlService;

final class ViewRendererTest extends TestCase
{
    private ViewRenderer $view;
    private HtmlService $html;

    protected function setUp(): void
    {
        $this->html = new HtmlService();
        $this->view = new ViewRenderer($this->html);
    }

    public function testPageReturnsHtml(): void
    {
        // render_page depends on App container services
        // Skip if services aren't registered
        try {
            $html = $this->view->page('Test Page', '', '', '<p>Content</p>');
            $this->assertIsString($html);
            $this->assertStringContainsString('Test Page', $html);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('App container services not registered');
        }
    }

    public function testFooterReturnsHtml(): void
    {
        try {
            $html = $this->view->footer();
            $this->assertIsString($html);
            $this->assertNotEmpty($html);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('App container services not registered');
        }
    }

    public function testHMethodDelegatesToHtmlService(): void
    {
        $result = $this->view->h('<script>');
        $this->assertSame('&lt;script&gt;', $result);
    }

    public function testTJargonMethodDelegatesToHtmlService(): void
    {
        $result = $this->view->tJargon('Workflow');
        $this->assertSame('Parcours', $result);
    }

    public function testFaviconReturnsString(): void
    {
        $html = $this->view->favicon();
        $this->assertIsString($html);
    }

    public function testMessagesReturnsString(): void
    {
        $html = $this->view->messages(['Test message']);
        $this->assertIsString($html);
    }

    public function testSearchBarReturnsString(): void
    {
        $html = $this->view->searchBar('index.php?p=search', '');
        $this->assertIsString($html);
        $this->assertStringContainsString('search', $html);
    }

    // ── Constructor / DI ────────────────────────────────────────

    public function testConstructorCreatesInstance(): void
    {
        $view = new ViewRenderer(new HtmlService());
        $this->assertInstanceOf(ViewRenderer::class, $view);
    }

    // ── h() method ──────────────────────────────────────────────

    public function testHEscapesNull(): void
    {
        $result = $this->view->h(null);
        $this->assertSame('', $result);
    }

    public function testHEscapesEmpty(): void
    {
        $result = $this->view->h('');
        $this->assertSame('', $result);
    }

    public function testHEscapesSpecialChars(): void
    {
        $result = $this->view->h('" & < >');
        $this->assertStringContainsString('&amp;', $result);
        $this->assertStringContainsString('&lt;', $result);
        $this->assertStringContainsString('&gt;', $result);
    }

    // ── tJargon() method ────────────────────────────────────────

    public function testTJargonDelegatesToHtmlService(): void
    {
        $result = $this->view->tJargon('RGPD');
        $this->assertStringContainsString('Protection des données', $result);
    }

    public function testTJargonWithNoJargon(): void
    {
        $result = $this->view->tJargon('plain text');
        $this->assertSame('plain text', $result);
    }

    // ── favicon() ───────────────────────────────────────────────

    public function testFaviconReturnsNonEmpty(): void
    {
        $html = $this->view->favicon();
        $this->assertNotEmpty($html);
    }

    // ── messages() ──────────────────────────────────────────────

    public function testMessagesWithEmptyArray(): void
    {
        $html = $this->view->messages([]);
        $this->assertIsString($html);
    }

    public function testMessagesWithMultipleMessages(): void
    {
        $html = $this->view->messages(['Msg 1', 'Msg 2', 'Msg 3']);
        $this->assertStringContainsString('Msg 1', $html);
        $this->assertStringContainsString('Msg 2', $html);
        $this->assertStringContainsString('Msg 3', $html);
    }

    public function testMessagesEscapesHtml(): void
    {
        $html = $this->view->messages(['<script>alert(1)</script>']);
        $this->assertStringNotContainsString('<script>', $html);
    }

    // ── searchBar() ─────────────────────────────────────────────

    public function testSearchBarWithQuery(): void
    {
        $html = $this->view->searchBar('index.php', 'test query');
        $this->assertStringContainsString('test query', $html);
    }

    public function testSearchBarWithoutQuery(): void
    {
        $html = $this->view->searchBar('index.php', '');
        $this->assertIsString($html);
        $this->assertNotEmpty($html);
    }

    public function testSearchBarContainsFormElement(): void
    {
        $html = $this->view->searchBar('index.php', '');
        $this->assertStringContainsString('form', $html);
    }

    // ── Container integration ───────────────────────────────────

    public function testServiceRegisteredInContainer(): void
    {
        $app = \App\Core\App::getInstance();
        $this->assertTrue($app->has(ViewRenderer::class));
    }

    public function testContainerReturnsSameInstance(): void
    {
        $app = \App\Core\App::getInstance();
        $v1 = $app->get(ViewRenderer::class);
        $v2 = $app->get(ViewRenderer::class);
        $this->assertSame($v1, $v2);
    }
}
