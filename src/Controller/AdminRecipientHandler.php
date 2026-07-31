<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Handlers pour les destinataires d'étape et propriétaires de formulaire.
 */
final class AdminRecipientHandler
{
    /**
     * @return array{error?: string, redirect?: string}
     */
    public static function handleAddRecipient(string $get_form_id): array
    {
        [$step_id, $err] = AdminFormsHandlers::postStepId();
        if ($err !== null) {
            return ['error' => $err];
        }
        $email = trim($_POST['email'] ?? '');
        if ($step_id === '' || $step_id === '0' || ($email === '' || $email === '0')) {
            return ['error' => 'L\'étape et le courriel sont requis.'];
        }
        $is_dynamic = preg_match('/^\{\{[a-z][a-z0-9_]*\}\}$/', $email);
        if (!$is_dynamic && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'Le destinataire "' . \App\Core\App::html()->escape($email) . '" n\'est ni une adresse email valide ni une référence dynamique {{field_name}}. Format attendu : prenom.nom@' . App::settings()->get('email_domain', 'exemple.invalid') . ' ou {{nom_du_champ}}'];
        }
        try {
            $repo = App::getInstance()->get(\App\Repository\FormRepository::class);
            $repo->createRecipient($step_id, $email);
            $label = $is_dynamic ? "Destinataire dynamique $email ajouté" : "Destinataire $email ajouté";
            App::audit()->log('recipient_add', 'step:' . $step_id, $label);
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($get_form_id) . '#step-' . urlencode($step_id)];
        } catch (\PDOException $e) {
            error_log('handleAddRecipient error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }

    /**
     * @return array{error?: string, redirect?: string}|null
     */
    public static function handleDeleteRecipient(string $get_form_id): ?array
    {
        $recipient_id = trim($_POST['recipient_id'] ?? '');
        if ($recipient_id === '' || $recipient_id === '0') {
            return null;
        }
        try {
            $repo = App::getInstance()->get(\App\Repository\FormRepository::class);
            $step_id_for_anchor = $repo->findRecipientStepId($recipient_id);
            $repo->deleteRecipient($recipient_id);
            $anchor = $step_id_for_anchor !== null ? '#step-' . urlencode($step_id_for_anchor) : '#workflow';
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($get_form_id) . $anchor];
        } catch (\PDOException $e) {
            error_log('handleDeleteRecipient error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }

    /**
     * @return array{error?: string, redirect?: string}
     */
    public static function handleAddOwner(): array
    {
        $form_id = trim($_POST['form_id'] ?? '');
        $owner_email = trim($_POST['owner_email'] ?? '');
        if ($form_id === '' || $form_id === '0' || ($owner_email === '' || $owner_email === '0')) {
            return ['error' => 'Le courriel du propriétaire est requis.'];
        }
        if (!filter_var($owner_email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'L\'adresse courriel "' . \App\Core\App::html()->escape($owner_email) . '" n\'est pas valide. Format attendu : prenom.nom@' . App::settings()->get('email_domain', 'exemple.invalid') . ''];
        }
        try {
            $repo = App::getInstance()->get(\App\Repository\FormRepository::class);
            $repo->createOwnerById($form_id, $owner_email);
            App::audit()->log('owner_add', 'form:' . $form_id, "Propriétaire $owner_email ajouté");
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($form_id) . '#owners'];
        } catch (\PDOException $e) {
            error_log('handleAddOwner error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }

    /**
     * @return array{error?: string, redirect?: string}
     */
    public static function handleDeleteOwner(): array
    {
        $owner_id = trim($_POST['owner_id'] ?? '');
        $form_id = trim($_POST['form_id'] ?? '');
        if ($owner_id === '' || $owner_id === '0' || ($form_id === '' || $form_id === '0')) {
            return ['error' => 'Paramètres manquants pour retirer le propriétaire (owner_id=' . \App\Core\App::html()->escape($owner_id) . ', form_id=' . \App\Core\App::html()->escape($form_id) . ').'];
        }
        try {
            $repo = App::getInstance()->get(\App\Repository\FormRepository::class);
            $repo->deleteOwnerById($owner_id);
            App::audit()->log('owner_remove', 'form:' . $form_id, 'Propriétaire retiré');
            return ['redirect' => App::html()->buildUrl('index.php?p=admin_forms&form_id=' . urlencode($form_id) . '#owners')];
        } catch (\PDOException $e) {
            error_log('handleDeleteOwner error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }
}
