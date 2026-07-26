<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\App;
use App\Enum\FieldVisibility;
use App\Enum\FilledBy;
use App\Enum\FieldType;
use App\Enum\ValidationAction;

/**
 * Contrôleur de la page Validation (accept/refuse de formulaires).
 *
 * Gère le workflow de validation : CSRF, tokens, email, DB, pièces jointes.
 * Routing : ?token=XXX → validate (auto-détecté dans index.php).
 */
final class ValidateController extends BaseController
{
    public function handle(): void
    {
        $result = ['status' => 'invalid', 'data' => null];
        $token  = '';

        // ── POST — Exécute l'action ──
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->security->requireCsrf();

            $token = trim($_POST['token'] ?? '');
            $action = trim($_POST['action'] ?? '');
            $comment = trim($_POST['comment'] ?? '');
            $motif = trim($_POST['motif'] ?? '');
            if ($action === ValidationAction::Refuser->value) {
                if ($motif === '') {
                    $error = 'Veuillez sélectionner un motif de refus.';
                } else {
                    $comment = $comment !== '' ? ($motif . ' — ' . $comment) : $motif;
                }
            }

            try {
                if ($token !== '' && $token !== '0') {
                    $token = validate_input($token, 'token');
                }
                if ($action !== '' && $action !== '0') {
                    $action = validate_input($action, 'action');
                }
            } catch (\InvalidArgumentException) {
                $error = 'Données invalides.';
                /** @phpstan-ignore-next-line if.alwaysTrue */
                if (TEST_MODE) {
                    test_json_response(['error' => 'Données invalides', 'token' => substr((string) $token, 0, 8) . '...', 'action' => $action]);
                }
            }

            if (!isset($error)) {
                if ($action === ValidationAction::Refuser->value && in_array(trim($comment), ['', '0'], true)) {
                    // Ne pas traiter — on affiche la page avec un message d'erreur
                } elseif ($token && in_array($action, [ValidationAction::Valider->value, ValidationAction::Refuser->value])) {
                    $pre_ctx = App::workflow()->getTokenWithContext((string) $token);
                    $pre_validator_fields = [];
                    if ($pre_ctx && !empty($pre_ctx['form_id'])) {
                        $pre_validator_fields = App::validatorData()->getFormValidatorFields(
                            (string) $pre_ctx['form_id'],
                            isset($pre_ctx['step_id']) ? (string) $pre_ctx['step_id'] : null
                        );
                    }

                    if ($action === ValidationAction::Valider->value && $pre_validator_fields !== []) {
                        $missing = [];
                        foreach ($pre_validator_fields as $pre_validator_field) {
                            if (!empty($pre_validator_field['required'])) {
                                $fname = (string) ($pre_validator_field['field_name'] ?? '');
                                if ($fname === '') {
                                    continue;
                                }
                                $val = trim((string) ($_POST[$fname] ?? ''));
                                if ($val === '') {
                                    $missing[] = App::html()->tJargon((string) ($pre_validator_field['label'] ?? $fname));
                                }
                            }
                        }
                        if ($missing !== []) {
                            $error = 'Champs obligatoires manquants : ' . implode(', ', $missing);
                            /** @phpstan-ignore-next-line if.alwaysTrue */
                            if (TEST_MODE) {
                                test_json_response([
                                    'error'   => $error,
                                    'action'  => $action,
                                    'token'   => substr((string) $token, 0, 8) . '...',
                                    'missing' => $missing,
                                ]);
                            }
                        }
                    }

                    if (!isset($error)) {
                        $done_by = $this->auth->getUser();
                        $result = App::workflow()->validateToken((string) $token, (string) $action, $comment, $done_by);

                        /** @phpstan-ignore-next-line if.alwaysTrue */
                        if (TEST_MODE) {
                            test_json_response([
                                'action'  => $action,
                                'result'  => $result,
                                'token'   => substr((string) $token, 0, 8) . '...',
                                'comment' => $comment,
                            ]);
                        }

                        if ($result['status'] === 'ok') {
                            $success = true;

                            $token_ctx = $result['data'] ?? [];
                            if (!empty($token_ctx['form_id'])) {
                                $form_id = (string) $token_ctx['form_id'];
                                $step_id = isset($token_ctx['step_id']) ? (string) $token_ctx['step_id'] : null;
                                $subm_id = isset($token_ctx['submission_id']) ? (string) $token_ctx['submission_id'] : '';
                                $validator_fields = App::validatorData()->getFormValidatorFields($form_id, $step_id);
                                if ($validator_fields !== [] && $subm_id !== '') {
                                    foreach ($validator_fields as $validator_field) {
                                        $fname = (string) ($validator_field['field_name'] ?? '');
                                        if ($fname === '') {
                                            continue;
                                        }
                                        $val = trim((string) ($_POST[$fname] ?? ''));
                                        if ($val !== '') {
                                            App::validatorData()->saveValidatorData(
                                                $subm_id,
                                                $fname,
                                                $val,
                                                FilledBy::Validator->value,
                                                $step_id,
                                                null,
                                                isset($token_ctx['email']) ? (string) $token_ctx['email'] : null,
                                                isset($token_ctx['id']) ? (string) $token_ctx['id'] : null
                                            );
                                        } else {
                                            App::validatorData()->deleteValidatorData($subm_id, $fname);
                                        }
                                    }
                                }
                            }
                        } else {
                            $error = $result['status'] === 'invalid' ? 'Lien invalide ou expiré.'
                                     : ($result['status'] === 'already_done' ? 'Cette tâche a déjà été traitée.'
                                     : ($result['status'] === 'closed' ? 'Le workflow est déjà terminé.'
                                     : ($result['status'] === 'expired' ? 'Ce lien a expiré.' : 'Erreur inconnue.')));
                        }
                    }
                } else {
                    /** @phpstan-ignore-next-line if.alwaysTrue */
                    if (TEST_MODE) {
                        test_json_response(['error' => 'Données invalides', 'token' => $token, 'action' => $action]);
                    }
                    $error = 'Données invalides.';
                }
            }
        }

