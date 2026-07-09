<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Contrôleur du téléchargement sécurisé des pièces jointes + export JSON.
 */
final class DownloadController extends BaseController
{
    public function handle(): void
    {
        $mode = (string)trim($_GET['mode'] ?? '');
        if ($mode === 'export_submission') {
            $this->exportSubmissionJson();
        }

        $attachmentId = (string)trim($_GET['id'] ?? '');
        if (empty($attachmentId)) {
            render_error_page(400, 'Requête invalide',
                'L\'identifiant de pièce jointe fourni est invalide.',
                'Vérifiez que le lien que vous avez utilisé est correct et complet.');
        }

        try {
            $attachmentId = (string)validate_input($attachmentId, 'uuid');
        } catch (\InvalidArgumentException $e) {
            App::audit()->securityLog('invalid_attachment_id', 'ID=' . substr($attachmentId, 0, 20));
            render_error_page(400, 'Requête invalide',
                'L\'identifiant de pièce jointe fourni est invalide.',
                'Vérifiez que le lien que vous avez utilisé est correct et complet.');
        }

        $attachment = get_attachment_by_id($attachmentId);
        if (!$attachment) {
            render_error_page(404, 'Pièce jointe introuvable',
                'La pièce jointe demandée n\'existe pas ou a été supprimée.',
                'Si vous avez suivi un lien depuis un email, la pièce jointe a peut-être été supprimée. Contactez l\'expéditeur de la demande.');
        }

        $user = App::auth()->getUser();
        $is_admin = App::auth()->isAdmin();

        $has_access = false;

        if ($is_admin) {
            $has_access = true;
        } else {
            $pdo = $this->db->getPdo();

            $subStmt = $pdo->prepare("SELECT submitted_by FROM submissions WHERE id = ?");
            $subStmt->execute([$attachment['submission_id']]);
            $owner = $subStmt->fetchColumn();
            if ($owner === $user) {
                $has_access = true;
            }

            if (!$has_access) {
                $valStmt = $pdo->prepare("SELECT 1 FROM tokens WHERE submission_id = ? AND email = ?");
                $valStmt->execute([$attachment['submission_id'], $user]);
                if ($valStmt->fetch()) {
                    $has_access = true;
                }
            }
        }

        if (!$has_access) {
            render_error_page(403, 'Accès non autorisé',
                'Vous n\'avez pas les droits nécessaires pour accéder à cette pièce jointe. Seuls l\'auteur de la demande, les validateurs concernés et les administrateurs peuvent la consulter.',
                'Si vous pensez que vous devriez avoir accès, vérifiez que vous êtes bien connecté avec votre compte habituel. Contactez un administrateur si le problème persiste.');
        }

        $mime_type = $attachment['mime_type'];
        $allowed_mimes = get_allowed_mime_types();
        if (!in_array($mime_type, $allowed_mimes)) {
            render_error_page(403, 'Type de fichier non autorisé',
                'Le type MIME de cette pièce jointe n\'est pas dans la liste autorisée.',
                'Contactez un administrateur si vous pensez qu\'il s\'agit d\'une erreur.');
        }
        (string)$original_name = $attachment['original_name'];
        $file_size = (int)$attachment['file_size'];

        (string)$original_name = str_replace(["\r", "\n"], '', (string)$original_name);
        (string)$original_name = str_replace('"', '\\"', (string)$original_name);
        $safe_name = rawurlencode($attachment['original_name']);

        header('Content-Type: ' . $mime_type);
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . $file_size);
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        if ($mime_type === 'application/pdf') {
            header("Content-Disposition: inline; filename=\"(string)$original_name\"; filename*=UTF-8''$safe_name");
        } else {
            header("Content-Disposition: attachment; filename=\"(string)$original_name\"; filename*=UTF-8''$safe_name");
        }

        if (!empty($attachment['file_data'])) {
            echo $attachment['file_data'];
            exit;
        }

        if (!empty($attachment['stored_name'])) {
            $file_path = dirname(__DIR__, 2) . '/db/uploads/' . $attachment['stored_name'];
            if (file_exists($file_path)) {
                $real_path = realpath($file_path);
                $upload_dir = realpath(dirname(__DIR__, 2) . '/db/uploads');
                if ($real_path !== false && $upload_dir !== false && strpos($real_path, $upload_dir) === 0) {
                    readfile($file_path);
                    exit;
                }
            }
        }

