<?php
$actions = [];

// Action 1 : Mettre à la corbeille (annuler)
if ($status === \App\Enum\SubmissionStatus::EnCours->value && ($is_admin || $submitted_by === $user)) {
    $cancel_url = \App\Core\App::html()->escape('index.php?p=confirm_action&action=cancel_submission&submission_id=' . urlencode($sub_id) . '&from=' . urlencode('index.php?p=submission_view&id=' . $sub_id));
    $actions[] = '<a href="' . $cancel_url . '" class="btn btn-danger u-tex"><span aria-hidden="true">🗑</span> Mettre à la corbeille</a>';
}

// Action 2 : Supprimer définitivement (admin only, status=annule ou refuse)
if (($status === \App\Enum\SubmissionStatus::Annule->value || $status === \App\Enum\SubmissionStatus::Refuse->value) && $is_admin) {
    $delete_url = \App\Core\App::html()->escape('index.php?p=confirm_action&action=delete_submission&submission_id=' . urlencode($sub_id) . '&from=' . urlencode('index.php?p=submission_view&id=' . $sub_id));
    $actions[] = '<a href="' . $delete_url . '" class="btn btn-danger u-bac-tex"><span aria-hidden="true">⚠</span> Supprimer définitivement</a>';
}

if ($actions === []) {
    return '';
}

$actions_html = implode('<br><br>', $actions);
return <<<HTML
      <!-- Actions -->
      <div class="card">
        <h2><span aria-hidden="true">⚙</span> Actions</h2>
        {$actions_html}
      </div>
    HTML;
