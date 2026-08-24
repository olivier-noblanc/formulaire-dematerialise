<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * Dispatcher POST pour admin_forms.php — délègue aux handler classes métier.
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
 */
final class AdminFormsHandlers
{
    // ── Helpers internes ───────────────────────────────────────────

    /**
     * Extrait et valide un form_id depuis $_POST.
     * @return array{0:string,1:string|null} [form_id, error_msg_or_null]
     */
    public static function postFormId(): array
    {
        $form_id = trim($_POST['form_id'] ?? '');
        try {
            $form_id = (string) \validate_input($form_id, 'uuid');
            return [$form_id, null];
        } catch (\InvalidArgumentException) {
            // @silent-ok: fallback returns user-facing validation error
            return ['', 'Identifiant de formulaire invalide.'];
        }
    }

    /**
     * Extrait et valide un step_id depuis $_POST.
     * @return array{0:string,1:string|null} [step_id, error_msg_or_null]
     */
    public static function postStepId(): array
    {
        $step_id = trim($_POST['step_id'] ?? '');
        try {
            $step_id = (string) \validate_input($step_id, 'uuid');
            return [$step_id, null];
        } catch (\InvalidArgumentException) {
            // @silent-ok: fallback returns user-facing validation error
            return ['', 'Identifiant d\'étape invalide.'];
        }
    }

    /**
     * Construit la valeur de card_group à partir des inputs POST.
     */
    public static function resolveCardGroup(): string
    {
        $ff_card_group_raw = trim($_POST['ff_card_group'] ?? '');
        $ff_card_group_new = trim($_POST['ff_card_group_new'] ?? '');
        if ($ff_card_group_new !== '' && $ff_card_group_new !== '0') {
            return $ff_card_group_new;
        }
        if ($ff_card_group_raw === '__new__' || ($ff_card_group_raw === '' || $ff_card_group_raw === '0')) {
            return 'Général';
        }
        return $ff_card_group_raw;
    }

    // ── Dispatcher ─────────────────────────────────────────────────

    /**
     * Route une action POST vers le handler correspondant.
     *
     * @param string $action      Valeur de $_POST['action'].
     * @param string $get_form_id form_id validé issu de $_GET.
     * @return array{error?: string, redirect?: string, success?: string, form_id?: string, filename?: string, json_output?: string, validation_html?: string, preserved_json?: string}|null
     */
    public static function dispatch(string $action, string $get_form_id = ''): ?array
    {
        return match ($action) {
            'add_form'         => AdminFormCrudHandler::handleAddForm(),
            'update_form'      => AdminFormCrudHandler::handleUpdateForm(),
            'delete_form'      => AdminFormCrudHandler::handleDeleteForm(),
            'duplicate_form'   => AdminFormCrudHandler::handleDuplicateForm(),
            'add_step'         => AdminStepCrudHandler::handleAddStep(),
            'update_step'      => AdminStepCrudHandler::handleUpdateStep($get_form_id),
            'delete_step'      => AdminStepCrudHandler::handleDeleteStep($get_form_id),
            'add_recipient'    => AdminRecipientHandler::handleAddRecipient($get_form_id),
            'delete_recipient' => AdminRecipientHandler::handleDeleteRecipient($get_form_id),
            'add_field'        => AdminFieldCrudHandler::handleAddField(),
            'update_field'     => AdminFieldCrudHandler::handleUpdateField(),
            'delete_field'     => AdminFieldCrudHandler::handleDeleteField(),
            'add_owner'        => AdminRecipientHandler::handleAddOwner(),
            'delete_owner'     => AdminRecipientHandler::handleDeleteOwner(),
            'remove_owner'     => AdminRecipientHandler::handleDeleteOwner(),
            'export_form'      => AdminImportExportHandler::handleExportForm(),
            'validate_json'    => AdminImportExportHandler::handleValidateJson(),
            'import_form'      => AdminImportExportHandler::handleImportForm(),
            default            => null,
        };
    }
}
