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
}
