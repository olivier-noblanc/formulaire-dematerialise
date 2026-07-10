<?php
declare(strict_types=1);

/**
 * POST handlers admin_forms.php — Wrapper backward-compatible.
 *
 * La logique métier est dans App\Controller\AdminFormsHandlers.
 * Ce fichier maintient le dispatcher switch pour la compatibilité
 * avec les tests (test_confirm_action_dispatch.php) qui lisent
 * le fichier source à la recherche de case 'xxx'.
 *
 * @package lib
 * @deprecated Utilisez App\Controller\AdminFormsHandlers directement.
 */

// ── Helpers internes (délegates vers la classe) ──────────────────
function _post_form_id(): array { return \App\Controller\AdminFormsHandlers::postFormId(); }
function _post_step_id(): array { return \App\Controller\AdminFormsHandlers::postStepId(); }
function _resolve_card_group(): string { return \App\Controller\AdminFormsHandlers::resolveCardGroup(); }

// ── Handlers individuels ─────────────────────────────────────────
function handle_admin_action_add_form(PDO $pdo): array { return \App\Controller\AdminFormsHandlers::handleAddForm($pdo); }
function handle_admin_action_update_form(PDO $pdo): array { return \App\Controller\AdminFormsHandlers::handleUpdateForm($pdo); }
function handle_admin_action_delete_form(PDO $pdo): array { return \App\Controller\AdminFormsHandlers::handleDeleteForm($pdo); }
function handle_admin_action_duplicate_form(PDO $pdo): array { return \App\Controller\AdminFormsHandlers::handleDuplicateForm($pdo); }
function handle_admin_action_add_field(PDO $pdo): array { return \App\Controller\AdminFormsHandlers::handleAddField($pdo); }
function handle_admin_action_update_field(PDO $pdo): array { return \App\Controller\AdminFormsHandlers::handleUpdateField($pdo); }
function handle_admin_action_delete_field(PDO $pdo): array { return \App\Controller\AdminFormsHandlers::handleDeleteField($pdo); }
function handle_admin_action_add_owner(PDO $pdo): array { return \App\Controller\AdminFormsHandlers::handleAddOwner($pdo); }
function handle_admin_action_delete_owner(PDO $pdo): array { return \App\Controller\AdminFormsHandlers::handleDeleteOwner($pdo); }
function handle_admin_action_export_form(PDO $pdo): array { return \App\Controller\AdminFormsHandlers::handleExportForm($pdo); }
function handle_admin_action_validate_json(): array { return \App\Controller\AdminFormsHandlers::handleValidateJson(); }
function handle_admin_action_import_form(PDO $pdo): array { return \App\Controller\AdminFormsHandlers::handleImportForm($pdo); }
function handle_admin_action_add_step(PDO $pdo): array { return \App\Controller\AdminFormsHandlers::handleAddStep($pdo); }
function handle_admin_action_update_step(PDO $pdo, string $get_form_id): array { return \App\Controller\AdminFormsHandlers::handleUpdateStep($pdo, $get_form_id); }
function handle_admin_action_delete_step(PDO $pdo, string $get_form_id): array { return \App\Controller\AdminFormsHandlers::handleDeleteStep($pdo, $get_form_id); }
function handle_admin_action_add_recipient(PDO $pdo, string $get_form_id): array { return \App\Controller\AdminFormsHandlers::handleAddRecipient($pdo, $get_form_id); }
function handle_admin_action_delete_recipient(PDO $pdo, string $get_form_id): array { return \App\Controller\AdminFormsHandlers::handleDeleteRecipient($pdo, $get_form_id); }

// ── Dispatcher (maintient le switch pour test_confirm_action_dispatch.php) ──
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
        case 'remove_owner':     return handle_admin_action_delete_owner($pdo);
        case 'export_form':      return handle_admin_action_export_form($pdo);
        case 'validate_json':    return handle_admin_action_validate_json();
        case 'import_form':      return handle_admin_action_import_form($pdo);
        default:                 return null;
    }
}
