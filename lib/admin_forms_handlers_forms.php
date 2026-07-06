<?php
declare(strict_types=1);

/**
 * POST handlers admin_forms.php — Formulaires, Champs, Propriétaires, JSON.
 *
 * Contient les handlers pour les actions POST liées aux formulaires,
 * champs, propriétaires, et import/export JSON :
 *  - add_form, update_form, delete_form, duplicate_form
 *  - add_field, update_field, delete_field
 *  - add_owner, delete_owner
 *  - export_form, validate_json, import_form
 *
 * Tous les handlers retournent un tableau de résultats interprété par
 * admin_forms.php (voir docblock de {@see handle_admin_action()} dans
 * admin_forms_handlers.php pour la liste des clés).
 *
 * @package lib
 */

// ── Helpers internes ───────────────────────────────────────────

/**
 * Extrait et valide un form_id depuis $_POST.
 * @return array{0:string,1:string|null} [form_id, error_msg_or_null]
 */
function _post_form_id(): array {
    $form_id = trim($_POST['form_id'] ?? '');
    try {
        $form_id = validate_input($form_id, 'uuid');
        return [$form_id, null];
    } catch (\InvalidArgumentException $e) {
        return ['', 'Identifiant de formulaire invalide.'];
    }
}

/**
 * Extrait et valide un step_id depuis $_POST.
 * @return array{0:string,1:string|null} [step_id, error_msg_or_null]
 */
function _post_step_id(): array {
    $step_id = trim($_POST['step_id'] ?? '');
    try {
        $step_id = validate_input($step_id, 'uuid');
        return [$step_id, null];
    } catch (\InvalidArgumentException $e) {
        return ['', 'Identifiant d\'étape invalide.'];
    }
}

/**
 * Construit la valeur de card_group à partir des inputs POST.
 * Règle : nouveau groupe (texte) > sélecteur > défaut "Général".
 */
function _resolve_card_group(): string {
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

/** Handler : add_form — créer un formulaire */
function handle_admin_action_add_form(PDO $pdo): array {
    // Sécurité (S-16) : limiter les créations de formulaires
    if (!rate_limit_check('admin_form_create', 10, 60)) {
        return ['error' => 'Trop de requêtes. Veuillez patienter avant de réessayer.'];
    }
    $label = trim($_POST['label'] ?? '');
    $description = trim($_POST['description'] ?? '');
    if (empty($label)) {
        return ['error' => 'Le libellé est requis.'];
    }
    try {
        $new_form_id = generate_uuid();
        $slug = generate_slug($label);
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$new_form_id, $slug, $label, $description]);
        app_log('form_create', 'form:' . $new_form_id, "Formulaire '$label' créé (slug auto: $slug)");
        return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($new_form_id)];
    } catch (PDOException $e) {
        return ['error' => 'Erreur lors de l\'ajout du formulaire : ' . $e->getMessage()];
    }
}

/** Handler : update_form — mettre à jour un formulaire */
function handle_admin_action_update_form(PDO $pdo): array {
    [$form_id, $err] = _post_form_id();
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
        // Préserve le comportement historique : si form_id invalide, on renvoie
        // aussi "Le libellé est requis." quand label est vide (override du msg).
        $result['error'] = 'Le libellé est requis.';
        return $result;
    }
    try {
        // Régénérer le slug à partir du libellé si le label a changé
        $slug = generate_slug($label, (string)$form_id);
        $pdo->prepare("UPDATE forms SET slug = ?, label = ?, description = ?, actif = ? WHERE id = ?")
            ->execute([$slug, $label, $description, $actif, $form_id]);
        app_log('form_update', 'form:' . $form_id, "Formulaire '$label' mis à jour");
        return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode((string)$form_id)];
    } catch (PDOException $e) {
        $result['error'] = 'Erreur lors de la mise à jour du formulaire : ' . $e->getMessage();
        return $result;
    }
}

/** Handler : delete_form — supprimer un formulaire */
function handle_admin_action_delete_form(PDO $pdo): array {
    [$form_id, $err] = _post_form_id();
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
    // Sécurité (S-15) : seuls les propriétaires du formulaire ou le super-admin peuvent le supprimer
    if (!is_form_owner((string)$form_id) && !is_super_admin()) {
        $result['error'] = 'Seuls les propriétaires du formulaire peuvent le supprimer.';
        return $result;
    }
    $active_count = has_active_submissions((string)$form_id);
    if ($active_count > 0) {
        $result['error'] = 'Impossible de supprimer ce formulaire : ' . $active_count . ' soumission(s) en cours y sont rattachée(s). Veuillez attendre que ces demandes soient clôturées ou les annuler avant de supprimer le formulaire.';
        return $result;
    }
    try {
        $pdo->prepare("DELETE FROM steps WHERE form_id = ?")->execute([$form_id]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$form_id]);
        app_log('form_delete', 'form:' . $form_id, "Formulaire supprimé");
        return ['redirect' => 'index.php?p=admin_forms'];
    } catch (PDOException $e) {
        $result['error'] = 'Erreur lors de la suppression du formulaire : ' . $e->getMessage();
        return $result;
    }
}

