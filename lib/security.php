<?php
declare(strict_types=1);

/**
 * Wrappers procéduraux pour SecurityService.
 *
 * Ces fonctions déléguent à App\Security\SecurityService via le container DI.
 * Elles permettent la compatibilité ascendante avec le code procédural existant.
 */

/**
 * Envoie les headers de sécurité HTTP.
 */
function send_security_headers(): void
{
    App\Core\App::security()->sendSecurityHeaders();
}

/**
 * Génère un champ hidden CSRF pour les formulaires.
 */
function csrf_field(): string
{
    return App\Core\App::security()->csrfField();
}

/**
 * Vérifie et exige un token CSRF valide.
 * Lève une exception ou affiche une page d'erreur en cas d'échec.
 */
function require_csrf(): void
{
    App\Core\App::security()->requireCsrf();
}
