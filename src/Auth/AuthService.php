<?php
declare(strict_types=1);

namespace App\Auth;

use App\Contract\AuthInterface;
use App\Core\Database;

/**
 * Service d'authentification et gestion des admins.
 */
final class AuthService implements AuthInterface
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
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
                $token = (string)$_GET['persona_token'];
            } elseif (isset($_POST['persona_token'])) {
                $token = (string)$_POST['persona_token'];
            }
            if ($token !== '' && function_exists('persona_lookup')) {
                $target = persona_lookup($token);
                if ($target !== '') {
                    return strtolower(trim($target));
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
        if (defined('TEST_MODE') && TEST_MODE) {
            $testUser = $_SERVER['HTTP_X_TEST_USER'] ?? '';
            if (!empty($testUser)) {
                if (str_contains($testUser, '@')) {
                    return strtolower(trim($testUser));
                }
                $domain = $this->getEmailDomain();
                return strtolower(trim($testUser)) . '@' . $domain;
            }
        }

        $authUser = $_SERVER['AUTH_USER'] ?? ($_SERVER['REMOTE_USER'] ?? '');
        if (empty($authUser)) return '';

        $authUser = trim($authUser);
        if (str_contains($authUser, '@')) return strtolower($authUser);

        // Format DREETS\prenom.nom → prenom.nom@exemple.invalid
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
        if ($email === '') return false;
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT 1 FROM admins WHERE email = ?");
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
     *
     * Utilisé pour l'AFFICHAGE (sidebar, pages admin) : quand un admin
     * active un persona, il veut voir l'interface comme un user simple,
     * donc les sections admin doivent être masquées.
     *
     * isAdmin() (basé sur l'user réel) reste true pour la SÉCURITÉ
     * (require_admin, accès aux pages admin directes).
     */
    public function isAdminEffective(): bool
    {
        if (!$this->isAdmin()) return false;
        // v10.0.0 — Si un persona token valide est présent (GET ou POST),
        // l'admin "effective" est false (masque la sidebar admin)
        $token = '';
        if (isset($_GET['persona_token'])) {
            $token = (string)$_GET['persona_token'];
        } elseif (isset($_POST['persona_token'])) {
            $token = (string)$_POST['persona_token'];
        }
        if ($token !== '' && function_exists('persona_lookup')) {
            if (persona_lookup($token) !== '') {
                return false;
            }
        }
        return true;
    }

    public function isSuperAdmin(): bool
    {
        // v9.7.0 — basé sur l'user réel (pas le persona)
        $user = $this->getRealUser();
        $adminEmail = $this->getAdminEmail();
        return $user === $adminEmail;
    }

    public function requireAdmin(): void
    {
        if (!$this->isAdmin() && !$this->isSuperAdmin()) {
            if (defined('TEST_MODE') && TEST_MODE && function_exists('test_json_response')) {
                test_json_response(['error' => 'Accès refusé', 'redirect' => 'index.php?p=admin_access']);
            }
            if (function_exists('render_error_page')) {
                render_error_page(403, 'Accès refusé', 'Vous devez être administrateur pour accéder à cette page.');
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
            $pdo = $this->db->getPdo();
            $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ?");
            $stmt->execute(['admin_email']);
            $val = $stmt->fetchColumn();
            if ($val && filter_var($val, FILTER_VALIDATE_EMAIL)) {
                return (string) $val;
            }
        } catch (\Throwable $e) {
            // DB pas encore prête
        }

        // Fallback sur SETTINGS_DEFAULTS
        return defined('SETTINGS_DEFAULTS') && isset(SETTINGS_DEFAULTS['admin_email'])
            ? SETTINGS_DEFAULTS['admin_email']
            : '';
    }

    public function getEmailDomain(): string
    {
        return defined('SETTINGS_DEFAULTS') && isset(SETTINGS_DEFAULTS['email_domain'])
            ? SETTINGS_DEFAULTS['email_domain']
            : 'exemple.invalid';
    }

    public function isFormOwner(string $formId, ?string $email = null): bool
    {
        if ($email === null) {
            $email = $this->getUser();
        }
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT 1 FROM form_owners WHERE form_id = ? AND email = ?");
        $stmt->execute([$formId, $email]);
        return $stmt->fetch() !== false;
    }

    /** @return array<int, array<string, mixed>> */
    public function getFormOwners(string $formId): array
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT id, email, added_at FROM form_owners WHERE form_id = ? ORDER BY email");
        $stmt->execute([$formId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> */
    public function getOwnedForms(?string $email = null): array
    {
        if ($email === null) {
            $email = $this->getUser();
        }
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            SELECT f.id, f.label, f.slug, f.actif
            FROM forms f
            JOIN form_owners fo ON fo.form_id = f.id
            WHERE fo.email = ?
            ORDER BY f.label
        ");
        $stmt->execute([$email]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
