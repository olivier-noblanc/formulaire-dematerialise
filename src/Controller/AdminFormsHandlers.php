<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Handlers POST pour admin_forms.php — Tous les handlers consolidés.
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

    // ── Handlers — Formulaires ─────────────────────────────────────

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
            return ['error' => 'Erreur lors de l\'ajout du formulaire : ' . $e->getMessage()];
        }
    }

    public static function handleUpdateForm(\PDO $pdo): array
    {
        [$form_id, $err] = self::postFormId();
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
            $result['error'] = 'Erreur lors de la mise à jour du formulaire : ' . $e->getMessage();
            return $result;
        }
    }

    public static function handleDeleteForm(\PDO $pdo): array
    {
        [$form_id, $err] = self::postFormId();
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
            $result['error'] = 'Erreur lors de la suppression du formulaire : ' . $e->getMessage();
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

    // ── Handlers — Champs de formulaire ────────────────────────────

    public static function handleAddField(\PDO $pdo): array
    {
        $form_id = trim($_POST['form_id'] ?? '');
        $ff_label = trim($_POST['ff_label'] ?? '');
        $ff_field_name = trim($_POST['ff_field_name'] ?? '');
        $ff_field_type = trim($_POST['ff_field_type'] ?? 'text');
        $ff_options_raw = trim($_POST['ff_options'] ?? '');
        $ff_required = isset($_POST['ff_required']) ? 1 : 0;
        $ff_ordre = (int)($_POST['ff_ordre'] ?? 0);
        $ff_card_group = self::resolveCardGroup();
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
            return ['error' => 'Erreur lors de l\'ajout du champ : ' . $e->getMessage()];
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
        $ff_card_group = self::resolveCardGroup();
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
            return ['error' => 'Erreur lors de la mise à jour du champ : ' . $e->getMessage()];
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
            return ['error' => 'Erreur lors de la suppression du champ : ' . $e->getMessage()];
        }
    }

    // ── Handlers — Propriétaires de formulaire ─────────────────────

    public static function handleAddOwner(\PDO $pdo): array
    {
        $form_id = trim($_POST['form_id'] ?? '');
        $owner_email = trim($_POST['owner_email'] ?? '');
        if (empty($form_id) || empty($owner_email)) {
            return ['error' => 'Le courriel du propriétaire est requis.'];
        }
        if (!filter_var($owner_email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'L\'adresse courriel "' . \App\Core\App::html()->escape($owner_email) . '" n\'est pas valide. Format attendu : prenom.nom@' . App::settings()->get('email_domain', 'dreets.gouv.fr') . ''];
        }
        try {
            $new_owner_id = \generate_uuid();
            $pdo->prepare("INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)")
                ->execute([$new_owner_id, $form_id, $owner_email]);
            App::audit()->log('owner_add', 'form:' . $form_id, "Propriétaire $owner_email ajouté");
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($form_id) . '#owners'];
        } catch (\PDOException $e) {
            return ['error' => 'Erreur lors de l\'ajout du propriétaire : ' . $e->getMessage()];
        }
    }

    public static function handleDeleteOwner(\PDO $pdo): array
    {
        $owner_id = trim($_POST['owner_id'] ?? $_POST['id'] ?? '');
        $form_id = trim($_POST['form_id'] ?? '');
        if (empty($owner_id) || empty($form_id)) {
            return ['error' => 'Paramètres manquants pour retirer le propriétaire (owner_id=' . \App\Core\App::html()->escape($owner_id) . ', form_id=' . \App\Core\App::html()->escape($form_id) . ').'];
        }
        try {
            $pdo->prepare("DELETE FROM form_owners WHERE id = ?")->execute([$owner_id]);
            App::audit()->log('owner_remove', 'form:' . $form_id, "Propriétaire retiré");
            return ['redirect' => App::html()->buildUrl('index.php?p=admin_forms&form_id=' . urlencode($form_id) . '#owners')];
        } catch (\PDOException $e) {
            return ['error' => 'Erreur lors de la suppression du propriétaire : ' . $e->getMessage()];
        }
    }

    // ── Handlers — Export / Import JSON ────────────────────────────

    public static function handleExportForm(\PDO $pdo): array
    {
        $export_id = trim($_POST['form_id'] ?? '');
        if (empty($export_id)) {
            return ['error' => 'Aucun formulaire sélectionné pour l\'export.'];
        }
        $stmt = $pdo->prepare("SELECT * FROM forms WHERE id = ?");
        $stmt->execute([$export_id]);
        $form_data = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$form_data) {
            return ['error' => 'Formulaire introuvable.'];
        }
        $export = [
            'schema_version' => '1.0',
            'exported_at' => date('c'),
            'form' => [
                'label' => $form_data['label'],
                'slug' => $form_data['slug'],
                'description' => $form_data['description'] ?? '',
                'actif' => (int)$form_data['actif'],
                'deadline_field' => $form_data['deadline_field'] ?? '',
            ],
            'fields' => [],
            'steps' => [],
        ];

        $f_stmt = $pdo->prepare("SELECT * FROM form_fields WHERE form_id = ? ORDER BY ordre");
        $f_stmt->execute([$export_id]);
        foreach ($f_stmt->fetchAll(\PDO::FETCH_ASSOC) as $f) {
            $export['fields'][] = [
                'label' => $f['label'],
                'field_type' => $f['field_type'],
                'field_name' => $f['field_name'],
                'options' => $f['options'] ? json_decode($f['options'], true) : null,
                'required' => (int)$f['required'],
                'ordre' => (int)$f['ordre'],
                'card_group' => $f['card_group'] ?? 'Général',
                'hint' => $f['hint'] ?? '',
                'filled_by' => $f['filled_by'] ?? 'demandeur',
                'validator_step' => $f['validator_step'] ?? '',
                'visibility' => $f['visibility'] ?? 'all',
            ];
        }

        $s_stmt = $pdo->prepare("SELECT * FROM steps WHERE form_id = ? ORDER BY ordre");
        $s_stmt->execute([$export_id]);
        foreach ($s_stmt->fetchAll(\PDO::FETCH_ASSOC) as $s) {
            $recipients = [];
            $r_stmt = $pdo->prepare("SELECT email FROM step_recipients WHERE step_id = ?");
            $r_stmt->execute([$s['id']]);
            foreach ($r_stmt->fetchAll(\PDO::FETCH_COLUMN) as $email) {
                $recipients[] = $email;
            }
            $raw_condition = (string)($s['condition'] ?? '');
            $condition_export = null;
            if ($raw_condition !== '') {
                $decoded = json_decode($raw_condition, true);
                if (is_array($decoded)) {
                    $condition_export = $decoded;
                }
            }
            $export['steps'][] = [
                'label' => $s['label'],
                'ordre' => (int)$s['ordre'],
                'actif' => (int)$s['actif'],
                'recipients' => $recipients,
                'condition' => $condition_export,
            ];
        }

        $filename = preg_replace('/[^a-z0-9_-]/i', '_', $form_data['slug']) . '.json';
        return [
            'filename' => $filename,
            'json_output' => json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    public static function handleValidateJson(): array
    {
        $json_input = trim($_POST['json_data'] ?? '');
        if (empty($json_input)) {
            return [
                'validation_html' => '<div class="msg-error" role="alert" aria-live="assertive">Aucune donnée JSON fournie pour la validation.</div>',
                'preserved_json' => $json_input,
            ];
        }
        $data = json_decode($json_input, true);
        if ($data === null) {
            return [
                'validation_html' => '<div class="msg-error" role="alert" aria-live="assertive">JSON invalide : ' . \App\Core\App::html()->escape(json_last_error_msg()) . '. Vérifiez la syntaxe (virgules manquantes, guillemets non fermés, etc.).</div>',
                'preserved_json' => $json_input,
            ];
        }
        $result = \App\Forms\FormJsonValidator::validate($data);
        if ($result['valid'] && empty($result['warnings'])) {
            $validation_html = '<div class="msg-success" role="status" aria-live="polite">✓ JSON valide ! Le formulaire et le circuit de validation sont correctement définis. Vous pouvez lancer l\'import.</div>';
        } elseif ($result['valid']) {
            $validation_html = '<div class="msg-success" role="status" aria-live="polite">✓ JSON valide (l\'import fonctionnera), mais avec des avertissements :</div>';
            $validation_html .= \App\Forms\FormJsonValidator::formatResults($result);
        } else {
            $validation_html = '<div class="msg-error" role="alert" aria-live="assertive" style="margin-bottom:.25rem;">✗ JSON invalide — l\'import échouerait. Corrigez les erreurs ci-dessous :</div>';
            $validation_html .= \App\Forms\FormJsonValidator::formatResults($result);
        }
        return ['validation_html' => $validation_html, 'preserved_json' => $json_input];
    }

    public static function handleImportForm(\PDO $pdo): array
    {
        $json_input = trim($_POST['json_data'] ?? '');
        if (empty($json_input)) {
            return ['error' => 'Aucune donnée JSON fournie pour l\'import.'];
        }
        $data = json_decode($json_input, true);
        if ($data === null) {
            return ['error' => 'JSON invalide : ' . json_last_error_msg()];
        }
        $validation = \App\Forms\FormJsonValidator::validate($data);
        if (!$validation['valid']) {
            return [
                'error' => 'Le JSON contient des erreurs de structure. L\'import a été bloqué. Corrigez les erreurs puis réessayez.',
                'validation_html' => \App\Forms\FormJsonValidator::formatResults($validation),
                'preserved_json' => $json_input,
            ];
        }
        try {
            $pdo->beginTransaction();

            $label = $data['form']['label'];
            $slug = \generate_slug($label);
            $desc = $data['form']['description'] ?? '';
            $deadline = $data['form']['deadline_field'] ?? '';

            $new_id = \generate_uuid();
            $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at, deadline_field) VALUES (?, ?, ?, ?, 1, datetime('now'), ?)")
                ->execute([$new_id, $slug, $label, $desc, $deadline]);

            if (!empty($data['fields'])) {
                $field_stmt = $pdo->prepare("INSERT INTO form_fields (id, form_id, label, field_type, field_name, options, required, ordre, card_group, hint, filled_by, validator_step, visibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ordre = 1;
                foreach ($data['fields'] as $f) {
                    $options_json = null;
                    if (!empty($f['options'])) {
                        $options_json = is_string($f['options']) ? $f['options'] : json_encode($f['options'], JSON_UNESCAPED_UNICODE);
                    }
                    $field_name = !empty($f['field_name']) ? $f['field_name'] : \generate_field_name($f['label']);
                    $filled_by = !empty($f['filled_by']) ? $f['filled_by'] : 'demandeur';
                    if (!in_array($filled_by, ['demandeur', 'validator'])) {
                        $filled_by = 'demandeur';
                    }
                    $visibility = $f['visibility'] ?? 'all';
                    if (!is_string($visibility) || !in_array($visibility, ['all', 'owner_only'], true)) {
                        $visibility = 'all';
                    }
                    $field_stmt->execute([
                        \generate_uuid(), $new_id,
                        $f['label'] ?? 'Champ',
                        $f['field_type'] ?? 'text',
                        $field_name,
                        $options_json,
                        (int)($f['required'] ?? 0),
                        (int)($f['ordre'] ?? $ordre),
                        $f['card_group'] ?? 'Général',
                        $f['hint'] ?? '',
                        $filled_by,
                        $f['validator_step'] ?? '',
                        $visibility,
                    ]);
                    $ordre++;
                }
            }

            if (!empty($data['steps'])) {
                foreach ($data['steps'] as $s) {
                    $step_id = \generate_uuid();
                    $raw_cond_import = $s['condition'] ?? '';
                    $cond_db = '';
                    if (is_array($raw_cond_import)) {
                        $op_imp = (string)($raw_cond_import['op'] ?? '');
                        $valid_ops = ['equals', 'not_equals', 'contains', 'not_empty', 'empty'];
                        if (!empty($raw_cond_import['field']) && in_array($op_imp, $valid_ops, true)) {
                            $encoded = json_encode([
                                'field' => (string)$raw_cond_import['field'],
                                'op'    => $op_imp,
                                'value' => (string)($raw_cond_import['value'] ?? ''),
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            if ($encoded !== false) {
                                $cond_db = $encoded;
                            }
                        }
                    } elseif (is_string($raw_cond_import) && $raw_cond_import !== '') {
                        $decoded = json_decode($raw_cond_import, true);
                        if (is_array($decoded)) {
                            $cond_db = $raw_cond_import;
                        }
                    }

                    $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$step_id, $new_id, $s['label'] ?? 'Étape', (int)($s['ordre'] ?? 1), (int)($s['actif'] ?? 1), $cond_db]);

                    if (!empty($s['recipients'])) {
                        $recip_stmt = $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)");
                        foreach ($s['recipients'] as $email) {
                            $recip_stmt->execute([\generate_uuid(), $step_id, $email]);
                        }
                    }
                }
            }

            $pdo->commit();
            App::audit()->log('form_import', 'form:' . $new_id, "Formulaire '$label' importé depuis JSON");
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($new_id)];
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['error' => 'Erreur lors de l\'import : ' . $e->getMessage()];
        }
    }

    // ── Handlers — Étapes de validation ────────────────────────────

    public static function handleAddStep(\PDO $pdo): array
    {
        [$form_id, $err] = self::postFormId();
        $result = [];
        if ($err !== null) {
            $result['error'] = $err;
            $result['form_id'] = '';
        } else {
            $result['form_id'] = $form_id;
        }
        $label = trim($_POST['label'] ?? '');
        $ordre = (int)($_POST['ordre'] ?? 0);
        if (empty($form_id) || empty($label) || $ordre <= 0) {
            $result['error'] = 'Les champs obligatoires ne sont pas remplis.';
            return $result;
        }
        try {
            $new_step_id = \generate_uuid();
            $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)")
                ->execute([$new_step_id, $form_id, $label, $ordre]);
            App::audit()->log('step_add', 'form:' . $form_id, "Étape '$label' ajoutée");
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode((string)$form_id) . '#step-' . urlencode($new_step_id)];
        } catch (\PDOException $e) {
            $result['error'] = 'Erreur lors de l\'ajout de l\'étape : ' . $e->getMessage();
            return $result;
        }
    }

    public static function handleUpdateStep(\PDO $pdo, string $get_form_id): array
    {
        [$step_id, $err] = self::postStepId();
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
            return ['error' => 'Erreur lors de la mise à jour de l\'étape : ' . $e->getMessage()];
        }
    }

    public static function handleDeleteStep(\PDO $pdo, string $get_form_id): array
    {
        [$step_id, $err] = self::postStepId();
        if ($err !== null) {
            return ['error' => $err];
        }
        if (empty($step_id)) {
            return [];
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
            return ['error' => 'Erreur lors de la suppression de l\'étape : ' . $e->getMessage()];
        }
    }

    // ── Handlers — Destinataires d'étape ───────────────────────────

    public static function handleAddRecipient(\PDO $pdo, string $get_form_id): array
    {
        [$step_id, $err] = self::postStepId();
        if ($err !== null) {
            return ['error' => $err];
        }
        $email = trim($_POST['email'] ?? '');
        if (empty($step_id) || empty($email)) {
            return ['error' => 'L\'étape et le courriel sont requis.'];
        }
        $is_dynamic = preg_match('/^\{\{[a-z][a-z0-9_]*\}\}$/', $email);
        if (!$is_dynamic && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'Le destinataire "' . \App\Core\App::html()->escape($email) . '" n\'est ni une adresse email valide ni une référence dynamique {{field_name}}. Format attendu : prenom.nom@' . App::settings()->get('email_domain', 'dreets.gouv.fr') . ' ou {{nom_du_champ}}'];
        }
        try {
            $new_rcpt_id = \generate_uuid();
            $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)")
                ->execute([$new_rcpt_id, $step_id, $email]);
            $label = $is_dynamic ? "Destinataire dynamique $email ajouté" : "Destinataire $email ajouté";
            App::audit()->log('recipient_add', 'step:' . $step_id, $label);
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($get_form_id) . '#step-' . urlencode($step_id)];
        } catch (\PDOException $e) {
            return ['error' => 'Erreur lors de l\'ajout du destinataire : ' . $e->getMessage()];
        }
    }

    public static function handleDeleteRecipient(\PDO $pdo, string $get_form_id): array
    {
        $recipient_id = trim($_POST['recipient_id'] ?? '');
        if (empty($recipient_id)) {
            return [];
        }
        try {
            $stmt = $pdo->prepare("SELECT step_id FROM step_recipients WHERE id = ?");
            $stmt->execute([$recipient_id]);
            $step_id_for_anchor = (string)$stmt->fetchColumn();
            $pdo->prepare("DELETE FROM step_recipients WHERE id = ?")->execute([$recipient_id]);
            $anchor = $step_id_for_anchor !== '' ? '#step-' . urlencode($step_id_for_anchor) : '#workflow';
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($get_form_id) . $anchor];
        } catch (\PDOException $e) {
            return ['error' => 'Erreur lors de la suppression du destinataire : ' . $e->getMessage()];
        }
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
            'add_form'         => self::handleAddForm($pdo),
            'update_form'      => self::handleUpdateForm($pdo),
            'delete_form'      => self::handleDeleteForm($pdo),
            'duplicate_form'   => self::handleDuplicateForm($pdo),
            'add_step'         => self::handleAddStep($pdo),
            'update_step'      => self::handleUpdateStep($pdo, $get_form_id),
            'delete_step'      => self::handleDeleteStep($pdo, $get_form_id),
            'add_recipient'    => self::handleAddRecipient($pdo, $get_form_id),
            'delete_recipient' => self::handleDeleteRecipient($pdo, $get_form_id),
            'add_field'        => self::handleAddField($pdo),
            'update_field'     => self::handleUpdateField($pdo),
            'delete_field'     => self::handleDeleteField($pdo),
            'add_owner'        => self::handleAddOwner($pdo),
            'delete_owner'     => self::handleDeleteOwner($pdo),
            'remove_owner'     => self::handleDeleteOwner($pdo),
            'export_form'      => self::handleExportForm($pdo),
            'validate_json'    => self::handleValidateJson(),
            'import_form'      => self::handleImportForm($pdo),
            default            => null,
        };
    }
}
