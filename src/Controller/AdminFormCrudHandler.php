<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Handlers CRUD pour les formulaires (add, update, delete, duplicate).
 */
final class AdminFormCrudHandler
{
    public static function handleAddForm(): array
    {
        $label = trim($_POST['label'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($label === '' || $label === '0') {
            return ['error' => 'Le libellé est requis.'];
        }
        try {
            $repo = App::getInstance()->get(\App\Repository\FormRepository::class);
            $slug = \generate_slug($label);
            $newFormId = $repo->create(['label' => $label, 'slug' => $slug, 'description' => $description]);
            App::audit()->log('form_create', 'form:' . $newFormId, "Formulaire '$label' créé (slug auto: $slug)");
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($newFormId)];
        } catch (\PDOException $e) {
            error_log('handleAddForm error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }

    public static function handleUpdateForm(): array
    {
        [$form_id, $err] = AdminFormsHandlers::postFormId();
        if ($err !== null) {
            return ['error' => $err, 'form_id' => ''];
        }
        $label = trim($_POST['label'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $actif = isset($_POST['actif']) ? 1 : 0;
        if ($label === '' || $label === '0') {
            return ['error' => 'Le libellé est requis.'];
        }
        try {
            $repo = App::getInstance()->get(\App\Repository\FormRepository::class);
            $slug = \generate_slug($label, (string) $form_id);
            $repo->update((string) $form_id, ['slug' => $slug, 'label' => $label, 'description' => $description, 'actif' => $actif]);
            App::audit()->log('form_update', 'form:' . $form_id, "Formulaire '$label' mis à jour");
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode((string) $form_id)];
        } catch (\PDOException $e) {
            error_log('handleUpdateForm error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }

    public static function handleDeleteForm(\PDO $pdo): array
    {
        [$form_id, $err] = AdminFormsHandlers::postFormId();
        if ($err !== null) {
            return ['error' => $err, 'form_id' => ''];
        }
        if (empty($form_id)) {
            return ['form_id' => ''];
        }
        if (!App::auth()->isFormOwner((string) $form_id) && !App::auth()->isSuperAdmin()) {
            return ['error' => 'Seuls les propriétaires du formulaire peuvent le supprimer.', 'form_id' => $form_id];
        }
        $active_count = App::workflow()->hasActiveSubmissions((string) $form_id);
        if ($active_count > 0) {
            return ['error' => 'Impossible de supprimer ce formulaire : ' . $active_count . ' soumission(s) en cours y sont rattachée(s). Veuillez attendre que ces demandes soient clôturées ou les annuler avant de supprimer le formulaire.', 'form_id' => $form_id];
        }
        $formRepository = App::getInstance()->get(\App\Repository\FormRepository::class);
        $pdo->beginTransaction();
        try {
            $formRepository->deleteCascade((string) $form_id);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('handleDeleteForm error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }

        App::audit()->log('form_delete', 'form:' . $form_id, 'Formulaire supprimé');
        return ['redirect' => 'index.php?p=admin_forms'];
    }

    public static function handleDuplicateForm(\PDO $pdo): array
    {
        $source_id = trim($_POST['source_form_id'] ?? '');
        try {
            $source_id = \validate_input($source_id, 'uuid');
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'Identifiant de formulaire source invalide.'];
        }
        if (in_array($source_id, ['', '0', 0], true)) {
            return ['error' => 'Identifiant de formulaire source invalide.'];
        }
        $formRepository = App::getInstance()->get(\App\Repository\FormRepository::class);
        $src_form = $formRepository->findById($source_id);
        if (!$src_form) {
            return ['error' => 'Formulaire source introuvable.'];
        }
        $new_label = $src_form['label'] . ' (copie)';
        $new_slug = \generate_slug($new_label);
        $new_id = \generate_uuid();

        $pdo->beginTransaction();
        try {
            $formRepository->duplicate($source_id, $new_id, $new_label, $new_slug, $src_form);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('handleDuplicateForm error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }

        App::audit()->log('form_duplicate', 'form:' . $new_id, 'Formulaire dupliqué');
        return [
            'redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($new_id),
            'success' => 'Formulaire dupliqué avec succès.',
        ];
    }
}
