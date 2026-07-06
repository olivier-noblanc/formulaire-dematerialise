<?php
// submission_view.php — Page de detail d'une soumission avec workflow visuel
//
// Le rendu HTML est extrait vers lib/render_submission_view.php (+ lib/render_submission_view_sections.php
// pour les sections workflow + data) pour garder ce fichier sous 600 lignes
// (refactor « all-under-600 »). Ce fichier ne contient plus que le data fetching
// et la logique métier (POST handling, accès, workflow steps, etc.).
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/lib/render_submission_view.php';
require_once dirname(__DIR__) . '/lib/render_submission_view_sections.php';

$pdo = get_pdo();
$sub_id = trim($_GET['id'] ?? '');

if (empty($sub_id)) {
    header('Location: index.php?p=dashboard');
    exit;
}

// Récupérer la soumission
$stmt = $pdo->prepare("
    SELECT s.*, f.label as form_label, f.slug as form_slug, f.deadline_field
    FROM submissions s
    JOIN forms f ON f.id = s.form_id
    WHERE s.id = ?
");
$stmt->execute([$sub_id]);
$sub = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sub) {
    render_error_page(404, 'Soumission introuvable',
        'La soumission demandée n\'existe pas ou a été supprimée.',
        'Vérifiez que l\'identifiant dans l\'adresse est correct. Retournez à votre tableau de bord pour voir vos demandes.');
}

$data = json_decode($sub['data'], true) ?: [];
$status = $sub['status'] ?? 'en_cours';
$user = get_auth_user();
$is_admin = is_admin_effective();  // v9.9.0 — persona: false si admin en mode visu
// Owner du formulaire : peut éditer les champs validateur post-validation
// (au même titre que l'admin). Calculé tôt car réutilisé par le POST handler
// ci-dessous et par le rendu (can_edit_validator).
$is_form_owner = is_form_owner((string)$sub['form_id']);

// Vérifier l'accès : admin ou propriétaire
if (!$is_admin && $sub['submitted_by'] !== $user) {
    // Vérifier aussi si l'utilisateur est validateur sur cette soumission
    $validator_check = $pdo->prepare("SELECT 1 FROM tokens WHERE submission_id = ? AND email = ?");
    $validator_check->execute([$sub_id, $user]);
    if (!$validator_check->fetch()) {
        header('Location: index.php?p=dashboard');
        exit;
    }
}

// Récupérer toutes les étapes du workflow
$workflow_steps = get_workflow_steps($sub['form_id']);

// Récupérer les tokens pour cette soumission
$all_tokens = get_tokens_for_submission($sub_id, ['t.id', 't.token', 't.relance_count', 't.relance_at', 't.expires_at', 't.sent_at']);

// Grouper tokens par step_id
$tokens_by_step = [];
foreach ($all_tokens as $tok) {
    $tokens_by_step[$tok['step_id']][] = $tok;
}

// Déterminer le statut de chaque étape
foreach ($workflow_steps as &$ws) {
    $step_id = $ws['step_id'];
    /** @phpstan-ignore-next-line empty.offset */
    if (!isset($tokens_by_step[$step_id]) || empty($tokens_by_step[$step_id])) {
        $ws['step_status'] = 'upcoming';
        $ws['tokens'] = [];
    } else {
        $ws['tokens'] = $tokens_by_step[$step_id];
        $all_done = true;
        foreach ($tokens_by_step[$step_id] as $tok) {
            if (empty($tok['done_at'])) $all_done = false;
        }
        $ws['step_status'] = $all_done ? 'validated' : 'current';
    }
}
unset($ws);

// Calculer la progression
$total_steps = count($workflow_steps);
$done_steps = count(array_filter($workflow_steps, fn($s) => $s['step_status'] === 'validated'));
$progress_pct = $total_steps > 0 ? (int) round(($done_steps / $total_steps) * 100) : 0;

