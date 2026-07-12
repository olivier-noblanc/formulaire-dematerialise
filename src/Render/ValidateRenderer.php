<?php
declare(strict_types=1);

namespace App\Render;

use App\Core\App;

/**
 * Rendu de la page Validation (accept/refuse de formulaires).
 */
final class ValidateRenderer
{
    /**
     * Retourne le HTML du contenu principal de la page Validation.
     *
     * @param string $token
     * @param string $pageCss
     * @param mixed $success
     * @param mixed $error
     * @param array{status: string, data: mixed} $result
     * @param array<int, array<string, mixed>> $all_wf_steps
     * @param array<int, array<string, mixed>> $validator_fields
     * @param array<string, string> $validator_data_index field_name => value
     * @param array<int, array<string, mixed>> $previous_vd_rows
     * @param array<int, array<string, mixed>> $visible_attachments
     * @param list<string> $current_step_field_names
     * @param string $existing_comment
     * @param string $existing_motif
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
        $h = App::html();
        $html = '<div class="card">' . "\n";

        // ── Success ──
        if (isset($success)) {
            $html .= '<h1>Validation effectuée</h1>' . "\n";
            $html .= '<p class="ok">Action effectuée avec succès.</p>' . "\n";
            $html .= '<div style="margin-top:1.5rem;display:flex;gap:.5rem;justify-content:center;">' . "\n";
            $html .= '  <a href="index.php?p=my_validations" class="btn btn-secondary">Mes validations</a>' . "\n";
            $html .= '  <a href="index.php" class="btn btn-secondary">Accueil</a>' . "\n";
            $html .= '</div>' . "\n";

        // ── Error ──
        } elseif (isset($error)) {
            $html .= '<h1>Erreur</h1>' . "\n";
            $html .= '<p class="err">' . $h->escape($error) . '</p>' . "\n";
            $html .= '<div style="margin-top:1.5rem;display:flex;gap:.5rem;justify-content:center;">' . "\n";
            $html .= '  <a href="index.php?p=my_validations" class="btn btn-secondary">Mes validations</a>' . "\n";
            $html .= '  <a href="index.php" class="btn btn-secondary">Accueil</a>' . "\n";
            $html .= '</div>' . "\n";

        // ── Invalid ──
        } elseif ($result['status'] === 'invalid') {
            $html .= '<h1>Lien invalide</h1>' . "\n";
            $html .= '<p class="err">Ce lien est introuvable ou expiré.</p>' . "\n";
            $html .= '<div style="margin-top:1.5rem;display:flex;gap:.5rem;justify-content:center;">' . "\n";
            $html .= '  <a href="index.php?p=my_validations" class="btn btn-secondary">Mes validations</a>' . "\n";
            $html .= '  <a href="index.php" class="btn btn-secondary">Accueil</a>' . "\n";
            $html .= '</div>' . "\n";

        // ── Already done ──
        } elseif ($result['status'] === 'already_done') {
            $data = $result['data'] ?? [];
            $html .= '<span class="badge">' . $h->escape($data['step_label'] ?? '') . '</span>' . "\n";
            $html .= '<h1>Déjà validé</h1>' . "\n";
            $html .= '<p class="info">Tâche validée le ' . $h->escape(date('d/m/Y à H:i', (int) strtotime((string)($data['done_at'] ?? 'now')))) . '</p>' . "\n";
            $html .= '<div style="margin-top:1.5rem;display:flex;gap:.5rem;justify-content:center;">' . "\n";
            $html .= '  <a href="index.php?p=my_validations" class="btn btn-secondary">Mes validations</a>' . "\n";
            $html .= '  <a href="index.php" class="btn btn-secondary">Accueil</a>' . "\n";
            $html .= '</div>' . "\n";

        // ── Closed ──
        } elseif ($result['status'] === 'closed') {
            $html .= '<h1>Workflow terminé</h1>' . "\n";
            $html .= '<p class="info">Ce dossier est déjà clôturé.</p>' . "\n";
            $html .= '<div style="margin-top:1.5rem;display:flex;gap:.5rem;justify-content:center;">' . "\n";
            $html .= '  <a href="index.php?p=my_validations" class="btn btn-secondary">Mes validations</a>' . "\n";
            $html .= '  <a href="index.php" class="btn btn-secondary">Accueil</a>' . "\n";
            $html .= '</div>' . "\n";

        // ── Expired ──
        } elseif ($result['status'] === 'expired') {
            $html .= '<h1>Lien expiré</h1>' . "\n";
            $html .= '<p class="err">Ce lien de validation a expiré. Veuillez contacter l\'expéditeur pour obtenir un nouveau lien.</p>' . "\n";
            $html .= '<div style="margin-top:1.5rem;display:flex;gap:.5rem;justify-content:center;">' . "\n";
            $html .= '  <a href="index.php?p=my_validations" class="btn btn-secondary">Mes validations</a>' . "\n";
            $html .= '  <a href="index.php" class="btn btn-secondary">Accueil</a>' . "\n";
            $html .= '</div>' . "\n";

        // ── Pending / OK ──
        } elseif ($result['status'] === 'pending' || $result['status'] === 'ok') {
            $data = $result['data'] ?? [];
            $d   = json_decode($data['data'] ?? '{}', true);
            $nom = $h->escape(($d['prenom'] ?? '') . ' ' . ($d['nom'] ?? ''));

            $html .= '<a href="index.php?p=my_validations" class="back-link">← Mes validations</a>' . "\n";
            $html .= '<span class="badge">' . $h->escape($data['step_label'] ?? '') . '</span>' . "\n";
            $html .= '<h1>Action requise</h1>' . "\n";

            $html .= '<aside class="what-to-do-box" role="region" aria-label="Que devez-vous faire ?">' . "\n";
            $html .= '  <span class="what-to-do-title">Que devez-vous faire ?</span>' . "\n";
            $html .= '  Vous devez <strong>valider</strong> ou <strong>refuser</strong> cette demande. Choisissez une action ci-dessous.' . "\n";
            $html .= '</aside>' . "\n";

            // ── Workflow progression ──
            if (!empty($all_wf_steps)) {
                $html .= '<div class="wf-progression">' . "\n";
                $html .= '  <h3>Avancement des étapes</h3>' . "\n";
                $html .= '  <div class="wf-steps">' . "\n";
                foreach ($all_wf_steps as $ws) {
                    $dones_arr = array_filter(explode('|', $ws['dones'] ?? ''), fn ($x) => !empty($x));
                    $all_done = count($dones_arr) > 0 && count(array_filter(explode('|', $ws['dones'] ?? ''))) === count(array_filter(explode('|', $ws['emails'] ?? '')));
                    $is_current = ($ws['id'] == ($data['step_id'] ?? 0));

                    if ($all_done) { $cls = 'wf-prog-done'; $icon = '<span aria-hidden="true">✓</span>'; }
                    elseif ($is_current) { $cls = 'wf-prog-current'; $icon = '<span aria-hidden="true">⏳</span>'; }
                    else { $cls = 'wf-prog-upcoming'; $icon = '○'; }

                    $html .= '    <div class="wf-prog-step ' . $cls . '">' . "\n";
                    $html .= '      <span class="wf-prog-icon">' . $icon . '</span>' . "\n";
                    $html .= '      <span>Étape ' . (int)$ws['ordre'] . ' — ' . $h->escape($ws['label'] ?? '') . ($is_current ? ' (votre tour)' : '') . ($all_done ? ' — validée' : '') . '</span>' . "\n";
                    $html .= '    </div>' . "\n";
                }
                $html .= '  </div>' . "\n";
                $html .= '</div>' . "\n";
            }

            // ── Détails du formulaire ──
            $html .= '<div class="validation-details">' . "\n";
            $html .= '  <h2>Détails du formulaire</h2>' . "\n";
            $html .= '  <p><strong>Dossier :</strong> ' . $nom . '</p>' . "\n";
            $html .= '  <p><strong>Étape :</strong> ' . $h->escape($data['step_label'] ?? '') . '</p>' . "\n";

            $exclude_keys = array_merge(['validations', 'csrf_token'], $current_step_field_names);
            $html .= (new FormRenderer())->submissionData($d, $exclude_keys);
            $html .= '</div>' . "\n";

            // ── Informations saisies par les validateurs précédents ──
            if (!empty($previous_vd_rows)) {
                $all_validator_fields = App::validatorData()->getFormValidatorFields((string)($data['form_id'] ?? ''));
                $field_labels = [];
                foreach ($all_validator_fields as $avf) {
                    $field_labels[$avf['field_name']] = $avf['label'];
                }

                $html .= '<div class="validation-details" style="border-left: 4px solid var(--c-success);">' . "\n";
                $html .= '  <h2>📋 Informations saisies par les validateurs précédents</h2>' . "\n";
                foreach ($previous_vd_rows as $pvd) {
                    $label = $field_labels[$pvd['field_name']] ?? ucfirst(str_replace('_', ' ', $pvd['field_name']));
                    $value = $pvd['value'] === '1' ? '✓ Oui' : $h->escape($pvd['value']);
                    $step_lbl = $h->escape($pvd['step_label'] ?? '');
                    $html .= '  <p><strong>' . $h->escape(App::html()->tJargon($label)) . ':</strong> ' . $value;
                    if ($step_lbl) {
                        $html .= '<br><small style="color:#666;">Étape : ' . $step_lbl . '</small>';
                    }
                    $html .= '</p>' . "\n";
                }
                $html .= '</div>' . "\n";
            }

            // ── Pièces jointes ──
            if (!empty($visible_attachments)) {
                $html .= '<div class="validation-details">' . "\n";
                $html .= '  <h2><span aria-hidden="true">📎</span> Pièces jointes (' . count($visible_attachments) . ')</h2>' . "\n";
                foreach ($visible_attachments as $att) {
                    $html .= '  <p>' . App::html()->getFileIcon($att['mime_type'] ?? '') . ' <a href="index.php?p=download&id=' . urlencode((string)$att['id']) . '" style="color:var(--c-primary-dark);text-decoration:underline;">' . $h->escape($att['original_name'] ?? '') . '</a> <span style="color:#595959;font-size:.85rem;">(' . App::html()->formatFileSize((int)($att['file_size'] ?? 0)) . ')</span></p>' . "\n";
                }
                $html .= '</div>' . "\n";
            }

            // ── Formulaire de validation ──
            $html .= '<form method="post" id="validation-form">' . "\n";
            $html .= '  ' . App::security()->csrfField() . "\n";
            $html .= '  <input type="hidden" name="token" value="' . $h->escape((string)$token) . '">' . "\n";

            if (!empty($validator_fields)) {
                $html .= '  <div class="validation-details" style="border-left: 4px solid var(--c-primary);">' . "\n";
                $html .= '    <h2>📝 Informations à compléter</h2>' . "\n";
                $html .= '    <p class="hint" style="margin-bottom: 1rem;">Remplissez les champs ci-dessous lors de la validation.</p>' . "\n";
                foreach ($validator_fields as $vf) {
                    $fname = $vf['field_name'] ?? '';
                    $existing_val = $validator_data_index[$fname] ?? '';
                    $html .= '    <div style="margin-bottom: 1rem;">' . "\n";
                    $html .= (new FormRenderer())->field($vf, $existing_val, [], '', false);
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
                $html .= '        <input type="radio" name="motif" value="' . $h->escape($motif_val) . '"' . $checked . '>' . "\n";
                $html .= '        <span class="refusal-motif-icon" aria-hidden="true">' . $motif_icon . '</span>' . "\n";
                $html .= '        <span class="refusal-motif-label">' . $h->escape($motif_val) . '</span>' . "\n";
                $html .= '      </label>' . "\n";
            }
            $html .= '    </div>' . "\n";
            $html .= '  </fieldset>' . "\n";

            // ── Commentaire ──
            $html .= '  <div class="form-group">' . "\n";
            $html .= '    <label for="comment">Précisions complémentaires <span class="hint">(recommandé pour le refus, optionnel pour la validation)</span></label>' . "\n";
            $html .= '    <textarea id="comment" name="comment" rows="4" placeholder="Ex : il manque le justificatif de domicile de moins de 3 mois...">' . $h->escape($existing_comment) . '</textarea>' . "\n";
            $html .= '  </div>' . "\n";

            // ── Boutons ──
            $html .= '  <div class="submit-buttons">' . "\n";
            $html .= '    <button type="submit" name="action" value="valider" class="btn-validate"><span aria-hidden="true">✅</span> Valider</button>' . "\n";
            $html .= '    <button type="button" id="btn-show-refusal-recap" class="btn-refuse-confirm" aria-haspopup="dialog" aria-expanded="false" aria-controls="refusal-recap"><span aria-hidden="true">❌</span> Confirmer le refus</button>' . "\n";
            $html .= '  </div>' . "\n";

            // ── Recap refus ──
            $html .= '  <div id="refusal-recap" class="refusal-summary" role="alert" aria-live="assertive" hidden tabindex="-1">' . "\n";
            $html .= '    <h3 class="refusal-summary-title"><span aria-hidden="true">⚠️</span> Confirmation du refus</h3>' . "\n";
            $html .= '    <p class="refusal-summary-text">Vous allez refuser cette demande pour le motif suivant : <strong id="refusal-recap-motif">—</strong></p>' . "\n";
            $html .= '    <p class="refusal-summary-warning">Cette action est <strong>irréversible</strong>. Le demandeur sera notifié par email.</p>' . "\n";
            $html .= '    <div class="refusal-summary-actions">' . "\n";
            $html .= '      <button type="submit" name="action" value="refuser" class="btn-refuse-definitive" formnovalidate><span aria-hidden="true">✓</span> Oui, refuser définitivement</button>' . "\n";
            $html .= '      <button type="button" id="btn-cancel-refusal" class="btn-refuse-cancel">Annuler</button>' . "\n";
            $html .= '    </div>' . "\n";
            $html .= '  </div>' . "\n";

            // ── Erreur refus sans motif ──
            if ($existing_motif === '' && $existing_comment === '') {
                // No error to display here; the controller handles this case
            }

            $html .= '</form>' . "\n";
        }

        $html .= '</div>' . "\n";

        return $html;
    }
}
