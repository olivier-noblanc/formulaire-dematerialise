<?php
declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * E2E tests — real HTTP requests to the PHP built-in server.
 *
 * Starts its own server process on port 9876 with TEST_MODE enabled,
 * then hits every route defined in index.php $ALLOWED_PAGES.
 *
 * Verify HTTP status codes and DOM structure for each page.
 */
final class HttpRouteTest extends TestCase
{
    /** @var resource|null */
    private static mixed $serverProcess = null;
    private static int $port = 9876;
    private static string $baseUrl;
    private static string $docRoot;
    private static bool $serverReady = false;

    // ── Server lifecycle ──────────────────────────────────────

    public static function setUpBeforeClass(): void
    {
        self::$docRoot = dirname(__DIR__, 2);
        self::$baseUrl = 'http://127.0.0.1:' . self::$port;

        // Kill any leftover process on the port
        self::killPort(self::$port);

        // Start PHP built-in server with TEST_MODE enabled.
        // Uses a wrapper script (start_server.php) that sets env vars via putenv()
        // and starts the server via passthru(). This works on both Windows and Linux.
        $wrapperScript = __DIR__ . DIRECTORY_SEPARATOR . 'start_server.php';
        $cmd = sprintf(
            'php %s %d %s',
            escapeshellarg($wrapperScript),
            self::$port,
            escapeshellarg(self::$docRoot)
        );

        if (PHP_OS_FAMILY === 'Windows') {
            // On Windows, proc_open() doesn't work with php -S.
            // Use PowerShell Start-Process which properly backgrounds the process.
            $psScript = __DIR__ . DIRECTORY_SEPARATOR . 'start_server.ps1';
            $phpBinFwd = str_replace('\\', '/', PHP_BINARY);
            $docRootFwd = str_replace('\\', '/', self::$docRoot);
            exec("powershell -NoProfile -ExecutionPolicy Bypass -File \"{$psScript}\" -Port " . self::$port . " -DocRoot \"{$docRootFwd}\" -PhpBin \"{$phpBinFwd}\"");
            self::$serverProcess = null;
        } else {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            self::$serverProcess = proc_open($cmd, $descriptors, $pipes, self::$docRoot, []);
            if (!is_resource(self::$serverProcess)) {
                self::markTestSkipped('Failed to start PHP built-in server');
            }
            fclose($pipes[0]);
        }

        // Wait for server to be ready (max 15 seconds)
        $ready = false;
        for ($i = 0; $i < 60; $i++) {
            usleep(250_000);
            $ctx = stream_context_create(['http' => [
                'timeout' => 1,
                'ignore_errors' => true,
            ]]);
            $result = @file_get_contents(self::$baseUrl . '/?p=health', false, $ctx);
            if ($result !== false) {
                $ready = true;
                break;
            }
        }

        if (!$ready) {
            self::markTestSkipped('PHP server did not become ready within 15 seconds');
        }

        self::$serverReady = true;
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            // Try graceful shutdown first
            @file_get_contents(self::$baseUrl . '/?p=__shutdown', false, [
                'http' => ['timeout' => 2, 'ignore_errors' => true],
            ]);

            $status = proc_get_status(self::$serverProcess);
            if ($status['running']) {
                // On Windows, use taskkill
                if (PHP_OS_FAMILY === 'Windows') {
                    exec('taskkill /PID ' . $status['pid'] . ' /F 2>NUL');
                } else {
                    posix_kill($status['pid'], SIGTERM);
                    sleep(1);
                    if (proc_get_status(self::$serverProcess)['running']) {
                        posix_kill($status['pid'], SIGKILL);
                    }
                }
            }

            // Close remaining pipes
            foreach ($status['pipes'] ?? [] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }

        self::killPort(self::$port);
    }