/** Handler : duplicate_form — dupliquer un formulaire (champs + étapes + destinataires) */
function handle_admin_action_duplicate_form(PDO $pdo): array {
    $source_id = trim($_POST['source_form_id'] ?? '');
    try { $source_id = validate_input($source_id, 'uuid'); } catch (\InvalidArgumentException $e) {
        return ['error' => 'Identifiant de formulaire source invalide.'];
    }
    if (empty($source_id)) {
        return ['error' => 'Identifiant de formulaire source invalide.'];
    }
    // Récupérer le formulaire source
    $src = $pdo->prepare("SELECT * FROM forms WHERE id = ?");
    $src->execute([$source_id]);
    $src_form = $src->fetch(PDO::FETCH_ASSOC);
    if (!$src_form) {
        return ['error' => 'Formulaire source introuvable.'];
    }
    // Créer le nouveau formulaire
    $new_label = $src_form['label'] . ' (copie)';
    $new_slug = generate_slug($new_label);
    $new_id = generate_uuid();
    $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, deadline_field) VALUES (?, ?, ?, ?, 1, ?)")
        ->execute([$new_id, $new_slug, $new_label, $src_form['description'], $src_form['deadline_field']]);

    // Copier les champs
    $fields = $pdo->prepare("SELECT * FROM form_fields WHERE form_id = ? ORDER BY ordre");
    $fields->execute([$source_id]);
    foreach ($fields->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $new_field_id = generate_uuid();
        $pdo->prepare("INSERT INTO form_fields (id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group, filled_by, validator_step, visibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$new_field_id, $new_id, $f['label'], $f['field_type'], $f['field_name'], $f['options'], $f['hint'] ?? '', $f['required'], $f['ordre'], $f['card_group'], $f['filled_by'] ?? 'demandeur', $f['validator_step'] ?? '', $f['visibility'] ?? 'all']);
    }

    // Copier les étapes et destinataires
    $steps = $pdo->prepare("SELECT * FROM steps WHERE form_id = ? ORDER BY ordre");
    $steps->execute([$source_id]);
    foreach ($steps->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $new_step_id = generate_uuid();
        // v19 — copier aussi la colonne `condition` (branches conditionnelles).
        $step_condition = (string)($s['condition'] ?? '');
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$new_step_id, $new_id, $s['label'], $s['ordre'], $s['actif'], $step_condition]);

        $recips = $pdo->prepare("SELECT * FROM step_recipients WHERE step_id = ?");
        $recips->execute([$s['id']]);
        foreach ($recips->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $new_recipient_id = generate_uuid();
            $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)")
                ->execute([$new_recipient_id, $new_step_id, $r['email']]);
        }
    }

    app_log('form_duplicate', 'form:' . $new_id, 'Formulaire dupliqué');
    return [
        'redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($new_id),
        'success' => 'Formulaire dupliqué avec succès.',
    ];
}

// ── Handlers — Champs de formulaire ────────────────────────────

