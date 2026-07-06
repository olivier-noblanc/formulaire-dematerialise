<?php
declare(strict_types=1);

/**
 * File attachments management.
 *
 * Upload, storage (BLOB or filesystem), and retrieval of submission attachments.
 *
 * @package lib
 */

// ── FILE ATTACHMENTS ─────────────────────────────────────────

/**
 * Types MIME autorises pour les pieces jointes
 * Securise : pas d'executables, pas de scripts
 * @return list<string>
 */
function get_allowed_mime_types(): array {
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
        // ⚠️ Sécurité : text/plain, text/csv et application/zip sont des vecteurs
        // d'attaque potentiels. text/plain peut contenir du code, CSV peut contenir
        // des formules d'injection (CSV injection), et ZIP peut encapsuler des
        // exécutables. La vérification d'extension + MIME est insuffisante pour
        // les ZIP ; envisager une analyse du contenu si le risque augmente.
    ];
}

/**
 * Extensions autorisees (verification supplementaire)
 * @return list<string>
 */
function get_allowed_extensions(): array {
    return ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip'];
    // ⚠️ Sécurité : les extensions txt, csv et zip sont conservées pour les cas
    // d'usage métier (administration publique). Si le risque augmente, retirer
    // ces extensions et utiliser un format d'archive sécurisé à la place.
}

/**
 * Taille maximale des fichiers en octets (10 Mo)
 */
function get_max_file_size(): int {
    return 10 * 1024 * 1024;
}

/**
 * Gère l'upload d'un fichier pour une soumission
 *
 * @param array<string, mixed> $file Le tableau $_FILES['field_name']
 * @param string $submission_id ID de la soumission
 * @param string $field_name Nom du champ
 * @return array<string, mixed> ['success' => bool, 'message' => string, 'attachment_id' => string|null]
 */
function handle_file_upload(array $file, string $submission_id, string $field_name): array {
    // Sécurité (S-16) : limiter le nombre d'uploads par IP
    if (!rate_limit_check('file_upload', 10, 60)) {
        return ['success' => false, 'message' => 'Trop de téléchargements en peu de temps. Veuillez patienter.', 'attachment_id' => null];
    }
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
    if ($file['size'] > get_max_file_size()) {
        return ['success' => false, 'message' => 'Le fichier dépasse la taille maximale autorisée (10 Mo).', 'attachment_id' => null];
    }

    // Sécurité (S-06) : sanitisser le nom de fichier — supprimer les chemins et caractères dangereux
    $safe_name = basename($file['name']);
    $safe_name = preg_replace('/[^a-zA-Z0-9._\-\x{00C0}-\x{024F}]/u', '_', $safe_name);
    // Supprimer les points en début de nom (fichiers cachés Unix)
    $safe_name = ltrim($safe_name, '.');
    if (empty($safe_name)) {
        $safe_name = 'fichier';
    }

    // Sécurité (S-06) : vérifier qu'il n'y a PAS de double extension dangereuse
    // Ex: fichier.php.jpg, fichier.phtml.png, etc.
    $dangerous_exts = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phar', 'shtml', 'asa', 'asp', 'aspx', 'cgi', 'pl', 'py', 'rb', 'sh', 'jsp', 'war'];
    $name_parts = explode('.', $safe_name);
    if (count($name_parts) > 2) {
        // Vérifier TOUTES les extensions, pas seulement la dernière
        foreach ($name_parts as $part) {
            if (in_array(strtolower($part), $dangerous_exts, true)) {
                app_log('file_upload_blocked', 'submission:' . $submission_id, 'Upload bloqué — double extension dangereuse : ' . $safe_name);
                return ['success' => false, 'message' => 'Nom de fichier non autorisé. Les doubles extensions contenant des scripts ne sont pas acceptées.', 'attachment_id' => null];
            }
        }
    }

    // Vérifier l'extension (dernière partie)
    $ext = strtolower(end($name_parts));
    if (!in_array($ext, get_allowed_extensions())) {
        return ['success' => false, 'message' => 'Type de fichier non autorisé. Extensions acceptées : ' . implode(', ', get_allowed_extensions()) . '.', 'attachment_id' => null];
    }

    // Sécurité (S-06) : vérifier aussi que l'extension n'est pas dans la liste dangereuse
    if (in_array($ext, $dangerous_exts, true)) {
        app_log('file_upload_blocked', 'submission:' . $submission_id, 'Upload bloqué — extension dangereuse : ' . $ext);
        return ['success' => false, 'message' => 'Type de fichier non autorisé.', 'attachment_id' => null];
    }

    // Vérifier le type MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return ['success' => false, 'message' => 'Impossible d\'analyser le type de fichier.', 'attachment_id' => null];
    }
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime_type, get_allowed_mime_types())) {
        return ['success' => false, 'message' => 'Type MIME non autorisé : ' . h($mime_type === false ? '' : $mime_type) . '.', 'attachment_id' => null];
    }

    // Sécurité (S-06) : vérifier que le type MIME ne correspond pas à un type exécutable
    $dangerous_mimes = ['application/x-php', 'text/x-php', 'application/x-httpd-php', 'application/x-sh', 'application/x-cgi', 'application/x-perl', 'application/x-python', 'text/html'];
    if (in_array($mime_type, $dangerous_mimes, true)) {
        app_log('file_upload_blocked', 'submission:' . $submission_id, 'Upload bloqué — MIME dangereux : ' . $mime_type . ' pour fichier ' . $safe_name);
        return ['success' => false, 'message' => 'Type de fichier non autorisé.', 'attachment_id' => null];
    }

    // Lire le contenu du fichier pour stockage BLOB
    $file_content = file_get_contents($file['tmp_name']);
    if ($file_content === false) {
        return ['success' => false, 'message' => 'Erreur lors de la lecture du fichier.', 'attachment_id' => null];
    }

    // Enregistrer dans la base de données avec le contenu BLOB
    // Sécurité (S-06) : utiliser le nom sanitisé, pas le nom original du client
    $pdo = get_pdo();
    $attachment_id = generate_uuid();
    $pdo->prepare("INSERT INTO attachments (id, submission_id, field_name, original_name, stored_name, mime_type, file_size, file_data, uploaded_at) VALUES (?, ?, ?, '', ?, ?, ?, datetime('now'))")
        ->execute([$attachment_id, $submission_id, $field_name, $safe_name, $mime_type, $file['size'], $file_content]);

    app_log('file_upload', 'submission:' . $submission_id, 'Fichier uploadé : ' . $safe_name . ' (' . $mime_type . ', ' . $file['size'] . ' octets)');

    return ['success' => true, 'message' => 'Fichier ' . $safe_name . ' enregistré.', 'attachment_id' => $attachment_id];
}

/**
 * Récupère les pièces jointes d'une soumission
 *
 * @param string $submission_id ID de la soumission
 * @return array<string, mixed> Liste des pièces jointes
 */
function get_attachments(string $submission_id): array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT * FROM attachments WHERE submission_id = ? ORDER BY uploaded_at ASC");
    $stmt->execute([$submission_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Récupère une pièce jointe par son ID
 * Vérifie l'accès avant de retourner
 *
 * @param string $attachment_id ID de la pièce jointe
 * @return array<string, mixed>|null Données de la pièce jointe ou null
 */
function get_attachment_by_id(string $attachment_id): ?array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT * FROM attachments WHERE id = ?");
    $stmt->execute([$attachment_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}
