<?php

declare(strict_types=1);

namespace App\Persona;

use App\Core\App;
use App\Core\Database;
use App\Repository\AdminRepository;
use App\Repository\PersonaTokenRepository;

/**
 * Service de gestion des tokens persona (refonte v10.0.0).
 *
 * Architecture :
 *   - Un admin génère un token aléatoire lié à (admin_email, target_email)
 *   - Le token est stocké en DB (table persona_tokens)
 *   - Le token est propagé dans toutes les URLs via ?persona_token=XXX
 *   - AuthService::getUser() lit le token et retourne le target_email
 *   - Le token expire après 8h (configurable)
 *   - Le token est révocable individuellement
 *
 * Sécurité :
 *   - Downgrade uniquement (admin → user simple), jamais upgrade
 *   - Même si le token fuite, l'attaquant ne fait que visualiser en user
 *   - Un token ne peut être créé que par un admin (vérifié par l'appelant)
 *
 * Le paramètre $database est conservé pour la compatibilité ascendante
 * (bootstrap, tests) mais n'est plus utilisé directement — tout accès DB
 * passe par les repositories injectés.
 */
final readonly class PersonaService
{
    public const int TOKEN_TTL = 28800;

    public PersonaTokenRepository $personaTokenRepository;
    public AdminRepository $adminRepository;

    /**
     * Get service instance from DI container or create one.
     */
    public static function getService(): self
    {
        if (App::getInstance()->has(self::class)) {
            return App::getInstance()->get(self::class);
        }
        return new self(new Database());
    }

    public function __construct(
        Database $database,
        ?PersonaTokenRepository $personaTokenRepository = null,
        ?AdminRepository $adminRepository = null
    ) {
        $app = App::getInstance();
        $this->personaTokenRepository = $personaTokenRepository ?? ($app->has(PersonaTokenRepository::class) ? $app->get(PersonaTokenRepository::class) : new PersonaTokenRepository($database));
        $this->adminRepository = $adminRepository ?? ($app->has(AdminRepository::class) ? $app->get(AdminRepository::class) : new AdminRepository($database, new \App\Repository\SettingsRepository($database)));
    }

    /**
     * Crée un token persona pour visualiser en tant que target_email.
     *
     * @param string $admin_email  Email de l'admin qui crée le token (user réel)
     * @param string $target_email Email du user à impersonner
     * @return string Le token généré (32 hex chars), ou '' si échec
     */
    public function createToken(string $admin_email, string $target_email): string
    {
        if ($admin_email === '' || $target_email === '') {
            return '';
        }

        try {
            $token = bin2hex(random_bytes(16));
            $id = generate_uuid();
            $expires_at = gmdate('Y-m-d H:i:s', time() + self::TOKEN_TTL);

            $this->personaTokenRepository->createToken($id, $token, $admin_email, $target_email, $expires_at);

            App::audit()->log('persona_create', 'admin:' . $admin_email, "Persona créé pour $target_email (expire $expires_at)", '');
            return $token;
        } catch (\Throwable $e) {
            // @silent-ok: log-only, returns '' caller handles it
            error_log('persona_create_token error: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Lookup un token persona → retourne le target_email si valide, '' sinon.
     *
     * Vérifications :
     *   - Token existe en DB
     *   - Token pas expiré (expires_at > now)
     *   - Token pas révoqué (revoked_at IS NULL)
     *   - Token créé par un admin (admin_email est dans la table admins)
     *
     * @param string $token Le token à vérifier
     * @return string target_email si valide, '' sinon
     */
    public function lookup(string $token): string
    {
        if ($token === '') {
            return '';
        }

        try {
            $row = $this->personaTokenRepository->findActiveByToken($token);
            if ($row === null) {
                return '';
            }

            if ((bool)($row['revoked_at'])) {
                return '';
            }

            $now = gmdate('Y-m-d H:i:s');
            if ($row['expires_at'] <= $now) {
                return '';
            }

            // Vérifier que l'admin qui a créé le token est toujours admin
            // (sécurité : si l'admin a été révoqué entre-temps, le token meurt).
            if (!$this->adminRepository->isAdmin($row['admin_email'])) {
                return '';
            }

            return (string) $row['target_email'];
        } catch (\Throwable $e) {
            // @silent-ok: log-only, returns '' caller handles it
            error_log('persona_lookup error: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Révoque un token persona (mark revoked_at = now).
     *
     * @param string $token Le token à révoquer
     * @return bool True si révoqué, false si non trouvé
     */
    public function revoke(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        try {
            $revoked = $this->personaTokenRepository->revokeByToken($token) > 0;
            if ($revoked) {
                App::audit()->log('persona_revoke', 'token:' . substr($token, 0, 8) . '…', 'Persona révoqué', '');
            }
            return $revoked;
        } catch (\Throwable $e) {
            // @silent-ok: log-only, returns false caller handles it
            error_log('persona_revoke error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Nettoie les tokens expirés ou révoqués depuis > 30 jours.
     * À appeler périodiquement (par exemple dans alert_check.php cron).
     *
     * @return int Nombre de tokens supprimés
     */
    public function cleanup(): int
    {
        try {
            $cutoff = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
            return $this->personaTokenRepository->cleanup($cutoff);
        } catch (\Throwable $e) {
            // @silent-ok: log-only background cleanup
            error_log('persona_cleanup error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Retourne le token persona actif depuis $_GET, ou '' si aucun.
     */
    public function currentToken(): string
    {
        return isset($_GET['persona_token']) ? (string) $_GET['persona_token'] : '';
    }

    /**
     * Retourne l'email du persona actif (target_email), ou '' si aucun.
     */
    public function currentTarget(): string
    {
        $token = $this->currentToken();
        if ($token === '') {
            return '';
        }
        return $this->lookup($token);
    }
}
