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
 *  - 'warnings'        (list<string>)  → avertissements d'import (rendus visibles)
 *  - null                              → aucune action / handler inexistant
 */
final class AdminFormsHandlers
{
    // ── Helpers internes ───────────────────────────────────────────

    /**
     * Extrait et valide un identifiant UUID depuis $_POST.
     *
     * Toute écriture (INSERT/UPDATE/DELETE) doit passer par ce helper plutôt que
     * d'utiliser un identifiant brut : un identifiant non UUID ne peut désigner
     * aucune ligne et doit produire une erreur utilisateur, pas une requête.
     *
     * @param string      $key         Clé de $_POST (field_id, step_id, recipient_id, …)
     * @param string      $subject     Libellé du sujet pour le message d'erreur (« de champ »)
     * @param string|null $fallbackKey Clé alternative lue si $key est vide
     * @return array{0:string,1:string|null} [valeur validée, error_msg_or_null]
     */
    public static function postUuid(string $key, string $subject, ?string $fallbackKey = null): array
    {
        $raw = (string) ($_POST[$key] ?? '');
        if (trim($raw) === '' && $fallbackKey !== null) {
            $raw = (string) ($_POST[$fallbackKey] ?? '');
        }

        return self::validateUuidValue($raw, $subject);
    }

    /**
     * Valide une valeur d'identifiant UUID déjà extraite (POST, GET, JSON…).
     *
     * @param string $raw     Valeur brute à valider
     * @param string $subject Libellé du sujet pour le message d'erreur (« de formulaire »)
     * @return array{0:string,1:string|null} [valeur validée, error_msg_or_null]
     */
    public static function validateUuidValue(string $raw, string $subject): array
    {
        try {
            return [(string) \validate_input(trim($raw), 'uuid'), null];
        } catch (\InvalidArgumentException) {
            // @silent-ok: fallback returns user-facing validation error
            return ['', 'Identifiant ' . $subject . ' invalide.'];
        }
    }

    /**
     * Extrait et valide un form_id depuis $_POST.
     * @return array{0:string,1:string|null} [form_id, error_msg_or_null]
     */
    public static function postFormId(): array
    {
        return self::validateUuidValue((string) ($_POST['form_id'] ?? ''), 'de formulaire');
    }

    /**
     * Extrait et valide un step_id depuis $_POST.
     * @return array{0:string,1:string|null} [step_id, error_msg_or_null]
     */
    public static function postStepId(): array
    {
        return self::validateUuidValue((string) ($_POST['step_id'] ?? ''), 'd\'étape');
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
     * @return array{error?: string, redirect?: string, success?: string, form_id?: string, filename?: string, json_output?: string, validation_html?: string, preserved_json?: string, warnings?: list<string>}|null
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
            // Audit 2026-11-26 : le bouton « Formulaires exemples »
            // (adminForms_selectorPanel.php) postait action=populate_samples,
            // accepté par le contrôleur (CSRF vérifié) puis ignoré ici —
            // dispatch() renvoyait null et rien ne se passait. Le service
            // existait déjà (SampleFormsService via populate_sample_forms()).
            'populate_samples'  => AdminFormCrudHandler::handlePopulateSamples(),
            default            => null,
        };
    }
}
