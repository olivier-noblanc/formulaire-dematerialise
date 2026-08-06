<?php

declare(strict_types=1);

namespace App\Auth;

use App\Contract\AuthInterface;
use App\Contract\MailInterface;
use App\Core\App;
use App\Core\Database;
use App\Repository\AdminRepository;
use App\Repository\FormRepository;
use App\Repository\SettingsRepository;

/**
 * Service d'authentification et gestion des admins.
 *
 * Le paramètre $database est conservé pour la compatibilité ascendante
 * (tests AuthServiceTest qui instancient AuthService avec une DB isolée
 * sans passer par le bootstrap global). En production, tout accès DB
 * passe par les repositories injectés ou résolus via App::getInstance().
 */
final class AuthService implements AuthInterface
{
    use AdminRequestManagementTrait;

    private ?MailInterface $mail = null;
    public readonly AdminRepository $adminRepository;
    public readonly FormRepository $formRepository;
    public readonly SettingsRepository $settingsRepository;

    public function __construct(
        Database $database,
        ?AdminRepository $adminRepository = null,
        ?FormRepository $formRepository = null,
        ?SettingsRepository $settingsRepository = null
    ) {
        $app = App::getInstance();
        $this->adminRepository = $adminRepository ?? ($app->has(AdminRepository::class) ? $app->get(AdminRepository::class) : new AdminRepository($database, new SettingsRepository($database)));
        $this->formRepository = $formRepository ?? ($app->has(FormRepository::class) ? $app->get(FormRepository::class) : new FormRepository($database));
        $this->settingsRepository = $settingsRepository ?? ($app->has(SettingsRepository::class) ? $app->get(SettingsRepository::class) : new SettingsRepository($database));
    }

    public function setMailer(MailInterface $mail): void
    {
        $this->mail = $mail;
    }

    private function getMailer(): MailInterface
    {
        if (!$this->mail instanceof \App\Contract\MailInterface) {
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
        /** @phpstan-ignore-next-line booleanAnd.rightAlwaysFalse */
        if (defined('TEST_MODE') && TEST_MODE) {
            $testUser = $_SERVER['HTTP_X_TEST_USER'] ?? '';
            if ($testUser !== '') {
                if (str_contains((string) $testUser, '@')) {
                    return strtolower(trim((string) $testUser));
                }
                $domain = $this->getEmailDomain();
                return (strtolower(trim((string) $testUser))) . '@' . $domain;
            }
        }

        $authUser = $_SERVER['AUTH_USER'] ?? ($_SERVER['REMOTE_USER'] ?? '');
        if ($authUser === '') {
            return '';
        }

        $authUser = trim((string) $authUser);
        if (str_contains($authUser, '@')) {
            return strtolower($authUser);
        }

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
     *
     * CS-11 (audit 2026-07-26) : délègue à AdminRepository::isAdmin() quand
     * le container DI a enregistré un AdminRepository (cas normal). Sinon
     * fallback sur AdminRepository créé à la volée (cas des tests qui
     * instancient AuthService avec une DB isolée sans passer par le
     * bootstrap global — AuthServiceTest).
     */
    private function isAdminByEmail(string $email): bool
    {
        if ($email === '') {
            return false;
        }
        return $this->adminRepository->isAdmin($email);
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
        return !$this->isPersonaTokenActive();
    }

    /**
     * Vrai si un token persona valide est présent dans la requête courante
     * (GET ou POST). Factorisé depuis isAdminEffective() pour être réutilisé
     * par requireAdminEffective() sans dupliquer la lecture du token.
     */
    private function isPersonaTokenActive(): bool
    {
        $token = '';
        if (isset($_GET['persona_token'])) {
            $token = (string) $_GET['persona_token'];
        } elseif (isset($_POST['persona_token'])) {
            $token = (string) $_POST['persona_token'];
        }
        return $token !== '' && function_exists('persona_lookup') && persona_lookup($token) !== '';
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
                new \App\Render\ErrorRenderer()->errorPage(403, 'Accès refusé', 'Vous devez être administrateur pour accéder à cette page.');
            }
            exit;
        }

        $this->regenerateSessionIdOnce();
    }