/** Handler : add_field — ajouter un champ au formulaire */
function handle_admin_action_add_field(PDO $pdo): array {
    $form_id = trim($_POST['form_id'] ?? '');
    $ff_label = trim($_POST['ff_label'] ?? '');
    $ff_field_name = trim($_POST['ff_field_name'] ?? '');
    $ff_field_type = trim($_POST['ff_field_type'] ?? 'text');
    $ff_options_raw = trim($_POST['ff_options'] ?? '');
    $ff_required = isset($_POST['ff_required']) ? 1 : 0;
    $ff_ordre = (int)($_POST['ff_ordre'] ?? 0);
    $ff_card_group = _resolve_card_group();
    // ── A-13 : filled_by + validator_step ──
    $ff_filled_by = trim($_POST['ff_filled_by'] ?? '');
    if (!in_array($ff_filled_by, ['demandeur', 'validator'])) {
        $ff_filled_by = 'demandeur'; // default
    }
    $ff_validator_step = trim($_POST['ff_validator_step'] ?? '');
    // ── FILE-VISIBILITY : visibilité du champ file (owner_only vs all) ──
    $ff_visibility = trim($_POST['ff_visibility'] ?? 'all');
    if (!in_array($ff_visibility, ['all', 'owner_only'], true)) {
        $ff_visibility = 'all';
    }

    // Auto-generate field_name from label if empty
    if (empty($ff_field_name) && !empty($ff_label)) {
        $ff_field_name = generate_field_name($ff_label);
    }

    if (empty($form_id) || empty($ff_label) || empty($ff_field_name)) {
        return ['error' => 'Le libellé du champ est requis.'];
    }
    try {
        // Parse options: one per line → JSON
        $options_json = parse_options_input($ff_options_raw);
        $ff_hint = trim($_POST['ff_hint'] ?? '');

        $new_field_id = generate_uuid();
        $pdo->prepare("INSERT INTO form_fields (id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group, filled_by, validator_step, visibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$new_field_id, $form_id, $ff_label, $ff_field_type, $ff_field_name, $options_json, $ff_hint, $ff_required, $ff_ordre, $ff_card_group, $ff_filled_by, $ff_validator_step, $ff_visibility]);
        app_log('field_add', 'form:' . $form_id, "Champ '$ff_label' ajouté");
        return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($form_id) . '#field-' . urlencode($new_field_id)];
    } catch (PDOException $e) {
        return ['error' => 'Erreur lors de l\'ajout du champ : ' . $e->getMessage()];
    }
}

/** Handler : update_field — mettre à jour un champ existant */
function handle_admin_action_update_field(PDO $pdo): array {
    $field_id = trim($_POST['field_id'] ?? '');
    $form_id = trim($_POST['form_id'] ?? '');
    $ff_label = trim($_POST['ff_label'] ?? '');
    $ff_field_name = trim($_POST['ff_field_name'] ?? '');
    $ff_field_type = trim($_POST['ff_field_type'] ?? 'text');
    $ff_options_raw = trim($_POST['ff_options'] ?? '');
    $ff_required = isset($_POST['ff_required']) ? 1 : 0;
    $ff_ordre = (int)($_POST['ff_ordre'] ?? 0);
    $ff_card_group = _resolve_card_group();
    // ── A-13 : filled_by + validator_step ──
    $ff_filled_by = trim($_POST['ff_filled_by'] ?? '');
    if (!in_array($ff_filled_by, ['demandeur', 'validator'])) {
        $ff_filled_by = 'demandeur'; // default
    }
    $ff_validator_step = trim($_POST['ff_validator_step'] ?? '');
    // ── FILE-VISIBILITY : visibilité du champ file (owner_only vs all) ──
    $ff_visibility = trim($_POST['ff_visibility'] ?? 'all');
    if (!in_array($ff_visibility, ['all', 'owner_only'], true)) {
        $ff_visibility = 'all';
    }

    // Auto-generate field_name from label if empty
    if (empty($ff_field_name) && !empty($ff_label)) {
        $ff_field_name = generate_field_name($ff_label);
    }

    if (empty($field_id) || empty($ff_label) || empty($ff_field_name)) {
        return ['error' => 'Le libellé du champ est requis.'];
    }
    try {
        // Parse options: one per line → JSON
        $options_json = parse_options_input($ff_options_raw);
        $ff_hint = trim($_POST['ff_hint'] ?? '');

        $pdo->prepare("UPDATE form_fields SET label = ?, field_type = ?, field_name = ?, options = ?, hint = ?, required = ?, ordre = ?, card_group = ?, filled_by = ?, validator_step = ?, visibility = ? WHERE id = ?")
            ->execute([$ff_label, $ff_field_type, $ff_field_name, $options_json, $ff_hint, $ff_required, $ff_ordre, $ff_card_group, $ff_filled_by, $ff_validator_step, $ff_visibility, $field_id]);
        app_log('field_update', 'field:' . $field_id, "Champ '$ff_label' mis à jour");
        return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($form_id) . '#field-' . urlencode($field_id)];
    } catch (PDOException $e) {
        return ['error' => 'Erreur lors de la mise à jour du champ : ' . $e->getMessage()];
    }
}

/** Handler : delete_field — supprimer un champ */
function handle_admin_action_delete_field(PDO $pdo): array {
    $field_id = trim($_POST['field_id'] ?? '');
    $form_id = trim($_POST['form_id'] ?? '');
    if (empty($field_id)) {
        return [];
    }
    try {
        $pdo->prepare("DELETE FROM form_fields WHERE id = ?")->execute([$field_id]);
        return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($form_id) . '#fields'];
    } catch (PDOException $e) {
        return ['error' => 'Erreur lors de la suppression du champ : ' . $e->getMessage()];
    }
}

