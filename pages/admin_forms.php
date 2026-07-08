<?php
// admin_forms.php — Gestion des formulaires et des étapes
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/lib/admin_forms_json.php';
require_once dirname(__DIR__) . '/lib/admin_forms_samples.php';
use App\Core\App;

// Vérification des droits d'accès
require_admin();

$pdo = get_pdo();

// Récupération des formulaires pour le sélecteur
$forms = _dbm_q($pdo, "SELECT id, label FROM forms ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);

// Récupération de l'ID du formulaire sélectionné
$form_id = trim($_GET['form_id'] ?? '');

// Récupération de l'ID de l'étape à modifier
$edit_step_id = trim($_GET['edit_step'] ?? '');

// Récupération de l'ID du champ à modifier
$edit_field_id = trim($_GET['edit_field'] ?? '');

// Sécurité (S-07/A-01) : valider les identifiants GET
try {
    if ($form_id) $form_id = validate_input($form_id, 'uuid');
    if ($edit_step_id) $edit_step_id = validate_input($edit_step_id, 'uuid');
    if ($edit_field_id) $edit_field_id = validate_input($edit_field_id, 'uuid');
} catch (\InvalidArgumentException $e) {
    App::audit()->securityLog('invalid_admin_forms_id', 'form_id=' . substr((string)$form_id, 0, 20) . ' edit_step=' . substr((string)$edit_step_id, 0, 20) . ' edit_field=' . substr((string)$edit_field_id, 0, 20));
    render_error_page(400, 'Paramètre invalide', 'Un des identifiants fournis est invalide.', 'Vérifiez l\'URL et réessayez.');
}

// Traitement des actions POST
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
}


// ── JSON Schema Validation (extrait vers lib/admin_forms_json.php) ──
require_once dirname(__DIR__) . '/lib/admin_forms_json.php';


// ── POST Handlers (extraits vers lib/admin_forms_handlers.php) ──
require_once dirname(__DIR__) . '/lib/admin_forms_handlers.php';

// Variables de rendu initialisées pour le scope global (rendu HTML plus bas)
$error_msg       = $error_msg       ?? '';
$success_msg     = $success_msg     ?? '';
$validation_html = $validation_html ?? '';
$preserved_json  = $preserved_json  ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' || $action) {
    $result = handle_admin_action($pdo, $action, (string)$form_id);
    if ($result !== null) {
        // Export JSON : envoyer le fichier de téléchargement et terminer
        if (isset($result['json_output']) && isset($result['filename'])) {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
            echo $result['json_output'];
            exit;
        }
        // Redirection classique (Location + exit)
        if (isset($result['redirect'])) {
            header('Location: ' . $result['redirect']);
            exit;
        }
        // Messages d'état et override de form_id (préserve le comportement
        // historique où update_form/delete_form/add_step réaffectaient $form_id)
        if (isset($result['error']))           $error_msg       = $result['error'];
        if (isset($result['success']))         $success_msg     = $result['success'];
        if (isset($result['validation_html'])) $validation_html = $result['validation_html'];
        if (isset($result['preserved_json']))  $preserved_json  = $result['preserved_json'];
        if (isset($result['form_id']))         $form_id         = $result['form_id'];
    }
}

// ── Populate sample forms (extrait vers lib/admin_forms_samples.php) ──
if ($action === 'populate_samples') {
    try {
        $success_msg = populate_sample_forms($pdo);
    } catch (RuntimeException $e) {
        $error_msg = $e->getMessage();
    } catch (Throwable $e) {
        $error_msg = 'Erreur lors du peuplement : ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    }
}



// ── Data fetching ──────────────────────────────────────────────

$form = null;
$steps = [];
$form_fields = [];
$existing_groups = [];

if (!empty($form_id)) {

    $stmt = $pdo->prepare("SELECT * FROM forms WHERE id = ?");
    $stmt->execute([$form_id]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($form) {
        // Steps with recipients
        $stmt = $pdo->prepare("
            SELECT s.*,
                   (SELECT COUNT(*) FROM step_recipients sr WHERE sr.step_id = s.id) as recipient_count
            FROM steps s
            WHERE s.form_id = ?
            ORDER BY s.ordre, s.label
        ");
        $stmt->execute([$form_id]);
        $steps = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($steps as &$step) {
            $stmt = $pdo->prepare("SELECT * FROM step_recipients WHERE step_id = ? ORDER BY email");
            $stmt->execute([$step['id']]);
            $step['recipients'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($step); // CRITIQUE : sans ça, $step reste une référence vers le dernier élément
                      // et la boucle foreach ($steps as $step) suivante écrase le dernier step
                      // avec les données du premier → bug "renomer un step renomme les 2"

        // Form fields
        $stmt = $pdo->prepare("SELECT * FROM form_fields WHERE form_id = ? ORDER BY ordre, id");
        $stmt->execute([$form_id]);
        $form_fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Existing card groups
        $stmt = $pdo->prepare("SELECT DISTINCT card_group FROM form_fields WHERE form_id = ? ORDER BY card_group");
        $stmt->execute([$form_id]);
        $existing_groups = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Form owners
        $owners = get_form_owners((string)$form_id);
    }
}

// ── Group steps by ordre for the workflow diagram ──────────────
$steps_by_ordre = [];
foreach ($steps as $step) {
    $steps_by_ordre[$step['ordre']][] = $step;
}
ksort($steps_by_ordre);

// ── Rendering (extrait vers lib/admin_forms_render.php) ──
require_once dirname(__DIR__) . '/lib/admin_forms_render.php';
render_admin_forms_page([
    'forms'           => $forms,
    'form_id'         => $form_id,
    'form'            => $form,
    'steps'           => $steps,
    'form_fields'     => $form_fields,
    'existing_groups' => $existing_groups,
    'edit_step_id'    => $edit_step_id,
    'edit_field_id'   => $edit_field_id,
    'error_msg'       => $error_msg,
    'success_msg'     => $success_msg,
    'validation_html' => $validation_html,
    'preserved_json'  => $preserved_json,
    'steps_by_ordre'  => $steps_by_ordre,
    'owners'          => $owners ?? [],
]);
