<?php
// admin_forms.php — Gestion des formulaires et des étapes
require_once __DIR__ . '/helpers.php';

// Vérification des droits d'accès
if (!is_admin_user() && !is_super_admin()) {
    if (TEST_MODE) { test_json_response(['error' => 'Accès refusé', 'redirect' => 'admin_access.php']); }
    header('Location: admin_access.php');
    exit;
}

// Récupération des formulaires pour le sélecteur
$forms = get_pdo()->query("SELECT id, label FROM forms ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);

// Récupération de l'ID du formulaire sélectionné
$form_id = trim($_GET['form_id'] ?? '');

// Récupération de l'ID de l'étape à modifier
$edit_step_id = trim($_GET['edit_step'] ?? '');

// Récupération de l'ID du champ à modifier
$edit_field_id = trim($_GET['edit_field'] ?? '');

// Traitement des actions POST
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf()) {
    if (TEST_MODE) { test_json_response(['error' => 'CSRF invalide']); }
    render_error_page(403, 'Requête invalide', 'Le jeton de sécurité (CSRF) de votre session est invalide ou a expiré. Cela peut arriver si votre session a été inactive trop longtemps ou si la page est restée ouverte depuis longtemps.', 'Rechargez la page et réessayez. Si le problème persiste, fermez tous les onglets de l\'application et reconnectez-vous.');
}

// ── Field type helper ──────────────────────────────────────────
$field_types = [
    'text'     => '📝 Texte',
    'date'     => '📅 Date',
    'select'   => '📋 Sélecteur',
    'checkbox' => '☑ Case à cocher',
    'textarea' => '📝 Zone de texte',
    'file'     => '📎 Fichier',
];

function field_type_icon(string $type): string {
    $icons = [
        'text'     => '📝',
        'date'     => '📅',
        'select'   => '📋',
        'checkbox' => '☑️',
        'textarea' => '📝',
        'file'     => '📎',
    ];
    return $icons[$type] ?? '📄';
}

function field_type_label(string $type): string {
    $labels = [
        'text'     => 'Texte',
        'date'     => 'Date',
        'select'   => 'Sélecteur',
        'checkbox' => 'Case à cocher',
        'textarea' => 'Zone de texte',
        'file'     => 'Fichier',
    ];
    return $labels[$type] ?? $type;
}

function options_to_lines(?string $json): string {
    if (empty($json)) return '';
    $decoded = json_decode($json, true);
    if (is_array($decoded)) return implode("\n", $decoded);
    return $json;
}

// ── POST Handlers ──────────────────────────────────────────────

