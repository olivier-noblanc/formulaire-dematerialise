<?php
// download.php — Téléchargement sécurisé des pièces jointes + export JSON de soumission
// L'accès est restreint aux utilisateurs authentifiés (admin, agent ou validateur)
// Les fichiers sont stockés en BLOB dans SQLite (depuis v4.0)
require_once dirname(__DIR__) . '/helpers.php';
use App\Core\App;

// P2-B : Mode export de soumission en JSON (incluant les données validator).
// Endpoint : download.php?mode=export_submission&submission_id=<uuid>
// On branche avant le code « pièce jointe » pour éviter de consommer l'argument ?id=.
$mode = (string)trim($_GET['mode'] ?? '');
if ($mode === 'export_submission') {
    export_submission_json();
    exit; // Ceinture : export_submission_json() fait déjà exit, mais par sécurité.
}

$attachment_id = (string)trim($_GET['id'] ?? '');
if (empty($attachment_id)) {
    render_error_page(400, 'Requête invalide',
        'L\'identifiant de pièce jointe fourni est invalide.',
        'Vérifiez que le lien que vous avez utilisé est correct et complet.');
}

// Sécurité (S-07) : valider le format de l'identifiant de pièce jointe
try {
        $attachment_id = (string)validate_input($attachment_id, 'uuid');
} catch (\InvalidArgumentException $e) {
    App::audit()->securityLog('invalid_attachment_id', 'ID=' . substr($attachment_id, 0, 20));
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
    $pdo = App::db()->getPdo();

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
// Sécurité : revalider le type MIME au moment du téléchargement
$allowed_mimes = get_allowed_mime_types();
if (!in_array($mime_type, $allowed_mimes)) {
    render_error_page(403, 'Type de fichier non autorisé',
        'Le type MIME de cette pièce jointe n\'est pas dans la liste autorisée.',
        'Contactez un administrateur si vous pensez qu\'il s\'agit d\'une erreur.');
}
(string)$original_name = $attachment['original_name'];
$file_size = (int)$attachment['file_size'];

// Strip CR/LF to prevent header injection
(string)$original_name = str_replace(["\r", "\n"], '', (string)$original_name);
(string)$original_name = str_replace('"', '\\"', (string)$original_name);
$safe_name = rawurlencode($attachment['original_name']);

header('Content-Type: ' . $mime_type);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . $file_size);
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Sécurité (S-12) : forcer le téléchargement (attachment) pour tous les types sauf PDF.
// Cela prévient l'exécution de contenu (HTML/JS dans text/plain, formules CSV, 
// exécutables dans ZIP). Seul le PDF est sûr en inline.
if ($mime_type === 'application/pdf') {
    header("Content-Disposition: inline; filename=\"(string)$original_name\"; filename*=UTF-8''$safe_name");
} else {
    header("Content-Disposition: attachment; filename=\"(string)$original_name\"; filename*=UTF-8''$safe_name");
}

// Depuis v4.0 : fichiers stockés en BLOB dans SQLite
if (!empty($attachment['file_data'])) {
    echo $attachment['file_data'];
    exit;
}

// Compatibilité descendante : anciens fichiers sur disque
if (!empty($attachment['stored_name'])) {
    $file_path = dirname(__DIR__) . '/db/uploads/' . $attachment['stored_name'];
    if (file_exists($file_path)) {
        // Vérifier que le fichier est bien dans le répertoire d'uploads (sécurité anti-traversal)
        $real_path = realpath($file_path);
        $upload_dir = realpath(dirname(__DIR__) . '/db/uploads');
        if ($real_path !== false && $upload_dir !== false && strpos($real_path, $upload_dir) === 0) {
            readfile($file_path);
            exit;
        }
    }
}

render_error_page(404, 'Fichier introuvable',
    'Le fichier demandé n\'existe pas sur le serveur. Il a peut-être été supprimé ou déplacé.',
    'Contactez un administrateur si vous pensez qu\'il s\'agit d\'une erreur.');

/**
 * P2-B : Exporte une soumission complète au format JSON, incluant les données
 * validator (filled_by='validator'). Endpoint dédié car download.php historiquement
 * ne servait que des pièces jointes ; on ajoute ce mode sans casser l'existant.
 *
 * Droits d'accès (mêmes règles que les pièces jointes) :
 *  - Admin : accès à tout
 *  - Propriétaire de la soumission (submitted_by) : accès à ses propres données
 *  - Validateur sur la soumission (table tokens) : accès aux données
 *
 * @return void  (la fonction termine toujours par exit)
 */
