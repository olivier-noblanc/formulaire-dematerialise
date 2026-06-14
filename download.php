<?php
// download.php — Téléchargement sécurisé des pièces jointes
// L'accès est restreint aux utilisateurs authentifiés (admin, agent ou validateur)
// Les fichiers sont stockés en BLOB dans SQLite (depuis v4.0)
require_once __DIR__ . '/helpers.php';

$attachment_id = trim($_GET['id'] ?? '');
if (empty($attachment_id)) {
    render_error_page(400, 'Requête invalide',
        'L\'identifiant de pièce jointe fourni est invalide.',
        'Vérifiez que le lien que vous avez utilisé est correct et complet.');
}

// Récupérer les infos du fichier
$attachment = get_attachment_by_id($attachment_id);
if (!$attachment) {
    render_error_page(404, 'Pièce jointe introuvable',
        'La pièce jointe demandée n\'existe pas ou a été supprimée.',
        'Si vous avez suivi un lien depuis un email, la pièce jointe a peut-être été supprimée. Contactez l\'expéditeur de la demande.');
}

$user = get_auth_user();
$is_admin = is_admin_user();

// Vérifier les droits d'accès :
// - Admin : accès à tout
// - Propriétaire de la soumission : accès à ses propres fichiers
// - Validateur sur la soumission : accès aux fichiers de la soumission
$has_access = false;

if ($is_admin) {
    $has_access = true;
} else {
    $pdo = get_pdo();

    // Vérifier si l'utilisateur est le propriétaire de la soumission
    $sub_stmt = $pdo->prepare("SELECT submitted_by FROM submissions WHERE id = ?");
    $sub_stmt->execute([$attachment['submission_id']]);
    $owner = $sub_stmt->fetchColumn();
    if ($owner === $user) {
        $has_access = true;
    }

    // Vérifier si l'utilisateur est validateur sur cette soumission
    if (!$has_access) {
        $val_stmt = $pdo->prepare("SELECT 1 FROM tokens WHERE submission_id = ? AND email = ?");
        $val_stmt->execute([$attachment['submission_id'], $user]);
        if ($val_stmt->fetch()) {
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
$original_name = $attachment['original_name'];
$file_size = (int)$attachment['file_size'];

header('Content-Type: ' . $mime_type);
header('Content-Disposition: attachment; filename="' . str_replace('"', '\\"', $original_name) . '"');
header('Content-Length: ' . $file_size);
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Pour les PDF, permettre l'affichage inline
if ($mime_type === 'application/pdf') {
    header('Content-Disposition: inline; filename="' . str_replace('"', '\\"', $original_name) . '"');
}

// Depuis v4.0 : fichiers stockés en BLOB dans SQLite
if (!empty($attachment['file_data'])) {
    echo $attachment['file_data'];
    exit;
}

// Compatibilité descendante : anciens fichiers sur disque
if (!empty($attachment['stored_name'])) {
    $file_path = __DIR__ . '/db/uploads/' . $attachment['stored_name'];
    if (file_exists($file_path)) {
        // Vérifier que le fichier est bien dans le répertoire d'uploads (sécurité anti-traversal)
        $real_path = realpath($file_path);
        $upload_dir = realpath(__DIR__ . '/db/uploads');
        if ($real_path !== false && $upload_dir !== false && strpos($real_path, $upload_dir) === 0) {
            readfile($file_path);
            exit;
        }
    }
}

render_error_page(404, 'Fichier introuvable',
    'Le fichier demandé n\'existe pas sur le serveur. Il a peut-être été supprimé ou déplacé.',
    'Contactez un administrateur si vous pensez qu\'il s\'agit d\'une erreur.');
