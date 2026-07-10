<?php
declare(strict_types=1);

/**
 * JSON schema validation for form import/export — Wrapper backward-compatible.
 *
 * La logique métier est dans App\Forms\FormJsonValidator.
 *
 * @package lib
 * @deprecated Utilisez App\Forms\FormJsonValidator directement.
 */

function validate_form_json(array $data): array {
    return \App\Forms\FormJsonValidator::validate($data);
}

function format_validation_results(array $result): string {
    return \App\Forms\FormJsonValidator::formatResults($result);
}
