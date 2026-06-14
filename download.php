<?php
// download.php — Téléchargement sécurisé des pièces jointes
// L'accès est restreint aux utilisateurs authentifiés (admin, agent ou validateur)
require_once __DIR__ . '/helpers.php';

$attachment_id = (int)($_GET['id'] ?? 0);
if ($attachment_id <= 0) {
    http_response_code(400);
    die('ID de pièce jointe invalide.');
}

// Récupérer les infos du fichier
$attachment = get_attachment_by_id($attachment_id);
if (!$attachment) {
    http_response_code(404);
    die('Pièce jointe introuvable.');
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
    $sub_stmt->execute([(int)$attachment['submission_id']]);
    $owner = $sub_stmt->fetchColumn();
    if ($owner === $user) {
        $has_access = true;
    }

    // Vérifier si l'utilisateur est validateur sur cette soumission
    if (!$has_access) {
        $val_stmt = $pdo->prepare("SELECT 1 FROM tokens WHERE submission_id = ? AND email = ?");
        $val_stmt->execute([(int)$attachment['submission_id'], $user]);
        if ($val_stmt->fetch()) {
            $has_access = true;
        }
    }
}

if (!$has_access) {
    http_response_code(403);
    die('Accès non autorisé à cette pièce jointe.');
}

// Vérifier que le fichier existe sur le disque
$file_path = __DIR__ . '/db/uploads/' . $attachment['stored_name'];
if (!file_exists($file_path)) {
    http_response_code(404);
    die('Fichier introuvable sur le serveur.');
}

// Vérifier que le fichier est bien dans le répertoire d'uploads (sécurité anti-traversal)
$real_path = realpath($file_path);
$upload_dir = realpath(__DIR__ . '/db/uploads');
if ($real_path === false || strpos($real_path, $upload_dir) !== 0) {
    http_response_code(403);
    die('Accès interdit.');
}

// Envoyer le fichier
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

readfile($file_path);
exit;
