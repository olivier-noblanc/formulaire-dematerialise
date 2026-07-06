<?php
declare(strict_types=1);

/**
 * RGPD compliance — export, deletion, auto-purge.
 *
 * rgpd_export_user_data() — exporte les données d'un agent (droit d'accès)
 * rgpd_delete_user_data() — supprime/anonymise les données d'un agent (droit à l'effacement)
 * rgpd_auto_purge()       — purge automatique des soumissions anciennes
 *
 * @package lib
 */

// ── RGPD COMPLIANCE ──────────────────────────────────────────

/**
 * Exporte toutes les données d'un agent au format JSON (droit d'accès RGPD)
 * @return array<string, mixed>
 */
function rgpd_export_user_data(string $email): array {
    // Sécurité (S-05) : seul le propriétaire ou un admin peut exporter les données
    $caller = get_auth_user();
    $caller_is_admin = is_admin_user() || is_super_admin();
    if (!$caller_is_admin && strtolower($email) !== strtolower($caller)) {
        app_log('access_denied', 'rgpd:' . $email, 'Tentative d\'export RGPD non autorisée par ' . $caller);
        return ['email' => $email, 'error' => 'Accès refusé : vous ne pouvez exporter que vos propres données.'];
    }

    $pdo = get_pdo();
    $data = ['email' => $email, 'export_date' => gmdate('c'), 'submissions' => [], 'validations' => []];

    // Soumissions de l'agent
    $stmt = $pdo->prepare("SELECT s.*, f.label as form_label FROM submissions s JOIN forms f ON f.id = s.form_id WHERE s.submitted_by = ? ORDER BY s.submitted_at DESC");
    $stmt->execute([$email]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $data['submissions'][] = [
            'id' => $row['id'],
            'form' => $row['form_label'],
            'status' => $row['status'],
            'submitted_at' => $row['submitted_at'],
            'closed_at' => $row['closed_at'],
            'data' => json_decode($row['data'], true),
        ];
    }

    // Validations effectuées par cet agent
    $stmt2 = $pdo->prepare("SELECT t.*, st.label as step_label, f.label as form_label FROM tokens t JOIN steps st ON st.id = t.step_id JOIN submissions s ON s.id = t.submission_id JOIN forms f ON f.id = s.form_id WHERE t.email = ? AND t.done_at IS NOT NULL ORDER BY t.done_at DESC");
    $stmt2->execute([$email]);
    $data['validations'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    return $data;
}

/**
 * Supprime les données d'un agent (droit à l'effacement RGPD)
 * Anonymise les soumissions et supprime les pièces jointes
 */
function rgpd_delete_user_data(string $email): bool {
    // Sécurité (S-05) : seul le propriétaire ou un admin peut supprimer les données
    $caller = get_auth_user();
    $caller_is_admin = is_admin_user() || is_super_admin();
    if (!$caller_is_admin && strtolower($email) !== strtolower($caller)) {
        app_log('access_denied', 'rgpd:' . $email, 'Tentative de suppression RGPD non autorisée par ' . $caller);
        return false;
    }

    $pdo = get_pdo();

    try {
        // Anonymiser les soumissions de l'agent
        $stmt = $pdo->prepare("SELECT id, data FROM submissions WHERE submitted_by = ?");
        $stmt->execute([$email]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $data = json_decode($row['data'], true) ?: [];
            // Anonymiser les champs personnels
            foreach (['prenom', 'nom', 'email', 'telephone', 'mobile', 'adresse'] as $field) {
                if (isset($data[$field])) $data[$field] = '[supprimé]';
            }
            $pdo->prepare("UPDATE submissions SET submitted_by = ?, data = ? WHERE id = ?")
                ->execute(['[supprimé]', json_encode($data, JSON_UNESCAPED_UNICODE), $row['id']]);
            // Supprimer les pièces jointes (BLOB)
            $pdo->prepare("DELETE FROM attachments WHERE submission_id = ?")->execute([$row['id']]);
        }

        // Anonymiser les tokens de l'agent
        $pdo->prepare("UPDATE tokens SET email = '[supprimé]' WHERE email = ?")->execute([$email]);

        // Anonymiser les délégations
        $pdo->prepare("UPDATE delegations SET from_email = '[supprimé]' WHERE from_email = ?")->execute([$email]);
        $pdo->prepare("UPDATE delegations SET to_email = '[supprimé]' WHERE to_email = ?")->execute([$email]);

        // Supprimer les demandes admin
        $pdo->prepare("DELETE FROM admin_requests WHERE email = ?")->execute([$email]);

        // Supprimer l'accès admin
        $pdo->prepare("DELETE FROM admins WHERE email = ?")->execute([$email]);

        app_log('rgpd_delete', 'user:' . $email, 'Données utilisateur supprimées (RGPD)', $email);
        return true;
    } catch (Exception $e) {
        error_log('RGPD delete error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Purge automatique des données anciennes (RGPD - conservation limitée)
 * Supprime les soumissions clôturées de plus de X mois
 */
function rgpd_auto_purge(int $months = 24): int {
    $pdo = get_pdo();
    $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$months} months") ?: time());

    // Supprimer les pièces jointes des anciennes soumissions
    $stmt = $pdo->prepare("SELECT id FROM submissions WHERE status != 'en_cours' AND closed_at < ?");
    $stmt->execute([$cutoff]);
    $old_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $count = 0;
    foreach ($old_ids as $sid) {
        $pdo->prepare("DELETE FROM attachments WHERE submission_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM delegations WHERE token_id IN (SELECT id FROM tokens WHERE submission_id = ?)")->execute([$sid]);
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM alert_log WHERE submission_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sid]);
        $count++;
    }

    if ($count > 0) {
        app_log('rgpd_purge', '', "Purge RGPD : {$count} soumissions de plus de {$months} mois supprimées");
    }

    return $count;
}
