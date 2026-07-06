<?php
declare(strict_types=1);

/**
 * Token lifecycle management — regeneration, cancellation, reminders, delegation.
 *
 * @package lib
 */

// ── TOKEN REGENERATION ───────────────────────────────────────

/**
 * Régénère un token expiré pour un validateur (admin uniquement)
 * Invalide l'ancien token et crée un nouveau avec une nouvelle date d'expiration
 *
 * @param string $old_token_id ID de l'ancien token
 * @return array<string, mixed> ['success' => bool, 'message' => string]
 */
function regenerate_token(string $old_token_id): array {
    // Sécurité (S-05) : seul un admin peut régénérer un token
    if (!is_admin_user() && !is_super_admin()) {
        app_log('access_denied', 'token:' . $old_token_id, 'Tentative de régénération de token non autorisée');
        return ['success' => false, 'message' => 'Accès refusé. Seul un administrateur peut régénérer un token.'];
    }

    $pdo = get_pdo();

    // Récupérer l'ancien token
    $stmt = $pdo->prepare("
        SELECT t.*, s.status as sub_status
        FROM tokens t
        JOIN submissions s ON s.id = t.submission_id
        WHERE t.id = ?
    ");
    $stmt->execute([$old_token_id]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$old) {
        return ['success' => false, 'message' => 'Token introuvable.'];
    }
    if ($old['done_at']) {
        return ['success' => false, 'message' => 'Ce token a déjà été traité.'];
    }
    if ($old['sub_status'] !== 'en_cours') {
        return ['success' => false, 'message' => 'La soumission n\'est plus en cours.'];
    }

    // Marquer l'ancien token comme traité (invalidé)
    $pdo->prepare("UPDATE tokens SET done_at = ? WHERE id = ?")
        ->execute([gmdate('Y-m-d H:i:s'), $old_token_id]);

    // Créer un nouveau token
    $new_token = generate_token();
    $expire_days = (int)get_setting('token_expire_days', '30');
    $expires_at = gmdate('Y-m-d H:i:s', strtotime("+{$expire_days} days") ?: time());
    $now = gmdate('Y-m-d H:i:s');

    $new_token_row_id = generate_uuid();
    $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$new_token_row_id, $old['submission_id'], $old['step_id'], $old['email'], $new_token, $now, $expires_at]);

    // Envoyer le nouveau lien par email
    // A-08 : utiliser la fonction centralisée get_submission_with_form_label()
    $submission = get_submission_with_form_label($old['submission_id']);

    $step_stmt = $pdo->prepare("SELECT label FROM steps WHERE id = ?");
    $step_stmt->execute([$old['step_id']]);
    $step = $step_stmt->fetch(PDO::FETCH_ASSOC);

    if ($submission && $step) {
        $subject = '[Renvoi] ' . ($submission['form_label'] ?? '') . ' — ' . ($step['label'] ?? '');
        $mail_sent = send_mail($old['email'], $subject, build_mail_html($submission, $step['label'], $new_token));
    }

    app_log('token_regenerate', 'token:' . $old_token_id, 'Token régénéré pour ' . $old['email'] . ', nouveau token créé');

    return [
        'success' => true,
        'message' => 'Nouveau lien de validation envoyé à ' . $old['email'],
    ];
}

// ── SUBMISSION CANCEL ────────────────────────────────────────

/**
 * Annule une soumission en cours
 *
 * @param string $submission_id ID de la soumission
 * @param string $cancelled_by Email de l'utilisateur qui annule
 * @return array<string, mixed> ['success' => bool, 'message' => string]
 */
