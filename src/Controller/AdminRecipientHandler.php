<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Handlers pour les destinataires d'étape et propriétaires de formulaire.
 */
final class AdminRecipientHandler
{
    public static function handleAddRecipient(\PDO $pdo, string $get_form_id): array
    {
        [$step_id, $err] = AdminFormsHandlers::postStepId();
        if ($err !== null) {
            return ['error' => $err];
        }
        $email = trim($_POST['email'] ?? '');
        if (empty($step_id) || empty($email)) {
            return ['error' => 'L\'étape et le courriel sont requis.'];
        }
        $is_dynamic = preg_match('/^\{\{[a-z][a-z0-9_]*\}\}$/', $email);
        if (!$is_dynamic && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'Le destinataire "' . \App\Core\App::html()->escape($email) . '" n\'est ni une adresse email valide ni une référence dynamique {{field_name}}. Format attendu : prenom.nom@' . App::settings()->get('email_domain', 'exemple.invalid') . ' ou {{nom_du_champ}}'];
        }
        try {
            $new_rcpt_id = \generate_uuid();
            $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)")
                ->execute([$new_rcpt_id, $step_id, $email]);
            $label = $is_dynamic ? "Destinataire dynamique $email ajouté" : "Destinataire $email ajouté";
            App::audit()->log('recipient_add', 'step:' . $step_id, $label);
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($get_form_id) . '#step-' . urlencode($step_id)];
        } catch (\PDOException $e) {
            error_log('handleAddRecipient error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }

    public static function handleDeleteRecipient(\PDO $pdo, string $get_form_id): ?array
    {
        $recipient_id = trim($_POST['recipient_id'] ?? '');
        if (empty($recipient_id)) {
            return null;
        }
        try {
            $stmt = $pdo->prepare("SELECT step_id FROM step_recipients WHERE id = ?");
            $stmt->execute([$recipient_id]);
            $step_id_for_anchor = (string)$stmt->fetchColumn();
            $pdo->prepare("DELETE FROM step_recipients WHERE id = ?")->execute([$recipient_id]);
            $anchor = $step_id_for_anchor !== '' ? '#step-' . urlencode($step_id_for_anchor) : '#workflow';
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($get_form_id) . $anchor];
        } catch (\PDOException $e) {
            error_log('handleDeleteRecipient error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }

    public static function handleAddOwner(\PDO $pdo): array
    {
        $form_id = trim($_POST['form_id'] ?? '');
        $owner_email = trim($_POST['owner_email'] ?? '');
        if (empty($form_id) || empty($owner_email)) {
            return ['error' => 'Le courriel du propriétaire est requis.'];
        }
        if (!filter_var($owner_email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'L\'adresse courriel "' . \App\Core\App::html()->escape($owner_email) . '" n\'est pas valide. Format attendu : prenom.nom@' . App::settings()->get('email_domain', 'exemple.invalid') . ''];
        }
        try {
            $new_owner_id = \generate_uuid();
            $pdo->prepare("INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)")
                ->execute([$new_owner_id, $form_id, $owner_email]);
            App::audit()->log('owner_add', 'form:' . $form_id, "Propriétaire $owner_email ajouté");
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($form_id) . '#owners'];
        } catch (\PDOException $e) {
            error_log('handleAddOwner error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
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
            error_log('handleDeleteOwner error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }
}
