<?php
declare(strict_types=1);

/**
 * Rendu de la page de détail d'une soumission (submission_view.php).
 *
 * Wrapper backward-compatible — délègue à App\Render\SubmissionViewRenderer.
 *
 * @package lib
 * @see /submission_view.php
 */

function submission_view_page_css(): string {
    return (new \App\Render\SubmissionViewRenderer())->pageCss();
}

function render_submission_view_back_link(bool $is_admin, string $action_msg): string {
    return (new \App\Render\SubmissionViewRenderer())->renderBackLink($is_admin, $action_msg);
}

function render_submission_view_header(array $sub, string $sub_id, string $nom_agent, string $status_label, string $status_cls): string {
    return (new \App\Render\SubmissionViewRenderer())->renderHeader($sub, $sub_id, $nom_agent, $status_label, $status_cls);
}

function render_submission_view_progress(int $progress_pct, int $done_steps, int $total_steps): string {
    return (new \App\Render\SubmissionViewRenderer())->renderProgress($progress_pct, $done_steps, $total_steps);
}

function render_submission_view_deadline(array $dl_info, ?int $deadline_ts, int $days_remaining, string $status): string {
    return (new \App\Render\SubmissionViewRenderer())->renderDeadline($dl_info, $deadline_ts, $days_remaining, $status);
}

function render_submission_view_delegations(array $delegations): string {
    return (new \App\Render\SubmissionViewRenderer())->renderDelegations($delegations);
}

function render_submission_view_actions(string $status, bool $is_admin, string $submitted_by, string $user, string $sub_id): string {
    return (new \App\Render\SubmissionViewRenderer())->renderActions($status, $is_admin, $submitted_by, $user, $sub_id);
}

function render_submission_view_admin_comment(string $admin_comment, bool $can_edit, string $sub_id): string {
    return (new \App\Render\SubmissionViewRenderer())->renderAdminComment($admin_comment, $can_edit, $sub_id);
}

function render_submission_view_content(array $ctx): string {
    return (new \App\Render\SubmissionViewRenderer())->renderContent($ctx);
}

function render_submission_view_workflow_diagram(array $workflow_steps, string $status): string {
    return (new \App\Render\SubmissionViewRenderer())->renderWorkflowDiagram($workflow_steps, $status);
}

function render_submission_view_workflow_actions(array $all_tokens, bool $is_admin, string $status): string {
    return (new \App\Render\SubmissionViewRenderer())->renderWorkflowActions($all_tokens, $is_admin, $status);
}

function render_submission_view_delegation_form(array $all_tokens, string $user, bool $is_admin, string $status): string {
    return (new \App\Render\SubmissionViewRenderer())->renderDelegationForm($all_tokens, $user, $is_admin, $status);
}

function render_submission_view_form_data(array $data, array $field_info): string {
    return (new \App\Render\SubmissionViewRenderer())->renderFormData($data, $field_info);
}

function render_submission_view_validator_data(array $validator_data_rows, array $field_info, bool $can_edit = false, string $sub_id = ''): string {
    return (new \App\Render\SubmissionViewRenderer())->renderValidatorData($validator_data_rows, $field_info, $can_edit, $sub_id);
}

function render_submission_view_validation_history(array $data): string {
    return (new \App\Render\SubmissionViewRenderer())->renderValidationHistory($data);
}

function render_submission_view_remind_history(array $all_tokens, array $submission_reminds, int $total_relances, array $pending_with_relance, bool $is_admin, string $status): string {
    return (new \App\Render\SubmissionViewRenderer())->renderRemindHistory($all_tokens, $submission_reminds, $total_relances, $pending_with_relance, $is_admin, $status);
}

function render_submission_view_attachments(array $attachments): string {
    return (new \App\Render\SubmissionViewRenderer())->renderAttachments($attachments);
}
