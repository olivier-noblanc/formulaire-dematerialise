<?php
declare(strict_types=1);

/**
 * POST handlers admin_settings.php — Wrapper backward-compatible.
 *
 * La logique métier est dans App\Controller\AdminSettingsHandlers.
 *
 * @package lib
 * @deprecated Utilisez App\Controller\AdminSettingsHandlers directement.
 */

function handle_admin_settings_post(): array {
    return \App\Controller\AdminSettingsHandlers::handlePost();
}