// Date limite
$deadline_field = $sub['deadline_field'] ?? '';
$deadline_val = $deadline_field ? ($data[$deadline_field] ?? '') : '';
$dl_info = calculate_deadline_urgency($deadline_val ?: '', $status);
$deadline_ts = parse_deadline_date($deadline_val ?: '');
$days_remaining = $dl_info['days_left'];

// Traitement des actions POST
$action_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'regenerate_token' && $is_admin) {
        $token_id = trim($_POST['token_id'] ?? '');
        $result = regenerate_token($token_id);
        $action_msg = $result['message'];
    }
    elseif ($action === 'remind_one' && $is_admin) {
        $token_id = trim($_POST['token_id'] ?? '');
        $result = remind_one($token_id);
        $action_msg = $result['message'];
    }
    elseif ($action === 'remind_all' && $is_admin) {
        $remind_results = [];
        foreach ($all_tokens as $tok) {
            if (empty($tok['done_at'])) {
                $r = remind_one($tok['id']);
                $remind_results[] = $r['message'];
            }
        }
        $action_msg = count($remind_results) > 0 
            ? count($remind_results) . ' rappel(s) envoyé(s)' 
            : 'Aucun validateur en attente.';
    }
    elseif ($action === 'delegate_token') {
        $token_id = trim($_POST['token_id'] ?? '');
        $delegate_to = trim($_POST['delegate_to'] ?? '');
        $delegate_reason = trim($_POST['delegate_reason'] ?? '');
        // Seul le validateur assigne ou un admin peut deleguer
        $tok_check = $pdo->prepare("SELECT email FROM tokens WHERE id = ? AND done_at IS NULL");
        $tok_check->execute([$token_id]);
        $tok_email = $tok_check->fetchColumn();
        if ($tok_email && ($is_admin || $tok_email === $user)) {
            $result = delegate_token($token_id, $delegate_to, $delegate_reason);
            $action_msg = $result['message'];
        } else {
            $action_msg = 'Action non autorisée.';
        }
    }
    elseif ($action === 'cancel_submission') {
        $confirmed = !empty($_POST['confirmed']);
        if (!$confirmed) {
            header('Location: index.php?p=confirm_action&action=cancel_submission&submission_id=' . urlencode($sub_id) . '&from=' . urlencode('index.php?p=submission_view&id=' . $sub_id));
            exit;
        }
        if ($is_admin || $sub['submitted_by'] === $user) {
            $result = cancel_submission($sub_id, $user);
            $action_msg = $result['message'];
            // Rafraîchir les données
            header('Location: index.php?p=submission_view&id=' . urlencode($sub_id));
            exit;
        }
    }
    // v10.1.14 — Suppression définitive (admin only, status=annule ou refuse)
    elseif ($action === 'delete_submission') {
        if (!$is_admin) {
            $action_msg = 'Seul un administrateur peut supprimer définitivement une demande.';
        } elseif ($status !== 'annule' && $status !== 'refuse') {
            $action_msg = 'Seules les demandes annulées ou refusées peuvent être supprimées définitivement.';
        } else {
            try {
                // Supprimer les tokens, pièces jointes, validator_data, puis la soumission
                $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$sub_id]);
                $pdo->prepare("DELETE FROM attachments WHERE submission_id = ?")->execute([$sub_id]);
                $pdo->prepare("DELETE FROM submission_validator_data WHERE submission_id = ?")->execute([$sub_id]);
                // submission_reminds n'existe pas — les relances sont dans audit_log
                $pdo->prepare("DELETE FROM audit_log WHERE target = ?")->execute(['submission:' . $sub_id]);
                $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);
                app_log('submission_delete', 'submission:' . $sub_id, "Demande supprimée définitivement");
                header('Location: index.php?p=my_submissions&deleted=1');
                exit;
            } catch (\Throwable $e) {
                $action_msg = 'Erreur lors de la suppression : ' . h($e->getMessage());
            }
        }
    }
    // OWNER-EDIT : édition d'un champ validateur après validation.
    // Permet à l'admin ou à l'owner du formulaire de modifier la valeur d'un
    // champ validateur (filled_by='validator') directement depuis
    // submission_view.php, même après que toutes les étapes soient terminées
    // (done_at rempli). PRG (Post-Redirect-Get) pour éviter la resoumission
    // au refresh — l'ancre #validator-data repositionne l'utilisateur sur la
    // section concernée.
    elseif ($action === 'update_validator_field') {
        $field_name    = trim((string)($_POST['field_name'] ?? ''));
        $value         = trim((string)($_POST['value'] ?? ''));
        $posted_sub_id = trim((string)($_POST['sub_id'] ?? ''));

        if (!$is_admin && !$is_form_owner) {
            $action_msg = 'Vous n\'avez pas l\'autorisation de modifier ces données.';
        } elseif ($posted_sub_id !== $sub_id) {
            // Sécurité : le sub_id posté doit correspondre à celui de l'URL.
            $action_msg = 'Identifiant de soumission invalide.';
        } else {
            // Vérifier que le champ est bien un champ validateur du formulaire.
            $validator_fields = get_form_validator_fields((string)$sub['form_id']);
            $is_validator_field = false;
            foreach ($validator_fields as $vf) {
                if ((string)($vf['field_name'] ?? '') === $field_name) {
                    $is_validator_field = true;
                    break;
                }
            }
            if (!$is_validator_field) {
                $action_msg = 'Ce champ n\'est pas un champ validateur.';
            } else {
                try {
                    if ($value !== '') {
                        save_validator_data(
                            $sub_id,
                            $field_name,
                            $value,
                            'validator',
                            null,   // step_id : pas de contexte step (édition manuelle)
                            null,   // step_label : laissé à null (pas de step)
                            $user,  // filled_by_email : audit (qui a édité)
                            null    // token_id : pas de token associé (édition directe)
                        );
                    } else {
                        // Valeur vide → on supprime la ligne (correction / reset).
                        delete_validator_data($sub_id, $field_name);
                    }
                    // PRG : redirige vers la même page avec ancre #validator-data.
                    header('Location: index.php?p=submission_view&id=' . urlencode($sub_id) . '#validator-data');
                    exit;
                } catch (Exception $e) {
                    $action_msg = 'Erreur : ' . $e->getMessage();
                }
            }
        }
    }
    // BACKLOG : commentaire admin/owner post-soumission (annotation libre,
    // indépendante des champs validator). Visible dans submission_view.php
    // (zone éditable #admin-comment) et dans dashboard.php (icône 💬 tooltip).
    // PRG (Post-Redirect-Get) pour éviter la resoumission au refresh — l'ancre
    // #admin-comment repositionne l'utilisateur sur la section concernée.
    elseif ($action === 'update_admin_comment') {
        $comment       = trim((string)($_POST['admin_comment'] ?? ''));
        $posted_sub_id = trim((string)($_POST['sub_id'] ?? ''));

        if (!$is_admin && !$is_form_owner) {
            $action_msg = 'Vous n\'avez pas l\'autorisation de modifier ce commentaire.';
        } elseif ($posted_sub_id !== $sub_id) {
            $action_msg = 'Identifiant de soumission invalide.';
        } else {
            try {
                $upd = $pdo->prepare("UPDATE submissions SET admin_comment = ? WHERE id = ?");
                $upd->execute([$comment, $sub_id]);
                // Rafraîchir la donnée en cours de requête (le rendu utilise $sub).
                $sub['admin_comment'] = $comment;
                // PRG : redirige vers la même page avec ancre #admin-comment.
                header('Location: index.php?p=submission_view&id=' . urlencode($sub_id) . '#admin-comment');
                exit;
            } catch (Exception $e) {
                $action_msg = 'Erreur : ' . $e->getMessage();
            }
        }
    }
}

