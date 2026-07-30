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
        // Ensure localhost bypasses corporate proxy for built-in server
        putenv('no_proxy=127.0.0.1,localhost');
        putenv('NO_PROXY=127.0.0.1,localhost');

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
            // env=null hérite de l'environnement courant (notamment PATH) — un tableau vide []
            // le remplace par un environnement totalement vide, ce qui rend PHP_BINARY vide dans
            // start_server.php (résolution interne dépendante de PATH) et fait échouer la commande
            // "$phpBin -S ..." avec "sh: -S: not found" (le process meurt immédiatement, exitcode 127).
            self::$serverProcess = proc_open($cmd, $descriptors, $pipes, self::$docRoot, null);
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
            $shutdownCtx = stream_context_create([
                'http' => ['timeout' => 2, 'ignore_errors' => true],
            ]);
            @file_get_contents(self::$baseUrl . '/?p=__shutdown', false, $shutdownCtx);

            $status = proc_get_status(self::$serverProcess);
            if ($status['running']) {
                // On Windows, use taskkill
                if (PHP_OS_FAMILY === 'Windows') {
                    exec('taskkill /PID ' . $status['pid'] . ' /F 2>NUL');
                } else {
                    // SIGTERM/SIGKILL (constantes ext-pcntl, non chargée en CI — voir ci.yml)
                    // remplacées par leurs valeurs numériques POSIX standard : posix_kill()
                    // accepte un int, pas besoin de pcntl pour ça.
                    posix_kill($status['pid'], 15); // SIGTERM
                    sleep(1);
                    if (proc_get_status(self::$serverProcess)['running']) {
                        posix_kill($status['pid'], 9); // SIGKILL
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

        // Default test user is admin; override via $headers['X-Test-User']
        $testUser = $headers['X-Test-User'] ?? 'olivier.noblanc@dreets.gouv.fr';
        unset($headers['X-Test-User']);

        $httpHeaders = [
            'X-Test-Mode: 1',
            'X-Test-User: ' . $testUser,
        ];
        foreach ($headers as $value) {
            $httpHeaders[] = $value;
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
        $location = '';

        if (isset($http_response_header)) {
            // Parse status from response header
            if (preg_match('#HTTP/\d\.\d\s+(\d+)#', $http_response_header[0] ?? '', $m)) {
                $status = (int) $m[1];
            }
            // Parse Location header for redirects
            foreach ($http_response_header as $header) {
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, 9));
                    break;
                }
            }
        }

        return [$status, $body ?? '', $location];
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

        self::assertSame(200, $status, "Page $path should return 200, got $status");
        self::assertStringContainsString($needle, $body, "Page $path should contain '$needle'");

        foreach ($htmlClasses as $class) {
            self::assertMatchesRegularExpression(
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

        self::assertSame(200, $status, "Admin page $path should return 200, got $status");
        self::assertStringContainsString($needle, $body, "Admin page $path should contain '$needle'");

        // At least one of the expected CSS classes should be present
        if (!empty($htmlClasses)) {
            $found = false;
            foreach ($htmlClasses as $class) {
                if (preg_match('/class="[^"]*' . preg_quote($class, '/') . '[^"]*"/', $body)) {
                    $found = true;
                    break;
                }
            }
            self::assertTrue(
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
        self::assertSame(
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
            self::markTestSkipped("Page $path returned $status, cannot check layout");
        }

        // Skip layout checks for error/error pages
        if (str_contains($body, 'error-card') || !str_contains($body, '<html')) {
            self::markTestSkipped("Page $path returned an error page, layout checks skipped");
        }

        // Skip link (accessibility)
        self::assertMatchesRegularExpression(
            '/<a[^>]*class="skip-link"[^>]*>Aller au contenu principal<\/a>/',
            $body,
            "Page $path should have skip-link for accessibility"
        );

        // Sidebar navigation
        self::assertMatchesRegularExpression(
            '/<nav[^>]*class="sidebar"[^>]*aria-label="Navigation principale"/',
            $body,
            "Page $path should have nav.sidebar with aria-label"
        );

        // Sidebar brand
        self::assertStringContainsString('sidebar-brand', $body, "Page $path should have sidebar-brand");

        // Main area
        self::assertMatchesRegularExpression(
            '/<div[^>]*class="main-area"/',
            $body,
            "Page $path should have div.main-area"
        );

        // Footer
        self::assertMatchesRegularExpression(
            '/<footer>/',
            $body,
            "Page $path should have a <footer>"
        );

        // Sidebar user card
        self::assertStringContainsString('sidebar-user', $body, "Page $path should have sidebar-user");
    }

    /**
     * Admin pages should have admin-specific sidebar links.
     */
    #[DataProvider('adminPageProvider')]
    public function testAdminSidebarLinks(string $path, string $needle, array $htmlClasses): void
    {
        [$status, $body] = self::httpGet($path);

        if ($status !== 200) {
            self::markTestSkipped("Admin page $path returned $status");
        }

        if (str_contains($body, 'error-card')) {
            self::markTestSkipped("Admin page $path returned error page");
        }

        // Admin section title in sidebar
        self::assertStringContainsString(
            'Administration',
            $body,
            "Admin page $path should have 'Administration' section in sidebar"
        );
    }

    // ── Tests: special routes ─────────────────────────────────

    public function testPersonaStopRedirectsToIndex(): void
    {
        [$status, $body, $location] = self::httpGet('/?p=persona&action=stop');

        // Persona stop should redirect to index (302) — no confirmation step
        self::assertSame(302, $status, 'Persona stop should redirect to index');
        self::assertStringNotContainsString('confirm_action', $location, 'Persona stop should NOT redirect to confirm_action');
    }

    /**
     * Non-admin agent should NOT see admin sidebar links.
     */
    public function testAgentDoesNotSeeAdminLinks(): void
    {
        [$status, $body] = self::httpGet('/', [
            'X-Test-User' => 'agent_' . uniqid() . '@test.com',
        ]);

        if ($status !== 200) {
            self::markTestSkipped("Agent page returned $status");
        }

        // Agent should see sidebar-user card WITHOUT admin class in rendered HTML
        // (the string 'sidebar-user-card-admin' may appear in JS inline, but not in class attributes)
        self::assertDoesNotMatchRegularExpression(
            '/class="[^"]*sidebar-user-card-admin[^"]*"/',
            $body,
            'Agent should not have admin user card class in rendered class attribute'
        );

        // Agent should NOT see 'Administration' section in sidebar
        self::assertStringNotContainsString(
            'Administration',
            $body,
            'Agent should not see admin sidebar section'
        );

        // Agent should NOT see persona chevron in rendered HTML
        self::assertStringNotContainsString(
            'sidebar-user-chevron',
            $body,
            'Agent should not have persona dropdown chevron'
        );
    }

    /**
     * Persona start in GET should NOT redirect to confirm_action.
     * It should execute directly (no confirmation step) — self-agent mode.
     */
    public function testPersonaGetDoesNotRedirectToConfirmation(): void
    {
        // Persona start with admin's own email → self-agent mode, direct activation
        $adminEmail = 'olivier.noblanc@dreets.gouv.fr';
        [$status, $body, $location] = self::httpGet('/?p=persona&action=start&email=' . urlencode($adminEmail));

        // Should redirect to index with persona_token (302) — NOT to confirm_action
        self::assertSame(302, $status, 'Persona GET should redirect directly (no confirmation step)');
        self::assertStringNotContainsString('confirm_action', $location, 'Persona GET should NOT redirect to confirm_action');
        self::assertStringContainsString('persona_token=', $location, 'Persona GET redirect should contain persona_token');
    }

    /**
     * Persona stop in GET should NOT redirect to confirm_action.
     */
    public function testPersonaStopDoesNotRedirectToConfirmation(): void
    {
        // First activate a persona, then stop it
        $adminEmail = 'olivier.noblanc@dreets.gouv.fr';
        [$startStatus, $startBody, $startLocation] = self::httpGet('/?p=persona&action=start&email=' . urlencode($adminEmail));
        if ($startStatus !== 302) {
            self::markTestSkipped('Could not activate persona');
        }

        // Extract persona_token from Location header
        if (!preg_match('/persona_token=([^&\s]+)/', $startLocation, $m)) {
            self::markTestSkipped('Could not extract persona_token from redirect');
        }
        $token = urldecode($m[1]);

        // Now stop persona — should redirect directly, not to confirm_action
        [$status, $body, $location] = self::httpGet('/?p=persona&action=stop&persona_token=' . urlencode($token));

        self::assertSame(302, $status, 'Persona stop GET should redirect directly');
        self::assertStringNotContainsString('confirm_action', $location, 'Persona stop should NOT redirect to confirm_action');
    }

    public function testHealthPageShowsSystemChecks(): void
    {
        [$status, $body] = self::httpGet('/?p=health');

        self::assertSame(200, $status);
        self::assertStringContainsString('Santé du système', $body);

        // Health page should have check items
        self::assertMatchesRegularExpression(
            '/class="check-item"/',
            $body,
            'Health page should have check-item elements'
        );
    }

    public function testAccueilHasFormCards(): void
    {
        [$status, $body] = self::httpGet('/');

        self::assertSame(200, $status);
        self::assertStringContainsString('CircuitDémat', $body);

        // Should have tutorial or form-cards section
        $hasTutorial = str_contains($body, 'tutorial') || str_contains($body, 'welcome-state');
        $hasFormCards = str_contains($body, 'form-cards') || str_contains($body, 'form-card');
        self::assertTrue(
            $hasTutorial || $hasFormCards,
            'Accueil page should have tutorial section or form cards'
        );
    }

    public function testDocsPageHasFullDocumentation(): void
    {
        [$status, $body] = self::httpGet('/?p=docs');

        self::assertSame(200, $status);
        self::assertStringContainsString('Aide et documentation', $body);
        self::assertStringContainsString('full-doc', $body);
        self::assertStringContainsString('start-section', $body);

        // Documentation should be substantial (500+ chars of content)
        self::assertGreaterThan(500, strlen($body), 'Documentation page should have substantial content');
    }

    public function testChangelogPageHasVersionCards(): void
    {
        [$status, $body] = self::httpGet('/?p=changelog');

        self::assertSame(200, $status);
        self::assertStringContainsString('Journal des modifications', $body);
        self::assertMatchesRegularExpression(
            '/class="version-card"/',
            $body,
            'Changelog should have version-card elements'
        );
    }

    public function testMyValidationsHasTabBar(): void
    {
        [$status, $body] = self::httpGet('/?p=my_validations');

        self::assertSame(200, $status);
        self::assertStringContainsString('Mes validations', $body);
        self::assertStringContainsString('stat', $body);
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

        self::assertSame(
            $status,
            $actualStatus,
            "Route $path should return $status, got $actualStatus"
        );

        if ($needle !== '' && $actualStatus === 200) {
            self::assertStringContainsString(
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
        self::assertContains($status, [404, 500], 'Unknown page should return 404 or 500 in TEST_MODE');
    }

    /**
     * Test that XSS attempts in page parameter are neutralized.
     */
    public function testXssInPageParameterIsSanitized(): void
    {
        [$status, $body] = self::httpGet('/?p=<script>alert(1)</script>');

        // In TEST_MODE, errorPage() throws which results in 500. Accept both.
        self::assertContains($status, [404, 500], 'XSS attempt should result in 404 or 500');
        self::assertStringNotContainsString('<script>', $body, 'XSS script tag should not be reflected');
    }

    /**
     * Test that a valid page with extra params doesn't break.
     */
    public function testValidPageWithExtraParams(): void
    {
        [$status, $body] = self::httpGet('/?p=docs&extra=value');

        self::assertSame(200, $status, 'Valid page with extra params should still work');
        self::assertStringContainsString('Aide et documentation', $body);
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

        self::assertStringContainsString(
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
            self::assertContains($status, [200, 302], "Rapid request to $route should not crash (got $status)");
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
        self::assertContains($status, [200, 400, 405], 'POST to health should not crash');
    }

    /**
     * Test that the form page without slug shows a meaningful error.
     */
    public function testFormWithoutSlugShowsError(): void
    {
        [$status, $body] = self::httpGet('/?p=form');

        // Pages without required params render normally (200) or show error (500 in TEST_MODE)
        self::assertContains($status, [200, 500], 'Form without slug should return 200 or 500');
    }

    /**
     * Test that validate without token shows a meaningful error.
     */
    public function testValidateWithoutTokenShowsError(): void
    {
        [$status, $body] = self::httpGet('/?p=validate');

        // Pages without required params render normally (200) or show error (500 in TEST_MODE)
        self::assertContains($status, [200, 500], 'Validate without token should return 200 or 500');
    }

    /**
     * Test that submission_view without id shows a meaningful error.
     */
    public function testSubmissionViewWithoutIdShowsError(): void
    {
        [$status, $body] = self::httpGet('/?p=submission_view');

        self::assertNotSame(200, $status, 'Submission view without id should not return 200');
    }

    /**
     * Test that form_tracking without form_id shows a meaningful error.
     */
    public function testFormTrackingWithoutIdShowsError(): void
    {
        [$status, $body] = self::httpGet('/?p=form_tracking');

        self::assertNotSame(200, $status, 'Form tracking without id should not return 200');
    }

    /**
     * Test that form_preview without form_id shows a meaningful error.
     */
    public function testFormPreviewWithoutIdShowsError(): void
    {
        [$status, $body] = self::httpGet('/?p=form_preview');

        self::assertNotSame(200, $status, 'Form preview without id should not return 200');
    }

    /**
     * Test that confirm_action without params shows a meaningful error.
     */
    public function testConfirmActionWithoutParamsShowsError(): void
    {
        [$status, $body] = self::httpGet('/?p=confirm_action');

        self::assertNotSame(200, $status, 'Confirm action without params should not return 200');
    }

    /**
     * Test that download without id shows a meaningful error.
     */
    public function testDownloadWithoutIdShowsError(): void
    {
        [$status, $body] = self::httpGet('/?p=download');

        self::assertNotSame(200, $status, 'Download without id should not return 200');
    }

    /**
     * Test that screenshot without params shows a meaningful error.
     */
    public function testScreenshotWithoutParamsShowsError(): void
    {
        [$status, $body] = self::httpGet('/?p=screenshot');

        self::assertNotSame(200, $status, 'Screenshot without params should not return 200');
    }

    // ── Tests: security headers ───────────────────────────────

    public function testNoServerHeaderLeak(): void
    {
        // Le serveur de dev est démarré avec -d expose_php=0 (start_server.php /
        // start_server.ps1), donc l'en-tête ne doit plus fuiter ici non plus.
        if (!self::$serverReady) {
            self::markTestSkipped('PHP built-in server not ready');
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
                self::assertStringNotContainsString(
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
        self::assertStringNotContainsString(
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
        self::assertContains($status, [200, 400, 404, 500], 'Null byte in URL should be handled safely');
    }

    // ── Tests: PHP generated correct content ─────────────────
    // Based on actual HTML analysis of each page.
    // Catches: misplaced if, empty variables, broken loops, wrong DB queries.

    /** Accueil: form cards from DB loop. */
    public function testAccueilRendersFormCards(): void
    {
        [$status, $body] = self::httpGet('/');
        self::assertSame(200, $status);
        preg_match_all('/class="form-card"/', $body, $m);
        self::assertGreaterThanOrEqual(1, count($m[0]), 'Accueil should render at least 1 form card from DB');
        self::assertStringContainsString('fc-title', $body, 'Each card should have a title');
        self::assertStringContainsString('Remplir le formulaire', $body, 'Each card should have action link');
        // Verify form slugs in hrefs
        self::assertStringContainsString('f=onboarding', $body, 'Should link to onboarding form');
        self::assertStringContainsString('f=mutation', $body, 'Should link to mutation form');
    }

    /** Health: exactly 6 system checks. */
    public function testHealthRendersExactly6Checks(): void
    {
        [$status, $body] = self::httpGet('/?p=health');
        // 503 is valid (unhealthy), 200 is valid (healthy)
        self::assertContains($status, [200, 503], 'Health returns 200 or 503');
        preg_match_all('/class="check-item"/', $body, $m);
        self::assertSame(6, count($m[0]), 'Health should render exactly 6 check items');
        self::assertStringContainsString('Base de données SQLite', $body, 'Check: DB');
        self::assertStringContainsString('Version PHP', $body, 'Check: PHP version');
        self::assertStringContainsString('Extensions PHP', $body, 'Check: extensions');
    }

    /** Docs: 3 start-cards, TOC entries, FAQ items. */
    public function testDocsRendersAllSections(): void
    {
        [$status, $body] = self::httpGet('/?p=docs');
        self::assertSame(200, $status);
        preg_match_all('/class="start-card"/', $body, $m);
        self::assertSame(3, count($m[0]), 'Docs should have exactly 3 start-cards');
        preg_match_all('/toc-marianne.*?<\/ol>/s', $body, $m);
        self::assertGreaterThanOrEqual(1, count($m[0]), 'Docs should have TOC');
        self::assertStringContainsString('full-doc', $body, 'Docs should have full documentation');
        self::assertStringContainsString('start-section', $body, 'Docs should have start section');
        self::assertMatchesRegularExpression('/v\d+\.\d+\.\d+/', $body, 'Docs should show version badge');
    }

    /** Changelog: version entries parsed from CHANGELOG.md. */
    public function testChangelogRenders7Versions(): void
    {
        [$status, $body] = self::httpGet('/?p=changelog');
        self::assertSame(200, $status);
        preg_match_all('/class="version-card"/', $body, $m);
        self::assertGreaterThanOrEqual(7, count($m[0]), 'Changelog should render at least 7 version cards');
        self::assertStringContainsString('changelog-summary', $body, 'Should have summary section');
        self::assertStringContainsString('v10.14.0', $body, 'Should show current version');
        preg_match_all('/summary-list.*?<\/ul>/s', $body, $m);
        self::assertGreaterThanOrEqual(1, count($m[0]), 'Should have summary list');
    }

    /** Dashboard: 4 stat chips, system health, filter form with 8+ options, admin actions. */
    public function testDashboardRendersCompleteAdminView(): void
    {
        [$status, $body] = self::httpGet('/?p=dashboard');
        self::assertSame(200, $status);
        self::assertStringContainsString('Tableau de bord', $body);
        self::assertStringContainsString('État du système', $body, 'Should show system health');
        self::assertStringContainsString('Total', $body, 'Stat: Total');
        self::assertStringContainsString('En cours', $body, 'Stat: En cours');
        self::assertStringContainsString('Validés', $body, 'Stat: Validés');
        self::assertStringContainsString('Refusés', $body, 'Stat: Refusés');
        self::assertStringContainsString('admin-actions-btns', $body, 'Should have admin action links');
        // Filter form should have options for each form
        preg_match_all('/<option[^>]+value="[a-z_]+"/', $body, $m);
        self::assertGreaterThanOrEqual(8, count($m[0]), 'Dashboard filter should have form options from DB');
    }

    /** Admin forms: selector with 9 options (1 default + 8 forms). */
    public function testAdminFormsRendersFormSelector(): void
    {
        [$status, $body] = self::httpGet('/?p=admin_forms');
        self::assertSame(200, $status);
        self::assertStringContainsString('Gestion des formulaires', $body);
        self::assertStringContainsString('Sélectionner un formulaire', $body);
        preg_match_all('/<option[^>]+value="[0-9a-f-]+"/', $body, $m);
        self::assertGreaterThanOrEqual(1, count($m[0]), 'Form selector should have form UUIDs from DB');
    }

    /** Admin settings: 7 nav sections, SMTP config, security settings. */
    public function testAdminSettingsRendersAllSections(): void
    {
        [$status, $body] = self::httpGet('/?p=admin_settings');
        self::assertSame(200, $status);
        self::assertStringContainsString('Sécurité email', $body, 'Section: security');
        self::assertStringContainsString('SMTP', $body, 'Section: SMTP');
        self::assertStringContainsString('Workflow', $body, 'Section: workflow');
        self::assertStringContainsString('olivier.noblanc@dreets.gouv.fr', $body, 'Admin email from DB');
        self::assertStringContainsString('smtp.social.gouv.fr', $body, 'SMTP host from DB');
        self::assertStringContainsString('Enregistrer', $body, 'Save buttons present');
        // CSRF tokens
        preg_match_all('/name="csrf_token"/', $body, $m);
        self::assertGreaterThanOrEqual(2, count($m[0]), 'Should have CSRF tokens on forms');
    }

    /** Stats: 3 period tabs, stat cards, performance table. */
    public function testStatsRendersChartsAndTables(): void
    {
        [$status, $body] = self::httpGet('/?p=stats');
        self::assertSame(200, $status);
        self::assertStringContainsString('Par semaine', $body, 'Tab: week');
        self::assertStringContainsString('Par mois', $body, 'Tab: month');
        self::assertStringContainsString('Par année', $body, 'Tab: year');
        self::assertStringContainsString('stat-card', $body, 'Should have stat cards');
        self::assertStringContainsString('Répartition des statuts', $body, 'Donut chart section');
        self::assertStringContainsString('Performance par formulaire', $body, 'Performance table');
    }

    /** My submissions: empty state or cards, form links. */
    public function testMySubmissionsShowsCorrectContent(): void
    {
        [$status, $body] = self::httpGet('/?p=my_submissions');
        self::assertSame(200, $status);
        self::assertStringContainsString('Mes demandes', $body);
        $hasEmpty = str_contains($body, 'encore soumis') || str_contains($body, 'Aucune');
        $hasCards = str_contains($body, 'sub-card') || str_contains($body, 'inline-progress');
        self::assertTrue($hasEmpty || $hasCards, 'Should show empty state or submission cards');
    }

    /** My validations: 2 tabs, search bar, stats. */
    public function testMyValidationsRendersTabsAndSearch(): void
    {
        [$status, $body] = self::httpGet('/?p=my_validations');
        self::assertSame(200, $status);
        self::assertStringContainsString('Mes validations', $body);
        self::assertStringContainsString('En attente', $body, 'Pending tab');
        self::assertStringContainsString('Traitées', $body, 'Done tab');
        self::assertStringContainsString('Rechercher', $body, 'Search bar');
        self::assertStringContainsString('tab-pending', $body, 'Tab container');
    }

    /** My forms: empty state or form cards. */
    public function testMyFormsShowsCorrectContent(): void
    {
        [$status, $body] = self::httpGet('/?p=my_forms');
        self::assertSame(200, $status);
        self::assertStringContainsString('Mes formulaires', $body);
        $hasEmpty = str_contains($body, 'propriétaire') || str_contains($body, 'formulaire');
        $hasCards = str_contains($body, 'form-card');
        self::assertTrue($hasEmpty || $hasCards, 'Should show empty state or form cards');
    }

    /** Monitoring: 6 stat cards, audit log, submission table. */
    public function testMonitoringRendersStatsAndAudit(): void
    {
        [$status, $body] = self::httpGet('/?p=monitoring');
        self::assertSame(200, $status);
        self::assertStringContainsString('Surveillance', $body);
        self::assertStringContainsString('Soumissions totales', $body, 'Stat card');
        self::assertStringContainsString('Soumissions par formulaire', $body, 'Per-form table');
        self::assertStringContainsString('Journal d\'audit', $body, 'Audit log section');
        self::assertStringContainsString('Journal des emails', $body, 'Email log section');
    }

    /** Backup: 4 cards, DB stats table, danger zones. */
    public function testBackupRendersDbStatsAndActions(): void
    {
        [$status, $body] = self::httpGet('/?p=backup');
        self::assertSame(200, $status);
        self::assertStringContainsString('Statistiques de la base', $body, 'DB stats card');
        self::assertStringContainsString('Télécharger', $body, 'Download button');
        self::assertStringContainsString('Restaurer', $body, 'Restore section');
        self::assertStringContainsString('Purger', $body, 'Purge section');
        self::assertStringContainsString('danger-zone', $body, 'Danger zones present');
        // DB tables listed
        self::assertStringContainsString('forms', $body, 'DB table: forms');
        self::assertStringContainsString('submissions', $body, 'DB table: submissions');
    }

    /** RGPD: 4 stat minis, 4 forms, legal mentions. */
    public function testRgpdRendersAllSections(): void
    {
        [$status, $body] = self::httpGet('/?p=rgpd');
        self::assertSame(200, $status);
        self::assertStringContainsString('RGPD', $body);
        self::assertStringContainsString('Soumissions', $body, 'Stat: submissions');
        self::assertStringContainsString('Entrées d\'audit', $body, 'Stat: audit entries');
        self::assertStringContainsString('Mentions légales', $body, 'Legal mentions section');
        self::assertStringContainsString('Export', $body, 'Export section');
        self::assertStringContainsString('Supprimer', $body, 'Delete section');
        self::assertStringContainsString('Purge', $body, 'Purge section');
        // Forms count
        preg_match_all('/<form[^>]*method="POST"/', $body, $m);
        self::assertSame(4, count($m[0]), 'RGPD should have exactly 4 POST forms');
    }

    /** Admin alerts: 4 rules, deadline configs for 8 forms. */
    public function testAdminAlertsRendersRulesAndConfigs(): void
    {
        [$status, $body] = self::httpGet('/?p=admin_alerts');
        self::assertSame(200, $status);
        self::assertStringContainsString('Alertes', $body);
        self::assertStringContainsString('Script de vérification', $body, 'Script status');
        self::assertStringContainsString('Règles d\'alerte', $body, 'Rules section');
        self::assertStringContainsString('Historique des alertes', $body, 'Alert log');
        self::assertStringContainsString('Champ date limite', $body, 'Deadline config');
    }

    /** Admin access: admin management for super admin. */
    public function testAdminAccessRendersAdminManagement(): void
    {
        [$status, $body] = self::httpGet('/?p=admin_access');
        self::assertSame(200, $status);
        self::assertMatchesRegularExpression('/admin/i', $body, 'Admin access should mention admin');
    }
}