if ($action === 'add_form') {
    $slug = trim($_POST['slug'] ?? '');
    $label = trim($_POST['label'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (!empty($slug) && !empty($label)) {
        $pdo = get_pdo();
        try {
            $new_form_id = generate_uuid();
            $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
                ->execute([$new_form_id, $slug, $label, $description]);
            app_log('form_create', 'form:' . $new_form_id, "Formulaire '$label' créé");
            header('Location: admin_forms.php?form_id=' . urlencode($new_form_id));
            exit;
        } catch (PDOException $e) {
            $error_msg = 'Erreur lors de l\'ajout du formulaire : ' . $e->getMessage();
        }
    } else {
        $error_msg = 'Le slug et le libellé sont requis.';
    }

} elseif ($action === 'update_form') {
    $form_id = trim($_POST['form_id'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $label = trim($_POST['label'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $actif = isset($_POST['actif']) ? 1 : 0;

    if (!empty($form_id) && !empty($slug) && !empty($label)) {
        $pdo = get_pdo();
        try {
            $pdo->prepare("UPDATE forms SET slug = ?, label = ?, description = ?, actif = ? WHERE id = ?")
                ->execute([$slug, $label, $description, $actif, $form_id]);
            app_log('form_update', 'form:' . $form_id, "Formulaire '$label' mis à jour");
            header('Location: admin_forms.php?form_id=' . urlencode($form_id));
            exit;
        } catch (PDOException $e) {
            $error_msg = 'Erreur lors de la mise à jour du formulaire : ' . $e->getMessage();
        }
    } else {
        $error_msg = 'Le slug et le libellé sont requis.';
    }

} elseif ($action === 'delete_form') {
    $form_id = trim($_POST['form_id'] ?? '');
    if (!empty($form_id)) {
        $pdo = get_pdo();
        $active_count = has_active_submissions($form_id);
        if ($active_count > 0) {
            $error_msg = 'Impossible de supprimer ce formulaire : ' . $active_count . ' soumission(s) en cours y sont rattachée(s). Veuillez attendre que ces demandes soient clôturées ou les annuler avant de supprimer le formulaire.';
        } else {
            try {
                $pdo->prepare("DELETE FROM steps WHERE form_id = ?")->execute([$form_id]);
                $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$form_id]);
                app_log('form_delete', 'form:' . $form_id, "Formulaire supprimé");
                header('Location: admin_forms.php');
                exit;
            } catch (PDOException $e) {
                $error_msg = 'Erreur lors de la suppression du formulaire : ' . $e->getMessage();
            }
        }
    }

} elseif ($action === 'duplicate_form') {
    $source_id = trim($_POST['source_form_id'] ?? '');
    if (!empty($source_id)) {
        // Récupérer le formulaire source
        $src = $pdo->prepare("SELECT * FROM forms WHERE id = ?");
        $src->execute([$source_id]);
        $src_form = $src->fetch(PDO::FETCH_ASSOC);
        if ($src_form) {
            // Créer le nouveau formulaire
            $new_slug = $src_form['slug'] . '-copie';
            $new_label = $src_form['label'] . ' (copie)';
            $new_id = generate_uuid();
            $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, deadline_field) VALUES (?, ?, ?, ?, 1, ?)")
                ->execute([$new_id, $new_slug, $new_label, $src_form['description'], $src_form['deadline_field']]);
            
            // Copier les champs
            $fields = $pdo->prepare("SELECT * FROM form_fields WHERE form_id = ? ORDER BY ordre");
            $fields->execute([$source_id]);
            foreach ($fields->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $new_field_id = generate_uuid();
                $pdo->prepare("INSERT INTO form_fields (id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$new_field_id, $new_id, $f['label'], $f['field_type'], $f['field_name'], $f['options'], $f['hint'] ?? '', $f['required'], $f['ordre'], $f['card_group']]);
            }
            
            // Copier les étapes et destinataires
            $steps = $pdo->prepare("SELECT * FROM steps WHERE form_id = ? ORDER BY ordre");
            $steps->execute([$source_id]);
            foreach ($steps->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $new_step_id = generate_uuid();
                $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$new_step_id, $new_id, $s['label'], $s['ordre'], $s['actif']]);
                
                $recips = $pdo->prepare("SELECT * FROM step_recipients WHERE step_id = ?");
                $recips->execute([$s['id']]);
                foreach ($recips->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $new_recipient_id = generate_uuid();
                    $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)")
                        ->execute([$new_recipient_id, $new_step_id, $r['email']]);
                }
            }
            
            app_log('form_duplicate', 'form:' . $new_id, 'Formulaire dupliqué');
            $success_msg = 'Formulaire dupliqué avec succès.';
        }
    }

} elseif ($action === 'add_step') {
    $form_id = trim($_POST['form_id'] ?? '');
    $label = trim($_POST['label'] ?? '');
    $ordre = (int)($_POST['ordre'] ?? 0);

    if (!empty($form_id) && !empty($label) && $ordre > 0) {
        $pdo = get_pdo();
        try {
            $new_step_id = generate_uuid();
            $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)")
                ->execute([$new_step_id, $form_id, $label, $ordre]);
            app_log('step_add', 'form:' . $form_id, "Étape '$label' ajoutée");
            header('Location: admin_forms.php?form_id=' . urlencode($form_id));
            exit;
        } catch (PDOException $e) {
            $error_msg = 'Erreur lors de l\'ajout de l\'étape : ' . $e->getMessage();
        }
    } else {
        $error_msg = 'Les champs obligatoires ne sont pas remplis.';
    }

} elseif ($action === 'update_step') {
    $step_id = trim($_POST['step_id'] ?? '');
    $label = trim($_POST['label'] ?? '');
    $ordre = (int)($_POST['ordre'] ?? 0);
    $actif = isset($_POST['actif']) ? 1 : 0;

    if (!empty($step_id) && !empty($label) && $ordre > 0) {
        $pdo = get_pdo();
        try {
            $pdo->prepare("UPDATE steps SET label = ?, ordre = ?, actif = ? WHERE id = ?")
                ->execute([$label, $ordre, $actif, $step_id]);
            app_log('step_update', 'step:' . $step_id, "Étape '$label' mise à jour");
            header('Location: admin_forms.php?form_id=' . urlencode($form_id));
            exit;
        } catch (PDOException $e) {
            $error_msg = 'Erreur lors de la mise à jour de l\'étape : ' . $e->getMessage();
        }
    } else {
        $error_msg = 'Les champs obligatoires ne sont pas remplis.';
    }

} elseif ($action === 'delete_step') {
    $step_id = trim($_POST['step_id'] ?? '');
    if (!empty($step_id)) {
        $pdo = get_pdo();
        $active_count = has_active_step_submissions($step_id);
        if ($active_count > 0) {
            $error_msg = 'Impossible de supprimer cette étape : ' . $active_count . ' soumission(s) en cours y sont rattachée(s). Veuillez attendre que ces demandes soient clôturées ou les annuler avant de supprimer l\'étape.';
        } else {
            try {
                $pdo->prepare("DELETE FROM step_recipients WHERE step_id = ?")->execute([$step_id]);
                $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$step_id]);
                header('Location: admin_forms.php?form_id=' . urlencode($form_id));
                exit;
            } catch (PDOException $e) {
                $error_msg = 'Erreur lors de la suppression de l\'étape : ' . $e->getMessage();
            }
        }
    }

} elseif ($action === 'add_recipient') {
    $step_id = trim($_POST['step_id'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if (!empty($step_id) && !empty($email)) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = 'L\'adresse email "' . h($email) . '" n\'est pas valide. Format attendu : prenom.nom@dreets.gouv.fr';
        } else {
            $pdo = get_pdo();
            try {
                $new_rcpt_id = generate_uuid();
                $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)")
                    ->execute([$new_rcpt_id, $step_id, $email]);
                app_log('recipient_add', 'step:' . $step_id, "Destinataire $email ajouté");
                header('Location: admin_forms.php?form_id=' . urlencode($form_id));
                exit;
            } catch (PDOException $e) {
                $error_msg = 'Erreur lors de l\'ajout du destinataire : ' . $e->getMessage();
            }
        }
    } else {
        $error_msg = 'L\'étape et l\'email sont requis.';
    }

} elseif ($action === 'delete_recipient') {
    $recipient_id = trim($_POST['recipient_id'] ?? '');
    if (!empty($recipient_id)) {
        $pdo = get_pdo();
        try {
            $pdo->prepare("DELETE FROM step_recipients WHERE id = ?")->execute([$recipient_id]);
            header('Location: admin_forms.php?form_id=' . urlencode($form_id));
            exit;
        } catch (PDOException $e) {
            $error_msg = 'Erreur lors de la suppression du destinataire : ' . $e->getMessage();
        }
    }

} elseif ($action === 'add_field') {
    $form_id = trim($_POST['form_id'] ?? '');
    $ff_label = trim($_POST['ff_label'] ?? '');
    $ff_field_name = trim($_POST['ff_field_name'] ?? '');
    $ff_field_type = trim($_POST['ff_field_type'] ?? 'text');
    $ff_options_raw = trim($_POST['ff_options'] ?? '');
    $ff_required = isset($_POST['ff_required']) ? 1 : 0;
    $ff_ordre = (int)($_POST['ff_ordre'] ?? 0);
    $ff_card_group_raw = trim($_POST['ff_card_group'] ?? '');
    $ff_card_group_new = trim($_POST['ff_card_group_new'] ?? '');
    // Determine card_group: new text input takes priority, then select value, then default
    if (!empty($ff_card_group_new)) {
        $ff_card_group = $ff_card_group_new;
    } elseif ($ff_card_group_raw === '__new__' || empty($ff_card_group_raw)) {
        $ff_card_group = 'Général';
    } else {
        $ff_card_group = $ff_card_group_raw;
    }

    // Auto-generate field_name from label if empty
    if (empty($ff_field_name) && !empty($ff_label)) {
        $ff_field_name = generate_field_name($ff_label);
    }

    if (!empty($form_id) && !empty($ff_label) && !empty($ff_field_name)) {
        $pdo = get_pdo();
        try {
            // Parse options: one per line → JSON
            $options_json = parse_options_input($ff_options_raw);
            $ff_hint = trim($_POST['ff_hint'] ?? '');

            $new_field_id = generate_uuid();
            $pdo->prepare("INSERT INTO form_fields (id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$new_field_id, $form_id, $ff_label, $ff_field_type, $ff_field_name, $options_json, $ff_hint, $ff_required, $ff_ordre, $ff_card_group]);
            app_log('field_add', 'form:' . $form_id, "Champ '$ff_label' ajouté");
            header('Location: admin_forms.php?form_id=' . urlencode($form_id) . '#fields');
            exit;
        } catch (PDOException $e) {
            $error_msg = 'Erreur lors de l\'ajout du champ : ' . $e->getMessage();
        }
    } else {
        $error_msg = 'Le libellé du champ est requis.';
    }

} elseif ($action === 'update_field') {
    $field_id = trim($_POST['field_id'] ?? '');
    $form_id = trim($_POST['form_id'] ?? '');
    $ff_label = trim($_POST['ff_label'] ?? '');
    $ff_field_name = trim($_POST['ff_field_name'] ?? '');
    $ff_field_type = trim($_POST['ff_field_type'] ?? 'text');
    $ff_options_raw = trim($_POST['ff_options'] ?? '');
    $ff_required = isset($_POST['ff_required']) ? 1 : 0;
    $ff_ordre = (int)($_POST['ff_ordre'] ?? 0);
    $ff_card_group_raw = trim($_POST['ff_card_group'] ?? '');
    $ff_card_group_new = trim($_POST['ff_card_group_new'] ?? '');
    // Determine card_group: new text input takes priority, then select value, then default
    if (!empty($ff_card_group_new)) {
        $ff_card_group = $ff_card_group_new;
    } elseif ($ff_card_group_raw === '__new__' || empty($ff_card_group_raw)) {
        $ff_card_group = 'Général';
    } else {
        $ff_card_group = $ff_card_group_raw;
    }

    // Auto-generate field_name from label if empty
    if (empty($ff_field_name) && !empty($ff_label)) {
        $ff_field_name = generate_field_name($ff_label);
    }

    if (!empty($field_id) && !empty($ff_label) && !empty($ff_field_name)) {
        $pdo = get_pdo();
        try {
            // Parse options: one per line → JSON
            $options_json = parse_options_input($ff_options_raw);
            $ff_hint = trim($_POST['ff_hint'] ?? '');

            $pdo->prepare("UPDATE form_fields SET label = ?, field_type = ?, field_name = ?, options = ?, hint = ?, required = ?, ordre = ?, card_group = ? WHERE id = ?")
                ->execute([$ff_label, $ff_field_type, $ff_field_name, $options_json, $ff_hint, $ff_required, $ff_ordre, $ff_card_group, $field_id]);
            app_log('field_update', 'field:' . $field_id, "Champ '$ff_label' mis à jour");
            header('Location: admin_forms.php?form_id=' . urlencode($form_id) . '#fields');
            exit;
        } catch (PDOException $e) {
            $error_msg = 'Erreur lors de la mise à jour du champ : ' . $e->getMessage();
        }
    } else {
        $error_msg = 'Le libellé du champ est requis.';
    }

} elseif ($action === 'delete_field') {
    $field_id = trim($_POST['field_id'] ?? '');
    $form_id = trim($_POST['form_id'] ?? '');
    if (!empty($field_id)) {
        $pdo = get_pdo();
        try {
            $pdo->prepare("DELETE FROM form_fields WHERE id = ?")->execute([$field_id]);
            header('Location: admin_forms.php?form_id=' . urlencode($form_id) . '#fields');
            exit;
        } catch (PDOException $e) {
            $error_msg = 'Erreur lors de la suppression du champ : ' . $e->getMessage();
        }
    }

} elseif ($action === 'add_owner') {
    $form_id = trim($_POST['form_id'] ?? '');
    $owner_email = trim($_POST['owner_email'] ?? '');

    if (!empty($form_id) && !empty($owner_email)) {
        if (!filter_var($owner_email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = 'L\'adresse email "' . h($owner_email) . '" n\'est pas valide. Format attendu : prenom.nom@dreets.gouv.fr';
        } else {
            $pdo = get_pdo();
            try {
                $new_owner_id = generate_uuid();
                $pdo->prepare("INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)")
                    ->execute([$new_owner_id, $form_id, $owner_email]);
                app_log('owner_add', 'form:' . $form_id, "Propriétaire $owner_email ajouté");
                header('Location: admin_forms.php?form_id=' . urlencode($form_id) . '#owners');
                exit;
            } catch (PDOException $e) {
                $error_msg = 'Erreur lors de l\'ajout du propriétaire : ' . $e->getMessage();
            }
        }
    } else {
        $error_msg = 'L\'email du propriétaire est requis.';
    }

} elseif ($action === 'delete_owner') {
    $owner_id = trim($_POST['owner_id'] ?? '');
    $form_id = trim($_POST['form_id'] ?? '');
    if (!empty($owner_id) && !empty($form_id)) {
        $pdo = get_pdo();
        try {
            $pdo->prepare("DELETE FROM form_owners WHERE id = ?")->execute([$owner_id]);
            app_log('owner_remove', 'form:' . $form_id, "Propriétaire retiré");
            header('Location: admin_forms.php?form_id=' . urlencode($form_id) . '#owners');
            exit;
        } catch (PDOException $e) {
            $error_msg = 'Erreur lors de la suppression du propriétaire : ' . $e->getMessage();
        }
    }
}

// ── Data fetching ──────────────────────────────────────────────

$form = null;
$steps = [];
$form_fields = [];
$existing_groups = [];

if (!empty($form_id)) {
    $pdo = get_pdo();

    $stmt = $pdo->prepare("SELECT * FROM forms WHERE id = ?");
    $stmt->execute([$form_id]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($form) {
        // Steps with recipients
        $stmt = $pdo->prepare("
            SELECT s.*,
                   (SELECT COUNT(*) FROM step_recipients sr WHERE sr.step_id = s.id) as recipient_count
            FROM steps s
            WHERE s.form_id = ?
            ORDER BY s.ordre, s.label
        ");
        $stmt->execute([$form_id]);
        $steps = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($steps as &$step) {
            $stmt = $pdo->prepare("SELECT * FROM step_recipients WHERE step_id = ? ORDER BY email");
            $stmt->execute([$step['id']]);
            $step['recipients'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Form fields
        $stmt = $pdo->prepare("SELECT * FROM form_fields WHERE form_id = ? ORDER BY ordre, id");
        $stmt->execute([$form_id]);
        $form_fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Existing card groups
        $stmt = $pdo->prepare("SELECT DISTINCT card_group FROM form_fields WHERE form_id = ? ORDER BY card_group");
        $stmt->execute([$form_id]);
        $existing_groups = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Form owners
        $owners = get_form_owners($form_id);
    }
}

// ── Group steps by ordre for the workflow diagram ──────────────
$steps_by_ordre = [];
foreach ($steps as $step) {
    $steps_by_ordre[$step['ordre']][] = $step;
}
ksort($steps_by_ordre);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestion des formulaires — DREETS Workflow</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='15' fill='%23003189'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial'>D</text></svg>">
    <?php require_once __DIR__ . '/style.php'; ?>
    <style>
        .container { max-width: 1200px; }

        /* ── Section cards with colored headers ──────────────── */
        .section-card {
            background: #fff;
            border: 1px solid #dde;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        .section-card-header {
            background: #003189;
            color: #fff;
            padding: .75rem 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .section-card-header h2 {
            color: #fff;
            border: none;
            margin: 0;
            padding: 0;
            font-size: 1.05rem;
        }
        .section-card-header a,
        .section-card-header button {
            color: #fff;
            text-decoration: none;
            font-size: .82rem;
            opacity: .85;
        }
        .section-card-header a:hover,
        .section-card-header button:hover {
            opacity: 1;
        }
        .section-card-body {
            padding: 1.25rem;
        }

        /* ── Workflow diagram ────────────────────────────────── */
        .workflow-diagram {
            display: flex;
            align-items: flex-start;
            gap: 0;
            padding: 1.5rem 0.5rem;
            overflow-x: auto;
        }
        .workflow-step-group {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 150px;
            max-width: 200px;
            flex-shrink: 0;
        }
        .workflow-arrow {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
            flex-shrink: 0;
            padding-top: 0;
            align-self: stretch;
            display: flex;
            align-items: center;
        }
        .workflow-arrow::after {
            content: '→';
            font-size: 1.8rem;
            color: #003189;
            font-weight: bold;
        }
        .workflow-box {
            background: #003189;
            color: #fff;
            border-radius: 6px;
            padding: .75rem 1rem;
            text-align: center;
            width: 100%;
            margin-bottom: .5rem;
            box-shadow: 0 2px 6px rgba(0,49,137,.2);
        }
        .workflow-box.inactive {
            background: #b0b0b0;
            box-shadow: none;
        }
        .workflow-box .wb-label {
            font-weight: bold;
            font-size: .88rem;
            margin-bottom: .25rem;
        }
        .workflow-box .wb-ordre {
            font-size: .72rem;
            opacity: .8;
            margin-bottom: .35rem;
        }
        .workflow-box .wb-emails {
            font-size: .72rem;
            opacity: .75;
            line-height: 1.4;
            word-break: break-all;
        }
        .workflow-box.inactive .wb-label { opacity: .7; }
        .workflow-box.inactive .wb-ordre { opacity: .5; }
        .workflow-box.inactive .wb-emails { opacity: .5; }
        .workflow-empty {
            text-align: center;
            padding: 2rem;
            color: #888;
            font-style: italic;
        }

        /* ── Step list items ─────────────────────────────────── */
        .step-card {
            border: 1px solid #dde;
            border-radius: 5px;
            padding: .75rem 1rem;
            margin-bottom: .75rem;
            background: #fafaff;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }
        .step-card.editing {
            background: #f0f4ff;
            border-color: #003189;
        }
        .step-info { flex: 1; }
        .step-info .step-label { font-weight: bold; color: #003189; }
        .step-info .step-meta { font-size: .82rem; color: #666; margin-top: .25rem; }
        .step-info .step-meta .badge-ok { margin-left: .5rem; }
        .step-actions { display: flex; gap: .4rem; flex-shrink: 0; }
        .recipient-chips { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .4rem; }
        .recipient-chip {
            background: #e3f2fd;
            border: 1px solid #90caf9;
            border-radius: 12px;
            padding: .15rem .6rem;
            font-size: .76rem;
            color: #1565c0;
            display: inline-flex;
            align-items: center;
            gap: .3rem;
        }
        .recipient-chip form {
            display: inline;
        }
        .recipient-chip .chip-delete {
            background: none;
            border: none;
            color: #c0392b;
            cursor: pointer;
            font-size: .9rem;
            padding: 0;
            line-height: 1;
        }

        /* ── Field table improvements ────────────────────────── */
        .fields-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
        .fields-table thead th {
            background: #003189;
            color: #fff;
            padding: .55rem .6rem;
            text-align: left;
            font-weight: normal;
            white-space: nowrap;
        }
        .fields-table tbody td {
            padding: .5rem .6rem;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }
        .fields-table tbody tr:hover { background: #f0f4ff; }
        .field-type-badge {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            background: #e8eaf6;
            color: #003189;
            border-radius: 3px;
            padding: .2rem .5rem;
            font-size: .78rem;
            font-weight: bold;
        }
        .required-star {
            color: #c0392b;
            font-weight: bold;
            font-size: 1rem;
            margin-left: 2px;
        }

        /* ── Preview button ──────────────────────────────────── */
        .btn-preview {
            background: #27ae60;
            color: #fff;
            padding: .5rem 1rem;
            border: none;
            border-radius: 3px;
            font-size: .85rem;
            font-family: inherit;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: .3rem;
        }
        .btn-preview:hover { background: #219a52; }

        /* ── Form grid ───────────────────────────────────────── */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .75rem;
        }
        .form-grid .full-width {
            grid-column: 1 / -1;
        }
        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
        }

        /* ── Step recipient section ──────────────────────────── */
        .step-recipient-picker {
            margin-top: 1rem;
        }
        .step-recipient-picker select {
            max-width: 350px;
        }

        /* ── Add forms ───────────────────────────────────────── */
        .add-sub-card {
            background: #f9f9ff;
            border: 1px dashed #aab;
            border-radius: 5px;
            padding: 1rem;
            margin-top: 1rem;
        }
        .add-sub-card h4 {
            font-size: .92rem;
            color: #003189;
            margin-bottom: .75rem;
        }
    </style>
</head>
<body>
<div class="bandeau">
    <strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités
    <span>Connecté en tant que : <strong><?= h(get_auth_user()) ?></strong></span>
    <span><a href="dashboard.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;">📊 Dashboard</a> <a href="stats.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;margin-left:8px;">📈 Statistiques</a> <a href="rgpd.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;margin-left:8px;">🔐 RGPD</a> <a href="docs.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;margin-left:8px;">📖 Documentation</a> <a href="admin_settings.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;margin-left:8px;">⚙ Paramètres</a></span>
</div>
<div class="container">
    <h1>⚙ Gestion des formulaires</h1>

    <?php if (!empty($success_msg)): ?>
        <div class="msg-success"><?= h($success_msg) ?></div>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
        <div class="msg-error"><?= h($error_msg) ?></div>
    <?php endif; ?>

    <!-- ── Form selector ──────────────────────────────────────── -->
    <div class="form-selector">
        <form method="GET" style="display:inline-flex;gap:.5rem;align-items:center;">
            <select name="form_id">
                <option value="">— Sélectionner un formulaire —</option>
                <?php foreach ($forms as $f): ?>
                    <option value="<?= $f['id'] ?>" <?= $form_id == $f['id'] ? 'selected' : '' ?>>
                        <?= h($f['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary" style="font-size:.8rem;padding:.3rem .8rem;">OK</button>
        </form>
        <a href="admin_forms.php" class="btn btn-primary">＋ Nouveau formulaire</a>
    </div>

    <?php if (empty($form_id)): ?>
        <!-- ── New form creation ──────────────────────────────── -->
        <div class="section-card">
            <div class="section-card-header">
                <h2>📋 Créer un nouveau formulaire</h2>
            </div>
            <div class="section-card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_form">
                    <div class="form-grid">
                        <div class="field">
                            <label>Slug (identifiant technique)<span class="req">*</span></label>
                            <input type="text" name="slug" required placeholder="ex: onboarding">
                        </div>
                        <div class="field">
                            <label>Libellé (affiché dans l'interface)<span class="req">*</span></label>
                            <input type="text" name="label" required placeholder="ex: Onboarding agent">
                        </div>
                        <div class="field full-width">
                            <label>Description</label>
                            <textarea name="description" placeholder="Description du formulaire"></textarea>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Créer le formulaire</button>
                </form>
            </div>
        </div>

    <?php else: ?>

        <?php if ($form): ?>
            <!-- ── Top action bar ──────────────────────────────── -->
            <div style="display:flex;gap:.75rem;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;">
                <a href="form_preview.php?form_id=<?= $form_id ?>" class="btn-preview" target="_blank">👁 Prévisualiser le formulaire</a>
                <a href="dashboard.php" class="btn btn-secondary">← Tableau de bord</a>
            </div>

            <!-- ══════════════════════════════════════════════════ -->
            <!-- SECTION A: Form info                             -->
            <!-- ══════════════════════════════════════════════════ -->
            <div class="section-card">
                <div class="section-card-header">
                    <h2>📋 Informations du formulaire</h2>
                    <form method="POST" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="duplicate_form">
                        <input type="hidden" name="source_form_id" value="<?= $form['id'] ?>">
                        <button type="submit" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;">📋 Dupliquer</button>
                    </form>
                    <form method="POST" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_form">
                        <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                        <button type="submit" style="background:#c0392b;color:#fff;border:none;border-radius:3px;padding:.3rem .7rem;cursor:pointer;font-size:.8rem;font-family:inherit;">Supprimer</button>
                    </form>
                </div>
                <div class="section-card-body">
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_form">
                        <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                        <div class="form-grid">
                            <div class="field">
                                <label>Slug (identifiant technique)<span class="req">*</span></label>
                                <input type="text" name="slug" value="<?= h($form['slug']) ?>" required>
                            </div>
                            <div class="field">
                                <label>Libellé (affiché dans l'interface)<span class="req">*</span></label>
                                <input type="text" name="label" value="<?= h($form['label']) ?>" required>
                            </div>
                            <div class="field full-width">
                                <label>Description</label>
                                <textarea name="description" placeholder="Description du formulaire"><?= h($form['description']) ?></textarea>
                            </div>
                            <div class="field">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="actif" <?= $form['actif'] ? 'checked' : '' ?>> Formulaire actif
                                </label>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Enregistrer</button>
                    </form>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════ -->
            <!-- SECTION B: Workflow diagram + Steps              -->
            <!-- ══════════════════════════════════════════════════ -->
            <div class="section-card">
                <div class="section-card-header">
                    <h2>🔀 Circuit de validation</h2>
                </div>
                <div class="section-card-body">

                    <!-- ── Visual Workflow Diagram ─────────────────── -->
                    <?php if (!empty($steps_by_ordre)): ?>
                        <div class="workflow-diagram">
                            <?php
                            $ordre_keys = array_keys($steps_by_ordre);
                            $last_key = end($ordre_keys);
                            ?>
                            <?php foreach ($steps_by_ordre as $ordre => $ordre_steps): ?>
                                <div class="workflow-step-group">
                                    <?php foreach ($ordre_steps as $idx => $wstep): ?>
                                        <div class="workflow-box <?= $wstep['actif'] ? '' : 'inactive' ?>" style="<?= count($ordre_steps) > 1 && $idx > 0 ? 'margin-top:.5rem;' : '' ?>">
                                            <div class="wb-label"><?= h($wstep['label']) ?></div>
                                            <div class="wb-ordre">Étape <?= h($ordre) ?></div>
                                            <?php if (!empty($wstep['recipients'])): ?>
                                                <div class="wb-emails"><?= h(implode(', ', array_column($wstep['recipients'], 'email'))) ?></div>
                                            <?php else: ?>
                                                <div class="wb-emails" style="font-style:italic;">Aucun destinataire</div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($ordre !== $last_key): ?>
                                    <div class="workflow-arrow"></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="workflow-empty">Aucune étape définie. Ajoutez-en ci-dessous.</div>
                    <?php endif; ?>

                    <hr style="border:none;border-top:1px solid #dde;margin:1rem 0;">

                    <!-- ── Add step form ───────────────────────────── -->
                    <div class="add-sub-card">
                        <h4>＋ Ajouter une étape</h4>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_step">
                            <input type="hidden" name="form_id" value="<?= $form_id ?>">
                            <div class="form-grid">
                                <div class="field">
                                    <label>Libellé de l'étape<span class="req">*</span></label>
                                    <input type="text" name="label" required placeholder="ex: Validation RH">
                                </div>
                                <div class="field">
                                    <label>Ordre (numéro)<span class="req">*</span></label>
                                    <input type="number" name="ordre" required min="1" value="<?= empty($steps) ? 1 : max(array_column($steps, 'ordre')) + 1 ?>">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">Ajouter l'étape</button>
                        </form>
                    </div>

                    <!-- ── Step list ───────────────────────────────── -->
                    <?php if (!empty($steps)): ?>
                        <div style="margin-top:1.25rem;">
                            <?php foreach ($steps as $step): ?>
                                <?php if ($edit_step_id === $step['id']): ?>
                                    <!-- ── Edit step inline ──────────────────── -->
                                    <div class="step-card editing">
                                        <div class="step-info" style="width:100%;">
                                            <form method="POST">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="update_step">
                                                <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
                                                <input type="hidden" name="form_id" value="<?= $form_id ?>">
                                                <div class="form-grid">
                                                    <div class="field">
                                                        <label>Libellé<span class="req">*</span></label>
                                                        <input type="text" name="label" value="<?= h($step['label']) ?>" required>
                                                    </div>
                                                    <div class="field">
                                                        <label>Ordre<span class="req">*</span></label>
                                                        <input type="number" name="ordre" value="<?= $step['ordre'] ?>" required min="1">
                                                    </div>
                                                </div>
                                                <div class="field">
                                                    <label class="checkbox-label">
                                                        <input type="checkbox" name="actif" <?= $step['actif'] ? 'checked' : '' ?>> Étape active
                                                    </label>
                                                </div>
                                                <div style="display:flex;gap:.5rem;">
                                                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                                                    <a href="?form_id=<?= $form_id ?>" class="btn btn-secondary">Annuler</a>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="step-card">
                                        <div class="step-info">
                                            <span class="step-label"><?= h($step['label']) ?></span>
                                            <div class="step-meta">
                                                Ordre <?= h($step['ordre']) ?>
                                                <?php if ($step['actif']): ?>
                                                    <span class="badge badge-ok">Actif</span>
                                                <?php else: ?>
                                                    <span class="badge" style="background:#eee;color:#888;">Inactif</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($step['recipients'])): ?>
                                                <div class="recipient-chips">
                                                    <?php foreach ($step['recipients'] as $rcpt): ?>
                                                        <span class="recipient-chip">
                                                            <?= h($rcpt['email']) ?>
                                                            <form method="POST" style="display:inline;">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="delete_recipient">
                                                                <input type="hidden" name="recipient_id" value="<?= $rcpt['id'] ?>">
                                                                <button type="submit" class="chip-delete" title="Supprimer">×</button>
                                                            </form>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <div style="font-size:.8rem;color:#999;margin-top:.3rem;">Aucun destinataire</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="step-actions">
                                            <a href="?form_id=<?= $form_id ?>&edit_step=<?= $step['id'] ?>" class="btn btn-secondary" style="font-size:.78rem;padding:.3rem .6rem;">Modifier</a>
                                            <form method="POST" style="display:inline;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_step">
                                                <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
                                                <button type="submit" class="btn btn-danger" style="font-size:.78rem;padding:.3rem .6rem;">Supprimer</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════ -->
            <!-- SECTION C: Recipient management per step         -->
            <!-- ══════════════════════════════════════════════════ -->
            <div class="section-card">
                <div class="section-card-header">
                    <h2>📧 Destinataires par étape</h2>
                </div>
                <div class="section-card-body">
                    <div class="step-recipient-picker">
                        <div class="field">
                            <label>Choisir une étape</label>
                            <form method="GET" style="display:inline-flex;gap:.5rem;align-items:center;">
                                <input type="hidden" name="form_id" value="<?= $form_id ?>">
                                <select name="step_id">
                                    <option value="">— Sélectionner une étape —</option>
                                    <?php foreach ($steps as $step): ?>
                                        <option value="<?= $step['id'] ?>" <?= (isset($_GET['step_id']) && $_GET['step_id'] == $step['id']) ? 'selected' : '' ?>>
                                            Étape <?= h($step['ordre']) ?> — <?= h($step['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-secondary" style="font-size:.8rem;padding:.3rem .8rem;">OK</button>
                            </form>
                        </div>

                        <?php if (isset($_GET['step_id']) && !empty($_GET['step_id'])): ?>
                            <?php
                            $selected_step = null;
                            foreach ($steps as $step) {
                                if ($step['id'] == $_GET['step_id']) {
                                    $selected_step = $step;
                                    break;
                                }
                            }
                            ?>

                            <?php if ($selected_step): ?>
                                <h3 style="margin-top:1rem;">Étape <?= h($selected_step['ordre']) ?> — <?= h($selected_step['label']) ?></h3>

                                <div class="add-sub-card">
                                    <h4>＋ Ajouter un destinataire</h4>
                                    <form method="POST">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="add_recipient">
                                        <input type="hidden" name="step_id" value="<?= $selected_step['id'] ?>">
                                        <div class="form-grid">
                                            <div class="field">
                                                <label>Email du destinataire<span class="req">*</span></label>
                                                <input type="email" name="email" required placeholder="ex: prenom.nom@dreets.gouv.fr">
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary">Ajouter le destinataire</button>
                                    </form>
                                </div>

                                <?php if (!empty($selected_step['recipients'])): ?>
                                    <div style="margin-top:1rem;">
                                        <div class="recipient-chips" style="gap:.5rem;">
                                            <?php foreach ($selected_step['recipients'] as $recipient): ?>
                                                <span class="recipient-chip" style="font-size:.82rem;padding:.3rem .7rem;">
                                                    📧 <?= h($recipient['email']) ?>
                                                    <form method="POST" style="display:inline;">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete_recipient">
                                                        <input type="hidden" name="recipient_id" value="<?= $recipient['id'] ?>">
                                                        <button type="submit" class="chip-delete" title="Supprimer">×</button>
                                                    </form>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <p style="color:#888;margin-top:.75rem;">Aucun destinataire défini pour cette étape.</p>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <p style="color:#888;margin-top:.5rem;">Sélectionnez une étape pour gérer ses destinataires.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════ -->
            <!-- SECTION D: Form fields                          -->
            <!-- ══════════════════════════════════════════════════ -->
            <div class="section-card" id="fields">
                <div class="section-card-header">
                    <h2>📝 Champs du formulaire</h2>
                    <a href="form_preview.php?form_id=<?= $form_id ?>" class="btn-preview" target="_blank" style="font-size:.8rem;">👁 Prévisualiser</a>
                </div>
                <div class="section-card-body">
                    <p style="font-size:.85rem;color:#666;margin-bottom:1rem;">Ces champs définissent le formulaire que les agents rempliront. <span class="required-star">*</span> = champ obligatoire.</p>

                    <?php if (!empty($form_fields)): ?>
                        <table class="fields-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Groupe</th>
                                    <th>Libellé</th>
                                    <th>Identifiant</th>
                                    <th>Type</th>
                                    <th>Options</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($form_fields as $ff): ?>
                                    <?php if ($edit_field_id === $ff['id']): ?>
                                        <!-- ── Edit field inline ──────────────── -->
                                        <tr>
                                            <td colspan="7" style="background:#f0f4ff;padding:1rem;">
                                                <h4 style="margin-bottom:.75rem;color:#003189;">Modifier le champ</h4>
                                                <form method="POST">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="update_field">
                                                    <input type="hidden" name="field_id" value="<?= $ff['id'] ?>">
                                                    <input type="hidden" name="form_id" value="<?= $form_id ?>">
                                                    <div class="form-grid">
                                                        <div class="field">
                                                            <label>Libellé<span class="req">*</span></label>
                                                            <input type="text" name="ff_label" value="<?= h($ff['label']) ?>" required>
                                                        </div>
                                                        <div class="field">
                                                            <label>Identifiant technique <span class="hint">(auto si vide)</span></label>
                                                            <input type="text" name="ff_field_name" value="<?= h($ff['field_name']) ?>" placeholder="Généré automatiquement depuis le libellé">
                                                        </div>
                                                        <div class="field">
                                                            <label>Type de champ</label>
                                                            <select name="ff_field_type">
                                                                <?php foreach ($field_types as $val => $lbl): ?>
                                                                    <option value="<?= $val ?>" <?= $ff['field_type'] === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="field">
                                                            <label>Ordre</label>
                                                            <input type="number" name="ff_ordre" value="<?= $ff['ordre'] ?>" min="0">
                                                        </div>
                                                        <div class="field">
                                                            <label>Groupe (carte)</label>
                                                            <?php if (!empty($existing_groups)): ?>
                                                                <select name="ff_card_group">
                                                                    <?php foreach ($existing_groups as $g): ?>
                                                                        <option value="<?= h($g) ?>" <?= $ff['card_group'] === $g ? 'selected' : '' ?>><?= h($g) ?></option>
                                                                    <?php endforeach; ?>
                                                                    <option value="__new__" <?= !in_array($ff['card_group'], $existing_groups) ? 'selected' : '' ?>>— Nouveau groupe —</option>
                                                                </select>
                                                            <?php endif; ?>
                                                            <input type="text" name="ff_card_group_new" placeholder="Nom du nouveau groupe" style="margin-top:.3rem;" value="">
                                                            <?php if (empty($existing_groups)): ?>
                                                                <input type="hidden" name="ff_card_group" value="">
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="field">
                                                            <label>Options <span class="hint">(une par ligne, uniquement pour Sélecteur)</span></label>
                                                            <textarea name="ff_options" rows="3" placeholder="Option A&#10;Option B&#10;Option C"><?= h(options_to_lines($ff['options'] ?? '')) ?></textarea>
                                                        </div>
                                                        <div class="field">
                                                            <label>Indication <span class="hint">(texte d'aide sous le champ)</span></label>
                                                            <input type="text" name="ff_hint" value="<?= h($ff['hint'] ?? '') ?>" placeholder="ex : en euros TTC">
                                                        </div>
                                                    </div>
                                                    <div class="field" style="margin-top:.25rem;">
                                                        <label class="checkbox-label">
                                                            <input type="checkbox" name="ff_required" <?= $ff['required'] ? 'checked' : '' ?>> Champ obligatoire <span class="required-star">*</span>
                                                        </label>
                                                    </div>
                                                    <div style="display:flex;gap:.5rem;margin-top:.5rem;">
                                                        <button type="submit" class="btn btn-primary">Enregistrer</button>
                                                        <a href="?form_id=<?= $form_id ?>#fields" class="btn btn-secondary">Annuler</a>
                                                    </div>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <tr>
                                            <td><?= h($ff['ordre']) ?></td>
                                            <td><span style="font-size:.8rem;color:#666;"><?= h($ff['card_group']) ?></span></td>
                                            <td>
                                                <?= h($ff['label']) ?>
                                                <?php if ($ff['required']): ?>
                                                    <span class="required-star" title="Champ obligatoire">*</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><code style="font-size:.78rem;background:#eef;padding:.1rem .3rem;border-radius:2px;"><?= h($ff['field_name']) ?></code></td>
                                            <td>
                                                <span class="field-type-badge">
                                                    <?= field_type_icon($ff['field_type']) ?>
                                                    <?= field_type_label($ff['field_type']) ?>
                                                </span>
                                            </td>
                                            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($ff['options'] ?? '') ?>">
                                                <?php
                                                $opts = $ff['options'] ?? '';
                                                if (!empty($opts)) {
                                                    $decoded = json_decode($opts, true);
                                                    if (is_array($decoded)) {
                                                        echo h(implode(', ', $decoded));
                                                    } else {
                                                        echo h($opts);
                                                    }
                                                } else {
                                                    echo '—';
                                                }
                                                ?>
                                            </td>
                                            <td class="actions">
                                                <a href="?form_id=<?= $form_id ?>&edit_field=<?= $ff['id'] ?>#fields" class="btn btn-secondary" style="font-size:.76rem;padding:.25rem .5rem;">Modifier</a>
                                                <form method="POST" style="display:inline;">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete_field">
                                                    <input type="hidden" name="field_id" value="<?= $ff['id'] ?>">
                                                    <input type="hidden" name="form_id" value="<?= $form_id ?>">
                                                    <button type="submit" class="btn btn-danger" style="font-size:.76rem;padding:.25rem .5rem;">Supprimer</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-icon">📝</div>
                            <p>Aucun champ défini pour ce formulaire.</p>
                        </div>
                    <?php endif; ?>

                    <!-- ── Add field form ──────────────────────────── -->
                    <div class="add-sub-card">
                        <h4>＋ Ajouter un champ</h4>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_field">
                            <input type="hidden" name="form_id" value="<?= $form_id ?>">
                            <div class="form-grid">
                                <div class="field">
                                    <label>Libellé<span class="req">*</span></label>
                                    <input type="text" name="ff_label" required placeholder="ex: Nom, Date de début">
                                </div>
                                <div class="field">
                                    <label>Identifiant technique <span class="hint">(auto si vide)</span></label>
                                    <input type="text" name="ff_field_name" placeholder="Généré automatiquement depuis le libellé">
                                </div>
                                <div class="field">
                                    <label>Type de champ</label>
                                    <select name="ff_field_type">
                                        <?php foreach ($field_types as $val => $lbl): ?>
                                            <option value="<?= $val ?>"><?= $lbl ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Ordre</label>
                                    <input type="number" name="ff_ordre" min="0" value="<?= count($form_fields) + 1 ?>">
                                </div>
                                <div class="field">
                                    <label>Groupe (carte)</label>
                                    <?php if (!empty($existing_groups)): ?>
                                        <select name="ff_card_group">
                                            <?php foreach ($existing_groups as $g): ?>
                                                <option value="<?= h($g) ?>" <?= $g === 'Général' ? 'selected' : '' ?>><?= h($g) ?></option>
                                            <?php endforeach; ?>
                                            <option value="__new__">— Nouveau groupe —</option>
                                        </select>
                                    <?php else: ?>
                                        <input type="hidden" name="ff_card_group" value="">
                                    <?php endif; ?>
                                    <input type="text" name="ff_card_group_new" placeholder="Nom du nouveau groupe" style="margin-top:.3rem;" value="">
                                </div>
                                <div class="field full-width">
                                    <label>Options <span class="hint">(une par ligne, uniquement pour Sélecteur)</span></label>
                                    <textarea name="ff_options" rows="3" placeholder="Option A&#10;Option B&#10;Option C"></textarea>
                                </div>
                                <div class="field full-width">
                                    <label>Indication <span class="hint">(texte d'aide sous le champ)</span></label>
                                    <input type="text" name="ff_hint" placeholder="ex : en euros TTC">
                                </div>
                            </div>
                            <div class="field" style="margin-top:.25rem;">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="ff_required"> Champ obligatoire <span class="required-star">*</span>
                                </label>
                            </div>
                            <button type="submit" class="btn btn-primary" style="margin-top:.5rem;">Ajouter le champ</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($form): ?>
        <!-- Section Propriétaires du formulaire -->
        <div class="section-card" id="owners">
            <h2>👥 Propriétaires du formulaire</h2>
            <p class="hint" style="margin-bottom:1rem;">Les propriétaires peuvent accéder au tableau de suivi spécifique de ce formulaire via la page <a href="form_tracking.php?f=<?= h($form['id'] ?? '') ?>">Suivi propriétaire</a>.</p>

            <?php if (!empty($owners)): ?>
                <table class="data-table" style="margin-bottom:1rem;">
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>Ajouté le</th>
                            <th style="width:80px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($owners as $owner): ?>
                        <tr>
                            <td><?= h($owner['email']) ?></td>
                            <td><?= h($owner['added_at']) ?></td>
                            <td>
                                <a href="confirm_action.php?action=remove_owner&id=<?= $owner['id'] ?>&form_id=<?= $form_id ?>" class="btn btn-sm btn-danger">Retirer</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="color:#888;font-style:italic;margin-bottom:1rem;">Aucun propriétaire défini. Seuls les administrateurs peuvent voir le tableau de suivi.</p>
            <?php endif; ?>

            <form method="POST" action="admin_forms.php?form_id=<?= $form_id ?>#owners">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_owner">
                <input type="hidden" name="form_id" value="<?= $form_id ?>">
                <div style="display:flex;gap:.5rem;align-items:center;">
                    <input type="email" name="owner_email" placeholder="prenom.nom@dreets.gouv.fr" required style="flex:1;">
                    <button type="submit" class="btn btn-primary">Ajouter un propriétaire</button>
                </div>
            </form>

            <?php if (!empty($owners)): ?>
                <div style="margin-top:1rem;">
                    <a href="form_tracking.php?f=<?= h($form['id'] ?? '') ?>" class="btn btn-secondary">📊 Ouvrir le tableau de suivi</a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?= render_footer() ?>
</body>
</html>