// ── Handlers — Propriétaires de formulaire ─────────────────────

/** Handler : add_owner — ajouter un propriétaire (email) au formulaire */
function handle_admin_action_add_owner(PDO $pdo): array {
    $form_id = trim($_POST['form_id'] ?? '');
    $owner_email = trim($_POST['owner_email'] ?? '');
    if (empty($form_id) || empty($owner_email)) {
        return ['error' => 'Le courriel du propriétaire est requis.'];
    }
    if (!filter_var($owner_email, FILTER_VALIDATE_EMAIL)) {
        return ['error' => 'L\'adresse courriel "' . h($owner_email) . '" n\'est pas valide. Format attendu : prenom.nom@' . get_setting('email_domain', 'exemple.invalid') . ''];
    }
    try {
        $new_owner_id = generate_uuid();
        $pdo->prepare("INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)")
            ->execute([$new_owner_id, $form_id, $owner_email]);
        app_log('owner_add', 'form:' . $form_id, "Propriétaire $owner_email ajouté");
        return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($form_id) . '#owners'];
    } catch (PDOException $e) {
        return ['error' => 'Erreur lors de l\'ajout du propriétaire : ' . $e->getMessage()];
    }
}

/** Handler : delete_owner — retirer un propriétaire du formulaire */
function handle_admin_action_delete_owner(PDO $pdo): array {
    // v10.0.4 — Fix bug : confirm_action.php envoie 'id' (config params = ['id', 'form_id']),
    // mais le handler attendait 'owner_id'. On accepte les 2 pour rétro-compat.
    $owner_id = trim($_POST['owner_id'] ?? $_POST['id'] ?? '');
    $form_id = trim($_POST['form_id'] ?? '');
    if (empty($owner_id) || empty($form_id)) {
        return ['error' => 'Paramètres manquants pour retirer le propriétaire (owner_id=' . h($owner_id) . ', form_id=' . h($form_id) . ').'];
    }
    try {
        $pdo->prepare("DELETE FROM form_owners WHERE id = ?")->execute([$owner_id]);
        app_log('owner_remove', 'form:' . $form_id, "Propriétaire retiré");
        return ['redirect' => build_url('index.php?p=admin_forms&form_id=' . urlencode($form_id) . '#owners')];
    } catch (PDOException $e) {
        return ['error' => 'Erreur lors de la suppression du propriétaire : ' . $e->getMessage()];
    }
}

// ── Handlers — Export / Import JSON ────────────────────────────

/** Handler : export_form — exporter un formulaire au format JSON (téléchargement) */
function handle_admin_action_export_form(PDO $pdo): array {
    $export_id = trim($_POST['form_id'] ?? '');
    if (empty($export_id)) {
        return ['error' => 'Aucun formulaire sélectionné pour l\'export.'];
    }
    $stmt = $pdo->prepare("SELECT * FROM forms WHERE id = ?");
    $stmt->execute([$export_id]);
    $form_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$form_data) {
        return ['error' => 'Formulaire introuvable.'];
    }
    // Build export structure
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

    // Fields
    $f_stmt = $pdo->prepare("SELECT * FROM form_fields WHERE form_id = ? ORDER BY ordre");
    $f_stmt->execute([$export_id]);
    foreach ($f_stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
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

    // Steps + recipients
    $s_stmt = $pdo->prepare("SELECT * FROM steps WHERE form_id = ? ORDER BY ordre");
    $s_stmt->execute([$export_id]);
    foreach ($s_stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $recipients = [];
        $r_stmt = $pdo->prepare("SELECT email FROM step_recipients WHERE step_id = ?");
        $r_stmt->execute([$s['id']]);
        foreach ($r_stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
            $recipients[] = $email;
        }
        // v19 — Export de la `condition` (JSON encodé en DB → objet dans le JSON
        // exporté pour être lisible / éditable par un humain ou un LLM).
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

/** Handler : validate_json — valider un JSON (dry-run, pas d'import)  * @return array<string, mixed>
 */
function handle_admin_action_validate_json(): array {
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
            'validation_html' => '<div class="msg-error" role="alert" aria-live="assertive">JSON invalide : ' . h(json_last_error_msg()) . '. Vérifiez la syntaxe (virgules manquantes, guillemets non fermés, etc.).</div>',
            'preserved_json' => $json_input,
        ];
    }
    $result = validate_form_json($data);
    if ($result['valid'] && empty($result['warnings'])) {
        $validation_html = '<div class="msg-success" role="status" aria-live="polite">✓ JSON valide ! Le formulaire et le circuit de validation sont correctement définis. Vous pouvez lancer l\'import.</div>';
    } elseif ($result['valid']) {
        $validation_html = '<div class="msg-success" role="status" aria-live="polite">✓ JSON valide (l\'import fonctionnera), mais avec des avertissements :</div>';
        $validation_html .= format_validation_results($result);
    } else {
        $validation_html = '<div class="msg-error" role="alert" aria-live="assertive" style="margin-bottom:.25rem;">✗ JSON invalide — l\'import échouerait. Corrigez les erreurs ci-dessous :</div>';
        $validation_html .= format_validation_results($result);
    }
    // Preserve the JSON input for the textarea
    return ['validation_html' => $validation_html, 'preserved_json' => $json_input];
}

