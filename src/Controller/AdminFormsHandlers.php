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
        } catch (\InvalidArgumentException $e) {
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
        } catch (\InvalidArgumentException $e) {
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
        if (!empty($ff_card_group_new)) {
            return $ff_card_group_new;
        }
        if ($ff_card_group_raw === '__new__' || empty($ff_card_group_raw)) {
            return 'Général';
        }
        return $ff_card_group_raw;
    }

    // ── Dispatcher ─────────────────────────────────────────────────

    /**
     * Route une action POST vers le handler correspondant.
     *
     * @param \PDO  $pdo         Connexion PDO à la base.
     * @param string $action      Valeur de $_POST['action'].
     * @param string $get_form_id form_id validé issu de $_GET.
     * @return array<string,mixed>|null
     */
    public static function dispatch(\PDO $pdo, string $action, string $get_form_id = ''): ?array
    {
        return match ($action) {
            'add_form'         => AdminFormCrudHandler::handleAddForm($pdo),
            'update_form'      => AdminFormCrudHandler::handleUpdateForm($pdo),
            'delete_form'      => AdminFormCrudHandler::handleDeleteForm($pdo),
            'duplicate_form'   => AdminFormCrudHandler::handleDuplicateForm($pdo),
            'add_step'         => AdminStepCrudHandler::handleAddStep($pdo),
            'update_step'      => AdminStepCrudHandler::handleUpdateStep($pdo, $get_form_id),
            'delete_step'      => AdminStepCrudHandler::handleDeleteStep($pdo, $get_form_id),
            'add_recipient'    => AdminRecipientHandler::handleAddRecipient($pdo, $get_form_id),
            'delete_recipient' => AdminRecipientHandler::handleDeleteRecipient($pdo, $get_form_id),
            'add_field'        => AdminFieldCrudHandler::handleAddField($pdo),
            'update_field'     => AdminFieldCrudHandler::handleUpdateField($pdo),
            'delete_field'     => AdminFieldCrudHandler::handleDeleteField($pdo),
            'add_owner'        => AdminRecipientHandler::handleAddOwner($pdo),
            'delete_owner'     => AdminRecipientHandler::handleDeleteOwner($pdo),
            'remove_owner'     => AdminRecipientHandler::handleDeleteOwner($pdo),
            'export_form'      => AdminImportExportHandler::handleExportForm($pdo),
            'validate_json'    => AdminImportExportHandler::handleValidateJson(),
            'import_form'      => AdminImportExportHandler::handleImportForm($pdo),
            default            => null,
        };
    }
}
