<?php
declare(strict_types=1);

namespace App\Render;

use App\Core\App;
use App\Enum\AssetType;

/**
 * Page rendering — full HTML page composition.
 *
 * Extracted from NavigationRenderer (H-01, 2026-08-05).
 * Handles page layout (DOCTYPE, head, body wrapper) and persona URL rewriting.
 */
final class PageRenderer
{
    /**
     * Generates a full HTML page (D1) — eliminates boilerplate duplication.
     *
     * @param array<string, mixed> $options Page options
     */
    public function page(
        string $title,
        string $nav_key,
        string $page_css  = '',
        string $content   = '',
        array  $options   = []
    ): string {
        $container_class = $options['container_class'] ?? 'container';
        $body_attr       = $options['body_attr'] ?? '';
        $before_main     = $options['before_main'] ?? '';
        $after_main      = $options['after_main'] ?? '';
        $nav_extra       = $options['nav_extra'] ?? [];
        $raw_title       = $options['raw_title'] ?? false;

        $page_body_class = 'page-' . preg_replace('/[^a-z0-9_-]/i', '', $nav_key);
        if ($body_attr) {
            if (preg_match('/class=["\']/', (string) $body_attr)) {
                $body_attr = preg_replace('/class=["\']/', 'class="' . $page_body_class . ' ', (string) $body_attr, 1);
            } else {
                $body_attr = 'class="' . $page_body_class . '" ' . $body_attr;
            }
        } else {
            $body_attr = 'class="' . $page_body_class . '"';
        }

        $full_title = $raw_title ? $title : ($title . ' — ' . App::html()->escape(NavigationRenderer::getAppName()));

        ob_start();
        if (!headers_sent()) {
            App::security()->sendSecurityHeaders();
        }
        ?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $full_title ?></title>
  <?= NavigationRenderer::favicon() ?>
  <link rel="stylesheet" href="<?= App::html()->assetUrl(AssetType::Css) ?>">
</head>
<body<?= $body_attr ? ' ' . $body_attr : '' ?>>
<a href="#main-content" class="skip-link">Aller au contenu principal</a>
<?= new NavigationRenderer()->nav($nav_key, $nav_extra) ?>
<?= $before_main ?>
<main class="<?= App::html()->escape($container_class) ?>" id="main-content">
<?= $content ?>
</main>
<?= $after_main ?>
<?= new NavigationRenderer()->footer() ?>
<script src="<?= App::html()->assetUrl(AssetType::Js, 'app') ?>" nonce="<?= App::security()->getScriptNonce() ?>"></script>
</body>
</html>
        <?php
        $page_out = ob_get_clean();
        if ($page_out === false) {
            return '';
        }

        return $this->personaRewriteUrls($page_out);
    }

    /**
     * Rewrites URLs in rendered HTML to propagate ?persona_token.
     */
    public function personaRewriteUrls(string $html): string
    {
        $token = isset($_GET['persona_token']) ? (string) $_GET['persona_token'] : '';
        if ($token === '') {
            return $html;
        }

        return preg_replace_callback(
            '/href=(["\'])(index\.php[^"\']*?)\1/',
            function (array $m) use ($token): string {
                $quote = $m[1];
                $url = $m[2];
                if (str_contains($url, '<?')) {
                    return $m[0];
                }
                if (str_contains($url, 'p=persona')) {
                    return $m[0];
                }
                if (str_contains($url, 'persona_token=')) {
                    return $m[0];
                }

                $anchor = '';
                $url_main = $url;
                $pos = strpos($url, '#');
                if ($pos !== false) {
                    $anchor = substr($url, $pos);
                    $url_main = substr($url, 0, $pos);
                }

                $sep = str_contains($url_main, '?') ? '&' : '?';
                return 'href=' . $quote . $url_main . $sep . 'persona_token=' . urlencode($token) . $anchor . $quote;
            },
            $html
        ) ?? $html;
    }
}
