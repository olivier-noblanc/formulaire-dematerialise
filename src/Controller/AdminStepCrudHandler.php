<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Handlers CRUD pour les étapes de validation (add, update, delete).
 */
final class AdminStepCrudHandler
{
    /**
     * @return array{error?: string, form_id?: string, redirect?: string}
     */
    public static function handleAddStep(): array
    {
        [$form_id, $err] = AdminFormsHandlers::postFormId();
        if ($err !== null) {
            return ['error' => $err, 'form_id' => ''];
        }
        $label = trim($_POST['label'] ?? '');
        $ordre = (int) ($_POST['ordre'] ?? 0);
        if ($label === '' || $label === '0' || $ordre <= 0) {
            return ['error' => 'Les champs obligatoires ne sont pas remplis.'];
        }
        try {
            $repo = App::getInstance()->get(\App\Repository\FormRepository::class);
            $new_step_id = $repo->createStep(['form_id' => $form_id, 'label' => $label, 'ordre' => $ordre]);
            App::audit()->log('step_add', 'form:' . $form_id, "Étape '$label' ajoutée");
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode((string) $form_id) . '#step-' . urlencode($new_step_id)];
        } catch (\PDOException $e) {
            error_log('handleAddStep error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }

    /**
     * @return array{error?: string, redirect?: string}
     */
    public static function handleUpdateStep(\PDO $pdo, string $get_form_id): array
    {
        [$step_id, $err] = AdminFormsHandlers::postStepId();
        if ($err !== null) {
            return ['error' => $err];
        }
        $label = trim($_POST['label'] ?? '');
        $ordre = (int) ($_POST['ordre'] ?? 0);
        $actif = isset($_POST['actif']) ? 1 : 0;
        if (empty($step_id) || ($label === '' || $label === '0') || $ordre <= 0) {
            return ['error' => 'Les champs obligatoires ne sont pas remplis.'];
        }

        $condition_field = trim($_POST['condition_field'] ?? '');
        $condition_op    = trim($_POST['condition_op'] ?? '');
        $condition_value = trim($_POST['condition_value'] ?? '');
        $valid_ops = ['equals', 'not_equals', 'contains', 'not_empty', 'empty'];
        if ($condition_op !== '' && !in_array($condition_op, $valid_ops, true)) {
            $condition_op = '';
        }
        if (strlen($condition_value) > 1000) {
            $condition_value = substr($condition_value, 0, 1000);
        }

        $condition_json = '';
        if ($condition_field !== '' && $condition_op !== '') {
            $condition_json = json_encode([
                'field' => $condition_field,
                'op'    => $condition_op,
                'value' => $condition_value,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($condition_json === false) {
                $condition_json = '';
            }
        }

        try {
            $repo = App::getInstance()->get(\App\Repository\FormRepository::class);
            $repo->updateStep((string) $step_id, ['label' => $label, 'ordre' => $ordre, 'actif' => $actif, 'condition' => $condition_json]);
            App::audit()->log('step_update', 'step:' . $step_id, "Étape '$label' mise à jour");
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($get_form_id) . '#step-' . urlencode($step_id)];
        } catch (\PDOException $e) {
            error_log('handleUpdateStep error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }

    /**
     * @return array{error?: string, redirect?: string}|null
     */
    public static function handleDeleteStep(\PDO $pdo, string $get_form_id): ?array
    {
        [$step_id, $err] = AdminFormsHandlers::postStepId();
        if ($err !== null) {
            return ['error' => $err];
        }
        if (empty($step_id)) {
            return null;
        }
        $active_count = App::workflow()->hasActiveStepSubmissions((string) $step_id);
        if ($active_count > 0) {
            return ['error' => 'Impossible de supprimer cette étape : ' . $active_count . ' soumission(s) en cours y sont rattachée(s). Veuillez attendre que ces demandes soient clôturées ou les annuler avant de supprimer l\'étape.'];
        }
        try {
            $repo = App::getInstance()->get(\App\Repository\FormRepository::class);
            $repo->deleteStep((string) $step_id);
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($get_form_id) . '#workflow'];
        } catch (\PDOException $e) {
            error_log('handleDeleteStep error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }
}
