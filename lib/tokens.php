<?php
declare(strict_types=1);

/**
 * Token lifecycle management — wrappers delegating to TokenService.
 *
 * @package lib
 */

function regenerate_token(string $old_token_id): array {
    return \App\Core\App::token()->regenerate($old_token_id);
}

function cancel_submission(string $submission_id, string $cancelled_by = ''): array {
    return \App\Core\App::token()->cancel($submission_id, $cancelled_by);
}

function remind_one(string $token_id): array {
    return \App\Core\App::token()->remind($token_id);
}

function get_tokens_for_submission(string $submission_id, array $extra_fields = []): array {
    return \App\Core\App::token()->getForSubmission($submission_id, $extra_fields);
}

function delegate_token(string $token_id, string $to_email, string $reason = ''): array {
    return \App\Core\App::token()->delegate($token_id, $to_email, $reason);
}

function get_delegations(string $submission_id): array {
    return \App\Core\App::token()->getDelegations($submission_id);
}
