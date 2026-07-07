<?php
declare(strict_types=1);

/**
 * lib/persona.php — Gestion des tokens persona (refonte v10.0.0).
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
 * @package lib
 */

/**
 * Durée de vie par défaut d'un token persona (en secondes).
 * 8h = 28800s — assez pour une journée de travail.
 */
const PERSONA_TOKEN_TTL = 28800;

/**
 * Crée un token persona pour visualiser en tant que target_email.
 *
 * @param string $admin_email  Email de l'admin qui crée le token (user réel)
 * @param string $target_email Email du user à impersonner
 * @return string Le token généré (32 hex chars), ou '' si échec
 */
function persona_create_token(string $admin_email, string $target_email): string {
    $service = persona_get_service();
    return $service->createToken($admin_email, $target_email);
}

/**
 * Lookup un token persona → retourne le target_email si valide, '' sinon.
 *
 * @param string $token Le token à vérifier
 * @return string target_email si valide, '' sinon
 */
function persona_lookup(string $token): string {
    $service = persona_get_service();
    return $service->lookup($token);
}

/**
 * Révoque un token persona (mark revoked_at = now).
 *
 * @param string $token Le token à révoquer
 * @return bool True si révoqué, false si non trouvé
 */
function persona_revoke(string $token): bool {
    $service = persona_get_service();
    return $service->revoke($token);
}

/**
 * Nettoie les tokens expirés ou révoqués depuis > 30 jours.
 *
 * @return int Nombre de tokens supprimés
 */
function persona_cleanup(): int {
    $service = persona_get_service();
    return $service->cleanup();
}

/**
 * Retourne le token persona actif depuis $_GET, ou '' si aucun.
 *
 * @return string
 */
function persona_current_token(): string {
    $service = persona_get_service();
    return $service->currentToken();
}

/**
 * Retourne l'email du persona actif (target_email), ou '' si aucun.
 *
 * @return string
 */
function persona_current_target(): string {
    $service = persona_get_service();
    return $service->currentTarget();
}

/**
 * Récupère l'instance PersonaService (lazy singleton via App container).
 *
 * @return \App\Persona\PersonaService
 */
function persona_get_service(): \App\Persona\PersonaService {
    if (\App\Core\App::has(\App\Persona\PersonaService::class)) {
        return \App\Core\App::get(\App\Persona\PersonaService::class);
    }
    return new \App\Persona\PersonaService(new \App\Core\Database());
}
