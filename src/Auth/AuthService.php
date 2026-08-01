<?php

declare(strict_types=1);

namespace App\Auth;

use App\Contract\AuthInterface;
use App\Contract\MailInterface;
use App\Core\App;
use App\Core\Database;
use App\Enum\AdminRequestStatus;
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
    private ?MailInterface $mail = null;
    public AdminRepository $adminRepository;
    public FormRepository $formRepository;
    public SettingsRepository $settingsRepository;

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
                if (str_contains($testUser, '@')) {
                    return strtolower(trim($testUser));
                }
                $domain = $this->getEmailDomain();
                return (strtolower(trim($testUser))) . '@' . $domain;
            }
        }

        $authUser = $_SERVER['AUTH_USER'] ?? ($_SERVER['REMOTE_USER'] ?? '');
        if ($authUser === '') {
            return '';
        }

        $authUser = trim($authUser);
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
            // DB pas encore prête (tôt dans le bootstrap)
        }

        // Fallback : SettingsRepository direct puis SETTINGS_DEFAULTS
        try {
            $val = $this->settingsRepository->get('admin_email');
            if ($val !== null && $val !== '' && filter_var($val, FILTER_VALIDATE_EMAIL) !== false) {
                return $val;
            }
        } catch (\Throwable) {
            // DB pas encore prête
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



    /** @return array<int, array<string, mixed>> */
    public function getOwnedForms(?string $email = null): array
    {
        if ($email === null) {
            $email = $this->getUser();
        }
        return $this->formRepository->findOwnedFormsByEmail($email);
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
            if ($this->isAdmin()) {
                return ['success' => true, 'reason' => 'already_admin'];
            }

            if ($this->adminRepository->hasPendingRequestByEmail($email)) {
                return ['success' => false, 'reason' => \App\Enum\AdminRequestStatus::Pending->value];
            }

            $token = bin2hex(random_bytes(32));
            $ar_id = generate_uuid();
            $this->adminRepository->createAdminRequest($ar_id, $email, gmdate('Y-m-d H:i:s'), AdminRequestStatus::Pending->value, $token);

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
            // Si pas d'ID, trouver la demande pending par email
            if ($requestId === null) {
                $request = $this->adminRepository->findPendingByEmail($email);
                if ($request === null) {
                    return false;
                }
                $requestId = $request['id'];
            }

            $this->adminRepository->approveRequest($requestId, $this->getUser());

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
            // Si pas d'ID, trouver la demande pending par email
            if ($requestId === null) {
                $request = $this->adminRepository->findPendingByEmail($email);
                if ($request === null) {
                    return false;
                }
                $requestId = $request['id'];
            }

            $this->adminRepository->rejectRequest($requestId, $this->getUser());

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
        if ($email === $this->getAdminEmail()) {
            return false;
        }

        try {
            $this->adminRepository->deleteByEmail($email);
            App::audit()->log('admin_remove', 'admin:' . $email, 'Admin supprimé', $email);
            return true;
        } catch (\Exception $e) {
            error_log('Erreur lors de la suppression d\'un admin : ' . $e->getMessage());
            return false;
        }
    }
}