// Nom de l'agent
$nom_agent = h(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? ''));
$status_label = $status === 'valide' ? '✓ Validée' : ($status === 'refuse' ? '❌ Refusée' : ($status === 'annule' ? '🗑 Annulée' : '⏳ En cours'));
$status_cls = $status === 'valide' ? 'badge-valide' : ($status === 'refuse' ? 'badge-refuse' : ($status === 'annule' ? 'badge-annule' : 'badge-en-cours'));

// ── DATA FETCHING ADDITIONNEL (pour le rendu) ─────────────────
// Ces fetches étaient historiquement inline dans le rendu HTML.
// Déplacés ici pour rendre les fonctions de rendu pures (aucun accès DB).

// Infos des champs du formulaire (labels + card_group) — pour l'affichage des données
$field_info = [];
$fields_stmt2 = $pdo->prepare("SELECT field_name, label, card_group, field_type FROM form_fields WHERE form_id = ? ORDER BY ordre");
$fields_stmt2->execute([$sub['form_id']]);
foreach ($fields_stmt2->fetchAll(PDO::FETCH_ASSOC) as $fi) {
    $field_info[$fi['field_name']] = $fi;
}

// Données saisies par les validateurs (filled_by='validator')
$validator_data_rows = get_submission_validator_data($sub_id);

