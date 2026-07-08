<?php
declare(strict_types=1);

/**
 * Authentication & admin user management — thin wrappers delegating to AuthService.
 *
 * @package lib
 */

function get_auth_user(): string {
    return \App\Core\App::auth()->getUser();
}

function is_admin_user(): bool {
    return \App\Core\App::auth()->isAdmin();
}

function is_admin_effective(): bool {
    return \App\Core\App::auth()->isAdminEffective();
}

function is_super_admin(): bool {
    return \App\Core\App::auth()->isSuperAdmin();
}

function require_admin(): void {
    \App\Core\App::auth()->requireAdmin();
}

function get_admin_email(): string {
    return \App\Core\App::auth()->getAdminEmail();
}

function is_form_owner(string $form_id, ?string $email = null): bool {
    return \App\Core\App::auth()->isFormOwner($form_id, $email);
}

/** @return array<string, mixed> */
function get_form_owners(string $form_id): array {
    return \App\Core\App::auth()->getFormOwners($form_id);
}

/** @return array<int, array<string, mixed>> */
function get_owned_forms(?string $email = null): array {
    return \App\Core\App::auth()->getOwnedForms($email);
}

/**
 * @param string $email Email de l'utilisateur qui demande l'accès
 * @return array{success: bool, reason: string}
 */
function process_admin_request(string $email): array {
    return \App\Core\App::auth()->processAdminRequest($email);
}

function approve_admin_request(string $email): bool {
    return \App\Core\App::auth()->approveAdminRequest($email);
}

function reject_admin_request(string $email): bool {
    return \App\Core\App::auth()->rejectAdminRequest($email);
}

function remove_admin(string $email): bool {
    return \App\Core\App::auth()->removeAdmin($email);
}
