<?php

declare(strict_types=1);

/**
 * Global view helpers.
 *
 * Loaded by lib_wrappers.php (main loader).
 */

function render_footer(): string
{
    return new \App\Render\NavigationRenderer()->footer();
}
