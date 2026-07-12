<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Handlers CRUD pour les étapes de validation (add, update, delete).
 */
final class AdminStepCrudHandler
{
    public static function handleAddStep(\PDO $pdo): array
    {
        [$form_id, $err] = AdminFormsHandlers::postFormId();
        if ($err !== null) {
            return ['error' => $err, 'form_id' => ''];
        }
        $label = trim($_POST['label'] ?? '');
        $ordre = (int)($_POST['ordre'] ?? 0);
        if (empty($label) || $ordre <= 0) {
            return ['error' => 'Les champs obligatoires ne sont pas remplis.'];
        }
        try {
            $new_step_id = \generate_uuid();
            $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)")
                ->execute([$new_step_id, $form_id, $label, $ordre]);
            App::audit()->log('step_add', 'form:' . $form_id, "Étape '$label' ajoutée");
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode((string)$form_id) . '#step-' . urlencode($new_step_id)];
        } catch (\PDOException $e) {
            error_log('handleAddStep error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }

    public static function handleUpdateStep(\PDO $pdo, string $get_form_id): array
    {
        [$step_id, $err] = AdminFormsHandlers::postStepId();
        if ($err !== null) {
            return ['error' => $err];
        }
        $label = trim($_POST['label'] ?? '');
        $ordre = (int)($_POST['ordre'] ?? 0);
        $actif = isset($_POST['actif']) ? 1 : 0;
        if (empty($step_id) || empty($label) || $ordre <= 0) {
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
            $pdo->prepare("UPDATE steps SET label = ?, ordre = ?, actif = ?, `condition` = ? WHERE id = ?")
                ->execute([$label, $ordre, $actif, $condition_json, $step_id]);
            App::audit()->log('step_update', 'step:' . $step_id, "Étape '$label' mise à jour");
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($get_form_id) . '#step-' . urlencode($step_id)];
        } catch (\PDOException $e) {
            error_log('handleUpdateStep error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }

    public static function handleDeleteStep(\PDO $pdo, string $get_form_id): ?array
    {
        [$step_id, $err] = AdminFormsHandlers::postStepId();
        if ($err !== null) {
            return ['error' => $err];
        }
        if (empty($step_id)) {
            return null;
        }
        $active_count = App::workflow()->hasActiveStepSubmissions((string)$step_id);
        if ($active_count > 0) {
            return ['error' => 'Impossible de supprimer cette étape : ' . $active_count . ' soumission(s) en cours y sont rattachée(s). Veuillez attendre que ces demandes soient clôturées ou les annuler avant de supprimer l\'étape.'];
        }
        try {
            $pdo->prepare("DELETE FROM step_recipients WHERE step_id = ?")->execute([$step_id]);
            $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$step_id]);
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($get_form_id) . '#workflow'];
        } catch (\PDOException $e) {
            error_log('handleDeleteStep error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }
}