        // ── GET — Affichage uniquement ──
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $token = trim($_GET['token'] ?? '');

            if ($token !== '' && $token !== '0') {
                if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
                    $result = ['status' => 'invalid'];
                } else {
                    $this->audit->log('token_view', 'token:' . substr($token, 0, 8) . '...', 'Consultation page de validation', '');

                    $data = App::workflow()->getTokenWithContext($token);

                    if (!$data) {
                        $result = ['status' => 'invalid'];
                    } elseif ($data['done_at']) {
                        $result = ['status' => 'already_done', 'data' => $data];
                    } elseif ($data['closed_at']) {
                        $result = ['status' => 'closed', 'data' => $data];
                    } elseif (!empty($data['expires_at'])) {
                        $exp_ts = strtotime($data['expires_at']);
                        if ($exp_ts !== false && $exp_ts < time()) {
                            $result = ['status' => 'expired', 'data' => $data];
                        } else {
                            $result = ['status' => 'pending', 'data' => $data];
                        }
                    } else {
                        $result = ['status' => 'ok', 'data' => $data];
                    }
                }
            } else {
                $result = ['status' => 'invalid'];
            }

            /** @phpstan-ignore-next-line booleanAnd.leftAlwaysTrue */
            if (TEST_MODE && !isset($_GET['screenshot'])) {
                $response = [
                    '_test_mode' => true,
                    'token_hash' => substr($token, 0, 8) . '...',
                    'result'     => $result['status'],
                ];
                if (isset($data)) {
                    $response['step_label']  = $data['step_label'] ?? '';
                    $response['form_label']  = $data['form_label'] ?? '';
                    $response['submission_id'] = $data['submission_id'] ?? null;
                    $response['csrf_token']  = $this->security->generateCsrfToken();
                }
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }
        }

        // ── Rendu HTML ──
        $pageCss = '';
        $all_wf_steps = [];
        $validator_fields = [];
        $validator_data_index = [];
        $previous_vd_rows = [];
        $visible_attachments = [];
        $current_step_field_names = [];
        $existing_comment = $_POST['comment'] ?? '';
        $existing_motif = $_POST['motif'] ?? '';

        if ($result['status'] === 'pending' || $result['status'] === 'ok') {
            $rdata = $result['data'] ?? [];
            $form_id = (string) ($rdata['form_id'] ?? '');
            $step_id = isset($rdata['step_id']) ? (string) $rdata['step_id'] : null;
            $subm_id = (string) ($rdata['submission_id'] ?? '');

            $all_wf_steps = $this->formRepo->getWorkflowStepsWithTokens($form_id, $subm_id);

            $vf_list = App::validatorData()->getFormValidatorFields($form_id, $step_id);
            foreach ($vf_list as $vf) {
                $current_step_field_names[] = $vf['field_name'] ?? '';
            }

            $all_validator_data = App::validatorData()->getSubmissionValidatorData($subm_id);
            $all_vd_by_field = [];
            foreach ($all_validator_data as $avd) {
                $all_vd_by_field[$avd['field_name']] = $avd;
            }
            $all_validator_fields = App::validatorData()->getFormValidatorFields($form_id);
            $field_labels = [];
            foreach ($all_validator_fields as $all_validator_field) {
                $field_labels[$all_validator_field['field_name']] = $all_validator_field['label'];
            }
            $previous_vd_rows = [];
            foreach ($all_vd_by_field as $fname => $vd_row) {
                if (in_array($fname, $current_step_field_names, true)) {
                    continue;
                }
                if (empty($vd_row['value'])) {
                    continue;
                }
                $previous_vd_rows[] = $vd_row;
            }

            $attachments = App::attachment()->getAttachments($subm_id);
            $visible_attachments = [];
            if ($attachments !== []) {
                $owner_only_fields = [];
                $form_fields = App::validatorData()->getFormFields($form_id);
                foreach ($form_fields as $form_field) {
                    if (($form_field['field_type'] ?? '') === FieldType::File->value && ($form_field['visibility'] ?? 'all') === FieldVisibility::OwnerOnly->value) {
                        $owner_only_fields[] = $form_field['field_name'];
                    }
                }
                foreach ($attachments as $attachment) {
                    if (!in_array($attachment['field_name'] ?? '', $owner_only_fields, true)) {
                        $visible_attachments[] = $attachment;
                    }
                }
            }

            $validator_fields = App::validatorData()->getFormValidatorFields($form_id, $step_id);
            $validator_data = App::validatorData()->getSubmissionValidatorData($subm_id, $step_id);
            $validator_data_index = [];
            foreach ($validator_data as $vd) {
                $validator_data_index[$vd['field_name']] = $vd['value'] ?? '';
            }
        }

        $content = \App\Render\ValidateRenderer::content(
            (string) $token,
            $pageCss,
            $success ?? null,
            $error ?? null,
            $result,
            $all_wf_steps,
            $validator_fields,
            $validator_data_index,
            $previous_vd_rows,
            $visible_attachments,
            $current_step_field_names,
            $existing_comment,
            $existing_motif,
        );
        echo $this->renderPage('Validation', 'mes_validations', $pageCss, $content);
    }
}