function cancel_submission(string $submission_id, string $cancelled_by = ''): array {
    // Sécurité (S-05) : vérifier l'autorisation avant d'annuler
    $caller = $cancelled_by ?: get_auth_user();
    $caller_is_admin = is_admin_user() || is_super_admin();

    $pdo = get_pdo();

    // A-08 : utiliser la fonction centralisée get_submission_with_form_label()
    $submission = get_submission_with_form_label($submission_id);

    if (!$submission) {
        return ['success' => false, 'message' => 'Soumission introuvable.'];
    }
    if ($submission['status'] !== 'en_cours') {
        return ['success' => false, 'message' => 'Seules les soumissions en cours peuvent être annulées.'];
    }

    // Sécurité (S-05) : seul le propriétaire ou un admin peut annuler
    if (!$caller_is_admin && strtolower($submission['submitted_by']) !== strtolower($caller)) {
        app_log('access_denied', 'submission:' . $submission_id, 'Tentative d\'annulation non autorisée par ' . $caller);
        return ['success' => false, 'message' => 'Vous n\'êtes pas autorisé à annuler cette soumission.'];
    }

    // v10.1.12 — Fermer la soumission avec le statut 'annule' (pas 'refuse').
    // Une annulation n'est pas un refus : l'agent retire sa demande,
    // le validateur n'a pas refusé. Statut distinct pour filtrage correct.
    $now = gmdate('Y-m-d H:i:s');
    $pdo->prepare("UPDATE submissions SET closed_at = ?, status = 'annule' WHERE id = ?")
        ->execute([$now, $submission_id]);

    // Marquer tous les tokens non traités comme done (annulés)
    $pdo->prepare("UPDATE tokens SET done_at = ? WHERE submission_id = ? AND done_at IS NULL")
        ->execute([$now, $submission_id]);

    // Ajouter l'annulation dans les validations
    $data = json_decode($submission['data'], true) ?: [];
    if (!isset($data['validations'])) $data['validations'] = [];
    $data['validations'][] = [
        'step_label' => 'Annulation',
        'email' => $cancelled_by ?: 'system',
        'action' => 'refuser',
        'commentaire' => 'Soumission annulée',
        'date' => $now,
    ];
    $pdo->prepare("UPDATE submissions SET data = ? WHERE id = ?")
        ->execute([json_encode($data, JSON_UNESCAPED_UNICODE), $submission_id]);

    // Notifier l'agent
    $agent_email = $submission['submitted_by'] ?? '';
    if (!empty($agent_email) && filter_var($agent_email, FILTER_VALIDATE_EMAIL)) {
        $subject = 'Demande annulée — ' . ($submission['form_label'] ?? get_app_name());
        $body_html = '<h2 style="color:#b45309;">Demande annulée</h2>'
            . '<p>Votre demande <strong>' . h($submission['form_label'] ?? '') . '</strong> a été annulée.</p>';
        send_mail($agent_email, $subject, render_email_template('Demande annulée', $body_html));
    }

    app_log('submission_cancel', 'submission:' . $submission_id, 'Soumission annulée', $cancelled_by);

    // Webhook notification
    send_webhook('submission_cancelled', ['submission_id' => $submission_id, 'form_label' => $submission['form_label'] ?? '', 'cancelled_by' => $cancelled_by]);

    return ['success' => true, 'message' => 'Soumission annulée avec succès.'];
}

// ── MANUAL REMINDER ──────────────────────────────────────────

/**
 * Envoie un rappel manuel pour un token en attente
 * Contrairement a regenerate_token, celui-ci ne modifie pas le token existant
 * Il envoie simplement un email de rappel au validateur
 *
 * @param string $token_id ID du token
 * @return array<string, mixed> ['success' => bool, 'message' => string]
 */
