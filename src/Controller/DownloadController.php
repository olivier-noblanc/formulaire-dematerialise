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
        $mode = trim($_GET['mode'] ?? '');
        if ($mode === 'export_submission') {
            $this->exportSubmissionJson();
        }

        $attachmentId = trim($_GET['id'] ?? '');
        if ($attachmentId === '' || $attachmentId === '0') {
            (new \App\Render\ErrorRenderer())->errorPage(
                400,
                'Requête invalide',
                'L\'identifiant de pièce jointe fourni est invalide.',
                'Vérifiez que le lien que vous avez utilisé est correct et complet.'
            );
        }

        try {
            $attachmentId = (string) validate_input($attachmentId, 'uuid');
        } catch (\InvalidArgumentException) {
            App::audit()->securityLog('invalid_attachment_id', 'ID=' . substr($attachmentId, 0, 20));
            (new \App\Render\ErrorRenderer())->errorPage(
                400,
                'Requête invalide',
                'L\'identifiant de pièce jointe fourni est invalide.',
                'Vérifiez que le lien que vous avez utilisé est correct et complet.'
            );
        }

        $attachment = App::attachment()->getAttachmentById($attachmentId);
        if (!$attachment) {
            (new \App\Render\ErrorRenderer())->errorPage(
                404,
                'Pièce jointe introuvable',
                'La pièce jointe demandée n\'existe pas ou a été supprimée.',
                'Si vous avez suivi un lien depuis un email, la pièce jointe a peut-être été supprimée. Contactez l\'expéditeur de la demande.'
            );
        }

        $user = App::auth()->getUser();
        $is_admin = App::auth()->isAdmin();

        $has_access = false;

        if ($is_admin) {
            $has_access = true;
        } else {
            $subRepo = App::getInstance()->get(\App\Repository\SubmissionRepository::class);
            $owner = $subRepo->getSubmitterById($attachment['submission_id']);
            if ($owner === $user) {
                $has_access = true;
            }

            if (!$has_access) {
                $tokenRepo = App::getInstance()->get(\App\Repository\TokenRepository::class);
                if ($tokenRepo->existsForSubmissionAndEmail($attachment['submission_id'], $user)) {
                    $has_access = true;
                }
            }
        }

        if (!$has_access) {
            (new \App\Render\ErrorRenderer())->errorPage(
                403,
                'Accès non autorisé',
                'Vous n\'avez pas les droits nécessaires pour accéder à cette pièce jointe. Seuls l\'auteur de la demande, les validateurs concernés et les administrateurs peuvent la consulter.',
                'Si vous pensez que vous devriez avoir accès, vérifiez que vous êtes bien connecté avec votre compte habituel. Contactez un administrateur si le problème persiste.'
            );
        }

        $mime_type = $attachment['mime_type'];
        $allowed_mimes = App::attachment()->getAllowedMimeTypes();
        if (!in_array($mime_type, $allowed_mimes)) {
            (new \App\Render\ErrorRenderer())->errorPage(
                403,
                'Type de fichier non autorisé',
                'Le type MIME de cette pièce jointe n\'est pas dans la liste autorisée.',
                'Contactez un administrateur si vous pensez qu\'il s\'agit d\'une erreur.'
            );
        }
        $original_name = $attachment['original_name'];
        $file_size = (int) $attachment['file_size'];

        $original_name = preg_replace('/[^\x20-\x7E]/', '', (string) $original_name);
        $safe_name = rawurlencode($attachment['original_name']);

        header('Content-Type: ' . $mime_type);
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . $file_size);
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $safe_filename = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', (string) $original_name);
        if ($mime_type === 'application/pdf') {
            header('Content-Disposition: inline; filename="' . $safe_filename . '"; filename*=UTF-8\'\'' . $safe_name);
        } else {
            header('Content-Disposition: attachment; filename="' . $safe_filename . '"; filename*=UTF-8\'\'' . $safe_name);
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
                if ($real_path !== false && $upload_dir !== false && str_starts_with($real_path, $upload_dir)) {
                    readfile($file_path);
                    exit;
                }
            }
        }

        (new \App\Render\ErrorRenderer())->errorPage(
            404,
            'Fichier introuvable',
            'Le fichier demandé n\'existe pas sur le serveur. Il a peut-être été supprimé ou déplacé.',
            'Contactez un administrateur si vous pensez qu\'il s\'agit d\'une erreur.'
        );
    }

    private function exportSubmissionJson(): void
    {
        $submission_id = trim($_GET['submission_id'] ?? '');
        if ($submission_id === '') {
            (new \App\Render\ErrorRenderer())->errorPage(
                400,
                'Requête invalide',
                'L\'identifiant de soumission fourni est invalide.',
                'Vérifiez que le lien que vous avez utilisé est correct et complet.'
            );
        }

        try {
            $submission_id = (string) validate_input($submission_id, 'uuid');
        } catch (\InvalidArgumentException) {
            App::audit()->securityLog('invalid_submission_id', 'ID=' . substr($submission_id, 0, 20));
            (new \App\Render\ErrorRenderer())->errorPage(
                400,
                'Requête invalide',
                'L\'identifiant de soumission fourni est invalide.',
                'Vérifiez que le lien que vous avez utilisé est correct et complet.'
            );
        }

        $submissionRepository = App::getInstance()->get(\App\Repository\SubmissionRepository::class);
        $submission = $submissionRepository->findByIdWithForm($submission_id);
        if ($submission === null) {
            (new \App\Render\ErrorRenderer())->errorPage(
                404,
                'Soumission introuvable',
                'La soumission demandée n\'existe pas ou a été supprimée.',
                'Contactez un administrateur si vous pensez qu\'il s\'agit d\'une erreur.'
            );
        }

        $user = App::auth()->getUser();
        $is_admin = App::auth()->isAdmin();

        $has_access = false;
        if ($is_admin) {
            $has_access = true;
        } elseif ((string) ($submission['submitted_by'] ?? '') === $user) {
            $has_access = true;
        } else {
            $tokenRepo = App::getInstance()->get(\App\Repository\TokenRepository::class);
            if ($tokenRepo->existsForSubmissionAndEmail($submission_id, $user)) {
                $has_access = true;
            }
        }

        if (!$has_access) {
            (new \App\Render\ErrorRenderer())->errorPage(
                403,
                'Accès non autorisé',
                'Vous n\'avez pas les droits nécessaires pour exporter cette soumission.',
                'Seuls l\'auteur de la demande, les validateurs concernés et les administrateurs peuvent la consulter.'
            );
        }

        $submission_data = json_decode((string) ($submission['data'] ?? ''), true);
        $export = [
            'export_date'  => gmdate('c'),
            'exported_by'  => $user,
            'submission'   => [
                'id'            => (string) ($submission['id'] ?? ''),
                'form_id'       => (string) ($submission['form_id'] ?? ''),
                'form_label'    => (string) ($submission['form_label'] ?? ''),
                'submitted_by'  => (string) ($submission['submitted_by'] ?? ''),
                'submitted_at'  => (string) ($submission['submitted_at'] ?? ''),
                'closed_at'     => (string) ($submission['closed_at'] ?? ''),
                'status'        => (string) ($submission['status'] ?? ''),
                'rgpd_consent'  => isset($submission['rgpd_consent']) ? (int) $submission['rgpd_consent'] : 0,
                'data'          => is_array($submission_data) ? $submission_data : [],
            ],
            'tokens'         => [],
            'attachments'    => [],
            'validator_data' => [],
        ];

        $tokenRepo = App::getInstance()->get(\App\Repository\TokenRepository::class);
        $export['tokens'] = $tokenRepo->findForExport($submission_id);

        $attachmentRepository = App::getInstance()->get(\App\Repository\AttachmentRepository::class);
        $export['attachments'] = $attachmentRepository->findForExport($submission_id);

        $export['validator_data'] = $submissionRepository->getValidatorData($submission_id);

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
