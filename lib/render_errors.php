<?php
declare(strict_types=1);

/**
 * Error pages & user messages rendering — thin wrapper delegating to App\Render\ErrorRenderer.
 *
 * @package lib
 */

/**
 * Exception dédiée pour les pages d'erreur (A-22).
 */
class ErrorResponseException extends \Exception {
    public function __construct(
        public readonly int $httpCode,
        public readonly string $title,
        string $message,
        public readonly string $hint = '',
        public readonly string $backUrl = 'index.php'
    ) {
        parent::__construct($message, $httpCode);
    }

    public function getErrorTitle(): string { return $this->title; }
    public function getHint(): string { return $this->hint; }
    public function getBackUrl(): string { return $this->backUrl; }
}

/**
 * Displays a full HTML error page and stops execution.
 *
 * @param int    $code      HTTP code (403, 404, 400, 401, 500…)
 * @param string $title     Short title (e.g. "Accès refusé")
 * @param string $message   Descriptive message
 * @param string $hint      Advice / next steps (optional)
 * @param string $back_url  Back button URL (default: index.php)
 * @return never
 */
function render_error_page(int $code, string $title, string $message, string $hint = '', string $back_url = 'index.php'): never {
    static $renderer = null;
    if ($renderer === null) {
        $renderer = new \App\Render\ErrorRenderer();
    }
    $renderer->errorPage($code, $title, $message, $hint, $back_url);
}

/**
 * Displays success/error/info/warning messages.
 *
 * @param array<string, mixed> $messages
 */
function render_messages(array $messages = []): string {
    static $renderer = null;
    if ($renderer === null) {
        $renderer = new \App\Render\ErrorRenderer();
    }
    return $renderer->messages($messages);
}
