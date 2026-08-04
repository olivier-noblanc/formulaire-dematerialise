<?php

declare(strict_types=1);

/**
 * Global Persona wrappers.
 *
 * Delegates to App\Persona\PersonaService.
 * Loaded by lib_wrappers.php (main loader).
 */

function persona_create_token(string $admin_email, string $target_email): string
{
    $personaService = persona_get_service();
    return $personaService->createToken($admin_email, $target_email);
}

function persona_lookup(string $token): string
{
    $personaService = persona_get_service();
    return $personaService->lookup($token);
}

function persona_revoke(string $token): bool
{
    $personaService = persona_get_service();
    return $personaService->revoke($token);
}

function persona_cleanup(): int
{
    $personaService = persona_get_service();
    return $personaService->cleanup();
}

function persona_current_token(): string
{
    $personaService = persona_get_service();
    return $personaService->currentToken();
}

function persona_current_target(): string
{
    $personaService = persona_get_service();
    return $personaService->currentTarget();
}

function persona_get_service(): \App\Persona\PersonaService
{
    if (\App\Core\App::getInstance()->has(\App\Persona\PersonaService::class)) {
        return \App\Core\App::getInstance()->get(\App\Persona\PersonaService::class);
    }
    return new \App\Persona\PersonaService(new \App\Core\Database());
}