        render_error_page(404, 'Fichier introuvable',
            'Le fichier demandé n\'existe pas sur le serveur. Il a peut-être été supprimé ou déplacé.',
            'Contactez un administrateur si vous pensez qu\'il s\'agit d\'une erreur.');
    }

    private function exportSubmissionJson(): void
    {
        $submission_id = (string)trim($_GET['submission_id'] ?? '');
        if ($submission_id === '') {
            render_error_page(400, 'Requête invalide',
                'L\'identifiant de soumission fourni est invalide.',
                'Vérifiez que le lien que vous avez utilisé est correct et complet.');
        }

        try {
            $submission_id = (string)validate_input($submission_id, 'uuid');
        } catch (\InvalidArgumentException $e) {
            App::audit()->securityLog('invalid_submission_id', 'ID=' . substr($submission_id, 0, 20));
            render_error_page(400, 'Requête invalide',
                'L\'identifiant de soumission fourni est invalide.',
                'Vérifiez que le lien que vous avez utilisé est correct et complet.');
        }

        $pdo = $this->db->getPdo();

        $subStmt = $pdo->prepare(
            "SELECT s.*, f.label AS form_label "
            . "FROM submissions s JOIN forms f ON f.id = s.form_id "
            . "WHERE s.id = ?"
        );
        $subStmt->execute([$submission_id]);
        $submission = $subStmt->fetch(\PDO::FETCH_ASSOC);
        if ($submission === false) {
            render_error_page(404, 'Soumission introuvable',
                'La soumission demandée n\'existe pas ou a été supprimée.',
                'Contactez un administrateur si vous pensez qu\'il s\'agit d\'une erreur.');
        }

        $user = App::auth()->getUser();
        $is_admin = App::auth()->isAdmin();

        $has_access = false;
        if ($is_admin) {
            $has_access = true;
        } elseif ((string)($submission['submitted_by'] ?? '') === $user) {
            $has_access = true;
        } else {
            $valStmt = $pdo->prepare("SELECT 1 FROM tokens WHERE submission_id = ? AND email = ?");
            $valStmt->execute([$submission_id, $user]);
            if ($valStmt->fetch() !== false) {
                $has_access = true;
            }
        }

        if (!$has_access) {
            render_error_page(403, 'Accès non autorisé',
                'Vous n\'avez pas les droits nécessaires pour exporter cette soumission.',
                'Seuls l\'auteur de la demande, les validateurs concernés et les administrateurs peuvent la consulter.');
        }

        $submission_data = json_decode((string)($submission['data'] ?? ''), true);
        $export = [
            'export_date'  => gmdate('c'),
            'exported_by'  => $user,
            'submission'   => [
                'id'            => (string)($submission['id'] ?? ''),
                'form_id'       => (string)($submission['form_id'] ?? ''),
                'form_label'    => (string)($submission['form_label'] ?? ''),
                'submitted_by'  => (string)($submission['submitted_by'] ?? ''),
                'submitted_at'  => (string)($submission['submitted_at'] ?? ''),
                'closed_at'     => (string)($submission['closed_at'] ?? ''),
                'status'        => (string)($submission['status'] ?? ''),
                'rgpd_consent'  => isset($submission['rgpd_consent']) ? (int)$submission['rgpd_consent'] : 0,
                'data'          => is_array($submission_data) ? $submission_data : [],
            ],
            'tokens'         => [],
            'attachments'    => [],
            'validator_data' => [],
        ];

        $tokensStmt = $pdo->prepare(
            "SELECT step_id, email, role, sent_at, done_at, expires_at "
            . "FROM tokens WHERE submission_id = ? ORDER BY sent_at"
        );
        $tokensStmt->execute([$submission_id]);
        $export['tokens'] = $tokensStmt->fetchAll(\PDO::FETCH_ASSOC);

        $attsStmt = $pdo->prepare(
            "SELECT id, field_name, original_name, mime_type, file_size, uploaded_at "
            . "FROM attachments WHERE submission_id = ? ORDER BY uploaded_at"
        );
        $attsStmt->execute([$submission_id]);
        $export['attachments'] = $attsStmt->fetchAll(\PDO::FETCH_ASSOC);

        $vdStmt = $pdo->prepare(
            "SELECT field_name, field_label, field_type, value, filled_by, filled_at, "
            . "       step_id, step_label, filled_by_email "
            . "FROM submission_validator_data "
            . "WHERE submission_id = ? "
            . "ORDER BY filled_at, field_name"
        );
        $vdStmt->execute([$submission_id]);
        $export['validator_data'] = $vdStmt->fetchAll(\PDO::FETCH_ASSOC);

        App::audit()->log('export_submission', 'submission:' . $submission_id, 'Export JSON de la soumission par ' . $user, '');

        $filename = 'submission_' . $submission_id . '_' . date('Ymd_His') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}
