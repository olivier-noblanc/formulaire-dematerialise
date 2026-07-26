<?php

declare(strict_types=1);

namespace App\Render;

use App\Core\App;

/**
 * Exception dédiée pour les pages d'erreur (A-22).
 */
class ErrorResponseException extends \Exception
{
    public function __construct(
        int $httpCode,
        public readonly string $title,
        string $message,
        public readonly string $hint = '',
        public readonly string $backUrl = 'index.php'
    ) {
        parent::__construct($message, $httpCode);
    }
}

/**
 * Error pages & user messages rendering.
 */
final class ErrorRenderer
{
    /**
     * Displays a full HTML error page and stops execution.
     *
     * @param int    $code      HTTP code (403, 404, 400, 401, 500…)
     * @param string $title     Short title (e.g. "Accès refusé")
     * @param string $message   Descriptive message
     * @param string $hint      Advice / next steps (optional)
     * @param string $back_url  Back button URL (default: index.php)
     */
    public function errorPage(int $code, string $title, string $message, string $hint = '', string $back_url = 'index.php'): void
    {
        http_response_code($code);

        if (!headers_sent()) {
            App::security()->sendSecurityHeaders();
        }

        $icons = $this->getErrorIcons();
        $icon = $icons[$code] ?? $icons[500];

        $hint_html = '';
        if ($hint !== '' && $hint !== '0') {
            $hint_html = '<div class="error-hint"><strong>Que faire ?</strong>' . nl2br(\App\Core\App::html()->escape($hint)) . '</div>';
        }

        $user = '';
        try {
            $user = App::auth()->getUser();
        } catch (\Throwable $e) {
            $user = '';
            error_log('render_error_page auth error: ' . $e->getMessage());
        }

        $bandeau_links = '';
        if ($user !== '' && $user !== '0') {
            $bandeau_links = '<span>Connecté en tant que : <strong>' . \App\Core\App::html()->escape($user) . '</strong></span>
    <span><a href="index.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;">Accueil</a></span>';
        }

        $css = $this->loadCss();

        $error_html = '<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>' . \App\Core\App::html()->escape($title) . ' — ' . \App\Core\App::html()->escape(NavigationRenderer::getAppName()) . '</title>
  ' . NavigationRenderer::favicon() . '
  ' . $css . '
</head>
<body>
<a href="#main-content" class="skip-link">Aller au contenu principal</a>
<div class="bandeau">
  <strong>DREETS</strong> — Direction Régionale de l\'Économie, de l\'Emploi, du Travail et des Solidarités
  ' . $bandeau_links . '
</div>
<div class="error-page" id="main-content">
  <div class="error-card">
    <div class="error-illustration">' . $icon . '</div>
    <div class="error-code code-' . $code . '">' . $code . '</div>
    <h1>' . \App\Core\App::html()->escape($title) . '</h1>
    <p class="error-message">' . \App\Core\App::html()->escape($message) . '</p>
    ' . $hint_html . '
    <div class="error-actions">
      <a href="' . \App\Core\App::html()->escape($back_url) . '" class="btn btn-primary">Retour à l\'accueil</a>
    </div>
    <div class="error-stamp">' . \App\Core\App::html()->escape(NavigationRenderer::getAppName()) . '</div>
  </div>
</div>
' . new NavigationRenderer()->footer() . '
</body>
</html>';

        /** @phpstan-ignore-next-line booleanAnd.leftAlwaysTrue */
        if (TEST_MODE && php_sapi_name() !== 'cli') {
            throw new ErrorResponseException($code, $title, $message, $hint, $back_url);
        }
        // B-EXIT (audit 2026-07-26) : mode 'no-exit' pour tests PHPUnit en CLI.
        // Capture l'erreur dans $GLOBALS au lieu d'exit — permet de tester les
        // controllers qui appellent errorPage() sans crasher PHPUnit.
        if (isset($GLOBALS['_test_no_exit']) && $GLOBALS['_test_no_exit'] === true) {
            $GLOBALS['_test_error_page'] = [
                'code' => $code,
                'title' => $title,
                'message' => $message,
                'hint' => $hint,
                'back_url' => $back_url,
            ];
            return; // errorPage() est déclarée :never — on return pour le no-exit
        }
        echo $error_html;
        exit(1);
    }

    /**
     * Displays success/error/info/warning messages.
     *
     * @param array<string, mixed> $messages
     */
    public function messages(array $messages = []): string
    {
        $html = '';
        foreach ($messages as $type => $text) {
            if (empty($text)) {
                continue;
            }
            $class = match ($type) {
                'success' => 'msg-success',
                'error'   => 'msg-error',
                'info'    => 'msg-info',
                'warning' => 'msg-warning',
                default   => 'msg-info',
            };
            $aria = match ($type) {
                'error'   => ' role="alert" aria-live="assertive"',
                'success', 'info', 'warning' => ' role="status" aria-live="polite"',
                default   => ' role="status" aria-live="polite"',
            };
            $html .= '<div class="' . $class . '"' . $aria . '>' . \App\Core\App::html()->escape($text) . '</div>';
        }
        return $html;
    }

