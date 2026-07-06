<?php
declare(strict_types=1);

/**
 * Workflow engine — tokens, steps, validation.
 *
 * Moteur de circuit de validation :
 *   - Tokens de validation (génération, lookup, validation/refus)
 *   - Étapes (steps) et leurs destinataires
 *   - advance_workflow() — génère les tokens de l'étape suivante
 *   - validate_token() — valide ou refuse un token (entrée utilisateur)
 *
 * @package lib
 */

// ── MOTEUR WORKFLOW ───────────────────────────────────────────

/**
 * Récupère un token avec tout le contexte métier associé (A-18).
 * Centralise la jointure tokens/steps/submissions/forms qui était dupliquée
 * dans validate_token(), remind_one(), delegate_token() et validate.php.
 *
 * @param string $token_value La valeur du token (64 hex chars)
 * @return array<string, mixed>|null Données du token avec contexte, ou null si introuvable
 */
function get_token_with_context(string $token_value): ?array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("
        SELECT t.*, st.label as step_label, s.form_id,
               f.label as form_label, s.data, s.closed_at, s.status,
               s.submitted_by
        FROM tokens t
        JOIN steps st ON st.id = t.step_id
        JOIN submissions s ON s.id = t.submission_id
        JOIN forms f ON f.id = s.form_id
        WHERE t.token = ?
    ");
    $stmt->execute([$token_value]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

/**
 * Récupère un token par son ID avec contexte métier (A-18).
 * Variante de get_token_with_context() utilisant l'ID du token au lieu de sa valeur.
 *
 * @param string $token_id ID de la ligne token
 * @return array<string, mixed>|null Données du token avec contexte, ou null si introuvable
 */
function get_token_by_id_with_context(string $token_id): ?array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("
        SELECT t.*, st.label as step_label, s.form_id,
               f.label as form_label, s.data, s.closed_at, s.status,
               s.submitted_by
        FROM tokens t
        JOIN steps st ON st.id = t.step_id
        JOIN submissions s ON s.id = t.submission_id
        JOIN forms f ON f.id = s.form_id
        WHERE t.id = ?
    ");
    $stmt->execute([$token_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

/**
 * Récupère les étapes actives du workflow d'un formulaire avec les destinataires.
 * @param string $form_id ID du formulaire
 * @return array<string, mixed> Tableau des étapes avec recipient_emails
 */
function get_workflow_steps(string $form_id): array {
    // A-11 : cache par requête pour éviter les requêtes SQL répétées
    static $cache = [];
    if (isset($cache[$form_id])) {
        return $cache[$form_id];
    }
    $pdo = get_pdo();
    $stmt = $pdo->prepare("
        SELECT st.id as step_id, st.label as step_label, st.ordre, st.actif,
               GROUP_CONCAT(sr.email, '|') as recipient_emails
        FROM steps st
        LEFT JOIN step_recipients sr ON sr.step_id = st.id
        WHERE st.form_id = ? AND st.actif = 1
        GROUP BY st.id
        ORDER BY st.ordre ASC, st.id ASC
    ");
    $stmt->execute([$form_id]);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $cache[$form_id] = $result;
    return $result;
}

/**
 * Récupère une soumission avec le label du formulaire associé (A-08).
 * Centralise la requête `SELECT s.*, f.label as form_label FROM submissions s
 * JOIN forms f ON f.id = s.form_id WHERE s.id = ?` qui était dupliquée
 * dans advance_workflow(), regenerate_token() et cancel_submission().
 *
 * @param string $submission_id ID de la soumission
 * @return array<string, mixed>|null Données de la soumission avec form_label, ou null si introuvable
 */
function get_submission_with_form_label(string $submission_id): ?array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("
        SELECT s.*, f.label as form_label
        FROM submissions s
        JOIN forms f ON f.id = s.form_id
        WHERE s.id = ?
    ");
    $stmt->execute([$submission_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

/**
 * Résout les références dynamiques {{field_name}} dans une adresse email de destinataire.
 * Si le destinataire est de la forme "{{field_name}}", la fonction cherche la valeur
 * correspondante dans les données du formulaire soumises.
 * Si la référence ne peut pas être résolue, retourne la chaîne inchangée.
 *
 * @param string $recipient  Adresse email ou référence {{field_name}}
 * @param array<string, mixed>  $form_data  Données du formulaire soumises (clé => valeur)
 * @return string Adresse email résolue
 */
function resolve_dynamic_recipient(string $recipient, array $form_data, ?string $submission_id = null): string {
    // Cas spécial : {{owner}} = le propriétaire du formulaire (admin qui a créé le formulaire)
    if ($recipient === '{{owner}}') {
        if ($submission_id !== null) {
            $pdo = get_pdo();
            // Récupérer le form_id depuis la soumission, puis l'owner
            $form_id = $pdo->prepare("SELECT form_id FROM submissions WHERE id = ?");
            $form_id->execute([$submission_id]);
            $fid = (string)$form_id->fetchColumn();
            if ($fid !== '') {
                $owners = get_form_owners($fid);
                $first_owner_email = $owners[0]['email'] ?? '';
                if (!empty($owners) && filter_var($first_owner_email, FILTER_VALIDATE_EMAIL)) {
                    return $first_owner_email;
                }
                // Fallback : admin_email (super admin)
                $admin_email = get_admin_email();
                if (filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
                    return $admin_email;
                }
            }
        }
        return $recipient; // Non résolu — sera filtré plus tard
    }

    if (preg_match('/^\{\{([a-z][a-z0-9_]*)\}\}$/', $recipient, $m)) {
        $field_name = $m[1];
        // Chercher dans les données du formulaire
        if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
            $resolved = trim($form_data[$field_name]);
            if (filter_var($resolved, FILTER_VALIDATE_EMAIL)) {
                return $resolved;
            }
        }
        // Essayer aussi avec le nom du champ en majuscules ou variations
        foreach ($form_data as $key => $val) {
            if (strtolower($key) === $field_name && !empty($val)) {
                $resolved = trim($val);
                if (filter_var($resolved, FILTER_VALIDATE_EMAIL)) {
                    return $resolved;
                }
            }
        }
        // Référence non résolue — retourner la chaîne brute (sera filtrée plus tard)
        return $recipient;
    }
    return $recipient;
}

/**
 * Déclenche la prochaine étape d'une soumission.
 * Appelé à la création ET après chaque validation.
 *
 * Logique :
 *  - Récupère toutes les étapes du formulaire triées par ordre
 *  - Trouve le plus petit ordre sans tokens générés = prochaine étape
 *  - Si ordre précédent non terminé (séquentiel) → on attend
 *  - Génère les tokens pour tous les destinataires de l'étape courante
 *  - Si plus aucune étape → soumission close
 *
 * v19 — Branches conditionnelles :
 *  - Pour chaque groupe d'étapes à un ordre donné, on filtre les étapes
 *    dont la `condition` n'est pas satisfaite (cf. evaluate_step_condition()).
 *  - Si toutes les étapes d'un ordre sont skippées → on avance
 *    automatiquement à l'ordre suivant (boucle).
 *  - Si aucun ordre suivant n'a d'étape à exécuter → clôture de la
 *    soumission (status = 'valide'), comme à la fin normale du workflow.
 *  - Une étape sans `condition` (vide / null) s'exécute toujours
 *    (rétrocompatibilité).
 */
function advance_workflow(string $submission_id): void {
    $pdo = get_pdo();

    // A-08 : utiliser la fonction centralisée get_submission_with_form_label()
    $submission = get_submission_with_form_label($submission_id);
    if (!$submission || $submission['closed_at']) return;

    // Toutes les étapes actives du formulaire
    $steps = $pdo->prepare("
        SELECT st.*, GROUP_CONCAT(sr.email, '|') as emails
        FROM steps st
        JOIN step_recipients sr ON sr.step_id = st.id
        WHERE st.form_id = ? AND st.actif = 1
        GROUP BY st.id
        ORDER BY st.ordre ASC, st.id ASC
    ");
    $steps->execute([$submission['form_id']]);
    $all_steps = $steps->fetchAll(PDO::FETCH_ASSOC);

    if (empty($all_steps)) return;

    // Tokens déjà générés pour cette soumission
    $existing = $pdo->prepare("SELECT step_id, done_at FROM tokens WHERE submission_id = ?");
    $existing->execute([$submission_id]);
    $tokens_by_step = [];
    foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $tokens_by_step[$t['step_id']][] = $t['done_at'];
    }

    // Groupe les étapes par ordre
    $by_ordre = [];
    foreach ($all_steps as $step) {
        $by_ordre[$step['ordre']][] = $step;
    }
    ksort($by_ordre);

    foreach ($by_ordre as $ordre => $groupe) {
        // v19 — Branches conditionnelles : filtrer les étapes dont la
        // `condition` n'est pas satisfaite. Une étape skippée ne génère pas
        // de token et ne bloque pas le reste du workflow. Si toutes les
        // étapes de l'ordre sont skippées → on avance automatiquement à
        // l'ordre suivant (continue). Le test `$all_started / $all_done`
        // se fait sur le sous-groupe filtré.
        $groupe_executable = [];
        foreach ($groupe as $step) {
            if (evaluate_step_condition($step, $submission_id)) {
                $groupe_executable[] = $step;
            }
        }

        // Ordre entièrement skippé : on passe au suivant sans rien faire.
        if (empty($groupe_executable)) {
            continue;
        }

        // On ne travaille que sur le sous-groupe exécutable pour les tokens.
        $groupe = $groupe_executable;

        $step_ids    = array_column($groupe, 'id');
        $all_started = count(array_intersect($step_ids, array_keys($tokens_by_step))) === count($step_ids);
        $all_done    = $all_started && array_reduce($step_ids, function($carry, $sid) use ($tokens_by_step) {
            if (!$carry) return false;
            if (!isset($tokens_by_step[$sid])) return false;
            foreach ($tokens_by_step[$sid] as $done) {
                if (empty($done)) return false;
            }
            return true;
        }, true);

        if (!$all_started) {
            // Cette étape n'a pas encore de tokens → on la démarre (parallèle dans le groupe)
            $now = gmdate('Y-m-d H:i:s');
            $expire_days = (int)get_setting('token_expire_days', '30');
            $expires_at = gmdate('Y-m-d H:i:s', strtotime("+{$expire_days} days") ?: time());
            // Décoder les données du formulaire pour résoudre les références {{field_name}}
            $form_data = json_decode($submission['data'] ?? '{}', true) ?: [];
            foreach ($groupe as $step) {
                $raw_emails = explode('|', $step['emails']);
                foreach ($raw_emails as $email) {
                    // Résoudre les références dynamiques {{field_name}} et {{owner}}
                    $email = resolve_dynamic_recipient($email, $form_data, $submission_id);
                    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        error_log("Workflow: skipping invalid recipient '$email' for step {$step['id']}");
                        continue;
                    }
                    $token = generate_token();
                    $token_row_id = generate_uuid();
                    $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$token_row_id, $submission_id, $step['id'], $email, $token, $now, $expires_at]);
                    $subject = '[Action requise] ' . ($submission['form_label'] ?? '') . ' — ' . $step['label'];
                    $mail_sent = send_mail($email, $subject, build_mail_html($submission, $step['label'], $token));
                    if (!$mail_sent) {
                        error_log("Workflow: mail failed for token $token to $email");
                    }
                }
            }
            return; // On attend que cette étape soit terminée avant de passer à la suivante
        }

        if (!$all_done) {
            return; // Étape en cours, on attend
        }

        // Étape terminée → on continue la boucle vers l'ordre suivant
    }

    // Toutes les étapes sont terminées → on close et on notifie l'agent
    $now = gmdate('Y-m-d H:i:s');
    $pdo->prepare("UPDATE submissions SET closed_at = ?, status = 'valide' WHERE id = ?")
        ->execute([$now, $submission_id]);

    // Notification de validation finale a l'agent
    $agent_email = $submission['submitted_by'] ?? '';
    if (!empty($agent_email) && filter_var($agent_email, FILTER_VALIDATE_EMAIL)) {
        $subject = 'Demande validée — ' . ($submission['form_label'] ?? get_app_name());
        $body_html = '<h2 style="color:#1a6b3c;">✓ Demande validée</h2>'
            . '<p>Votre demande <strong>' . h($submission['form_label'] ?? '') . '</strong> a été <strong>validée</strong> par l\'ensemble des validateurs.</p>'
            . '<p>Le processus de workflow est désormais terminé.</p>';
        send_mail($agent_email, $subject, render_email_template('Demande validée', $body_html));
    }

    app_log('workflow_complete', 'submission:' . $submission_id, 'Formulaire ' . ($submission['form_label'] ?? '') . ' validé', $agent_email);

    // Webhook notification
    send_webhook('workflow_complete', ['submission_id' => $submission_id, 'form_label' => $submission['form_label'] ?? '', 'submitted_by' => $submission['submitted_by'] ?? '']);
}

/**
 * Valide ou refuse un token.
 *
 * Appelé par validate.php quand un token est validé.
 * Met à jour done_at puis avance le workflow.
 *
 * v10.0.2 — Ajout paramètre $done_by : email du user logged-on qui a cliqué
 * sur le bouton Valider/Refuser. Différent de l'email du token (qui peut être
 * une shared mailbox). Les 2 infos sont stockées séparément dans
 * $data['validations'][] :
 *   - 'email'    : email du token (destinataire de la notif, shared mailbox possible)
 *   - 'done_by'  : user logged-on qui a réellement cliqué (personne physique)
 *
 * @param string $token   Le token à valider
 * @param string $action  'valider' ou 'refuser'
 * @param string $comment Commentaire optionnel (motif de refus, etc.)
 * @param string $done_by Email du user logged-on qui a cliqué (v10.0.2)
 * @return array<string, mixed> Résultat ['status' => 'ok'|'invalid'|'expired', ...]
 */
function validate_token(string $token, string $action = 'valider', string $comment = '', string $done_by = ''): array {
    // Sécurité : limiter les tentatives de validation de token (prévention brute-force)
    if (!rate_limit_check('validate_token', 20, 60)) {
        return ['status' => 'rate_limited', 'message' => 'Trop de tentatives. Veuillez patienter.'];
    }
    // Sécurité : vérifier le format du token (64 hex chars)
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return ['status' => 'invalid'];
    }
    // Sécurité : valider l'action
    if (!in_array($action, ['valider', 'refuser'], true)) {
        return ['status' => 'invalid', 'message' => 'Action non autorisée.'];
    }
    $pdo = get_pdo();
    $pdo->beginTransaction();

    // A-18 : utiliser la fonction centralisée au lieu de dupliquer la jointure
    $t = get_token_with_context($token);

    if (!$t)             { $pdo->rollBack(); return ['status' => 'invalid']; }
    if ($t['done_at'])   { $pdo->rollBack(); return ['status' => 'already_done', 'data' => $t]; }
    if ($t['closed_at']) { $pdo->rollBack(); return ['status' => 'closed',       'data' => $t]; }

    // Vérifier si le token a expiré
    if (!empty($t['expires_at'])) {
        $exp_ts = strtotime($t['expires_at']);
        if ($exp_ts !== false && $exp_ts < time()) {
            $pdo->rollBack();
            return ['status' => 'expired', 'data' => $t];
        }
    }

    // Récupérer les données actuelles
    $data = json_decode($t['data'], true);

    // Ajouter la validation au tableau des validations
    // Sécurité : limiter la longueur du commentaire pour éviter les abus
    $comment = mb_substr($comment, 0, 1000);
    $validation = [
        'step_label' => $t['step_label'],
        'email'      => $t['email'],         // destinataire de la notif (shared mailbox possible)
        'done_by'    => $done_by,            // v10.0.2 — user logged-on qui a cliqué
        'action'     => $action,
        'commentaire'=> $comment,
        'date'       => gmdate('Y-m-d H:i:s')
    ];

    // Initialiser le tableau des validations s'il n'existe pas
    if (!isset($data['validations'])) {
        $data['validations'] = [];
    }

    // Ajouter la nouvelle validation
    $data['validations'][] = $validation;

    // Mettre à jour les données avec les validations
    $updated_data = json_encode($data);

    if ($action === 'refuser') {
        // Pour le refus : mettre à jour done_at et fermer la soumission avec status refuse
        $stmt = $pdo->prepare("UPDATE tokens SET done_at = ? WHERE token = ? AND done_at IS NULL");
        $stmt->execute([gmdate('Y-m-d H:i:s'), $token]);
        if ($stmt->rowCount() === 0) {
            // Token déjà validé ou inexistant — abandon silencieux
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Ce jeton a déjà été utilisé ou n\'existe pas.'];
        }

        // Fermer la soumission avec le statut refuse
        $pdo->prepare("UPDATE submissions SET closed_at = ?, status = 'refuse' WHERE id = ?")
            ->execute([gmdate('Y-m-d H:i:s'), $t['submission_id']]);

        // Notifier l'agent du refus
        $agent_email = $t['submitted_by'] ?? '';
        if (!empty($agent_email) && filter_var($agent_email, FILTER_VALIDATE_EMAIL)) {
            $refuse_subject = 'Demande refusée — ' . ($t['form_label'] ?? get_app_name());
            $body_html = '<h2 style="color:#c0392b;">Demande refusée</h2>'
                . '<p>Votre demande <strong>' . h($t['form_label'] ?? '') . '</strong> a été refusée à l\'étape <strong>' . h($t['step_label']) . '</strong>.</p>'
                . (!empty($comment) ? '<p><strong>Motif :</strong> ' . h($comment) . '</p>' : '');
            send_mail($agent_email, $refuse_subject, render_email_template('Demande refusée', $body_html));
        }
    } else {
        // Pour la validation : comportement normal
        $stmt = $pdo->prepare("UPDATE tokens SET done_at = ? WHERE token = ? AND done_at IS NULL");
        $stmt->execute([gmdate('Y-m-d H:i:s'), $token]);
        if ($stmt->rowCount() === 0) {
            // Token déjà validé ou inexistant — abandon silencieux
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Ce jeton a déjà été utilisé ou n\'existe pas.'];
        }

        advance_workflow($t['submission_id']);
    }

    // Mettre à jour les données de la soumission
    $pdo->prepare("UPDATE submissions SET data = ? WHERE id = ?")
        ->execute([$updated_data, $t['submission_id']]);

    // Webhook notification
    send_webhook('token_validated', ['submission_id' => $t['submission_id'], 'step_label' => $t['step_label'], 'email' => $t['email'], 'action' => $action]);

    $pdo->commit();
    $t['done_at'] = gmdate('Y-m-d H:i:s');
    return ['status' => 'ok', 'data' => $t];
}

// ── ACTIVE SUBMISSIONS CHECK ───────────────────────────────────

/**
 * Vérifie si un formulaire a des soumissions actives (en_cours)
 */
function has_active_submissions(string $form_id): int {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE form_id = ? AND status = 'en_cours'");
    $stmt->execute([$form_id]);
    return (int)$stmt->fetchColumn();
}

/**
 * Vérifie si une étape a des soumissions actives (tokens en cours sur cette étape)
 */
function has_active_step_submissions(string $step_id): int {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT t.submission_id)
        FROM tokens t
        JOIN submissions s ON s.id = t.submission_id
        WHERE t.step_id = ? AND t.done_at IS NULL AND s.status = 'en_cours'
    ");
    $stmt->execute([$step_id]);
    return (int)$stmt->fetchColumn();
}
