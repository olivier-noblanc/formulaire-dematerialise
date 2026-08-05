<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Handlers CRUD pour les formulaires (add, update, delete, duplicate).
 */
final class AdminFormCrudHandler
{
    /**
     * @return array<string, mixed>
     */
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
            $repo->createOwnerById($newFormId, App::auth()->getUser());
            App::audit()->log('form_create', 'form:' . $newFormId, "Formulaire '$label' créé (slug auto: $slug)");
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($newFormId)];
        } catch (\PDOException $e) {
            error_log('handleAddForm error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }

    /**
     * @return array<string, mixed>
     */
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
            $slug = \generate_slug($label, $form_id);
            $repo->update($form_id, ['slug' => $slug, 'label' => $label, 'description' => $description, 'actif' => $actif]);
            App::audit()->log('form_update', 'form:' . $form_id, "Formulaire '$label' mis à jour");
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($form_id)];
        } catch (\PDOException $e) {
            error_log('handleUpdateForm error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function handleDeleteForm(): array
    {
        [$form_id, $err] = AdminFormsHandlers::postFormId();
        if ($err !== null) {
            return ['error' => $err, 'form_id' => ''];
        }
        if ($form_id === '' || $form_id === '0') {
            return ['form_id' => ''];
        }
        if (!App::auth()->isFormOwner($form_id) && !App::auth()->isSuperAdmin()) {
            return ['error' => 'Seuls les propriétaires du formulaire peuvent le supprimer.', 'form_id' => $form_id];
        }
        $active_count = App::workflow()->hasActiveSubmissions($form_id);
        if ($active_count > 0) {
            return ['error' => 'Impossible de supprimer ce formulaire : ' . $active_count . ' soumission(s) en cours y sont rattachée(s). Veuillez attendre que ces demandes soient clôturées ou les annuler avant de supprimer le formulaire.', 'form_id' => $form_id];
        }
        $formRepository = App::getInstance()->get(\App\Repository\FormRepository::class);
        $db = App::db();
        $db->beginTransaction();
        try {
            $formRepository->deleteCascade($form_id);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('handleDeleteForm error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }

        App::audit()->log('form_delete', 'form:' . $form_id, 'Formulaire supprimé');
        return ['redirect' => 'index.php?p=admin_forms'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function handleDuplicateForm(): array
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
        // B-01-1 fix (audit 2026-07-26) : handleDeleteForm vérifiait isFormOwner
        // mais handleDuplicateForm ne le faisait pas. Tout admin pouvait dupliquer
        // n'importe quel formulaire (et donc exfiltrer les emails destinataires
        // des étapes de validation).
        if (!App::auth()->isFormOwner((string) $source_id) && !App::auth()->isSuperAdmin()) {
            return ['error' => 'Seuls les propriétaires du formulaire peuvent le dupliquer.'];
        }
        $formRepository = App::getInstance()->get(\App\Repository\FormRepository::class);
        $src_form = $formRepository->findById((string) $source_id);
        if (!((bool)$src_form)) {
            return ['error' => 'Formulaire source introuvable.'];
        }
        $new_label = $src_form['label'] . ' (copie)';
        $new_slug = \generate_slug($new_label);
        $new_id = \generate_uuid();

        $db = App::db();
        $db->beginTransaction();
        try {
            $formRepository->duplicate((string) $source_id, $new_id, $new_label, $new_slug, $src_form);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
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