/** Handler : import_form — importer un formulaire depuis JSON (transactionnel) */
function handle_admin_action_import_form(PDO $pdo): array {
    $json_input = trim($_POST['json_data'] ?? '');
    if (empty($json_input)) {
        return ['error' => 'Aucune donnée JSON fournie pour l\'import.'];
    }
    $data = json_decode($json_input, true);
    if ($data === null) {
        return ['error' => 'JSON invalide : ' . json_last_error_msg()];
    }
    // Validate schema before importing
    $validation = validate_form_json($data);
    if (!$validation['valid']) {
        return [
            'error' => 'Le JSON contient des erreurs de structure. L\'import a été bloqué. Corrigez les erreurs puis réessayez.',
            'validation_html' => format_validation_results($validation),
            'preserved_json' => $json_input,
        ];
    }
    // Show warnings but proceed with import
    try {
        $pdo->beginTransaction();

        $label = $data['form']['label'];
        $slug = generate_slug($label);
        $desc = $data['form']['description'] ?? '';
        $deadline = $data['form']['deadline_field'] ?? '';

        $new_id = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at, deadline_field) VALUES (?, ?, ?, ?, 1, datetime('now'), ?)")
            ->execute([$new_id, $slug, $label, $desc, $deadline]);

        // Import fields
        if (!empty($data['fields'])) {
            $field_stmt = $pdo->prepare("INSERT INTO form_fields (id, form_id, label, field_type, field_name, options, required, ordre, card_group, hint, filled_by, validator_step, visibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $ordre = 1;
            foreach ($data['fields'] as $f) {
                $options_json = null;
                if (!empty($f['options'])) {
                    $options_json = is_string($f['options']) ? $f['options'] : json_encode($f['options'], JSON_UNESCAPED_UNICODE);
                }
                $field_name = !empty($f['field_name']) ? $f['field_name'] : generate_field_name($f['label']);
                $filled_by = !empty($f['filled_by']) ? $f['filled_by'] : 'demandeur';
                if (!in_array($filled_by, ['demandeur', 'validator'])) {
                    $filled_by = 'demandeur';
                }
                // FILE-VISIBILITY : valider visibility, fallback 'all' si absent/invalide
                $visibility = $f['visibility'] ?? 'all';
                if (!is_string($visibility) || !in_array($visibility, ['all', 'owner_only'], true)) {
                    $visibility = 'all';
                }
                $field_stmt->execute([
                    generate_uuid(), $new_id,
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

        // Import steps
        if (!empty($data['steps'])) {
            foreach ($data['steps'] as $s) {
                $step_id = generate_uuid();

                // v19 — Construction de la `condition` (JSON TEXT en DB) :
                // accepte soit un objet {field, op, value}, soit une chaîne
                // JSON existante, soit null/absent (défaut '').
                $raw_cond_import = $s['condition'] ?? '';
                $cond_db = '';
                if (is_array($raw_cond_import)) {
                    // Objet → on re-encode en JSON. On valide l'opérateur
                    // contre la liste fixe (sécurité).
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
                    // Chaîne JSON → on valide que c'est du JSON décodable,
                    // sinon on ignore (sécurité : on n'écrit pas de JSON invalide).
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
                        $recip_stmt->execute([generate_uuid(), $step_id, $email]);
                    }
                }
            }
        }

        $pdo->commit();
        app_log('form_import', 'form:' . $new_id, "Formulaire '$label' importé depuis JSON");
        return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($new_id)];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['error' => 'Erreur lors de l\'import : ' . $e->getMessage()];
    }
}