function remind_one(string $token_id): array {
    // A-18 : utiliser la fonction centralisée au lieu de dupliquer la jointure
    $tok = get_token_by_id_with_context($token_id);

    if (!$tok) {
        return ['success' => false, 'message' => 'Token introuvable.'];
    }
    if ($tok['done_at']) {
        return ['success' => false, 'message' => 'Ce token a déjà été traité.'];
    }
    if ($tok['status'] !== 'en_cours') {
        return ['success' => false, 'message' => 'La soumission n\'est plus en cours.'];
    }

    // Récupérer le label de l'étape (déjà disponible dans step_label)
    $step_label = $tok['step_label'] ?? 'Validation requise';

    // Incrémenter le compteur de relances
    $new_count = (int)$tok['relance_count'] + 1;
    $relance_max = (int)get_setting('relance_max', '3');

    get_pdo()->prepare("UPDATE tokens SET relance_count = ?, relance_at = datetime('now') WHERE id = ?")
        ->execute([$new_count, $token_id]);

    // Construire l'email de rappel
    $submission = [
        'data' => $tok['data'],
        'form_label' => $tok['form_label'],
    ];
    $subject = '[Rappel] ' . $tok['form_label'] . ' — ' . $step_label;
    if ($new_count > 1) {
        $subject = '[Rappel ' . $new_count . '/' . $relance_max . '] ' . $tok['form_label'] . ' — ' . $step_label;
    }

    $mail_body = build_mail_html($submission, $step_label, $tok['token']);
    // Ajouter un message de rappel en haut du corps
    $rappel_notice = '<div style="background:#fff3e0;border:1px solid #b45309;border-radius:4px;padding:12px;margin-bottom:16px;">
        <strong>⏰ Rappel :</strong> Cette demande est toujours en attente de votre validation.
        <br>Ceci est le rappel n°' . $new_count . ' sur un maximum de ' . $relance_max . '.
    </div>';
    $mail_body = str_replace('<h2 style="color:#003189;">', $rappel_notice . '<h2 style="color:#003189;">', $mail_body);

    $mail_sent = send_mail($tok['email'], $subject, $mail_body);

    app_log('manual_remind', 'token:' . $token_id, 'Rappel manuel envoyé à ' . $tok['email'] . ' (relance ' . $new_count . '/' . $relance_max . ')');

    if ($mail_sent) {
        return ['success' => true, 'message' => 'Rappel envoyé à ' . $tok['email'] . ' (relance ' . $new_count . '/' . $relance_max . ')'];
    } else {
        return ['success' => false, 'message' => 'Erreur lors de l\'envoi de l\'email à ' . $tok['email'] . '. Vérifiez la configuration SMTP.'];
    }
}

/**
 * Récupère les tokens d'une soumission avec les infos de l'étape associée.
 * Centralise la requête qui était dupliquée dans dashboard, my_submissions et submission_view.
 *
 * @param string $submission_id ID de la soumission
 * @param list<string>  $extra_fields  Champs supplémentaires à sélectionner (ex: ['t.id', 't.token', 't.relance_count', 't.expires_at'])
 * @return array<string, mixed> Tokens avec step_label, ordre, etc.
 */
