<?php
declare(strict_types=1);

/**
 * RGPD compliance — thin wrappers delegating to RgpdService.
 *
 * @package lib
 */

function rgpd_export_user_data(string $email): array {
    return \App\Core\App::getInstance()->get(\App\Rgpd\RgpdService::class)->exportUserData($email);
}

function rgpd_delete_user_data(string $email): bool {
    return \App\Core\App::getInstance()->get(\App\Rgpd\RgpdService::class)->deleteUserData($email);
}

function rgpd_auto_purge(int $months = 24): int {
    return \App\Core\App::getInstance()->get(\App\Rgpd\RgpdService::class)->autoPurge($months);
}
