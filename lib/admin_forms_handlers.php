<?php
declare(strict_types=1);

/**
 * POST handlers admin_forms.php — Façade + dispatcher.
 *
 * Ce fichier est le point d'entrée pour admin_forms.php. Il charge les
 * deux sous-modules contenant les handlers :
 *  - admin_forms_handlers_forms.php  → formulaires, champs, propriétaires, JSON
 *  - admin_forms_handlers_steps.php  → étapes de validation, destinataires
 *
 * Puis définit le dispatcher {@see handle_admin_action()} qui route une
 * action POST vers le handler correspondant.
 *
 * Contrat de retour des handlers (tableau associatif) :
 *  - 'redirect'        (string)        → header('Location: …') + exit
 *  - 'error'           (string)        → $error_msg
 *  - 'success'         (string)        → $success_msg
 *  - 'validation_html' (string)        → $validation_html (panneau d'import)
 *  - 'preserved_json'  (string)        → $preserved_json (textarea d'import)
 *  - 'json_output'     (string)        → export JSON à télécharger
 *  - 'filename'        (string)        → nom du fichier d'export
 *  - 'form_id'         (string)        → override $form_id (compat comportement)
 *  - null                              → aucune action / handler inexistant
 *
 * @package lib
 */

require_once __DIR__ . '/admin_forms_handlers_forms.php';
require_once __DIR__ . '/admin_forms_handlers_steps.php';

// ── Dispatcher ─────────────────────────────────────────────────

/**
 * Route une action POST vers le handler correspondant.
 *
 * @param PDO    $pdo         Connexion PDO à la base.
 * @param string $action      Valeur de $_POST['action'] (peut être vide).
 * @param string $get_form_id form_id validé issu de $_GET (utilisé pour les
 *                            redirections des handlers qui ne reçoivent pas
 *                            form_id en POST — delete_step, add_recipient,
 *                            delete_recipient).
 * @return array<string,mixed>|null Tableau de résultats ou null si action vide/inconnue.
 */
function handle_admin_action(PDO $pdo, string $action, string $get_form_id = ''): ?array {
    switch ($action) {
        case 'add_form':         return handle_admin_action_add_form($pdo);
        case 'update_form':      return handle_admin_action_update_form($pdo);
        case 'delete_form':      return handle_admin_action_delete_form($pdo);
        case 'duplicate_form':   return handle_admin_action_duplicate_form($pdo);
        case 'add_step':         return handle_admin_action_add_step($pdo);
        case 'update_step':      return handle_admin_action_update_step($pdo, $get_form_id);
        case 'delete_step':      return handle_admin_action_delete_step($pdo, $get_form_id);
        case 'add_recipient':    return handle_admin_action_add_recipient($pdo, $get_form_id);
        case 'delete_recipient': return handle_admin_action_delete_recipient($pdo, $get_form_id);
        case 'add_field':        return handle_admin_action_add_field($pdo);
        case 'update_field':     return handle_admin_action_update_field($pdo);
        case 'delete_field':     return handle_admin_action_delete_field($pdo);
        case 'add_owner':        return handle_admin_action_add_owner($pdo);
        case 'delete_owner':     return handle_admin_action_delete_owner($pdo);
        case 'remove_owner':     return handle_admin_action_delete_owner($pdo);  // v10.0.4 — alias (confirm_action envoie remove_owner)
        case 'export_form':      return handle_admin_action_export_form($pdo);
        case 'validate_json':    return handle_admin_action_validate_json();
        case 'import_form':      return handle_admin_action_import_form($pdo);
        default:                 return null;
    }
}
