<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\App;
use App\Enum\FieldType;

/**
 * Gestion de la soumission POST d'un formulaire dynamique.
 *
 * Extrait de FormController pour réduire la taille du contrôleur.
 * Prend les services nécessaires via App::getInstance().
 */
final class FormSubmissionHandler
{
    /**
     * Assemble les données POST, crée la soumission, traite les fichiers,
     * déclenche le workflow et envoie l'email de confirmation.
     *
     * @param array{id: string, slug: string, label: string} $form
     * @param list<array{id: string, form_id: string, label: string, field_type: string, field_name: string, options: string|null, hint: string, required: int, ordre: int, card_group: string, filled_by: string, validator_step: string, visibility: string, condition: string}> $form_fields
     * @return array{
     *   success: bool,
     *   submission_id?: string,
     *   data?: array<string, string>,
     *   file_errors?: array<string, string>
     * }
     */
    public static function process(array $form, array $form_fields, string $submitted_by): array
    {
        $now = date('Y-m-d H:i:s');
        $data = [];

        // Sécurité : exclure les champs internes du JSON de données métier
        $exclude_keys = ['csrf_token', 'rgpd_consent', 'action', 'MAX_FILE_SIZE'];
        foreach ($_POST as $k => $v) {
            if (in_array($k, $exclude_keys, true)) {
                continue;
            }
            $data[$k] = is_array($v) ? implode(', ', $v) : trim((string) $v);
        }

        // Ajouter les noms de fichiers uploadés dans les données
        foreach ($form_fields as $field) {
            if ((string) $field['field_type'] === FieldType::File->value) {
                $fname = (string) $field['field_name'];
                if (isset($_FILES[$fname]['name']) && $_FILES[$fname]['name'] !== '') {
                    $data[$fname] = $_FILES[$fname]['name'];
                }
            }
        }

        $rgpd_consent  = ($_POST['rgpd_consent'] ?? '') === '' ? 0 : 1;

        /** @var \App\Repository\SubmissionRepository $submissionRepo */
        $submissionRepo = App::getInstance()->get(\App\Repository\SubmissionRepository::class);
        $encoded_data = json_encode($data, JSON_UNESCAPED_UNICODE);
        $submission_id = $submissionRepo->createWithRgpd([
            'form_id'       => $form['id'],
            'data'          => $encoded_data !== false ? $encoded_data : '',
            'submitted_by'  => $submitted_by,
            'submitted_at'  => $now,
            'rgpd_consent'  => $rgpd_consent,
        ]);

        // Traiter les fichiers uploadés — AVANT d'invoquer advance_workflow() et
        // d'envoyer l'email de confirmation.
        $file_errors = [];
        foreach ($form_fields as $form_field) {
            if ((string) $form_field['field_type'] === FieldType::File->value) {
                $fname = (string) $form_field['field_name'];
                if (isset($_FILES[$fname]['name']) && $_FILES[$fname]['name'] !== '' && $_FILES[$fname]['error'] !== UPLOAD_ERR_NO_FILE) {
                    $upload_result = App::attachment()->handleFileUpload($_FILES[$fname], $submission_id, $fname);
                    if (!$upload_result['success']) {
                        $file_errors[$fname] = (string) $upload_result['message'];
                    }
                }
            }
        }

        // Si un upload a échoué, on nettoie la soumission (pour ne pas laisser
        // de soumission orpheline sans fichiers) et on retourne au formulaire.
        if ($file_errors !== []) {
            $submissionRepo->deleteById($submission_id);
            return [
                'success' => false,
                'file_errors' => $file_errors,
            ];
        }

        App::workflow()->advanceWorkflow($submission_id);

        // Envoyer un email de confirmation à l'agent
        $confirm_subject = 'Demande enregistrée — ' . $form['label'];
        $confirm_body = App::mail()->renderEmailTemplate(
            '✓ Demande enregistrée',
            '<p>Votre demande <strong>'
            . App::html()->h(App::html()->tJargon($form['label']))
            . '</strong> a bien été enregistrée le '
            . App::html()->h(date('d/m/Y à H:i'))
            . '.</p><p>'
            . App::html()->h(App::html()->tJargon(
                'Le workflow de validation a été déclenché. Vous serez notifié par email lorsque votre demande sera traitée ou si un refus est émis.'
            ))
            . '</p>'
        );
        App::mail()->send($submitted_by, $confirm_subject, $confirm_body);

        return [
            'success' => true,
            'submission_id' => $submission_id,
            'data' => $data,
        ];
    }
}