// Historique des relances — A-13: optimisé (cible directement les token IDs de
// cette soumission avec un IN (?,?,?...), au lieu d'un large scan LIKE 'token:%').
$submission_reminds = [];
if (!empty($all_tokens)) {
    $token_id_placeholders = [];
    $token_id_params = [];
    foreach ($all_tokens as $tok) {
        $token_id_placeholders[] = '?';
        $token_id_params[] = 'token:' . $tok['id'];
    }
    $in_clause = implode(',', $token_id_placeholders);
    $remind_logs = $pdo->prepare("
        SELECT * FROM audit_log
        WHERE (action = 'manual_remind' OR action = 'auto_remind')
        AND target IN ($in_clause)
        ORDER BY created_at DESC
    ");
    $remind_logs->execute($token_id_params);
    $submission_reminds = $remind_logs->fetchAll(PDO::FETCH_ASSOC);
}

// Total des relances + tokens en attente avec relance
$total_relances = array_sum(array_column($all_tokens, 'relance_count'));
$pending_with_relance = array_filter($all_tokens, function ($t) {
    return (int)$t['relance_count'] > 0 && empty($t['done_at']);
});

// Pièces jointes
$attachments = get_attachments($sub_id);

// Délégations
$delegations = get_delegations($sub_id);

// ── RENDU ──────────────────────────────────────────────────────
// Le rendu HTML est délégué à lib/render_submission_view.php
// (+ lib/render_submission_view_sections.php pour les sections workflow + data)
// pour garder ce fichier sous 600 lignes (refactor « all-under-600 »).
// L'ordre des sections reproduit exactement le rendu historique.

$ctx = [
    'sub_id'                => $sub_id,
    'sub'                   => $sub,
    'data'                  => $data,
    'status'                => $status,
    'status_label'          => $status_label,
    'status_cls'            => $status_cls,
    'user'                  => $user,
    'is_admin'              => $is_admin,
    'is_form_owner'         => $is_form_owner,
    'nom_agent'             => $nom_agent,
    'workflow_steps'        => $workflow_steps,
    'all_tokens'            => $all_tokens,
    'total_steps'           => $total_steps,
    'done_steps'            => $done_steps,
    'progress_pct'          => $progress_pct,
    'dl_info'               => $dl_info,
    'deadline_ts'           => $deadline_ts,
    'days_remaining'        => $days_remaining,
    'action_msg'            => $action_msg,
    'field_info'            => $field_info,
    'validator_data_rows'   => $validator_data_rows,
    'submission_reminds'    => $submission_reminds,
    'total_relances'        => $total_relances,
    'pending_with_relance'  => $pending_with_relance,
    'attachments'           => $attachments,
    'delegations'           => $delegations,
    'admin_comment'         => (string)($sub['admin_comment'] ?? ''),
];

$page_css    = submission_view_page_css();
$content     = render_submission_view_content($ctx);

echo render_page('Soumission #' . $sub_id, 'mes_demandes', $page_css, $content);
