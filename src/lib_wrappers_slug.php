<?php

declare(strict_types=1);

/**
 * Global slug/field helpers.
 *
 * Delegates to App\Core\SlugHelper.
 * Loaded by lib_wrappers.php (main loader).
 */

use App\Core\SlugHelper;

function generate_field_name(string $label): string
{
    return SlugHelper::generateFieldName($label);
}
function generate_slug(string $label, ?string $exclude_form_id = null): string
{
    return SlugHelper::generateSlug($label, $exclude_form_id);
}
function parse_options_input(string $input): ?string
{
    return SlugHelper::parseOptionsInput($input);
}
/**
 * @return array{id: string, slug: string, label: string, description: string|null, actif: int, created_at: string, deadline_field: string}|null
 */
function get_form_by_uuid(string $uuid): ?array
{
    return SlugHelper::getFormByUuid($uuid);
}
