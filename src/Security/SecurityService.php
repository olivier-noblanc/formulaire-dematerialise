<?php
declare(strict_types=1);

namespace App\Security;

use App\Contract\SecurityInterface;

/**
 * Service de sécurité : CSRF, headers HTTP, rate limiting.
 */
final class SecurityService implements SecurityInterface
{
    public function sendSecurityHeaders(): void
    {
        if (php_sapi_name() === 'cli') return;

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        $csp = "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none';";
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
        $html = '<input type="hidden" name="csrf_token" value="' . h($token) . '">';

        // v10.0.0 — Persona token-based : propager ?persona_token dans les POST
        // via un champ hidden, pour que le persona persiste après un submit
        $persona_token = isset($_GET['persona_token']) ? (string)$_GET['persona_token'] : '';
        if ($persona_token !== '') {
            $html .= '<input type="hidden" name="persona_token" value="' . h($persona_token) . '">';
        }

        return $html;
    }

    public function verifyCsrf(): bool
    {
        // Mode test : bypass CSRF (parité avec la fonction legacy verify_csrf()).
        // Sans ce bypass, les tests E2E/HTTP qui POST sans jeton valide échoueraient.
        /** @phpstan-ignore-next-line if.alwaysTrue */
        if (defined('TEST_MODE') && TEST_MODE) {
            return true;
        }

        $token = $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';

        if (empty($token) || empty($sessionToken)) return false;
        if (!hash_equals($sessionToken, $token)) return false;

        // Rotation du token après validation
        unset($_SESSION['csrf_token']);
        return true;
    }

    public function requireCsrf(): void
    {
        if (!$this->verifyCsrf()) {
            if (defined('TEST_MODE') && TEST_MODE && function_exists('test_json_response')) {
                test_json_response(['error' => 'Token CSRF invalide']);
            }
            if (function_exists('render_error_page')) {
                render_error_page(403, 'Erreur de sécurité', 'Le jeton de sécurité (CSRF) est invalide ou manquant. Veuillez réessayer.');
            }
            exit;
        }
    }

    public function rateLimitCheck(string $action = 'default', int $maxAttempts = 10, int $windowSeconds = 60): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $pdo = function_exists('get_pdo') ? get_pdo() : null;
        if ($pdo === null) return true;

        static $tableCreated = false;
        if (!$tableCreated) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
                id TEXT PRIMARY KEY NOT NULL,
                action_key TEXT NOT NULL,
                ip TEXT NOT NULL,
                attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rate_limits_lookup ON rate_limits(action_key, ip, attempted_at)");
            $tableCreated = true;
        }

        $windowStart = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
        $windowStartSql = "'" . $windowStart . "'";

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE action_key = ? AND ip = ? AND attempted_at > $windowStartSql");
        $stmt->execute([$action, $ip]);
        $count = (int) $stmt->fetchColumn();

        if ($count >= $maxAttempts) return false;

        $pdo->prepare("INSERT INTO rate_limits (id, action_key, ip, attempted_at) VALUES (?, ?, ?, datetime('now'))")
            ->execute([$this->generateUuid(), $action, $ip]);

        return true;
    }

    private function generateUuid(): string
    {
        return \generate_uuid();
    }
}