function export_submission_json(): void {
    $submission_id = (string)trim($_GET['submission_id'] ?? '');
    if ($submission_id === '') {
        render_error_page(400, 'Requête invalide',
            'L\'identifiant de soumission fourni est invalide.',
            'Vérifiez que le lien que vous avez utilisé est correct et complet.');
    }

    // Sécurité (S-07) : valider le format UUID de la soumission
    try {
        $submission_id = (string)validate_input($submission_id, 'uuid');
    } catch (\InvalidArgumentException $e) {
        App::audit()->securityLog('invalid_submission_id', 'ID=' . substr($submission_id, 0, 20));
        render_error_page(400, 'Requête invalide',
            'L\'identifiant de soumission fourni est invalide.',
            'Vérifiez que le lien que vous avez utilisé est correct et complet.');
    }

    $pdo = App::db()->getPdo();

    // Récupérer la soumission + le label du formulaire
    $sub_stmt = $pdo->prepare(
        "SELECT s.*, f.label AS form_label "
        . "FROM submissions s JOIN forms f ON f.id = s.form_id "
        . "WHERE s.id = ?"
    );
    $sub_stmt->execute([$submission_id]);
    /** @var array<string, mixed>|false $submission */
    $submission = $sub_stmt->fetch(PDO::FETCH_ASSOC);
    if ($submission === false) {
        render_error_page(404, 'Soumission introuvable',
            'La soumission demandée n\'existe pas ou a été supprimée.',
            'Contactez un administrateur si vous pensez qu\'il s\'agit d\'une erreur.');
    }

    $user = get_auth_user();
    $is_admin = is_admin_user();

    // Vérifier les droits d'accès (mêmes règles que pour les pièces jointes) :
    // - Admin : accès à tout
    // - Propriétaire de la soumission : accès à ses propres données
    // - Validateur sur la soumission : accès aux données
    $has_access = false;
    if ($is_admin) {
        $has_access = true;
    } elseif ((string)($submission['submitted_by'] ?? '') === $user) {
        $has_access = true;
    } else {
        $val_stmt = $pdo->prepare("SELECT 1 FROM tokens WHERE submission_id = ? AND email = ?");
        $val_stmt->execute([$submission_id, $user]);
        if ($val_stmt->fetch() !== false) {
            $has_access = true;
        }
    }

    if (!$has_access) {
        render_error_page(403, 'Accès non autorisé',
            'Vous n\'avez pas les droits nécessaires pour exporter cette soumission.',
            'Seuls l\'auteur de la demande, les validateurs concernés et les administrateurs peuvent la consulter.');
    }

    // Construire le payload d'export
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

    // Tokens associés (sans exposer le secret `token` pour ne pas le fuiter dans l'export)
    $tokens_stmt = $pdo->prepare(
        "SELECT step_id, email, role, sent_at, done_at, expires_at "
        . "FROM tokens WHERE submission_id = ? ORDER BY sent_at"
    );
    $tokens_stmt->execute([$submission_id]);
    $export['tokens'] = $tokens_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Liste des pièces jointes (sans le BLOB file_data pour ne pas exploser le JSON)
    $atts_stmt = $pdo->prepare(
        "SELECT id, field_name, original_name, mime_type, file_size, uploaded_at "
        . "FROM attachments WHERE submission_id = ? ORDER BY uploaded_at"
    );
    $atts_stmt->execute([$submission_id]);
    $export['attachments'] = $atts_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Données validator (filled_by='validator') — Option A (recommandée par P2-B)
    $vd_stmt = $pdo->prepare(
        "SELECT field_name, field_label, field_type, value, filled_by, filled_at, "
        . "       step_id, step_label, filled_by_email "
        . "FROM submission_validator_data "
        . "WHERE submission_id = ? "
        . "ORDER BY filled_at, field_name"
    );
    $vd_stmt->execute([$submission_id]);
    $export['validator_data'] = $vd_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Journalisation avant l'envoi du payload
    App::audit()->log('export_submission', 'submission:' . $submission_id, 'Export JSON de la soumission par ' . $user, '');

    // Envoi du JSON en téléchargement
    $filename = 'submission_' . $submission_id . '_' . date('Ymd_His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
