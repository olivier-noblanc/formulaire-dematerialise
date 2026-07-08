<?php
declare(strict_types=1);
use App\Core\App;

/**
 * POST handlers admin_forms.php — Étapes de validation et destinataires.
 *
 * Contient les handlers pour les actions POST liées aux étapes et aux
 * destinataires d'étape :
 *  - add_step, update_step, delete_step
 *  - add_recipient, delete_recipient
 *
 * Tous les handlers retournent un tableau de résultats interprété par
 * admin_forms.php (voir docblock de {@see handle_admin_action()} dans
 * admin_forms_handlers.php pour la liste des clés).
 *
 * @package lib
 */

// ── Handlers — Étapes de validation ────────────────────────────

/** Handler : add_step — ajouter une étape de validation */
function handle_admin_action_add_step(PDO $pdo): array {
    [$form_id, $err] = _post_form_id();
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
        $new_step_id = generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)")
            ->execute([$new_step_id, $form_id, $label, $ordre]);
        App::audit()->log('step_add', 'form:' . $form_id, "Étape '$label' ajoutée");
        return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode((string)$form_id) . '#step-' . urlencode($new_step_id)];
    } catch (PDOException $e) {
        $result['error'] = 'Erreur lors de l\'ajout de l\'étape : ' . $e->getMessage();
        return $result;
    }
}

/** Handler : update_step — mettre à jour une étape */
function handle_admin_action_update_step(PDO $pdo, string $get_form_id): array {
    [$step_id, $err] = _post_step_id();
    if ($err !== null) {
        return ['error' => $err];
    }
    $label = trim($_POST['label'] ?? '');
    $ordre = (int)($_POST['ordre'] ?? 0);
    $actif = isset($_POST['actif']) ? 1 : 0;
    if (empty($step_id) || empty($label) || $ordre <= 0) {
        return ['error' => 'Les champs obligatoires ne sont pas remplis.'];
    }

    // v19 — Construction de la condition d'exécution (JSON) :
    //   - Si `condition_field` est vide → condition vide (exécuter toujours).
    //   - Sinon → on encode {field, op, value} en JSON.
    //   - On valide l'opérateur contre une liste fixe (sécurité).
    //   - On nettoie la valeur (trim, longueur max 1000).
    // Note : la condition n'est significative que pour les étapes d'ordre > 1,
    // mais on la persiste telle quelle si elle est fournie — le moteur
    // advance_workflow() l'évaluera quel que soit l'ordre (l'UI masque le
    // champ pour ordre=1, mais le handler reste permissif).
    $condition_field = trim($_POST['condition_field'] ?? '');
    $condition_op    = trim($_POST['condition_op'] ?? '');
    $condition_value = trim($_POST['condition_value'] ?? '');
    $valid_ops = ['equals', 'not_equals', 'contains', 'not_empty', 'empty'];
    if ($condition_op !== '' && !in_array($condition_op, $valid_ops, true)) {
        $condition_op = ''; // Opérateur invalide → on ignore la condition.
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
            $condition_json = ''; // Sécurité fallback.
        }
    }

    try {
        $pdo->prepare("UPDATE steps SET label = ?, ordre = ?, actif = ?, `condition` = ? WHERE id = ?")
            ->execute([$label, $ordre, $actif, $condition_json, $step_id]);
        App::audit()->log('step_update', 'step:' . $step_id, "Étape '$label' mise à jour");
        return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($get_form_id) . '#step-' . urlencode($step_id)];
    } catch (PDOException $e) {
        return ['error' => 'Erreur lors de la mise à jour de l\'étape : ' . $e->getMessage()];
    }
}

/** Handler : delete_step — supprimer une étape (et ses destinataires) */
function handle_admin_action_delete_step(PDO $pdo, string $get_form_id): array {
    [$step_id, $err] = _post_step_id();
    if ($err !== null) {
        return ['error' => $err];
    }
    if (empty($step_id)) {
        return [];
    }
    $active_count = has_active_step_submissions((string)$step_id);
    if ($active_count > 0) {
        return ['error' => 'Impossible de supprimer cette étape : ' . $active_count . ' soumission(s) en cours y sont rattachée(s). Veuillez attendre que ces demandes soient clôturées ou les annuler avant de supprimer l\'étape.'];
    }
    try {
        $pdo->prepare("DELETE FROM step_recipients WHERE step_id = ?")->execute([$step_id]);
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$step_id]);
        return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($get_form_id) . '#workflow'];
    } catch (PDOException $e) {
        return ['error' => 'Erreur lors de la suppression de l\'étape : ' . $e->getMessage()];
    }
}

// ── Handlers — Destinataires d'étape ───────────────────────────

/** Handler : add_recipient — ajouter un destinataire à une étape */
function handle_admin_action_add_recipient(PDO $pdo, string $get_form_id): array {
    [$step_id, $err] = _post_step_id();
    if ($err !== null) {
        return ['error' => $err];
    }
    $email = trim($_POST['email'] ?? '');
    if (empty($step_id) || empty($email)) {
        return ['error' => 'L\'étape et le courriel sont requis.'];
    }
    // Accepter soit une adresse email valide, soit une référence dynamique {{field_name}}
    $is_dynamic = preg_match('/^\{\{[a-z][a-z0-9_]*\}\}$/', $email);
    if (!$is_dynamic && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['error' => 'Le destinataire "' . h($email) . '" n\'est ni une adresse email valide ni une référence dynamique {{field_name}}. Format attendu : prenom.nom@' . \App\Core\App::settings()->get('email_domain', 'exemple.invalid') . ' ou {{nom_du_champ}}'];
    }
    try {
        $new_rcpt_id = generate_uuid();
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)")
            ->execute([$new_rcpt_id, $step_id, $email]);
        $label = $is_dynamic ? "Destinataire dynamique $email ajouté" : "Destinataire $email ajouté";
        App::audit()->log('recipient_add', 'step:' . $step_id, $label);
        return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($get_form_id) . '#step-' . urlencode($step_id)];
    } catch (PDOException $e) {
        return ['error' => 'Erreur lors de l\'ajout du destinataire : ' . $e->getMessage()];
    }
}

/** Handler : delete_recipient — supprimer un destinataire */
function handle_admin_action_delete_recipient(PDO $pdo, string $get_form_id): array {
    $recipient_id = trim($_POST['recipient_id'] ?? '');
    if (empty($recipient_id)) {
        return [];
    }
    try {
        // Récupérer le step_id AVANT suppression pour pouvoir rediriger vers la bonne ancre
        $stmt = $pdo->prepare("SELECT step_id FROM step_recipients WHERE id = ?");
        $stmt->execute([$recipient_id]);
        $step_id_for_anchor = (string)$stmt->fetchColumn();
        $pdo->prepare("DELETE FROM step_recipients WHERE id = ?")->execute([$recipient_id]);
        $anchor = $step_id_for_anchor !== '' ? '#step-' . urlencode($step_id_for_anchor) : '#workflow';
        return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($get_form_id) . $anchor];
    } catch (PDOException $e) {
        return ['error' => 'Erreur lors de la suppression du destinataire : ' . $e->getMessage()];
    }
}
