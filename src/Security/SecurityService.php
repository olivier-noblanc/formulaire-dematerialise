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

    /**
     * Nonce CSP de la requête courante — à appliquer sur tout <script> ou
     * <style> inline pour qu'il soit autorisé sans 'unsafe-inline'.
     * Vide avant le premier appel à sendSecurityHeaders() (ex. CLI).
     */
    public function getScriptNonce(): string
    {
        return $this->scriptNonce;
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
        $nonceValue = $this->scriptNonce;
        // script-src : plus de 'unsafe-inline' — le seul <script> inline
        // (NavigationRenderer::footer(), menu persona) est noncé (2026-07-30,
        // cf. README "JavaScript : Aucun" — objectif zéro JS non conforme
        // à ce jour, mais au moins plus de fallback unsafe-inline).
        // style-src : 'unsafe-inline' encore nécessaire — les attributs
        // style="" ne peuvent PAS être autorisés par nonce (contrairement
        // aux éléments <script>/<style> — nuance CSP), et le code utilise
        // encore largement des style="" dynamiques (pourcentages, couleurs
        // calculées...). Migration vers des <style nonce> ciblés à faire
        // page par page, pas en un seul commit.
        $csp = "default-src 'self'; script-src 'self' 'nonce-{$nonceValue}'; style-src 'self' 'unsafe-inline' 'nonce-{$nonceValue}'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none';";
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
                new \App\Render\ErrorRenderer()->errorPage(403, 'Erreur de sécurité', 'Le jeton de sécurité (CSRF) est invalide ou manquant. Veuillez réessayer.');
            }
            exit;
        }
    }


}
