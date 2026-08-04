<?php

declare(strict_types=1);

/**
 * Global input validation wrappers.
 *
 * Delegates to App\Validation\ValidationService.
 * Loaded by lib_wrappers.php (main loader).
 */

function sanitize_input(string $input): string
{
    trigger_error('sanitize_input() is deprecated — use \App\Core\App::html()->escape() for HTML output and prepared statements for SQL', E_USER_DEPRECATED);
    return \App\Core\App::validation()->sanitize($input);
}

function validate_email(string $email): string
{
    return \App\Core\App::validation()->validateEmail($email);
}

/**
 * @param array<string, mixed> $options
 */
function validate_input(mixed $value, string $rule, array $options = []): string|int
{
    return \App\Core\App::validation()->validate($value, $rule, $options);
}
