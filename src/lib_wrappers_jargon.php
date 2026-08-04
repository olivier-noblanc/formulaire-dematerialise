<?php

declare(strict_types=1);

/**
 * Global jargon helper.
 *
 * Delegates to App\Render\JargonService.
 * Loaded by lib_wrappers.php (main loader).
 */

use App\Render\JargonService;

function t_jargon(string $text): string
{
    return JargonService::translate($text);
}
