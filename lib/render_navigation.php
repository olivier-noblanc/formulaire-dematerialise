<?php
declare(strict_types=1);

/**
 * Navigation rendering — thin wrapper delegating to App\Render\NavigationRenderer.
 *
 * @package lib
 */

/**
 * Generates the shared navigation bar.
 * Alias of render_header() for backward compatibility.
 *
 * @param string $current_page  Current page identifier for active marking
 * @param array<string, mixed>  $extra_admin_links Additional admin links
 * @return string HTML of the <nav>
 */
function render_nav(string $current_page = '', array $extra_admin_links = []): string {
    static $renderer = null;
    if ($renderer === null) {
        $renderer = new \App\Render\NavigationRenderer();
    }
    return $renderer->nav($current_page, $extra_admin_links);
}

/**
 * Generates the complete header: sidebar + content opening.
 *
 * @param string $current_page  Current page identifier for active marking
 * @param array<string, mixed>  $extra_admin_links Additional admin links
 * @return string HTML of the complete header
 */
function render_header(string $current_page = '', array $extra_admin_links = []): string {
    static $renderer = null;
    if ($renderer === null) {
        $renderer = new \App\Render\NavigationRenderer();
    }
    return $renderer->header($current_page, $extra_admin_links);
}

/**
 * Generates a breadcrumb navigation.
 *
 * @param array<int, mixed> $breadcrumbs Array of [label, href] from top to bottom
 * @return string HTML of the breadcrumb
 */
function render_breadcrumb(array $breadcrumbs): string {
    static $renderer = null;
    if ($renderer === null) {
        $renderer = new \App\Render\NavigationRenderer();
    }
    return $renderer->breadcrumb($breadcrumbs);
}

/**
 * Generates the page footer with persona dropdown JS.
 */
function render_footer(): string {
    static $renderer = null;
    if ($renderer === null) {
        $renderer = new \App\Render\NavigationRenderer();
    }
    return $renderer->footer();
}

/**
 * Gets the application name from settings (cached).
 */
function get_app_name(): string {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = \App\Core\App::settings()->get('app_name', 'CircuitDémat');
    return $cache;
}

/**
 * Renders the favicon link tag.
 */
function render_favicon(): string {
    $svg = \App\Core\App::settings()->get('app_favicon', '');
    if (!empty($svg)) {
        return '<link rel="icon" href="data:image/svg+xml,' . h($svg) . '">';
    }
    return '<link rel="icon" href="data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 100 100\'><rect width=\'100\' height=\'100\' rx=\'20\' fill=\'%231E40AF\'/><text x=\'50\' y=\'78\' font-size=\'80\' text-anchor=\'middle\' fill=\'white\' font-family=\'Arial\'>&#9670;</text></svg>">';
}

/**
 * Generates a full HTML page (D1) — eliminates boilerplate duplication.
 *
 * @param array<string, mixed> $options Page options
 */
function render_page(
    string $title,
    string $nav_key,
    string $page_css  = '',
    string $content   = '',
    array  $options   = []
): string {
    static $renderer = null;
    if ($renderer === null) {
        $renderer = new \App\Render\NavigationRenderer();
    }
    return $renderer->page($title, $nav_key, $page_css, $content, $options);
}

/**
 * Rewrites URLs in rendered HTML to propagate ?persona_token.
 *
 * @param string $html The rendered HTML
 * @return string The HTML with rewritten URLs
 */
function persona_rewrite_urls(string $html): string {
    static $renderer = null;
    if ($renderer === null) {
        $renderer = new \App\Render\NavigationRenderer();
    }
    return $renderer->personaRewriteUrls($html);
}