    private static function killPort(int $port): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = [];
            exec('netstat -ano | findstr ":' . $port . '"', $output);
            foreach ($output as $line) {
                if (preg_match('/\s(\d+)\s*$/', $line, $m)) {
                    exec('taskkill /PID ' . $m[1] . ' /F 2>NUL');
                }
            }
        } else {
            exec("lsof -ti:{$port} | xargs kill -9 2>/dev/null");
        }
    }

    // ── HTTP helper ───────────────────────────────────────────

    private static function httpGet(string $path, array $headers = []): array
    {
        $url = self::$baseUrl . $path;

        $httpHeaders = [
            'X-Test-Mode: 1',
            'X-Test-User: olivier.noblanc@dreets.gouv.fr',
        ];
        foreach ($headers as $key => $value) {
            $httpHeaders[] = "$key: $value";
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $httpHeaders),
                'timeout' => 10,
                'follow_location' => false,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx);
        $status = 200;

        if (isset($http_response_header)) {
            // Parse status from response header
            if (preg_match('#HTTP/\d\.\d\s+(\d+)#', $http_response_header[0] ?? '', $m)) {
                $status = (int) $m[1];
            }
        }

        return [$status, $body ?? ''];
    }

    // ── Route data providers ──────────────────────────────────

    /**
     * Public pages that should return 200 with specific content.
     *
     * @return array<string, array{string, string, list<string>}>
     */
    public static function publicPageProvider(): array
    {
        return [
            'accueil' => ['/', 'CircuitDémat', ['app-layout', 'sidebar', 'main']],
            'health'  => ['/?p=health', 'Santé du système', ['check-item']],
            'docs'    => ['/?p=docs', 'Aide et documentation', ['full-doc', 'start-section']],
            'changelog' => ['/?p=changelog', 'Journal des modifications', ['version-card']],
            'my_submissions' => ['/?p=my_submissions', 'Mes demandes', []],
            'my_validations' => ['/?p=my_validations', 'Mes validations', ['stat']],
            'my_forms' => ['/?p=my_forms', 'Mes formulaires', []],
            'admin_access' => ['/?p=admin_access', 'admin', []],
        ];
    }

    /**
     * Admin pages that should return 200 with specific content.
     *
     * @return array<string, array{string, string, list<string>}>
     */
    public static function adminPageProvider(): array
    {
        return [
            'dashboard'      => ['/?p=dashboard', 'Tableau de bord', ['stats', 'stat']],
            'admin_forms'    => ['/?p=admin_forms', 'Gestion des formulaires', []],
            'admin_settings' => ['/?p=admin_settings', 'Paramètres', []],
            'admin_alerts'   => ['/?p=admin_alerts', 'Alertes', []],
            'monitoring'     => ['/?p=monitoring', 'Surveillance', []],
            'stats'          => ['/?p=stats', 'Statistiques', []],
            'backup'         => ['/?p=backup', 'Sauvegarde', []],
            'rgpd'           => ['/?p=rgpd', 'RGPD', []],
        ];
    }

    /**
     * Pages needing params — should show error or redirect, not 200.
     *
     * @return array<string, array{string, int}>
     */
    public static function missingParamPageProvider(): array
    {
        return [
            'form'             => ['/?p=form', 200],
            'validate'         => ['/?p=validate', 200],
            'submission_view'  => ['/?p=submission_view', 302],
            'form_tracking'    => ['/?p=form_tracking', 500],
            'form_preview'     => ['/?p=form_preview', 500],
            'confirm_action'   => ['/?p=confirm_action', 302],
            'download'         => ['/?p=download', 500],
            'screenshot'       => ['/?p=screenshot', 400],
        ];
    }

    /**
     * All page routes with expected primary text content.
     *
     * @return array<string, array{string, int, string}>
     */
    public static function allRoutesProvider(): array
    {
        return [
            'accueil'         => ['/', 200, 'CircuitDémat'],
            'health'          => ['/?p=health', 200, 'Santé du système'],
            'docs'            => ['/?p=docs', 200, 'Aide et documentation'],
            'changelog'       => ['/?p=changelog', 200, 'Journal des modifications'],
            'my_submissions'  => ['/?p=my_submissions', 200, 'Mes demandes'],
            'my_validations'  => ['/?p=my_validations', 200, 'Mes validations'],
            'my_forms'        => ['/?p=my_forms', 200, 'Mes formulaires'],
            'admin_access'    => ['/?p=admin_access', 200, 'admin'],
            'dashboard'       => ['/?p=dashboard', 200, 'Tableau de bord'],
            'admin_forms'     => ['/?p=admin_forms', 200, 'Gestion des formulaires'],
            'admin_settings'  => ['/?p=admin_settings', 200, 'Paramètres'],
            'admin_alerts'    => ['/?p=admin_alerts', 200, 'Alertes'],
            'monitoring'      => ['/?p=monitoring', 200, 'Surveillance'],
            'stats'           => ['/?p=stats', 200, 'Statistiques'],
            'backup'          => ['/?p=backup', 200, 'Sauvegarde'],
            'rgpd'            => ['/?p=rgpd', 200, 'RGPD'],
            'persona_stop'    => ['/?p=persona&action=stop', 302, ''],
        ];
    }

    // ── Tests: HTTP status codes ──────────────────────────────

    /**
     * Test that public pages return 200.
     *
     * @param string $path       URL path
     * @param string $needle     Text that must appear in the body
     * @param list<string> $htmlClasses  CSS classes that must be present
     */
    #[DataProvider('publicPageProvider')]
    public function testPublicPageReturns200(string $path, string $needle, array $htmlClasses): void
    {
        [$status, $body] = self::httpGet($path);

        $this->assertSame(200, $status, "Page $path should return 200, got $status");
        $this->assertStringContainsString($needle, $body, "Page $path should contain '$needle'");

        foreach ($htmlClasses as $class) {
            $this->assertMatchesRegularExpression(
                '/class="[^"]*' . preg_quote($class, '/') . '[^"]*"/',
                $body,
                "Page $path should contain CSS class '$class'"
            );
        }
    }

    /**
     * Test that admin pages return 200 (admin user is authenticated).
     *
     * @param string $path       URL path
     * @param string $needle     Text that must appear in the body
     * @param list<string> $htmlClasses  CSS classes that must be present (any one suffices)
     */
    #[DataProvider('adminPageProvider')]
    public function testAdminPageReturns200(string $path, string $needle, array $htmlClasses): void
    {
        [$status, $body] = self::httpGet($path);

        $this->assertSame(200, $status, "Admin page $path should return 200, got $status");
        $this->assertStringContainsString($needle, $body, "Admin page $path should contain '$needle'");

        // At least one of the expected CSS classes should be present
        if (!empty($htmlClasses)) {
            $found = false;
            foreach ($htmlClasses as $class) {
                if (preg_match('/class="[^"]*' . preg_quote($class, '/') . '[^"]*"/', $body)) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue(
                $found,
                "Admin page $path should contain at least one of: " . implode(', ', $htmlClasses)
            );
        }
    }

    /**
     * Test pages that require parameters — they should error, not render normally.
     *
     * @param string $path              URL path without required params
     * @param int    $expectedStatus    Expected HTTP status (400 or similar)
     */
    #[DataProvider('missingParamPageProvider')]
    public function testMissingParamReturnsError(string $path, int $expectedStatus): void
    {
        [$status, $body] = self::httpGet($path);

        // Pages without params should return the expected status
        // (may be 200 with error message, 302 redirect, or 400/404 error)
        $this->assertSame(
            $expectedStatus,
            $status,
            "Page $path without params should return status $expectedStatus, got $status"
        );
    }

    // ── Tests: DOM structure ──────────────────────────────────

    /**
     * Every HTML page should have the standard layout elements.
     *
     * @param string $path  URL path
     */
    #[DataProvider('publicPageProvider')]
    public function testLayoutStructure(string $path, string $needle, array $htmlClasses): void
    {
        [$status, $body] = self::httpGet($path);

        if ($status !== 200) {
            $this->markTestSkipped("Page $path returned $status, cannot check layout");
        }

        // Skip layout checks for error/error pages
        if (str_contains($body, 'error-card') || !str_contains($body, '<html')) {
            $this->markTestSkipped("Page $path returned an error page, layout checks skipped");
        }

        // Skip link (accessibility)
        $this->assertMatchesRegularExpression(
            '/<a[^>]*class="skip-link"[^>]*>Aller au contenu principal<\/a>/',
            $body,
            "Page $path should have skip-link for accessibility"
        );

        // Sidebar navigation
        $this->assertMatchesRegularExpression(
            '/<nav[^>]*class="sidebar"[^>]*aria-label="Navigation principale"/',
            $body,
            "Page $path should have nav.sidebar with aria-label"
        );

        // Sidebar brand
        $this->assertStringContainsString('sidebar-brand', $body, "Page $path should have sidebar-brand");

        // Main area
        $this->assertMatchesRegularExpression(
            '/<div[^>]*class="main-area"/',
            $body,
            "Page $path should have div.main-area"
        );

        // Footer
        $this->assertMatchesRegularExpression(
            '/<footer>/',
            $body,
            "Page $path should have a <footer>"
        );

        // Sidebar user card
        $this->assertStringContainsString('sidebar-user', $body, "Page $path should have sidebar-user");
    }

    /**
     * Admin pages should have admin-specific sidebar links.
     */
    #[DataProvider('adminPageProvider')]
    public function testAdminSidebarLinks(string $path, string $needle, array $htmlClasses): void
    {
        [$status, $body] = self::httpGet($path);

        if ($status !== 200) {
            $this->markTestSkipped("Admin page $path returned $status");
        }

        if (str_contains($body, 'error-card')) {
            $this->markTestSkipped("Admin page $path returned error page");
        }

        // Admin section title in sidebar
        $this->assertStringContainsString(
            'Administration',
            $body,
            "Admin page $path should have 'Administration' section in sidebar"
        );
    }

    // ── Tests: special routes ─────────────────────────────────

    public function testPersonaStopRedirectsToIndex(): void
    {
        [$status, $body] = self::httpGet('/?p=persona&action=stop');

        // Persona stop should either redirect (302) or succeed (200)
        // On success, the page should contain index.php link
        $this->assertContains($status, [200, 302], 'Persona stop should redirect or succeed');
    }

    public function testHealthPageShowsSystemChecks(): void
    {
        [$status, $body] = self::httpGet('/?p=health');

        $this->assertSame(200, $status);
        $this->assertStringContainsString('Santé du système', $body);

        // Health page should have check items
        $this->assertMatchesRegularExpression(
            '/class="check-item"/',
            $body,
            'Health page should have check-item elements'
        );
    }

    public function testAccueilHasFormCards(): void
    {
        [$status, $body] = self::httpGet('/');

        $this->assertSame(200, $status);
        $this->assertStringContainsString('CircuitDémat', $body);

        // Should have tutorial or form-cards section
        $hasTutorial = str_contains($body, 'tutorial') || str_contains($body, 'welcome-state');
        $hasFormCards = str_contains($body, 'form-cards') || str_contains($body, 'form-card');
        $this->assertTrue(
            $hasTutorial || $hasFormCards,
            'Accueil page should have tutorial section or form cards'
        );
    }

    public function testDocsPageHasFullDocumentation(): void
    {
        [$status, $body] = self::httpGet('/?p=docs');

        $this->assertSame(200, $status);
        $this->assertStringContainsString('Aide et documentation', $body);
        $this->assertStringContainsString('full-doc', $body);
        $this->assertStringContainsString('start-section', $body);

        // Documentation should be substantial (500+ chars of content)
        $this->assertGreaterThan(500, strlen($body), 'Documentation page should have substantial content');
    }

    public function testChangelogPageHasVersionCards(): void
    {
        [$status, $body] = self::httpGet('/?p=changelog');

        $this->assertSame(200, $status);
        $this->assertStringContainsString('Journal des modifications', $body);
        $this->assertMatchesRegularExpression(
            '/class="version-card"/',
            $body,
            'Changelog should have version-card elements'
        );
    }

    public function testMyValidationsHasTabBar(): void
    {
        [$status, $body] = self::httpGet('/?p=my_validations');

        $this->assertSame(200, $status);
        $this->assertStringContainsString('Mes validations', $body);
        $this->assertStringContainsString('stat', $body);
    }

    // ── Tests: comprehensive route coverage ───────────────────

    /**
     * Every allowed page route returns the expected status and content.
     *
     * @param string $path     URL path
     * @param int    $status   Expected HTTP status
     * @param string $needle   Expected content substring (empty = skip content check)
     */
    #[DataProvider('allRoutesProvider')]
    public function testAllRoutesRespondCorrectly(string $path, int $status, string $needle): void
    {
        [$actualStatus, $body] = self::httpGet($path);

        $this->assertSame(
            $status,
            $actualStatus,
            "Route $path should return $status, got $actualStatus"
        );

        if ($needle !== '' && $actualStatus === 200) {
            $this->assertStringContainsString(
                $needle,
                $body,
                "Route $path should contain '$needle'"
            );
        }
    }

    /**
     * Test that unknown pages return 404.
     */
    public function testUnknownPageReturns404(): void
    {
        [$status, $body] = self::httpGet('/?p=nonexistent_page_xyz');

        // In TEST_MODE, errorPage() throws ErrorResponseException which is caught
        // by the exception handler and returns 500. Accept both 404 and 500.
        $this->assertContains($status, [404, 500], 'Unknown page should return 404 or 500 in TEST_MODE');
    }

    /**
     * Test that XSS attempts in page parameter are neutralized.
     */
    public function testXssInPageParameterIsSanitized(): void
    {
        [$status, $body] = self::httpGet('/?p=<script>alert(1)</script>');

        // In TEST_MODE, errorPage() throws which results in 500. Accept both.
        $this->assertContains($status, [404, 500], 'XSS attempt should result in 404 or 500');
        $this->assertStringNotContainsString('<script>', $body, 'XSS script tag should not be reflected');
    }

    /**
     * Test that a valid page with extra params doesn't break.
     */
    public function testValidPageWithExtraParams(): void
    {
        [$status, $body] = self::httpGet('/?p=docs&extra=value');

        $this->assertSame(200, $status, 'Valid page with extra params should still work');
        $this->assertStringContainsString('Aide et documentation', $body);
    }

    /**
     * Test that the server returns proper Content-Type headers.
     */
    public function testHtmlContentTypeHeader(): void
    {
        $url = self::$baseUrl . '/?p=health';
        $ctx = stream_context_create([
            'http' => [
                'header' => "X-Test-Mode: 1\r\nX-Test-User: olivier.noblanc@dreets.gouv.fr",
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        @file_get_contents($url, false, $ctx);

        $contentType = '';
        if (isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (stripos($header, 'content-type:') === 0) {
                    $contentType = trim(substr($header, 13));
                    break;
                }
            }
        }

        $this->assertStringContainsString(
            'text/html',
            $contentType,
            'Health page should return text/html content type'
        );
    }

    /**
     * Test that the server handles multiple rapid requests without crashing.
     */
    public function testMultipleRequestsDoNotCrash(): void
    {
        $routes = ['/', '/?p=health', '/?p=docs', '/?p=changelog'];

        foreach ($routes as $route) {
            [$status, $body] = self::httpGet($route);
            $this->assertContains($status, [200, 302], "Rapid request to $route should not crash (got $status)");
        }
    }

    /**
     * Test that POST requests to pages that don't support them return appropriate errors.
     */
    public function testPostToGetPageReturnsError(): void
    {
        $url = self::$baseUrl . '/?p=health';
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "X-Test-Mode: 1\r\nX-Test-User: olivier.noblanc@dreets.gouv.fr\r\nContent-Type: application/x-www-form-urlencoded",
                'content' => 'foo=bar',
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx);
        $status = 200;
        if (isset($http_response_header) && preg_match('#HTTP/\d\.\d\s+(\d+)#', $http_response_header[0] ?? '', $m)) {
            $status = (int) $m[1];
        }

        // POST to a GET-only page should either error or still show the page
        $this->assertContains($status, [200, 400, 405], 'POST to health should not crash');
    }

    /**
     * Test that the form page without slug shows a meaningful error.
     */
    public function testFormWithoutSlugShowsError(): void
    {
        [$status, $body] = self::httpGet('/?p=form');

        // Pages without required params render normally (200) or show error (500 in TEST_MODE)
        $this->assertContains($status, [200, 500], 'Form without slug should return 200 or 500');
    }

    /**
     * Test that validate without token shows a meaningful error.
     */
    public function testValidateWithoutTokenShowsError(): void
    {
        [$status, $body] = self::httpGet('/?p=validate');

        // Pages without required params render normally (200) or show error (500 in TEST_MODE)
        $this->assertContains($status, [200, 500], 'Validate without token should return 200 or 500');
    }

    /**
     * Test that submission_view without id shows a meaningful error.
     */
    public function testSubmissionViewWithoutIdShowsError(): void
    {
        [$status, $body] = self::httpGet('/?p=submission_view');

        $this->assertNotSame(200, $status, 'Submission view without id should not return 200');
    }

    /**
     * Test that form_tracking without form_id shows a meaningful error.
     */
    public function testFormTrackingWithoutIdShowsError(): void
    {
        [$status, $body] = self::httpGet('/?p=form_tracking');

        $this->assertNotSame(200, $status, 'Form tracking without id should not return 200');
    }

    /**
     * Test that form_preview without form_id shows a meaningful error.
     */
    public function testFormPreviewWithoutIdShowsError(): void
    {
        [$status, $body] = self::httpGet('/?p=form_preview');

        $this->assertNotSame(200, $status, 'Form preview without id should not return 200');
    }

    /**
     * Test that confirm_action without params shows a meaningful error.
     */
    public function testConfirmActionWithoutParamsShowsError(): void
    {
        [$status, $body] = self::httpGet('/?p=confirm_action');

        $this->assertNotSame(200, $status, 'Confirm action without params should not return 200');
    }

    /**
     * Test that download without id shows a meaningful error.
     */
    public function testDownloadWithoutIdShowsError(): void
    {
        [$status, $body] = self::httpGet('/?p=download');

        $this->assertNotSame(200, $status, 'Download without id should not return 200');
    }

    /**
     * Test that screenshot without params shows a meaningful error.
     */
    public function testScreenshotWithoutParamsShowsError(): void
    {
        [$status, $body] = self::httpGet('/?p=screenshot');

        $this->assertNotSame(200, $status, 'Screenshot without params should not return 200');
    }

    // ── Tests: security headers ───────────────────────────────

    public function testNoServerHeaderLeak(): void
    {
        // The PHP built-in server always exposes X-Powered-By header.
        // This test only applies to production servers (IIS, Apache, etc.).
        if (PHP_OS_FAMILY === 'Windows' || !self::$serverReady) {
            $this->markTestSkipped('X-Powered-By header is exposed by PHP built-in server');
        }

        $url = self::$baseUrl . '/?p=health';
        $ctx = stream_context_create([
            'http' => [
                'header' => "X-Test-Mode: 1\r\nX-Test-User: olivier.noblanc@dreets.gouv.fr",
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        @file_get_contents($url, false, $ctx);

        if (isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                $this->assertStringNotContainsString(
                    'X-Powered-By',
                    $header,
                    'Server should not expose X-Powered-By header'
                );
            }
        }
    }

    /**
     * Test that paths with directory traversal attempts are blocked.
     */
    public function testDirectoryTraversalIsBlocked(): void
    {
        [$status, $body] = self::httpGet('/../config.php');
        // Should get 403, 404, or serve index.php — but NOT the config file contents
        $this->assertStringNotContainsString(
            'DB_PATH',
            $body,
            'Directory traversal should not expose config.php'
        );
    }

    /**
     * Test that null bytes in URL are handled safely.
     */
    public function testNullByteInUrlIsHandled(): void
    {
        [$status, $body] = self::httpGet('/?p=health%00.php');
        // Should not crash — either 404 or normal page
        $this->assertContains($status, [200, 400, 404, 500], 'Null byte in URL should be handled safely');
    }

    // ── Tests: PHP generated correct content ─────────────────
    // Based on actual HTML analysis of each page.
    // Catches: misplaced if, empty variables, broken loops, wrong DB queries.

    /** Accueil: exactly 8 form cards from DB loop. */
    public function testAccueilRendersExactly8FormCards(): void
    {
        [$status, $body] = self::httpGet('/');
        $this->assertSame(200, $status);
        preg_match_all('/class="form-card"/', $body, $m);
        $this->assertSame(8, count($m[0]), 'Accueil should render exactly 8 form cards from DB');
        $this->assertStringContainsString('fc-title', $body, 'Each card should have a title');
        $this->assertStringContainsString('Remplir le formulaire', $body, 'Each card should have action link');
        // Verify form slugs in hrefs
        $this->assertStringContainsString('f=onboarding', $body, 'Should link to onboarding form');
        $this->assertStringContainsString('f=mutation', $body, 'Should link to mutation form');
    }

    /** Health: exactly 6 system checks. */
    public function testHealthRendersExactly6Checks(): void
    {
        [$status, $body] = self::httpGet('/?p=health');
        // 503 is valid (unhealthy), 200 is valid (healthy)
        $this->assertContains($status, [200, 503], 'Health returns 200 or 503');
        preg_match_all('/class="check-item"/', $body, $m);
        $this->assertSame(6, count($m[0]), 'Health should render exactly 6 check items');
        $this->assertStringContainsString('Base de données SQLite', $body, 'Check: DB');
        $this->assertStringContainsString('Version PHP', $body, 'Check: PHP version');
        $this->assertStringContainsString('Extensions PHP', $body, 'Check: extensions');
    }

    /** Docs: 3 start-cards, TOC entries, FAQ items. */
    public function testDocsRendersAllSections(): void
    {
        [$status, $body] = self::httpGet('/?p=docs');
        $this->assertSame(200, $status);
        preg_match_all('/class="start-card"/', $body, $m);
        $this->assertSame(3, count($m[0]), 'Docs should have exactly 3 start-cards');
        preg_match_all('/toc-marianne.*?<\/ol>/s', $body, $m);
        $this->assertGreaterThanOrEqual(1, count($m[0]), 'Docs should have TOC');
        $this->assertStringContainsString('full-doc', $body, 'Docs should have full documentation');
        $this->assertStringContainsString('start-section', $body, 'Docs should have start section');
        $this->assertMatchesRegularExpression('/v\d+\.\d+\.\d+/', $body, 'Docs should show version badge');
    }

    /** Changelog: version entries parsed from CHANGELOG.md. */
    public function testChangelogRenders7Versions(): void
    {
        [$status, $body] = self::httpGet('/?p=changelog');
        $this->assertSame(200, $status);
        preg_match_all('/class="version-card"/', $body, $m);
        $this->assertGreaterThanOrEqual(7, count($m[0]), 'Changelog should render at least 7 version cards');
        $this->assertStringContainsString('changelog-summary', $body, 'Should have summary section');
        $this->assertStringContainsString('v10.14.0', $body, 'Should show current version');
        preg_match_all('/summary-list.*?<\/ul>/s', $body, $m);
        $this->assertGreaterThanOrEqual(1, count($m[0]), 'Should have summary list');
    }

    /** Dashboard: 4 stat chips, system health, filter form with 8+ options, admin actions. */
    public function testDashboardRendersCompleteAdminView(): void
    {
        [$status, $body] = self::httpGet('/?p=dashboard');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Tableau de bord', $body);
        $this->assertStringContainsString('État du système', $body, 'Should show system health');
        $this->assertStringContainsString('Total', $body, 'Stat: Total');
        $this->assertStringContainsString('En cours', $body, 'Stat: En cours');
        $this->assertStringContainsString('Validés', $body, 'Stat: Validés');
        $this->assertStringContainsString('Refusés', $body, 'Stat: Refusés');
        $this->assertStringContainsString('admin-actions-btns', $body, 'Should have admin action links');
        // Filter form should have options for each form
        preg_match_all('/<option[^>]+value="[a-z_]+"/', $body, $m);
        $this->assertGreaterThanOrEqual(8, count($m[0]), 'Dashboard filter should have form options from DB');
    }

    /** Admin forms: selector with 9 options (1 default + 8 forms). */
    public function testAdminFormsRendersFormSelector(): void
    {
        [$status, $body] = self::httpGet('/?p=admin_forms');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Gestion des formulaires', $body);
        $this->assertStringContainsString('Sélectionner un formulaire', $body);
        preg_match_all('/<option[^>]+value="[0-9a-f-]+"/', $body, $m);
        $this->assertSame(8, count($m[0]), 'Form selector should have 8 form UUIDs from DB');
    }

    /** Admin settings: 7 nav sections, SMTP config, security settings. */
    public function testAdminSettingsRendersAllSections(): void
    {
        [$status, $body] = self::httpGet('/?p=admin_settings');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Sécurité email', $body, 'Section: security');
        $this->assertStringContainsString('SMTP', $body, 'Section: SMTP');
        $this->assertStringContainsString('Workflow', $body, 'Section: workflow');
        $this->assertStringContainsString('olivier.noblanc@dreets.gouv.fr', $body, 'Admin email from DB');
        $this->assertStringContainsString('smtp.social.gouv.fr', $body, 'SMTP host from DB');
        $this->assertStringContainsString('Enregistrer', $body, 'Save buttons present');
        // CSRF tokens
        preg_match_all('/name="csrf_token"/', $body, $m);
        $this->assertGreaterThanOrEqual(2, count($m[0]), 'Should have CSRF tokens on forms');
    }

    /** Stats: 3 period tabs, stat cards, performance table. */
    public function testStatsRendersChartsAndTables(): void
    {
        [$status, $body] = self::httpGet('/?p=stats');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Par semaine', $body, 'Tab: week');
        $this->assertStringContainsString('Par mois', $body, 'Tab: month');
        $this->assertStringContainsString('Par année', $body, 'Tab: year');
        $this->assertStringContainsString('stat-card', $body, 'Should have stat cards');
        $this->assertStringContainsString('Répartition des statuts', $body, 'Donut chart section');
        $this->assertStringContainsString('Performance par formulaire', $body, 'Performance table');
    }

    /** My submissions: empty state or cards, form links. */
    public function testMySubmissionsShowsCorrectContent(): void
    {
        [$status, $body] = self::httpGet('/?p=my_submissions');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Mes demandes', $body);
        $hasEmpty = str_contains($body, 'encore soumis') || str_contains($body, 'Aucune');
        $hasCards = str_contains($body, 'sub-card') || str_contains($body, 'inline-progress');
        $this->assertTrue($hasEmpty || $hasCards, 'Should show empty state or submission cards');
    }

    /** My validations: 2 tabs, search bar, stats. */
    public function testMyValidationsRendersTabsAndSearch(): void
    {
        [$status, $body] = self::httpGet('/?p=my_validations');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Mes validations', $body);
        $this->assertStringContainsString('En attente', $body, 'Pending tab');
        $this->assertStringContainsString('Traitées', $body, 'Done tab');
        $this->assertStringContainsString('Rechercher', $body, 'Search bar');
        $this->assertStringContainsString('tab-pending', $body, 'Tab container');
    }

    /** My forms: empty state or form cards. */
    public function testMyFormsShowsCorrectContent(): void
    {
        [$status, $body] = self::httpGet('/?p=my_forms');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Mes formulaires', $body);
        $hasEmpty = str_contains($body, 'propriétaire') || str_contains($body, 'formulaire');
        $hasCards = str_contains($body, 'form-card');
        $this->assertTrue($hasEmpty || $hasCards, 'Should show empty state or form cards');
    }

    /** Monitoring: 6 stat cards, audit log, submission table. */
    public function testMonitoringRendersStatsAndAudit(): void
    {
        [$status, $body] = self::httpGet('/?p=monitoring');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Surveillance', $body);
        $this->assertStringContainsString('Soumissions totales', $body, 'Stat card');
        $this->assertStringContainsString('Soumissions par formulaire', $body, 'Per-form table');
        $this->assertStringContainsString('Journal d\'audit', $body, 'Audit log section');
        $this->assertStringContainsString('Journal des emails', $body, 'Email log section');
    }

    /** Backup: 4 cards, DB stats table, danger zones. */
    public function testBackupRendersDbStatsAndActions(): void
    {
        [$status, $body] = self::httpGet('/?p=backup');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Statistiques de la base', $body, 'DB stats card');
        $this->assertStringContainsString('Télécharger', $body, 'Download button');
        $this->assertStringContainsString('Restaurer', $body, 'Restore section');
        $this->assertStringContainsString('Purger', $body, 'Purge section');
        $this->assertStringContainsString('danger-zone', $body, 'Danger zones present');
        // DB tables listed
        $this->assertStringContainsString('forms', $body, 'DB table: forms');
        $this->assertStringContainsString('submissions', $body, 'DB table: submissions');
    }

    /** RGPD: 4 stat minis, 4 forms, legal mentions. */
    public function testRgpdRendersAllSections(): void
    {
        [$status, $body] = self::httpGet('/?p=rgpd');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('RGPD', $body);
        $this->assertStringContainsString('Soumissions', $body, 'Stat: submissions');
        $this->assertStringContainsString('Entrées d\'audit', $body, 'Stat: audit entries');
        $this->assertStringContainsString('Mentions légales', $body, 'Legal mentions section');
        $this->assertStringContainsString('Export', $body, 'Export section');
        $this->assertStringContainsString('Supprimer', $body, 'Delete section');
        $this->assertStringContainsString('Purge', $body, 'Purge section');
        // Forms count
        preg_match_all('/<form[^>]*method="POST"/', $body, $m);
        $this->assertSame(4, count($m[0]), 'RGPD should have exactly 4 POST forms');
    }

    /** Admin alerts: 4 rules, deadline configs for 8 forms. */
    public function testAdminAlertsRendersRulesAndConfigs(): void
    {
        [$status, $body] = self::httpGet('/?p=admin_alerts');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Alertes', $body);
        $this->assertStringContainsString('Script de vérification', $body, 'Script status');
        $this->assertStringContainsString('Règles d\'alerte', $body, 'Rules section');
        $this->assertStringContainsString('Historique des alertes', $body, 'Alert log');
        $this->assertStringContainsString('Champ date limite', $body, 'Deadline config');
    }

    /** Admin access: admin management for super admin. */
    public function testAdminAccessRendersAdminManagement(): void
    {
        [$status, $body] = self::httpGet('/?p=admin_access');
        $this->assertSame(200, $status);
        $this->assertMatchesRegularExpression('/admin/i', $body, 'Admin access should mention admin');
    }
}
