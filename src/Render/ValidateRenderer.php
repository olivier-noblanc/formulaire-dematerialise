<?php

declare(strict_types=1);

namespace App\Render;

use App\Core\App;
use App\Enum\SubmissionField;
use App\Enum\ValidationAction;
use App\Forms\SubmissionData;

/**
 * Rendu de la page Validation (accept/refuse de formulaires).
 */
final class ValidateRenderer
{
    /**
     * Retourne le HTML du contenu principal de la page Validation.
     *
     * @param array{status: string, data?: mixed} $result
     * @param array<int, array{id: string, label: string, ordre: int, dones: string|null, emails: string|null}> $all_wf_steps
     * @param array<int, array{id: string, form_id: string, label: string, field_type: string, field_name: string, options: string|null, hint: string, required: int}> $validator_fields
     * @param array<string, string> $validator_data_index field_name => value
     * @param list<array{id: string, submission_id: string, field_name: string, field_label: string, field_type: string, value: non-falsy-string, filled_by: string, filled_at: string}> $previous_vd_rows
     * @param list<array<string, mixed>> $visible_attachments
     * @param list<string> $current_step_field_names
     */
    public static function content(
        string $token,
        string $pageCss,
        mixed $success,
        mixed $error,
        array $result,
        array $all_wf_steps,
        array $validator_fields,
        array $validator_data_index,
        array $previous_vd_rows,
        array $visible_attachments,
        array $current_step_field_names,
        string $existing_comment,
        string $existing_motif,
    ): string {
        $htmlService = App::html();
        $html = '<div class="card">' . "\n";

        // ── Success ──
        if (isset($success)) {
            $html .= '<h1>Validation effectuée</h1>' . "\n";
            $html .= '<p class="ok">Action effectuée avec succès.</p>' . "\n";
            $html .= '<div class="flex-gap5-mt-3">' . "\n";
            $html .= '  <a href="index.php?p=my_validations" class="btn btn-secondary">Mes validations</a>' . "\n";
            $html .= '  <a href="index.php" class="btn btn-secondary">Accueil</a>' . "\n";
            $html .= '</div>' . "\n";

            // ── Error ──
        } elseif (isset($error)) {
            $html .= '<h1>Erreur</h1>' . "\n";
            $html .= '<p class="err">' . $htmlService->escape($error) . '</p>' . "\n";
            $html .= '<div class="flex-gap5-mt-3">' . "\n";
            $html .= '  <a href="index.php?p=my_validations" class="btn btn-secondary">Mes validations</a>' . "\n";
            $html .= '  <a href="index.php" class="btn btn-secondary">Accueil</a>' . "\n";
            $html .= '</div>' . "\n";

            // ── Invalid ──
        } elseif ($result['status'] === 'invalid') {
            $html .= '<h1>Lien invalide</h1>' . "\n";
            $html .= '<p class="err">Ce lien est introuvable ou expiré.</p>' . "\n";
            $html .= '<div class="flex-gap5-mt-3">' . "\n";
            $html .= '  <a href="index.php?p=my_validations" class="btn btn-secondary">Mes validations</a>' . "\n";
            $html .= '  <a href="index.php" class="btn btn-secondary">Accueil</a>' . "\n";
            $html .= '</div>' . "\n";

            // ── Already done ──
        } elseif ($result['status'] === 'already_done') {
            $data = $result['data'] ?? [];
            $html .= '<span class="badge">' . $htmlService->escape($data['step_label'] ?? '') . '</span>' . "\n";
            $html .= '<h1>Déjà validé</h1>' . "\n";
            $html .= '<p class="info">Tâche validée le ' . $htmlService->escape(date('d/m/Y à H:i', (int) strtotime((string) ($data['done_at'] ?? 'now')))) . '</p>' . "\n";
            $html .= '<div class="flex-gap5-mt-3">' . "\n";
            $html .= '  <a href="index.php?p=my_validations" class="btn btn-secondary">Mes validations</a>' . "\n";
            $html .= '  <a href="index.php" class="btn btn-secondary">Accueil</a>' . "\n";
            $html .= '</div>' . "\n";

            // ── Closed ──
        } elseif ($result['status'] === 'closed') {
            $html .= '<h1>Workflow terminé</h1>' . "\n";
            $html .= '<p class="info">Ce dossier est déjà clôturé.</p>' . "\n";
            $html .= '<div class="flex-gap5-mt-3">' . "\n";
            $html .= '  <a href="index.php?p=my_validations" class="btn btn-secondary">Mes validations</a>' . "\n";
            $html .= '  <a href="index.php" class="btn btn-secondary">Accueil</a>' . "\n";
            $html .= '</div>' . "\n";

            // ── Expired ──
        } elseif ($result['status'] === 'expired') {
            $html .= '<h1>Lien expiré</h1>' . "\n";
            $html .= '<p class="err">Ce lien de validation a expiré. Veuillez contacter l\'expéditeur pour obtenir un nouveau lien.</p>' . "\n";
            $html .= '<div class="flex-gap5-mt-3">' . "\n";
            $html .= '  <a href="index.php?p=my_validations" class="btn btn-secondary">Mes validations</a>' . "\n";
            $html .= '  <a href="index.php" class="btn btn-secondary">Accueil</a>' . "\n";
            $html .= '</div>' . "\n";

            // ── Pending / OK ──
        } elseif ($result['status'] === 'pending' || $result['status'] === 'ok') {
            $data = $result['data'] ?? [];
            $d   = json_decode($data['data'] ?? '{}', true);
            $nom = $htmlService->escape(SubmissionData::get($d, SubmissionField::PRENOM) . ' ' . SubmissionData::get($d, SubmissionField::NOM));

            $html .= '<a href="index.php?p=my_validations" class="back-link">← Mes validations</a>' . "\n";
            $html .= '<span class="badge">' . $htmlService->escape($data['step_label'] ?? '') . '</span>' . "\n";
            $html .= '<h1>Action requise</h1>' . "\n";

            $html .= '<aside class="what-to-do-box" role="region" aria-label="Que devez-vous faire ?">' . "\n";
            $html .= '  <span class="what-to-do-title">Que devez-vous faire ?</span>' . "\n";
            $html .= '  Vous devez <strong>valider</strong> ou <strong>refuser</strong> cette demande. Choisissez une action ci-dessous.' . "\n";
            $html .= '</aside>' . "\n";

            // ── Workflow progression ──
            if ($all_wf_steps !== []) {
                $html .= '<div class="wf-progression">' . "\n";
                $html .= '  <h3>Avancement des étapes</h3>' . "\n";
                $html .= '  <div class="wf-steps">' . "\n";
                foreach ($all_wf_steps as $all_wf_step) {
                    $dones_arr = array_filter(explode('|', $all_wf_step['dones'] ?? ''), fn(string $x): bool => !in_array($x, ['', null, '0'], true));
                    $all_done = count($dones_arr) > 0 && count(array_filter(explode('|', $all_wf_step['dones'] ?? ''))) === count(array_filter(explode('|', $all_wf_step['emails'] ?? '')));
                    $is_current = ($all_wf_step['id'] === ($data['step_id'] ?? 0));

                    if ($all_done) {
                        $cls = 'wf-prog-done';
                        $icon = '<span aria-hidden="true">✓</span>';
                    } elseif ($is_current) {
                        $cls = 'wf-prog-current';
                        $icon = '<span aria-hidden="true">⏳</span>';
                    } else {
                        $cls = 'wf-prog-upcoming';
                        $icon = '○';
                    }

                    $html .= '    <div class="wf-prog-step ' . $cls . '">' . "\n";
                    $html .= '      <span class="wf-prog-icon">' . $icon . '</span>' . "\n";
                    $html .= '      <span>Étape ' . (int) $all_wf_step['ordre'] . ' — ' . $htmlService->escape($all_wf_step['label'] ?? '') . ($is_current ? ' (votre tour)' : '') . ($all_done ? ' — validée' : '') . '</span>' . "\n";
                    $html .= '    </div>' . "\n";
                }
                $html .= '  </div>' . "\n";
                $html .= '</div>' . "\n";
            }

            // ── Détails du formulaire ──
            $html .= '<div class="validation-details">' . "\n";
            $html .= '  <h2>Détails du formulaire</h2>' . "\n";
            $html .= '  <p><strong>Dossier :</strong> ' . $nom . '</p>' . "\n";
            $html .= '  <p><strong>Étape :</strong> ' . $htmlService->escape($data['step_label'] ?? '') . '</p>' . "\n";

            $exclude_keys = array_merge(['validations', 'csrf_token'], $current_step_field_names);
            $html .= new FormRenderer()->submissionData($d, $exclude_keys);
            $html .= '</div>' . "\n";

            // ── Informations saisies par les validateurs précédents ──
            if ($previous_vd_rows !== []) {
                $all_validator_fields = App::validatorData()->getFormValidatorFields((string) ($data['form_id'] ?? ''));
                $field_labels = [];
                foreach ($all_validator_fields as $all_validator_field) {
                    $field_labels[$all_validator_field['field_name']] = $all_validator_field['label'];
                }

                $html .= '<div class="validation-details u-bor-3">' . "\n";
                $html .= '  <h2>📋 Informations saisies par les validateurs précédents</h2>' . "\n";
                foreach ($previous_vd_rows as $previou_vd_row) {
                    $label = $field_labels[$previou_vd_row['field_name']] ?? ucfirst(str_replace('_', ' ', $previou_vd_row['field_name']));
                    $value = $previou_vd_row['value'] === '1' ? '✓ Oui' : $htmlService->escape($previou_vd_row['value']);
                    $step_lbl = $htmlService->escape($previou_vd_row['step_label'] ?? '');
                    $html .= '  <p><strong>' . $htmlService->escape(App::html()->tJargon($label)) . ':</strong> ' . $value;
                    if ($step_lbl !== '' && $step_lbl !== '0') {
                        $html .= '<br><small class="text-muted-2">Étape : ' . $step_lbl . '</small>';
                    }
                    $html .= '</p>' . "\n";
                }
                $html .= '</div>' . "\n";
            }

            // ── Pièces jointes ──
            if ($visible_attachments !== []) {
                $html .= '<div class="validation-details">' . "\n";
                $html .= '  <h2><span aria-hidden="true">📎</span> Pièces jointes (' . count($visible_attachments) . ')</h2>' . "\n";
                foreach ($visible_attachments as $visible_attachment) {
                    $html .= '  <p>' . App::html()->getFileIcon($visible_attachment['mime_type'] ?? '') . ' <a href="index.php?p=download&id=' . urlencode((string) $visible_attachment['id']) . '" class="u-col-tex">' . $htmlService->escape($visible_attachment['original_name'] ?? '') . '</a> <span class="u-col-fon-9">(' . App::html()->formatFileSize((int) ($visible_attachment['file_size'] ?? 0)) . ')</span></p>' . "\n";
                }
                $html .= '</div>' . "\n";
            }

            // ── Formulaire de validation ──
            $html .= '<form method="post" id="validation-form">' . "\n";
            $html .= '  ' . App::security()->csrfField() . "\n";
            $html .= '  <input type="hidden" name="token" value="' . $htmlService->escape($token) . '">' . "\n";

            if ($validator_fields !== []) {
                $html .= '  <div class="validation-details u-bor">' . "\n";
                $html .= '    <h2>📝 Informations à compléter</h2>' . "\n";
                $html .= '    <p class="hint mb-1-2">Remplissez les champs ci-dessous lors de la validation.</p>' . "\n";
                foreach ($validator_fields as $validator_field) {
                    $fname = $validator_field['field_name'] ?? '';
                    $existing_val = $validator_data_index[$fname] ?? '';
                    $html .= '    <div class="mb-1-2">' . "\n";
                    $html .= new FormRenderer()->field($validator_field, $existing_val, [], '', false);
                    $html .= '    </div>' . "\n";
                }
                $html .= '  </div>' . "\n";
            }

            // ── Motif du refus ──
            $motifs = [
                'Information manquante' => '📄',
                'Hors périmètre'       => '🚫',
                'Non conforme'         => '⚠️',
                'Autre motif'          => '✏️',
            ];
            $html .= '  <fieldset class="refusal-section">' . "\n";
            $html .= '    <legend class="refusal-legend">Motif du refus <span class="req" aria-hidden="true">*</span></legend>' . "\n";
            $html .= '    <span class="hint refusal-hint">Sélectionnez un motif. Vous pourrez préciser en complément ci-dessous.</span>' . "\n";
            $html .= '    <div class="refusal-motif-list" role="radiogroup" aria-label="Motif du refus">' . "\n";
            foreach ($motifs as $motif_val => $motif_icon) {
                $checked = ($existing_motif === $motif_val) ? ' checked' : '';
                $html .= '      <label class="refusal-motif-radio">' . "\n";
                $html .= '        <input type="radio" name="motif" value="' . $htmlService->escape($motif_val) . '"' . $checked . '>' . "\n";
                $html .= '        <span class="refusal-motif-icon" aria-hidden="true">' . $motif_icon . '</span>' . "\n";
                $html .= '        <span class="refusal-motif-label">' . $htmlService->escape($motif_val) . '</span>' . "\n";
                $html .= '      </label>' . "\n";
            }
            $html .= '    </div>' . "\n";
            $html .= '  </fieldset>' . "\n";

            // ── Commentaire ──
            $html .= '  <div class="form-group">' . "\n";
            $html .= '    <label for="comment">Précisions complémentaires <span class="hint">(recommandé pour le refus, optionnel pour la validation)</span></label>' . "\n";
            $html .= '    <textarea id="comment" name="comment" rows="4" placeholder="Ex : il manque le justificatif de domicile de moins de 3 mois...">' . $htmlService->escape($existing_comment) . '</textarea>' . "\n";
            $html .= '  </div>' . "\n";

            // ── Boutons ──
            $html .= '  <div class="submit-buttons">' . "\n";
            $html .= '    <button type="submit" name="action" value="' . ValidationAction::Valider->value . '" class="btn-validate"><span aria-hidden="true">✅</span> Valider</button>' . "\n";
            $html .= '    <button type="button" id="btn-show-refusal-recap" class="btn-refuse-confirm" aria-haspopup="dialog" aria-expanded="false" aria-controls="refusal-recap"><span aria-hidden="true">❌</span> Confirmer le refus</button>' . "\n";
            $html .= '  </div>' . "\n";

            // ── Recap refus ──
            $html .= '  <div id="refusal-recap" class="refusal-summary" role="alert" aria-live="assertive" hidden tabindex="-1">' . "\n";
            $html .= '    <h3 class="refusal-summary-title"><span aria-hidden="true">⚠️</span> Confirmation du refus</h3>' . "\n";
            $html .= '    <p class="refusal-summary-text">Vous allez refuser cette demande pour le motif suivant : <strong id="refusal-recap-motif">—</strong></p>' . "\n";
            $html .= '    <p class="refusal-summary-warning">Cette action est <strong>irréversible</strong>. Le demandeur sera notifié par email.</p>' . "\n";
            $html .= '    <div class="refusal-summary-actions">' . "\n";
            $html .= '      <button type="submit" name="action" value="' . ValidationAction::Refuser->value . '" class="btn-refuse-definitive" formnovalidate><span aria-hidden="true">✓</span> Oui, refuser définitivement</button>' . "\n";
            $html .= '      <button type="button" id="btn-cancel-refusal" class="btn-refuse-cancel">Annuler</button>' . "\n";
            $html .= '    </div>' . "\n";
            $html .= '  </div>' . "\n";

            // ── Erreur refus sans motif ──
            if ($existing_motif === '' && $existing_comment === '') {
                // No error to display here; the controller handles this case
            }

            $html .= '</form>' . "\n";
        }

        return $html . ('</div>' . "\n");
    }
}
