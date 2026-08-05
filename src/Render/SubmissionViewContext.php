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
     * @param array{form_label: string, submitted_by: string, submitted_at: string, closed_at?: string|null}|array<empty, empty> $sub
     * @param array<string, mixed>                                             $data
     * @param list<array{step_status: string, step_label: string, ordre: int, tokens: list<array>}> $workflow_steps
     * @param list<array>                                                      $all_tokens
     * @param array<string, mixed>                                             $dl_info
     * @param array<string, array{card_group?: string, label?: string}>        $field_info
     * @param list<array{field_name: string, field_label?: string, value: string, filled_by_email?: string, step_label?: string, filled_at?: string}> $validator_data_rows
     * @param list<array{detail?: string, created_at?: string, actor?: string}> $submission_reminds
     * @param list<array>                                                      $pending_with_relance
     * @param list<array{id?: string, mime_type?: string, original_name?: string, file_size?: int, uploaded_at?: string}> $attachments
     * @param list<array{step_label?: string, from_email?: string, to_email?: string, delegated_at?: string, reason?: string}> $delegations
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
    ) {}

    /**
     * Build from legacy array context (BC for lib_wrappers).
     *
     * @param array<string, mixed> $ctx
     */
    public static function fromLegacyArray(array $ctx): self
    {
        return new self(
            sub_id: (string) ($ctx['sub_id'] ?? ''),
            sub: $ctx['sub'] ?? [],
            data: $ctx['data'] ?? [],
            status: (string) ($ctx['status'] ?? \App\Enum\SubmissionStatus::EnCours->value),
            status_label: (string) ($ctx['status_label'] ?? ''),
            status_cls: (string) ($ctx['status_cls'] ?? ''),
            user: (string) ($ctx['user'] ?? ''),
            is_admin: (bool) ($ctx['is_admin'] ?? false),
            is_form_owner: (bool) ($ctx['is_form_owner'] ?? false),
            nom_agent: (string) ($ctx['nom_agent'] ?? ''),
            workflow_steps: $ctx['workflow_steps'] ?? [],
            all_tokens: $ctx['all_tokens'] ?? [],
            total_steps: (int) ($ctx['total_steps'] ?? 0),
            done_steps: (int) ($ctx['done_steps'] ?? 0),
            progress_pct: (int) ($ctx['progress_pct'] ?? 0),
            dl_info: $ctx['dl_info'] ?? [],
            deadline_ts: $ctx['deadline_ts'] ?? null,
            days_remaining: (int) ($ctx['days_remaining'] ?? 0),
            action_msg: (string) ($ctx['action_msg'] ?? ''),
            field_info: $ctx['field_info'] ?? [],
            validator_data_rows: $ctx['validator_data_rows'] ?? [],
            submission_reminds: $ctx['submission_reminds'] ?? [],
            total_relances: (int) ($ctx['total_relances'] ?? 0),
            pending_with_relance: $ctx['pending_with_relance'] ?? [],
            attachments: $ctx['attachments'] ?? [],
            delegations: $ctx['delegations'] ?? [],
            admin_comment: (string) ($ctx['admin_comment'] ?? ''),
        );
    }
}
