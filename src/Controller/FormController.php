<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;
use App\Enum\FilledBy;
use App\Render\FormRenderer;

/**
 * Contrôleur du formulaire de demande (form.php?f=<slug>).
 *
 * Routing, auth checks, délégation — la logique métier est dans les handlers.
 */
final class FormController extends BaseController
{
    /**
     * Point d'entrée du contrôleur.
     */
    public function handle(): void
    {
        $slug = trim($_GET['f'] ?? '');

        // Sécurité (A-01) : valider le slug du formulaire
        if ($slug !== '' && $slug !== '0') {
            try {
                $slug = validate_input($slug, 'slug', ['max_length' => 100]);
            } catch (\InvalidArgumentException) {
                new \App\Render\ErrorRenderer()->errorPage(
                    400,
                    'Paramètre invalide',
                    'Le paramètre de formulaire fourni est invalide.',
                    'Vérifiez l\'adresse dans votre navigateur.'
                );
            }
        }

        $form = $this->formRepo->findActiveBySlug((string) $slug);

        if (!((bool)$form)) {
            /** @phpstan-ignore-next-line if.alwaysTrue */
            if (TEST_MODE) {
                test_json_response(['error' => 'Formulaire introuvable', 'slug' => $slug]);
            }
            new \App\Render\ErrorRenderer()->errorPage(
                404,
                'Formulaire introuvable',
                'Le formulaire demandé n\'existe pas ou a été désactivé.',
                'Vérifiez l\'adresse dans votre navigateur. Vous pouvez retourner à l\'accueil pour voir les formulaires disponibles.'
            );
            return;
        }

        $submitted_by = $this->auth->getUser();
        $field_errors = [];
        $file_errors  = [];
        $success      = false;

        // Vérifier si l'agent a déjà une soumission en cours pour ce formulaire
        $existing_submission = $this->submissionRepo->findActiveByFormAndSubmitter($form['id'], $submitted_by);

        // Palier de confirmation (v34) : les doublons accidentels sont évités
        // par une confirmation explicite, pas un blocage en base.
        /** @phpstan-ignore-next-line if.alwaysTrue */
        $confirmed = TEST_MODE || isset($_GET['confirmed']) || isset($_POST['confirmed']);
        /** @phpstan-ignore booleanNot.alwaysFalse, booleanAnd.alwaysFalse */
        if ($existing_submission !== null && !$confirmed) {
            echo $this->renderPage(
                $this->html->h($this->html->tJargon($form['label'])),
                'forms',
                $this->renderPageCss(),
                new FormRenderer()->confirmDuplicate($form, $existing_submission, (string) $slug)
            );
            return;
        }

        // Charger les champs dynamiques du formulaire (filled_by='demandeur')
        $all_form_fields = App::validatorData()->getFormFields($form['id']);
        $form_fields = array_filter($all_form_fields, fn(array $f): bool => !isset($f['filled_by']) || $f['filled_by'] === '' || $f['filled_by'] === FilledBy::Demandeur->value);

        $submission_id = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->security->requireCsrf();

            // Validation
            $field_errors = FormValidationHandler::validateFields($form_fields);
            $file_errors  = FormValidationHandler::validateFiles($form_fields);
            $rgpd_error   = FormValidationHandler::validateRgpdConsent();
            if ($rgpd_error !== null) {
                $field_errors['rgpd_consent'] = $rgpd_error;
            }

            if ($field_errors === [] && $file_errors === []) {
                $result = FormSubmissionHandler::process($form, $form_fields, $submitted_by);

                if (($result['success'] ?? false) !== true && isset($result['file_errors'])) {
                    $file_errors = $result['file_errors'];
                } else {
                    $success       = true;
                    $submission_id = $result['submission_id'] ?? '';

                    // Mode test : renvoyer JSON
                    /** @phpstan-ignore-next-line if.alwaysTrue */
                    if (TEST_MODE) {
                        $generated_tokens = $this->tokenRepo->findWithStepsBySubmission($submission_id);
                        test_json_response([
                            'success'       => true,
                            'submission_id' => $submission_id,
                            'form_slug'     => $slug,
                            'form_label'    => $form['label'],
                            'submitted_by'  => $submitted_by,
                            'data'          => $result['data'] ?? [],
                            'tokens'        => $generated_tokens,
                            'mails_count'   => count($GLOBALS['_test_mails']),
                        ]);
                    }
                }

                /** @phpstan-ignore-next-line elseif.alwaysFalse */
            } elseif (TEST_MODE) {
                test_json_response(['error' => 'Erreurs de validation', 'field_errors' => $field_errors]);
            }
        }

        // Mode test : GET renvoie les métadonnées du formulaire en JSON
        /** @phpstan-ignore-next-line booleanAnd.leftAlwaysTrue */
        if (TEST_MODE && $_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['screenshot'])) {
            $fields_list = [];
            foreach ($form_fields as $form_field) {
                $fields_list[] = [
                    'field_name' => $form_field['field_name'],
                    'label'      => $form_field['label'],
                    'field_type' => $form_field['field_type'],
                    'required'   => (bool) $form_field['required'],
                    'options'    => ((string) $form_field['options']) !== '' ? json_decode((string) $form_field['options'], true) : null,
                    'card_group' => $form_field['card_group'],
                ];
            }
            test_json_response([
                'form'         => [
                    'id'          => $form['id'],
                    'slug'        => $form['slug'],
                    'label'       => $form['label'],
                    'description' => $form['description'],
                ],
                'fields'       => $fields_list,
                'csrf_token'   => $this->security->generateCsrfToken(),
                'submitted_by' => $submitted_by,
            ]);
        }

        // Regrouper les champs par card_group pour le rendu visuel
        $grouped       = [];
        foreach ($form_fields as $form_field) {
            $group = $form_field['card_group'] ?? 'Général';
            $grouped[$group][] = $form_field;
        }

        $field_values = $_POST;

        echo $this->renderPage(
            $this->html->h($this->html->tJargon($form['label'])),
            'forms',
            $this->renderPageCss(),
            new FormRenderer()->formContent(
                $form,
                $submitted_by,
                $existing_submission,
                $success,
                $submission_id,
                $grouped,
                $field_errors,
                $file_errors,
                $field_values,
                '',
                '',
                (string) $slug
            )
        );
    }

    /**
     * CSS spécifique à la page formulaire (nowdoc statique — sans interpolation).
     */
    private function renderPageCss(): string
    {
        return '';
    }
}