function get_tokens_for_submission(string $submission_id, array $extra_fields = []): array {
    // Sécurité : liste blanche des champs supplémentaires autorisés
    $allowed_fields = ['t.id', 't.token', 't.relance_count', 't.relance_at', 't.expires_at', 't.delegated_at', 't.sent_at'];
    if (!empty($extra_fields)) {
        $extra_fields = array_intersect($extra_fields, $allowed_fields);
    }
    $base = "t.email, t.done_at, t.sent_at, t.step_id, st.label, st.label as step_label, st.ordre";
    if (!empty($extra_fields)) {
        $base = implode(', ', $extra_fields) . ', ' . $base;
    }
    $stmt = get_pdo()->prepare("
        SELECT {$base}
        FROM tokens t
        JOIN steps st ON st.id = t.step_id
        WHERE t.submission_id = ?
        ORDER BY st.ordre ASC, st.label ASC
    ");
    $stmt->execute([$submission_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── DELEGATION ───────────────────────────────────────────────

/**
 * Délègue un token de validation à un autre validateur
 * L'ancien token est marqué comme traité (délégué) et un nouveau token est créé
 *
 * @param string $token_id ID du token à déléguer
 * @param string $to_email Email du délégataire
 * @param string $reason Motif de la délégation
 * @return array<string, mixed> ['success' => bool, 'message' => string]
 */
function delegate_token(string $token_id, string $to_email, string $reason = ''): array {
    // A-18 : utiliser la fonction centralisée au lieu de dupliquer la jointure
    $tok = get_token_by_id_with_context($token_id);

    if (!$tok) {
        return ['success' => false, 'message' => 'Token introuvable.'];
    }
    if ($tok['done_at']) {
        return ['success' => false, 'message' => 'Ce token a déjà été traité.'];
    }
    if ($tok['status'] !== 'en_cours') {
        return ['success' => false, 'message' => 'La soumission n\'est plus en cours.'];
    }

    // Sécurité : validation du format email du destinataire
    $to_email = strtolower(trim($to_email));
    if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Adresse email invalide.'];
    }
    if ($to_email === $tok['email']) {
        return ['success' => false, 'message' => 'Vous ne pouvez pas déléguer à vous-même.'];
    }

    $pdo = get_pdo();

    // Vérifier qu'un token n'existe pas déjà pour cet email sur cette étape
    $dup_check = $pdo->prepare("SELECT 1 FROM tokens WHERE submission_id = ? AND step_id = ? AND email = ? AND done_at IS NULL");
    $dup_check->execute([$tok['submission_id'], $tok['step_id'], $to_email]);
    if ($dup_check->fetch()) {
        return ['success' => false, 'message' => 'Un token de validation est déjà actif pour ' . $to_email . ' sur cette étape.'];
    }

    // Marquer l'ancien token comme traité (délégué)
    $pdo->prepare("UPDATE tokens SET done_at = datetime('now') WHERE id = ?")
        ->execute([$token_id]);

    // Créer le nouveau token pour le délégataire
    $new_token = generate_token();
    $expire_days = (int)get_setting('token_expire_days', '30');
    $expires_at = gmdate('Y-m-d H:i:s', strtotime("+{$expire_days} days") ?: time());
    $now = gmdate('Y-m-d H:i:s');

    $new_token_row_id = generate_uuid();
    $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$new_token_row_id, $tok['submission_id'], $tok['step_id'], $to_email, $new_token, $now, $expires_at]);

    $new_token_id = $new_token_row_id;

    // Enregistrer la délégation
    $delegation_id = generate_uuid();
    $pdo->prepare("INSERT INTO delegations (id, token_id, from_email, to_email, reason, delegated_at, new_token_id) VALUES (?, ?, ?, ?, ?, datetime('now'), ?)")
        ->execute([$delegation_id, $token_id, $tok['email'], $to_email, $reason, $new_token_id]);

    // Envoyer l'email au délégataire
    $step_label = $tok['step_label'] ?? 'Validation requise';

    $submission = [
        'data' => $tok['data'],
        'form_label' => $tok['form_label'],
    ];

    $subject = '[Délégation] ' . $tok['form_label'] . ' — ' . $step_label;
    $mail_body = build_mail_html($submission, $step_label, $new_token);

    // Ajouter un bloc de délégation en haut de l'email
    $delegation_notice = '<div style="background:#e8eaf6;border:1px solid #003189;border-radius:4px;padding:12px;margin-bottom:16px;">
        <strong>🔄 Délégation :</strong> Cette validation vous a été déléguée par <strong>' . display_user($tok['email']) . '</strong>.
        ' . (!empty($reason) ? '<br><em>Motif : ' . h($reason) . '</em>' : '') . '
    </div>';
    $mail_body = str_replace('<h2 style="color:#003189;">', $delegation_notice . '<h2 style="color:#003189;">', $mail_body);

    send_mail($to_email, $subject, $mail_body);

    // Notifier le délégateur que sa délégation a été prise en compte
    $confirm_subject = 'Délégation confirmée — ' . $tok['form_label'];
    $confirm_body_html = '<h2 style="color:#003189;">🔄 Délégation confirmée</h2>'
        . '<p>Votre validation pour <strong>' . h($tok['form_label']) . '</strong> (étape ' . h($step_label) . ') a été déléguée à <strong>' . display_user($to_email) . '</strong>.</p>'
        . '<p>Vous n\'avez plus besoin d\'effectuer cette validation.</p>';
    send_mail($tok['email'], $confirm_subject, render_email_template('Délégation confirmée', $confirm_body_html));

    app_log('token_delegate', 'token:' . $token_id, 'Token délégué de ' . $tok['email'] . ' à ' . $to_email . ($reason ? ' — Motif : ' . $reason : ''));

    return ['success' => true, 'message' => 'Validation déléguée à ' . $to_email . '. Un email lui a été envoyé.'];
}

/**
 * Récupère l'historique des délégations pour une soumission
 * @return array<string, mixed>
 */
function get_delegations(string $submission_id): array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("
        SELECT d.*, t.step_id, st.label as step_label
        FROM delegations d
        JOIN tokens t ON t.id = d.token_id
        JOIN steps st ON st.id = t.step_id
        WHERE t.submission_id = ?
        ORDER BY d.delegated_at DESC
    ");
    $stmt->execute([$submission_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
