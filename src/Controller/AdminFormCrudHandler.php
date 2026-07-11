<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Handlers CRUD pour les formulaires (add, update, delete, duplicate).
 */
final class AdminFormCrudHandler
{
    public static function handleAddForm(\PDO $pdo): array
    {
        $label = trim($_POST['label'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if (empty($label)) {
            return ['error' => 'Le libellé est requis.'];
        }
        try {
            $new_form_id = \generate_uuid();
            $slug = \generate_slug($label);
            $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
                ->execute([$new_form_id, $slug, $label, $description]);
            App::audit()->log('form_create', 'form:' . $new_form_id, "Formulaire '$label' créé (slug auto: $slug)");
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($new_form_id)];
        } catch (\PDOException $e) {
            error_log('handleAddForm error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }

    public static function handleUpdateForm(\PDO $pdo): array
    {
        [$form_id, $err] = AdminFormsHandlers::postFormId();
        $result = [];
        if ($err !== null) {
            $result['error'] = $err;
            $result['form_id'] = '';
        } else {
            $result['form_id'] = $form_id;
        }
        $label = trim($_POST['label'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $actif = isset($_POST['actif']) ? 1 : 0;
        if (empty($form_id) || empty($label)) {
            $result['error'] = 'Le libellé est requis.';
            return $result;
        }
        try {
            $slug = \generate_slug($label, (string)$form_id);
            $pdo->prepare("UPDATE forms SET slug = ?, label = ?, description = ?, actif = ? WHERE id = ?")
                ->execute([$slug, $label, $description, $actif, $form_id]);
            App::audit()->log('form_update', 'form:' . $form_id, "Formulaire '$label' mis à jour");
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode((string)$form_id)];
        } catch (\PDOException $e) {
            error_log('handleUpdateForm error: ' . $e->getMessage());
            $result['error'] = 'Une erreur technique est survenue.';
            return $result;
        }
    }

    public static function handleDeleteForm(\PDO $pdo): array
    {
        [$form_id, $err] = AdminFormsHandlers::postFormId();
        $result = [];
        if ($err !== null) {
            $result['error'] = $err;
            $result['form_id'] = '';
            return $result;
        }
        $result['form_id'] = $form_id;
        if (empty($form_id)) {
            return $result;
        }
        if (!App::auth()->isFormOwner((string)$form_id) && !App::auth()->isSuperAdmin()) {
            $result['error'] = 'Seuls les propriétaires du formulaire peuvent le supprimer.';
            return $result;
        }
        $active_count = App::workflow()->hasActiveSubmissions((string)$form_id);
        if ($active_count > 0) {
            $result['error'] = 'Impossible de supprimer ce formulaire : ' . $active_count . ' soumission(s) en cours y sont rattachée(s). Veuillez attendre que ces demandes soient clôturées ou les annuler avant de supprimer le formulaire.';
            return $result;
        }
        try {
            $pdo->prepare("DELETE FROM steps WHERE form_id = ?")->execute([$form_id]);
            $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$form_id]);
            App::audit()->log('form_delete', 'form:' . $form_id, "Formulaire supprimé");
            return ['redirect' => 'index.php?p=admin_forms'];
        } catch (\PDOException $e) {
            error_log('handleDeleteForm error: ' . $e->getMessage());
            $result['error'] = 'Une erreur technique est survenue.';
            return $result;
        }
    }

    public static function handleDuplicateForm(\PDO $pdo): array
    {
        $source_id = trim($_POST['source_form_id'] ?? '');
        try { $source_id = \validate_input($source_id, 'uuid'); } catch (\InvalidArgumentException $e) {
            return ['error' => 'Identifiant de formulaire source invalide.'];
        }
        if (empty($source_id)) {
            return ['error' => 'Identifiant de formulaire source invalide.'];
        }
        $src = $pdo->prepare("SELECT * FROM forms WHERE id = ?");
        $src->execute([$source_id]);
        $src_form = $src->fetch(\PDO::FETCH_ASSOC);
        if (!$src_form) {
            return ['error' => 'Formulaire source introuvable.'];
        }
        $new_label = $src_form['label'] . ' (copie)';
        $new_slug = \generate_slug($new_label);
        $new_id = \generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, deadline_field) VALUES (?, ?, ?, ?, 1, ?)")
            ->execute([$new_id, $new_slug, $new_label, $src_form['description'], $src_form['deadline_field']]);

        $fields = $pdo->prepare("SELECT * FROM form_fields WHERE form_id = ? ORDER BY ordre");
        $fields->execute([$source_id]);
        foreach ($fields->fetchAll(\PDO::FETCH_ASSOC) as $f) {
            $new_field_id = \generate_uuid();
            $pdo->prepare("INSERT INTO form_fields (id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group, filled_by, validator_step, visibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$new_field_id, $new_id, $f['label'], $f['field_type'], $f['field_name'], $f['options'], $f['hint'] ?? '', $f['required'], $f['ordre'], $f['card_group'], $f['filled_by'] ?? 'demandeur', $f['validator_step'] ?? '', $f['visibility'] ?? 'all']);
        }

        $steps = $pdo->prepare("SELECT * FROM steps WHERE form_id = ? ORDER BY ordre");
        $steps->execute([$source_id]);
        foreach ($steps->fetchAll(\PDO::FETCH_ASSOC) as $s) {
            $new_step_id = \generate_uuid();
            $step_condition = (string)($s['condition'] ?? '');
            $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$new_step_id, $new_id, $s['label'], $s['ordre'], $s['actif'], $step_condition]);

            $recips = $pdo->prepare("SELECT * FROM step_recipients WHERE step_id = ?");
            $recips->execute([$s['id']]);
            foreach ($recips->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $new_recipient_id = \generate_uuid();
                $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)")
                    ->execute([$new_recipient_id, $new_step_id, $r['email']]);
            }
        }

        App::audit()->log('form_duplicate', 'form:' . $new_id, 'Formulaire dupliqué');
        return [
            'redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($new_id),
            'success' => 'Formulaire dupliqué avec succès.',
        ];
    }
}
