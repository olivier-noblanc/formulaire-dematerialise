<?php

declare(strict_types=1);

namespace App\Attachment;

use App\Core\App;
use App\Repository\AttachmentRepository;

/**
 * Service de gestion des pièces jointes.
 *
 * Extrait de lib/attachments.php — upload, stockage (BLOB), et récupération
 * des pièces jointes d'une soumission.
 * Les fonctions globales dans lib/attachments.php délèguent maintenant ici.
 */
final readonly class AttachmentService
{
    public function __construct(private AttachmentRepository $attachmentRepository)
    {
    }

    /**
     * Types MIME autorisés pour les pièces jointes.
     * Sécurisé : pas d'exécutables, pas de scripts.
     * @return list<string>
     */
    public function getAllowedMimeTypes(): array
    {
        return [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/gif',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'text/csv',
            'application/zip',
        ];
    }

    /**
     * Extensions autorisées (vérification supplémentaire).
     * @return list<string>
     */
    public function getAllowedExtensions(): array
    {
        return ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip'];
    }

    /**
     * Taille maximale des fichiers en octets (10 Mo).
     */
    public function getMaxFileSize(): int
    {
        return 10 * 1024 * 1024;
    }

    /**
     * Gère l'upload d'un fichier pour une soumission.
     *
     * @param array<string, mixed> $file Le tableau $_FILES['field_name']
     * @param string $submissionId ID de la soumission
     * @param string $fieldName Nom du champ
     * @return array{success: bool, message: string, attachment_id: string|null}
     */
    public function handleFileUpload(array $file, string $submissionId, string $fieldName): array
    {
        // Vérifier les erreurs d'upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE   => 'Le fichier dépasse la taille maximale autorisée par le serveur.',
                UPLOAD_ERR_FORM_SIZE  => 'Le fichier dépasse la taille maximale autorisée par le formulaire.',
                UPLOAD_ERR_PARTIAL    => 'Le fichier n\'a été que partiellement téléchargé.',
                UPLOAD_ERR_NO_FILE    => 'Aucun fichier n\'a été téléchargé.',
                UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant sur le serveur.',
                UPLOAD_ERR_CANT_WRITE => 'Erreur d\'écriture sur le serveur.',
            ];
            return ['success' => false, 'message' => $errors[$file['error']] ?? 'Erreur inconnue lors de l\'upload.', 'attachment_id' => null];
        }

        // Vérifier la taille
        if ($file['size'] > $this->getMaxFileSize()) {
            return ['success' => false, 'message' => 'Le fichier dépasse la taille maximale autorisée (10 Mo).', 'attachment_id' => null];
        }

        // Sécurité (S-06) : sanitisser le nom de fichier
        $safeName = basename($file['name']);
        $safeName = preg_replace('/[^a-zA-Z0-9._\-\x{00C0}-\x{024F}]/u', '_', $safeName) ?? $safeName;
        $safeName = ltrim($safeName, '.');
        if ($safeName === '' || $safeName === '0') {
            $safeName = 'fichier';
        }

        // Sécurité (S-06) : vérifier les doubles extensions dangereuses
        $dangerousExts = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phar', 'shtml', 'asa', 'asp', 'aspx', 'cgi', 'pl', 'py', 'rb', 'sh', 'jsp', 'war'];
        $nameParts = explode('.', $safeName);
        if (count($nameParts) > 2) {
            foreach ($nameParts as $namePart) {
                if (in_array(strtolower($namePart, true), $dangerousExts, true)) {
                    App::audit()->log('file_upload_blocked', 'submission:' . $submissionId, 'Upload bloqué — double extension dangereuse : ' . $safeName, '');
                    return ['success' => false, 'message' => 'Nom de fichier non autorisé. Les doubles extensions contenant des scripts ne sont pas acceptées.', 'attachment_id' => null];
                }
            }
        }

        // Vérifier l'extension (dernière partie)
        $ext = strtolower(array_last($nameParts));
        if (!in_array($ext, $this->getAllowedExtensions(, true), true)) {
            return ['success' => false, 'message' => 'Type de fichier non autorisé. Extensions acceptées : ' . implode(', ', $this->getAllowedExtensions()) . '.', 'attachment_id' => null];
        }

        // Sécurité (S-06) : vérifier que l'extension n'est pas dans la liste dangereuse
        if (in_array($ext, $dangerousExts, true)) {
            App::audit()->log('file_upload_blocked', 'submission:' . $submissionId, 'Upload bloqué — extension dangereuse : ' . $ext, '');
            return ['success' => false, 'message' => 'Type de fichier non autorisé.', 'attachment_id' => null];
        }

        // Vérifier le type MIME
        if (!function_exists('finfo_open')) {
            return ['success' => false, 'message' => 'Extension fileinfo non disponible', 'attachment_id' => null];
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return ['success' => false, 'message' => 'Impossible d\'analyser le type de fichier.', 'attachment_id' => null];
        }
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        if (!in_array($mimeType, $this->getAllowedMimeTypes(, true), true)) {
            return ['success' => false, 'message' => 'Type MIME non autorisé : ' . \App\Core\App::html()->escape($mimeType === false ? '' : $mimeType) . '.', 'attachment_id' => null];
        }

        // Sécurité (S-06) : vérifier les types MIME dangereux
        $dangerousMimes = ['application/x-php', 'text/x-php', 'application/x-httpd-php', 'application/x-sh', 'application/x-cgi', 'application/x-perl', 'application/x-python', 'text/html'];
        if (in_array($mimeType, $dangerousMimes, true)) {
            App::audit()->log('file_upload_blocked', 'submission:' . $submissionId, 'Upload bloqué — MIME dangereux : ' . $mimeType . ' pour fichier ' . $safeName, '');
            return ['success' => false, 'message' => 'Type de fichier non autorisé.', 'attachment_id' => null];
        }

        // Lire le contenu du fichier pour stockage BLOB
        $fileContent = file_get_contents($file['tmp_name']);
        if ($fileContent === false) {
            return ['success' => false, 'message' => 'Erreur lors de la lecture du fichier.', 'attachment_id' => null];
        }

        // Enregistrer dans la base de données
        $attachmentId = $this->attachmentRepository->create([
            'submission_id' => $submissionId,
            'field_name' => $fieldName,
            'original_name' => $safeName,
            'mime_type' => $mimeType,
            'file_size' => $file['size'],
            'file_data' => $fileContent,
        ]);

        App::audit()->log('file_upload', 'submission:' . $submissionId, 'Fichier uploadé : ' . $safeName . ' (' . $mimeType . ', ' . $file['size'] . ' octets)', '');

        return ['success' => true, 'message' => 'Fichier ' . $safeName . ' enregistré.', 'attachment_id' => $attachmentId];
    }

    /**
     * Récupère les pièces jointes d'une soumission.
     *
     * @param string $submissionId ID de la soumission
     * @return array<int, array<string, mixed>> Liste des pièces jointes
     */
    public function getAttachments(string $submissionId): array
    {
        return $this->attachmentRepository->findBySubmission($submissionId);
    }

    /**
     * Récupère une pièce jointe par son ID.
     *
     * @param string $attachmentId ID de la pièce jointe
     * @return array{id: string, submission_id: string, field_name: string, original_name: string, stored_name: string, mime_type: string, file_size: int, file_data: string|null, uploaded_at: string}|null
     */
    public function getAttachmentById(string $attachmentId): ?array
    {
        return $this->attachmentRepository->findById($attachmentId);
    }
}
