<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Handlers CRUD pour les champs de formulaire (add, update, delete).
 */
final class AdminFieldCrudHandler
{
    /**
     * @return array<string, mixed>
     */
    public static function handleAddField(): array
    {
        $form_id = trim($_POST['form_id'] ?? '');
        $ff_label = trim($_POST['ff_label'] ?? '');
        $ff_field_name = trim($_POST['ff_field_name'] ?? '');
        $ff_field_type = trim($_POST['ff_field_type'] ?? 'text');
        $ff_options_raw = trim($_POST['ff_options'] ?? '');
        $ff_required = isset($_POST['ff_required']) ? 1 : 0;
        $ff_ordre = (int) ($_POST['ff_ordre'] ?? 0);
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
        if (($ff_field_name === '' || $ff_field_name === '0') && ($ff_label !== '' && $ff_label !== '0')) {
            $ff_field_name = \generate_field_name($ff_label);
        }
        if ($form_id === '' || $form_id === '0' || ($ff_label === '' || $ff_label === '0') || ($ff_field_name === '' || $ff_field_name === '0')) {
            return ['error' => 'Le libellé du champ est requis.'];
        }
        try {
            $options_json = \parse_options_input($ff_options_raw);
            $ff_hint = trim($_POST['ff_hint'] ?? '');

            $repo = App::getInstance()->get(\App\Repository\FormRepository::class);
            $new_field_id = $repo->createField([
                'form_id' => $form_id, 'label' => $ff_label, 'field_type' => $ff_field_type,
                'field_name' => $ff_field_name, 'options' => $options_json, 'hint' => $ff_hint,
                'required' => $ff_required, 'ordre' => $ff_ordre, 'card_group' => $ff_card_group,
                'filled_by' => $ff_filled_by, 'validator_step' => $ff_validator_step, 'visibility' => $ff_visibility,
            ]);
            App::audit()->log('field_add', 'form:' . $form_id, "Champ '$ff_label' ajouté");
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($form_id) . '#field-' . urlencode($new_field_id)];
        } catch (\PDOException $e) {
            error_log('handleAddField error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function handleUpdateField(): array
    {
        $field_id = trim($_POST['field_id'] ?? '');
        $form_id = trim($_POST['form_id'] ?? '');
        $ff_label = trim($_POST['ff_label'] ?? '');
        $ff_field_name = trim($_POST['ff_field_name'] ?? '');
        $ff_field_type = trim($_POST['ff_field_type'] ?? 'text');
        $ff_options_raw = trim($_POST['ff_options'] ?? '');
        $ff_required = isset($_POST['ff_required']) ? 1 : 0;
        $ff_ordre = (int) ($_POST['ff_ordre'] ?? 0);
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
        if (($ff_field_name === '' || $ff_field_name === '0') && ($ff_label !== '' && $ff_label !== '0')) {
            $ff_field_name = \generate_field_name($ff_label);
        }
        if ($field_id === '' || $field_id === '0' || ($ff_label === '' || $ff_label === '0') || ($ff_field_name === '' || $ff_field_name === '0')) {
            return ['error' => 'Le libellé du champ est requis.'];
        }
        try {
            $options_json = \parse_options_input($ff_options_raw);
            $ff_hint = trim($_POST['ff_hint'] ?? '');

            $repo = App::getInstance()->get(\App\Repository\FormRepository::class);
            $repo->updateField($field_id, [
                'label' => $ff_label, 'field_type' => $ff_field_type, 'field_name' => $ff_field_name,
                'options' => $options_json, 'hint' => $ff_hint, 'required' => $ff_required,
                'ordre' => $ff_ordre, 'card_group' => $ff_card_group, 'filled_by' => $ff_filled_by,
                'validator_step' => $ff_validator_step, 'visibility' => $ff_visibility,
            ]);
            App::audit()->log('field_update', 'field:' . $field_id, "Champ '$ff_label' mis à jour");
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($form_id) . '#field-' . urlencode($field_id)];
        } catch (\PDOException $e) {
            error_log('handleUpdateField error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function handleDeleteField(): ?array
    {
        $field_id = trim($_POST['field_id'] ?? '');
        $form_id = trim($_POST['form_id'] ?? '');
        if ($field_id === '' || $field_id === '0') {
            return null;
        }
        try {
            $repo = App::getInstance()->get(\App\Repository\FormRepository::class);
            $repo->deleteField($field_id);
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($form_id) . '#fields'];
        } catch (\PDOException $e) {
            error_log('handleDeleteField error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }
}
