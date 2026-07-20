<?php

declare(strict_types=1);

namespace App\Auth;

use App\Contract\AuthInterface;
use App\Contract\MailInterface;
use App\Core\App;
use App\Core\Database;

/**
 * Service d'authentification et gestion des admins.
 */
final class AuthService implements AuthInterface
{
    private ?MailInterface $mail = null;

    public function __construct(private readonly Database $database)
    {
    }

    public function setMailer(MailInterface $mail): void
    {
        $this->mail = $mail;
    }

    private function getMailer(): MailInterface
    {
        if ($this->mail === null) {
            $this->mail = App::mail();
        }
        return $this->mail;
    }

    /**
     * Récupère l'email de l'utilisateur courant (header IIS ou test mode).
     *
     * v9.7.0 — Issue 3 : si un admin a activé le mode persona (session
     * _persona_user), on retourne cet email à la place. isAdmin() reste
     * basé sur l'user réel (pas le persona) pour la sécurité.
     */
    public function getUser(): string
    {
        // v10.0.0 — Persona token-based : si un token est dans l'URL ou en POST
        // (champ hidden) et valide, retourner le target_email.
        $realUser = $this->getRealUser();
        if ($realUser !== '' && $this->isAdminByEmail($realUser)) {
            $token = '';
            if (isset($_GET['persona_token'])) {
                $token = (string) $_GET['persona_token'];
            } elseif (isset($_POST['persona_token'])) {
                $token = (string) $_POST['persona_token'];
            }
            if ($token !== '' && function_exists('persona_lookup')) {
                $target = persona_lookup($token);
                if ($target !== '') {
                    return $target |> trim(...) |> strtolower(...);
                }
            }
        }
        return $realUser;
    }

    /**
     * Récupère l'utilisateur RÉEL (sans persona) — pour is_admin_user().
     */
    private function getRealUser(): string
    {
        /** @phpstan-ignore-next-line booleanAnd.rightAlwaysFalse */
        if (defined('TEST_MODE') && TEST_MODE) {
            $testUser = $_SERVER['HTTP_X_TEST_USER'] ?? '';
            if (!empty($testUser)) {
                if (str_contains((string) $testUser, '@')) {
                    return $testUser |> trim(...) |> strtolower(...);
                }
                $domain = $this->getEmailDomain();
                return ($testUser |> trim(...) |> strtolower(...)) . '@' . $domain;
            }
        }

        $authUser = $_SERVER['AUTH_USER'] ?? ($_SERVER['REMOTE_USER'] ?? '');
        if (empty($authUser)) {
            return '';
        }

        $authUser = trim((string) $authUser);
        if (str_contains($authUser, '@')) {
            return strtolower($authUser);
        }

        // Format DREETS\prenom.nom → prenom.nom@dreets.gouv.fr
        $domain = $this->getEmailDomain();
        if (str_contains($authUser, '\\')) {
            $parts = explode('\\', $authUser);
            $userPart = $parts[1] ?? $parts[0];
        } else {
            $userPart = $authUser;
        }
        return strtolower($userPart) . '@' . $domain;
    }

    /**
     * Vérifie si un email donné est admin (sans dépendre de getUser()).
     * Utile pour vérifier l'user réel quand un persona est actif.
     */
    private function isAdminByEmail(string $email): bool
    {
        if ($email === '') {
            return false;
        }
        $pdo = $this->database->getPdo();
        $stmt = $pdo->prepare('SELECT 1 FROM admins WHERE email = ?');
        $stmt->execute([$email]);
        return $stmt->fetch() !== false;
    }

    public function isAdmin(): bool
    {
        // v9.7.0 — isAdmin() reste basé sur l'user RÉEL (pas le persona)
        // pour ne pas perdre les droits admin quand on visualise en tant qu'un user.
        $user = $this->getRealUser();
        return $this->isAdminByEmail($user);
    }

    /**
     * v9.9.0 — "Effective admin" = admin réel ET pas de persona actif.
     */
    public function isAdminEffective(): bool
    {
        if (!$this->isAdmin()) {
            return false;
        }
        $token = '';
        if (isset($_GET['persona_token'])) {
            $token = (string) $_GET['persona_token'];
        } elseif (isset($_POST['persona_token'])) {
            $token = (string) $_POST['persona_token'];
        }
        if ($token !== '' && function_exists('persona_lookup') && persona_lookup($token) !== '') {
            return false;
        }
        return true;
    }