    /**
     * @return array<int, string>
     */
    private function getErrorIcons(): array
    {
        return [
            403 => '<svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="42" stroke="#c0392b" stroke-width="5" fill="#fde8e8"/><rect x="38" y="28" width="24" height="28" rx="4" fill="#c0392b"/><circle cx="50" cy="30" r="2.5" fill="#fde8e8"/><path d="M50 42v8" stroke="#fde8e8" stroke-width="3" stroke-linecap="round"/><circle cx="50" cy="56" r="2" fill="#fde8e8"/><path d="M30 72 Q50 65 70 72" stroke="#c0392b" stroke-width="3" fill="none" stroke-linecap="round"/></svg>',
            404 => '<svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="42" stroke="#003189" stroke-width="5" fill="#e8eaf6"/><path d="M30 70 L50 30 L70 70" stroke="#003189" stroke-width="4" fill="none" stroke-linecap="round" stroke-linejoin="round"/><line x1="38" y1="58" x2="62" y2="58" stroke="#003189" stroke-width="4" stroke-linecap="round"/><circle cx="50" cy="26" r="3" fill="#003189"/></svg>',
            400 => '<svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="42" stroke="#b45309" stroke-width="5" fill="#fff3e0"/><path d="M50 30v24" stroke="#b45309" stroke-width="5" stroke-linecap="round"/><circle cx="50" cy="66" r="3.5" fill="#b45309"/></svg>',
            401 => '<svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="42" stroke="#003189" stroke-width="5" fill="#e8eaf6"/><rect x="38" y="42" width="24" height="22" rx="3" fill="#003189"/><path d="M42 42V36 a8 8 0 0 1 16 0v6" stroke="#003189" stroke-width="3" fill="none" stroke-linecap="round"/><circle cx="50" cy="52" r="2.5" fill="#e8eaf6"/></svg>',
            500 => '<svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="42" stroke="#c0392b" stroke-width="5" fill="#fde8e8"/><path d="M32 38 Q40 32 50 38 Q60 44 68 38" stroke="#c0392b" stroke-width="3" fill="none" stroke-linecap="round"/><path d="M32 56 Q40 50 50 56 Q60 62 68 56" stroke="#c0392b" stroke-width="3" fill="none" stroke-linecap="round"/><path d="M35 72 Q50 64 65 72" stroke="#c0392b" stroke-width="3" fill="none" stroke-linecap="round"/></svg>',
        ];
    }

    private function loadCss(): string
    {
        $css = '';
        $style_file = __DIR__ . '/../../style.php';
        if (file_exists($style_file)) {
            ob_start();
            require $style_file;
            $css = ob_get_clean();
        }
        if (in_array(trim(strip_tags((string) $css)), ['', '0'], true)) {
            $css = '<style>*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}body{font-family:"Marianne",Arial,sans-serif;background:#f5f5fe;color:#1e1e1e}.bandeau{background:#003189;color:#fff;padding:.75rem 2rem;font-size:.85rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem}.bandeau a{color:#b3c8f0;font-size:.8rem;text-decoration:none}.btn{padding:.5rem 1rem;border:none;border-radius:3px;font-size:.85rem;font-family:inherit;cursor:pointer;text-decoration:none;display:inline-block}.btn-primary{background:#003189;color:#fff}.btn-primary:hover{background:#002270}.skip-link{position:absolute;left:-9999px;top:0;background:#003189;color:#fff;padding:.5rem 1rem;z-index:9999}.skip-link:focus{left:0}.error-page{display:flex;min-height:calc(100vh - 120px);align-items:center;justify-content:center;padding:2rem 1rem}.error-card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:3rem 2.5rem;max-width:560px;width:100%;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.06)}.error-card .error-code{font-size:5rem;font-weight:900;line-height:1;margin-bottom:.25rem;letter-spacing:-2px}.error-card .error-code.code-403{color:#c0392b}.error-card .error-code.code-404{color:#003189}.error-card .error-code.code-400{color:#b45309}.error-card .error-code.code-401{color:#003189}.error-card .error-code.code-500{color:#c0392b}.error-card .error-illustration{margin-bottom:1.25rem}.error-card .error-illustration svg{width:100px;height:100px}.error-card h1{font-size:1.35rem;color:#1e1e1e;margin-bottom:.75rem;border:none;padding:0}.error-card .error-message{color:#555;font-size:.95rem;line-height:1.6;margin-bottom:1.25rem}.error-card .error-hint{font-size:.85rem;color:#666;background:#f5f5fe;border:1px solid #e0e0f0;border-radius:6px;padding:1rem 1.25rem;margin-bottom:1.5rem;text-align:left;line-height:1.55}.error-card .error-hint strong{color:#333;display:block;margin-bottom:.35rem}.error-card .error-actions{display:flex;gap:.75rem;justify-content:center;margin-bottom:1.5rem}.error-card .error-stamp{font-size:.7rem;color:#aaa;margin-top:.5rem}footer{padding:1.5rem 2rem;text-align:center;font-size:.75rem;color:#888;border-top:1px solid #eee}</style>';
        }
        return (string) $css;
    }
}
