<?php

declare(strict_types=1);

/**
 * Global UUID/Token wrappers.
 *
 * Delegates to App\Core\UuidHelper.
 * Loaded by lib_wrappers.php (main loader).
 */

use App\Core\UuidHelper;

function generate_uuid(): string
{
    return UuidHelper::generateUuid();
}
function generate_token(): string
{
    return UuidHelper::generateToken();
}
