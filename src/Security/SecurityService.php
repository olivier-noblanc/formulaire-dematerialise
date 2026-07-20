<?php

declare(strict_types=1);

namespace App\Security;

use App\Contract\SecurityInterface;
use App\Render\HtmlService;

/**
 * Service de sécurité : CSRF, headers HTTP.
 */
final class SecurityService implements SecurityInterface
{
    private string $scriptNonce = '';

    public function __construct(private readonly HtmlService $htmlService)
    {
    }

    public function sendSecurityHeaders(): void
    {
        if (php_sapi_name() === 'cli') {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        $this->scriptNonce = bin2hex(random_bytes(16));
        $nonceAttr = "nonce=\"{$this->scriptNonce}\"";
        $csp = "default-src 'self'; script-src 'self' 'unsafe-inline' {$nonceAttr}; style-src 'self' 'unsafe-inline' {$nonceAttr}; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none';";
        header('Content-Security-Policy: ' . $csp);

        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        if ($isSecure) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
    }

    public function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public function csrfField(): string
    {
        $token = $this->generateCsrfToken();
        $html = '<input type="hidden" name="csrf_token" value="' . $this->htmlService->h($token) . '">';

        // v10.0.0 — Persona token-based : propager ?persona_token dans les POST
        // via un champ hidden, pour que le persona persiste après un submit
        $persona_token = isset($_GET['persona_token']) ? (string) $_GET['persona_token'] : '';
        if ($persona_token !== '') {
            $html .= '<input type="hidden" name="persona_token" value="' . $this->htmlService->h($persona_token) . '">';
        }

        return $html;
    }

    public function verifyCsrf(): bool
    {
        // Mode test : bypass CSRF (parité avec la fonction legacy verify_csrf()).
        // Sans ce bypass, les tests E2E/HTTP qui POST sans jeton valide échoueraient.
        /** @phpstan-ignore-next-line if.alwaysTrue */
        if (defined('TEST_MODE') && TEST_MODE) {
            if (str_contains($_SERVER['HTTP_HOST'] ?? '', 'exemple.invalid')) {
                throw new \RuntimeException('TEST_MODE ne doit pas être actif en production');
            }
            error_log('[SECURITY] TEST_MODE actif — CSRF bypassed');
            return true;
        }

        $token = $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';

        if (empty($token) || empty($sessionToken)) {
            return false;
        }
        if (!hash_equals($sessionToken, $token)) {
            return false;
        }

        // Rotation du token après validation
        unset($_SESSION['csrf_token']);
        return true;
    }

    public function requireCsrf(): void
    {
        if (!$this->verifyCsrf()) {
            /** @phpstan-ignore-next-line booleanAnd.rightAlwaysFalse */
            if (defined('TEST_MODE') && TEST_MODE && function_exists('test_json_response')) {
                test_json_response(['error' => 'Token CSRF invalide']);
            }
            if (class_exists(\App\Render\ErrorRenderer::class)) {
                (new \App\Render\ErrorRenderer())->errorPage(403, 'Erreur de sécurité', 'Le jeton de sécurité (CSRF) est invalide ou manquant. Veuillez réessayer.');
            }
            exit;
        }
    }


}