    /**
     * Régénère l'ID de session au premier accès authentifié de la requête.
     * Factorisé entre requireAdmin() et requireAdminEffective().
     */
    private function regenerateSessionIdOnce(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && ($_SESSION['_session_initialized'] ?? false) !== true) {
            session_regenerate_id(true);
            $_SESSION['_session_initialized'] = true;
        }
    }

    /**
     * Fix (bug persona — signalé 2026-07-28) : garde d'accès pour les
     * contrôleurs admin qui doivent rester inaccessibles pendant un persona
     * actif (downgrade réel, pas seulement masquage des liens UI). Avant ce
     * correctif, requireAdmin() (basé sur l'user RÉEL) protégeait déjà ces
     * pages, mais ne tenait jamais compte du persona — un admin en persona
     * voyait donc toujours l'intégralité de l'interface et des données admin
     * (paramètres, sauvegardes, RGPD, monitoring...), alors que
     * PersonaService documente un modèle "downgrade uniquement".
     *
     * PersonaController garde volontairement requireAdmin() (pas celle-ci) :
     * l'admin doit toujours pouvoir arrêter son propre persona même pendant
     * qu'il est actif.
     */
    public function requireAdminEffective(): void
    {
        if ((!$this->isAdmin() && !$this->isSuperAdmin()) || $this->isPersonaTokenActive()) {
            /** @phpstan-ignore-next-line booleanAnd.rightAlwaysFalse */
            if (defined('TEST_MODE') && TEST_MODE && function_exists('test_json_response')) {
                test_json_response(['error' => 'Accès refusé', 'redirect' => 'index.php?p=admin_access']);
            }
            if (class_exists(\App\Render\ErrorRenderer::class)) {
                new \App\Render\ErrorRenderer()->errorPage(403, 'Accès refusé', 'Cette page n\'est pas accessible pendant un persona actif.');
            }
            exit;
        }

        $this->regenerateSessionIdOnce();
    }

    public function getAdminEmail(): string
    {
        // CS-11 (audit 2026-07-26) : délègue à AdminRepository::getSuperAdminEmail()
        // quand disponible dans le container DI. Sinon fallback SettingsRepository
        // puis SETTINGS_DEFAULTS (cas des tests AuthServiceTest qui ne passe pas
        // par bootstrap global).
        try {
            $email = $this->adminRepository->getSuperAdminEmail();
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                return $email;
            }
        } catch (\Throwable) {
            // @silent-ok: fallback — DB not yet ready early in bootstrap
        }

        // Fallback : SettingsRepository direct puis SETTINGS_DEFAULTS
        try {
            $val = $this->settingsRepository->get('admin_email');
            if ($val !== null && $val !== '' && filter_var($val, FILTER_VALIDATE_EMAIL) !== false) {
                return $val;
            }
        } catch (\Throwable) {
            // @silent-ok: fallback — DB not yet ready
        }

        return defined('SETTINGS_DEFAULTS')
            ? SETTINGS_DEFAULTS['admin_email']
            : '';
    }

    public function getEmailDomain(): string
    {
        return defined('SETTINGS_DEFAULTS')
            ? SETTINGS_DEFAULTS['email_domain']
            : 'exemple.invalid';
    }

    public function isFormOwner(string $formId, ?string $email = null): bool
    {
        if ($email === null) {
            $email = $this->getUser();
        }
        return $this->formRepository->isOwnerByEmail($formId, $email);
    }



    /** @return list<array<string, mixed>> */
    public function getOwnedForms(?string $email = null): array
    {
        if ($email === null) {
            $email = $this->getUser();
        }
        return $this->formRepository->findOwnedFormsByEmail($email);
    }

    // ── ADMIN REQUEST MANAGEMENT (via AdminRequestManagementTrait) ─
}
