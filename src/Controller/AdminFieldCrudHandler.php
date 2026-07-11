<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Handlers CRUD pour les champs de formulaire (add, update, delete).
 */
final class AdminFieldCrudHandler
{
    public static function handleAddField(\PDO $pdo): array
    {
        $form_id = trim($_POST['form_id'] ?? '');
        $ff_label = trim($_POST['ff_label'] ?? '');
        $ff_field_name = trim($_POST['ff_field_name'] ?? '');
        $ff_field_type = trim($_POST['ff_field_type'] ?? 'text');
        $ff_options_raw = trim($_POST['ff_options'] ?? '');
        $ff_required = isset($_POST['ff_required']) ? 1 : 0;
        $ff_ordre = (int)($_POST['ff_ordre'] ?? 0);
        $ff_card_group = AdminFormsHandlers::resolveCardGroup();
        $ff_filled_by = trim($_POST['ff_filled_by'] ?? '');
        if (!in_array($ff_filled_by, ['demandeur', 'validator'])) {
            $ff_filled_by = 'demandeur';
        }
        $ff_validator_step = trim($_POST['ff_validator_step'] ?? '');
        $ff_visibility = trim($_POST['ff_visibility'] ?? 'all');
        if (!in_array($ff_visibility, ['all', 'owner_only'], true)) {
            $ff_visibility = 'all';
        }

        if (empty($ff_field_name) && !empty($ff_label)) {
            $ff_field_name = \generate_field_name($ff_label);
        }

        if (empty($form_id) || empty($ff_label) || empty($ff_field_name)) {
            return ['error' => 'Le libellé du champ est requis.'];
        }
        try {
            $options_json = \parse_options_input($ff_options_raw);
            $ff_hint = trim($_POST['ff_hint'] ?? '');

            $new_field_id = \generate_uuid();
            $pdo->prepare("INSERT INTO form_fields (id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group, filled_by, validator_step, visibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$new_field_id, $form_id, $ff_label, $ff_field_type, $ff_field_name, $options_json, $ff_hint, $ff_required, $ff_ordre, $ff_card_group, $ff_filled_by, $ff_validator_step, $ff_visibility]);
            App::audit()->log('field_add', 'form:' . $form_id, "Champ '$ff_label' ajouté");
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($form_id) . '#field-' . urlencode($new_field_id)];
        } catch (\PDOException $e) {
            error_log('handleAddField error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }

    public static function handleUpdateField(\PDO $pdo): array
    {
        $field_id = trim($_POST['field_id'] ?? '');
        $form_id = trim($_POST['form_id'] ?? '');
        $ff_label = trim($_POST['ff_label'] ?? '');
        $ff_field_name = trim($_POST['ff_field_name'] ?? '');
        $ff_field_type = trim($_POST['ff_field_type'] ?? 'text');
        $ff_options_raw = trim($_POST['ff_options'] ?? '');
        $ff_required = isset($_POST['ff_required']) ? 1 : 0;
        $ff_ordre = (int)($_POST['ff_ordre'] ?? 0);
        $ff_card_group = AdminFormsHandlers::resolveCardGroup();
        $ff_filled_by = trim($_POST['ff_filled_by'] ?? '');
        if (!in_array($ff_filled_by, ['demandeur', 'validator'])) {
            $ff_filled_by = 'demandeur';
        }
        $ff_validator_step = trim($_POST['ff_validator_step'] ?? '');
        $ff_visibility = trim($_POST['ff_visibility'] ?? 'all');
        if (!in_array($ff_visibility, ['all', 'owner_only'], true)) {
            $ff_visibility = 'all';
        }

        if (empty($ff_field_name) && !empty($ff_label)) {
            $ff_field_name = \generate_field_name($ff_label);
        }

        if (empty($field_id) || empty($ff_label) || empty($ff_field_name)) {
            return ['error' => 'Le libellé du champ est requis.'];
        }
        try {
            $options_json = \parse_options_input($ff_options_raw);
            $ff_hint = trim($_POST['ff_hint'] ?? '');

            $pdo->prepare("UPDATE form_fields SET label = ?, field_type = ?, field_name = ?, options = ?, hint = ?, required = ?, ordre = ?, card_group = ?, filled_by = ?, validator_step = ?, visibility = ? WHERE id = ?")
                ->execute([$ff_label, $ff_field_type, $ff_field_name, $options_json, $ff_hint, $ff_required, $ff_ordre, $ff_card_group, $ff_filled_by, $ff_validator_step, $ff_visibility, $field_id]);
            App::audit()->log('field_update', 'field:' . $field_id, "Champ '$ff_label' mis à jour");
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($form_id) . '#field-' . urlencode($field_id)];
        } catch (\PDOException $e) {
            error_log('handleUpdateField error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }

    public static function handleDeleteField(\PDO $pdo): array
    {
        $field_id = trim($_POST['field_id'] ?? '');
        $form_id = trim($_POST['form_id'] ?? '');
        if (empty($field_id)) {
            return [];
        }
        try {
            $pdo->prepare("DELETE FROM form_fields WHERE id = ?")->execute([$field_id]);
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($form_id) . '#fields'];
        } catch (\PDOException $e) {
            error_log('handleDeleteField error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }
}