    public function isSuperAdmin(): bool
    {
        $user = $this->getRealUser();
        $adminEmail = $this->getAdminEmail();
        return $user === $adminEmail;
    }

    public function requireAdmin(): void
    {
        if (!$this->isAdmin() && !$this->isSuperAdmin()) {
            /** @phpstan-ignore-next-line booleanAnd.rightAlwaysFalse */
            if (defined('TEST_MODE') && TEST_MODE && function_exists('test_json_response')) {
                test_json_response(['error' => 'Accès refusé', 'redirect' => 'index.php?p=admin_access']);
            }
            if (class_exists(\App\Render\ErrorRenderer::class)) {
                (new \App\Render\ErrorRenderer())->errorPage(403, 'Accès refusé', 'Vous devez être administrateur pour accéder à cette page.');
            }
            exit;
        }

        // Régénération session ID au premier accès authentifié
        if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['_session_initialized'])) {
            session_regenerate_id(true);
            $_SESSION['_session_initialized'] = true;
        }
    }

    public function getAdminEmail(): string
    {
        try {
            $pdo = $this->database->getPdo();
            $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
            $stmt->execute(['admin_email']);
            $val = $stmt->fetchColumn();
            if ($val && filter_var($val, FILTER_VALIDATE_EMAIL)) {
                return (string) $val;
            }
        } catch (\Throwable) {
            // DB pas encore prête
        }

        // Fallback sur SETTINGS_DEFAULTS
        return defined('SETTINGS_DEFAULTS')
            ? (string) SETTINGS_DEFAULTS['admin_email']
            : '';
    }

    public function getEmailDomain(): string
    {
        return defined('SETTINGS_DEFAULTS')
            ? (string) SETTINGS_DEFAULTS['email_domain']
            : 'dreets.gouv.fr';
    }

    public function isFormOwner(string $formId, ?string $email = null): bool
    {
        if ($email === null) {
            $email = $this->getUser();
        }
        $pdo = $this->database->getPdo();
        $stmt = $pdo->prepare('SELECT 1 FROM form_owners WHERE form_id = ? AND LOWER(email) = LOWER(?)');
        $stmt->execute([$formId, $email]);
        return $stmt->fetch() !== false;
    }



    /** @return array<int, array<string, mixed>> */
    public function getOwnedForms(?string $email = null): array
    {
        if ($email === null) {
            $email = $this->getUser();
        }
        $pdo = $this->database->getPdo();
        $stmt = $pdo->prepare('
            SELECT f.id, f.label, f.slug, f.actif
            FROM forms f
            JOIN form_owners fo ON fo.form_id = f.id
            WHERE LOWER(fo.email) = LOWER(?)
            ORDER BY f.label
        ');
        $stmt->execute([$email]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ── ADMIN REQUEST MANAGEMENT ────────────────────────────────

    /**
     * Traite une demande d'accès admin.
     *
     * @return array{success: bool, reason: string}
     */
    public function processAdminRequest(string $email): array
    {
        try {
            $pdo = $this->database->getPdo();

            if ($this->isAdmin()) {
                return ['success' => true, 'reason' => 'already_admin'];
            }

            $stmt = $pdo->prepare("SELECT 1 FROM admin_requests WHERE email = ? AND status = 'pending'");
            $stmt->execute([$email]);
            if ($stmt->fetch() !== false) {
                return ['success' => false, 'reason' => 'pending'];
            }

            $token = bin2hex(random_bytes(32));
            $ar_id = generate_uuid();
            $stmt = $pdo->prepare("INSERT INTO admin_requests (id, email, requested_at, status, token) VALUES (?, ?, ?, 'pending', ?)");
            $stmt->execute([$ar_id, $email, gmdate('Y-m-d H:i:s'), $token]);

            App::audit()->log('admin_request', 'admin:' . $email, 'Demande d\'accès admin', $email);

            $approve_url = resolve_base_url() . '/index.php?p=admin_access&action=approve&token=' . $token;
            $reject_url = resolve_base_url() . '/index.php?p=admin_access&action=reject&token=' . $token;
            $subject = 'Demande d\'accès admin - ' . \App\Render\NavigationRenderer::getAppName();
            $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body>
    <h2>Demande d\'accès admin</h2>
    <p>Un utilisateur a demandé l\'accès admin au back office du workflow :</p>
    <p><strong>Utilisateur :</strong> ' . \App\Core\App::html()->escape($email) . '</p>
    <p><strong>Date :</strong> ' . gmdate('d/m/Y H:i:s') . ' UTC</p>
    <p><a href="' . $approve_url . '" style="background:#1a6b3c;color:#fff;padding:10px 15px;text-decoration:none;border-radius:4px;display:inline-block;margin-right:10px;">Approuver</a>
    <a href="' . $reject_url . '" style="background:#c0392b;color:#fff;padding:10px 15px;text-decoration:none;border-radius:4px;display:inline-block;">Refuser</a></p>
</body>
</html>';

            $cc_email = App::settings()->get('admin_email_cc', '');
            $mail_sent = $this->getMailer()->send($this->getAdminEmail(), $subject, $body);
            if ($cc_email !== '' && $cc_email !== $this->getAdminEmail()) {
                $this->getMailer()->send($cc_email, '[CC] ' . $subject, $body);
            }

            $dry_run = App::settings()->get('mail_dry_run', '0') === '1';
            if ($dry_run) {
                return ['success' => true, 'reason' => 'dry_run'];
            }
            if (!$mail_sent) {
                return ['success' => false, 'reason' => 'mail_failed'];
            }

            return ['success' => true, 'reason' => 'sent'];
        } catch (\Exception $e) {
            error_log('Erreur lors de la demande d\'accès admin : ' . $e->getMessage());
            return ['success' => false, 'reason' => 'exception', 'error' => $e->getMessage()];
        }
    }

    public function approveAdminRequest(string $email, ?string $requestId = null): bool
    {
        try {
            $adminRepo = App::getInstance()->get(\App\Repository\AdminRepository::class);

            // Si pas d'ID, trouver la demande pending par email
            if ($requestId === null) {
                $request = $adminRepo->findPendingByEmail($email);
                if ($request === null) {
                    return false;
                }
                $requestId = $request['id'];
            }

            $adminRepo->approveRequest($requestId, $this->getUser());

            $subject = 'Accès admin approuvé - ' . \App\Render\NavigationRenderer::getAppName();
            $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body>
    <h2>Accès admin approuvé</h2>
    <p>Votre demande d\'accès admin au back office du workflow a été approuvée.</p>
    <p>Vous pouvez maintenant accéder au back office en cliquant sur le lien ci-dessous :</p>
    <p><a href="' . resolve_base_url() . '/index.php?p=admin_access">Accéder au back office</a></p>
</body>
</html>';

            $this->getMailer()->send($email, $subject, $body);
            App::audit()->log('admin_approve', 'admin:' . $email, 'Accès admin approuvé');
            return true;
        } catch (\Exception $e) {
            error_log('Erreur lors de l\'approbation de la demande admin : ' . $e->getMessage());
            return false;
        }
    }

    public function rejectAdminRequest(string $email, ?string $requestId = null): bool
    {
        try {
            $adminRepo = App::getInstance()->get(\App\Repository\AdminRepository::class);

            // Si pas d'ID, trouver la demande pending par email
            if ($requestId === null) {
                $request = $adminRepo->findPendingByEmail($email);
                if ($request === null) {
                    return false;
                }
                $requestId = $request['id'];
            }

            $adminRepo->rejectRequest($requestId, $this->getUser());

            $subject = 'Demande d\'accès admin refusée - ' . \App\Render\NavigationRenderer::getAppName();
            $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body>
    <h2>Demande d\'accès admin refusée</h2>
    <p>Votre demande d\'accès admin au back office du workflow a été refusée.</p>
</body>
</html>';

            $this->getMailer()->send($email, $subject, $body);
            App::audit()->log('admin_reject', 'admin:' . $email, 'Accès admin refusé');
            return true;
        } catch (\Exception $e) {
            error_log('Erreur lors du refus de la demande admin : ' . $e->getMessage());
            return false;
        }
    }

    public function removeAdmin(string $email): bool
    {
        $pdo = $this->database->getPdo();

        if ($email === $this->getAdminEmail()) {
            return false;
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM admins WHERE email = ?');
            $stmt->execute([$email]);
            App::audit()->log('admin_remove', 'admin:' . $email, 'Admin supprimé', $email);
            return true;
        } catch (\Exception $e) {
            error_log('Erreur lors de la suppression d\'un admin : ' . $e->getMessage());
            return false;
        }
    }
}
