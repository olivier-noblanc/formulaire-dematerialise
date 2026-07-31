<?php

declare(strict_types=1);

namespace App\Render;

/**
 * Context object for SubmissionViewRenderer::renderContent().
 *
 * Replaces the loose array<string, mixed> $ctx parameter.
 * All properties are typed and enforced by PHPStan.
 */
final readonly class SubmissionViewContext
{
    /**
     * @param string                                                           $sub_id
     * @param array{form_label: string, submitted_by: string, submitted_at: string, closed_at?: string|null}|array<empty, empty> $sub
     * @param array<string, mixed>                                             $data
     * @param string                                                           $status
     * @param string                                                           $status_label
     * @param string                                                           $status_cls
     * @param string                                                           $user
     * @param bool                                                             $is_admin
     * @param bool                                                             $is_form_owner
     * @param string                                                           $nom_agent
     * @param list<array{step_status: string, step_label: string, ordre: int, tokens: list<array>}> $workflow_steps
     * @param list<array>                                                      $all_tokens
     * @param int                                                              $total_steps
     * @param int                                                              $done_steps
     * @param int                                                              $progress_pct
     * @param array<string, mixed>                                             $dl_info
     * @param int|null                                                         $deadline_ts
     * @param int                                                              $days_remaining
     * @param string                                                           $action_msg
     * @param array<string, array{card_group?: string, label?: string}>        $field_info
     * @param list<array{field_name: string, field_label?: string, value: string, filled_by_email?: string, step_label?: string, filled_at?: string}> $validator_data_rows
     * @param list<array{detail?: string, created_at?: string, actor?: string}> $submission_reminds
     * @param int                                                              $total_relances
     * @param list<array>                                                      $pending_with_relance
     * @param list<array{id?: string, mime_type?: string, original_name?: string, file_size?: int, uploaded_at?: string}> $attachments
     * @param list<array{step_label?: string, from_email?: string, to_email?: string, delegated_at?: string, reason?: string}> $delegations
     * @param string                                                           $admin_comment
     */
    public function __construct(
        public string $sub_id,
        public array $sub,
        public array $data,
        public string $status,
        public string $status_label,
        public string $status_cls,
        public string $user,
        public bool $is_admin,
        public bool $is_form_owner,
        public string $nom_agent,
        public array $workflow_steps,
        public array $all_tokens,
        public int $total_steps,
        public int $done_steps,
        public int $progress_pct,
        public array $dl_info,
        public ?int $deadline_ts,
        public int $days_remaining,
        public string $action_msg,
        public array $field_info,
        public array $validator_data_rows,
        public array $submission_reminds,
        public int $total_relances,
        public array $pending_with_relance,
        public array $attachments,
        public array $delegations,
        public string $admin_comment,
    ) {
    }
}
