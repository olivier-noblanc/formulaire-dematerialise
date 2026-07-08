<?php
declare(strict_types=1);

/**
 * Section "Circuit de validation" de la page admin_forms.php.
 *
 * Extrait de {@see render_admin_forms_page()} — contient :
 *  - {@see render_workflow_diagram_section()}      : SECTION B — diagramme
 *    visuel du circuit + ajout/édition/suppression d'étapes ET ajout
 *    inline de destinataires par étape (mini-formulaire dépliable sous
 *    chaque step, à côté des boutons Modifier/Supprimer).
 *
 * @package lib
 */

/**
 * SECTION B : Circuit de validation (diagramme visuel + liste des étapes).
 *
 * @param array<string,mixed> $ctx Contexte (clés utilisées :
 *        form_id, steps, steps_by_ordre, edit_step_id)
 * @return string HTML de la section B
 */
function render_workflow_diagram_section(array $ctx): string {
    $form_id        = $ctx['form_id']        ?? '';
    $steps          = $ctx['steps']          ?? [];
    $steps_by_ordre = $ctx['steps_by_ordre'] ?? [];
    $edit_step_id   = $ctx['edit_step_id']   ?? '';

    ob_start();
    ?>
    <!-- ══════════════════════════════════════════════════ -->
    <!-- SECTION B: Workflow diagram + Steps              -->
    <!-- ══════════════════════════════════════════════════ -->
    <div class="section-card" id="workflow">
        <div class="section-card-header">
            <h2>🔀 Circuit de validation</h2>
        </div>
        <div class="section-card-body">

            <!-- ── Visual Workflow Diagram ─────────────────── -->
            <?php if (!empty($steps_by_ordre)): ?>
                <div class="workflow-diagram">
                    <?php
                    $ordre_keys = array_keys($steps_by_ordre);
                    $last_key = end($ordre_keys);
                    ?>
                    <?php foreach ($steps_by_ordre as $ordre => $ordre_steps): ?>
                        <div class="workflow-step-group">
                            <?php foreach ($ordre_steps as $idx => $wstep): ?>
                                <div class="workflow-box <?= $wstep['actif'] ? '' : 'inactive' ?>" style="<?= count($ordre_steps) > 1 && $idx > 0 ? 'margin-top:.5rem;' : '' ?>">
                                    <div class="wb-label"><?= h($wstep['label']) ?></div>
                                    <div class="wb-ordre">Étape <?= h((string)$ordre) ?></div>
                                    <?php if (!empty($wstep['recipients'])): ?>
                                        <div class="wb-emails"><?= h(implode(', ', array_column($wstep['recipients'], 'email'))) ?></div>
                                    <?php else: ?>
                                        <div class="wb-emails" style="font-style:italic;">Aucun destinataire</div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($ordre !== $last_key): ?>
                            <div class="workflow-arrow"></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="workflow-empty">Aucune étape définie. Ajoutez-en ci-dessous.</div>
            <?php endif; ?>

            <hr style="border:none;border-top:1px solid #dde;margin:1rem 0;">

            <!-- ── Add step form ───────────────────────────── -->
            <div class="add-sub-card">
                <h4>＋ Ajouter une étape</h4>
                <form method="POST">
                    <?= \App\Core\App::security()->csrfField() ?>
                    <input type="hidden" name="action" value="add_step">
                    <input type="hidden" name="form_id" value="<?= $form_id ?>">
                    <div class="form-grid">
                        <div class="field">
                            <label>Libellé de l'étape<span class="req">*</span></label>
                            <input type="text" name="label" required placeholder="ex: Validation RH">
                        </div>
                        <div class="field">
                            <label>Ordre (numéro)<span class="req">*</span></label>
                            <input type="number" name="ordre" required min="1" value="<?= empty($steps) ? 1 : max(array_column($steps, 'ordre')) + 1 ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Ajouter l'étape</button>
                </form>
            </div>

            <!-- ── Step list ───────────────────────────────── -->
            <?php if (!empty($steps)): ?>
                <div style="margin-top:1.25rem;">
                    <?php foreach ($steps as $step): ?>
                        <?php if ($edit_step_id === $step['id']): ?>
                            <!-- ── Edit step inline ──────────────────── -->
                            <div class="step-card editing" id="step-<?= h($step['id']) ?>">
                                <div class="step-info" style="width:100%;">
                                    <form method="POST">
                                        <?= \App\Core\App::security()->csrfField() ?>
                                        <input type="hidden" name="action" value="update_step">
                                        <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
                                        <input type="hidden" name="form_id" value="<?= $form_id ?>">
                                        <div class="form-grid">
                                            <div class="field">
                                                <label>Libellé<span class="req">*</span></label>
                                                <input type="text" name="label" value="<?= h($step['label']) ?>" required>
                                            </div>
                                            <div class="field">
                                                <label>Ordre<span class="req">*</span></label>
                                                <input type="number" name="ordre" value="<?= $step['ordre'] ?>" required min="1">
                                            </div>
                                        </div>
                                        <div class="field">
                                            <label class="checkbox-label">
                                                <input type="checkbox" name="actif" <?= $step['actif'] ? 'checked' : '' ?>> Étape active
                                            </label>
                                        </div>

                                        <?php
                                        // v19 — Condition d'exécution conditionnelle.
                                        // Affichée uniquement pour les étapes d'ordre > 1
                                        // (la première étape s'exécute toujours).
                                        $step_ordre_int = (int)($step['ordre'] ?? 0);
                                        $can_have_condition = $step_ordre_int > 1;

                                        // Décodage de la condition existante (JSON) pour
                                        // pré-remplir les champs du formulaire.
                                        $existing_condition = ['field' => '', 'op' => '', 'value' => ''];
                                        $raw_condition = (string)($step['condition'] ?? '');
                                        if ($raw_condition !== '') {
                                            $decoded = json_decode($raw_condition, true);
                                            if (is_array($decoded)) {
                                                $existing_condition['field'] = (string)($decoded['field'] ?? '');
                                                $existing_condition['op']   = (string)($decoded['op'] ?? '');
                                                $existing_condition['value'] = (string)($decoded['value'] ?? '');
                                            }
                                        }

                                        // Liste des champs validateur du formulaire pour le
                                        // sélecteur de champ conditionné. On utilise les champs
                                        // filled_by='validator' visibles sur les étapes
                                        // précédentes (le cas nominal est un champ rempli à
                                        // l'étape 1 qui pilote l'étape 2+).
                                        $validator_fields = $form_id !== '' ? get_form_validator_fields((string)$form_id) : [];
                                        ?>

                                        <?php if ($can_have_condition): ?>
                                            <details style="margin-top:.5rem;border-top:1px dashed #dde;padding-top:.5rem;">
                                                <summary style="cursor:pointer;font-size:.85rem;font-weight:bold;">🔀 Condition d'exécution (optionnel)</summary>
                                                <div class="form-grid" style="margin-top:.5rem;">
                                                    <div class="field">
                                                        <label>Champ validateur à tester</label>
                                                        <select name="condition_field">
                                                            <option value="">— Toujours exécuter (pas de condition) —</option>
                                                            <?php foreach ($validator_fields as $vf): ?>
                                                                <?php $vf_name = (string)($vf['field_name'] ?? ''); ?>
                                                                <option value="<?= h($vf_name) ?>" <?= $existing_condition['field'] === $vf_name ? 'selected' : '' ?>>
                                                                    <?= h((string)($vf['label'] ?? $vf_name)) ?> (<?= h($vf_name) ?>)
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="field">
                                                        <label>Opérateur</label>
                                                        <select name="condition_op">
                                                            <?php
                                                            $ops = [
                                                                'equals'     => 'Égal à',
                                                                'not_equals' => 'Différent de',
                                                                'contains'   => 'Contient',
                                                                'not_empty'  => 'Non vide',
                                                                'empty'      => 'Vide',
                                                            ];
                                                            foreach ($ops as $op_val => $op_label):
                                                            ?>
                                                                <option value="<?= h($op_val) ?>" <?= $existing_condition['op'] === $op_val ? 'selected' : '' ?>><?= h($op_label) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="field">
                                                        <label>Valeur attendue</label>
                                                        <input type="text" name="condition_value" value="<?= h($existing_condition['value']) ?>" placeholder="ex: Acceptée">
                                                        <span class="hint" style="font-size:.7rem;color:#777;">Utilisé pour « Égal à », « Différent de », « Contient ». Ignoré pour « Non vide » / « Vide ».</span>
                                                    </div>
                                                </div>
                                            </details>
                                        <?php else: ?>
                                            <div style="margin-top:.5rem;font-size:.78rem;color:#777;">
                                                ℹ️ La condition d'exécution n'est disponible qu'à partir de l'ordre 2 (la première étape s'exécute toujours).
                                            </div>
                                        <?php endif; ?>

                                        <div style="display:flex;gap:.5rem;">
                                            <button type="submit" class="btn btn-primary">Enregistrer</button>
                                            <a href="index.php?p=admin_forms&form_id=<?= $form_id ?>#step-<?= $step['id'] ?>" class="btn btn-secondary">Annuler</a>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="step-card" id="step-<?= h($step['id']) ?>">
                                <div class="step-info">
                                    <span class="step-label"><?= h($step['label']) ?></span>
                                    <div class="step-meta">
                                        Ordre <?= h((string)$step['ordre']) ?>
                                        <?php if ($step['actif']): ?>
                                            <span class="badge badge-ok">Actif</span>
                                        <?php else: ?>
                                            <span class="badge" style="background:#eee;color:#595959;">Inactif</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($step['recipients'])): ?>
                                        <div class="recipient-chips">
                                            <?php foreach ($step['recipients'] as $rcpt): ?>
                                                <span class="recipient-chip">
                                                    <?= display_user($rcpt['email']) ?>
                                                    <form method="POST" style="display:inline;">
                                                        <?= \App\Core\App::security()->csrfField() ?>
                                                        <input type="hidden" name="action" value="delete_recipient">
                                                        <input type="hidden" name="recipient_id" value="<?= $rcpt['id'] ?>">
                                                        <button type="submit" class="chip-delete" title="Supprimer">×</button>
                                                    </form>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div style="font-size:.8rem;color:#999;margin-top:.3rem;">Aucun destinataire</div>
                                    <?php endif; ?>
                                </div>
                                <div class="step-actions">
                                    <a href="index.php?p=admin_forms&form_id=<?= $form_id ?>&edit_step=<?= $step['id'] ?>#step-<?= $step['id'] ?>" class="btn btn-secondary" style="font-size:.78rem;padding:.3rem .6rem;">Modifier</a>
                                    <form method="POST" style="display:inline;">
                                        <?= \App\Core\App::security()->csrfField() ?>
                                        <input type="hidden" name="action" value="delete_step">
                                        <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
                                        <button type="submit" class="btn btn-danger" style="font-size:.78rem;padding:.3rem .6rem;" onclick="return confirm('Supprimer cette étape ? Les validateurs associés perdront leurs accès.');">Supprimer</button>
                                    </form>
                                    <!-- ── Mini-formulaire inline "＋ Destinataire" ─── -->
                                    <details style="display:inline-block;position:relative;">
                                        <summary class="btn btn-secondary" style="font-size:.78rem;padding:.3rem .6rem;cursor:pointer;list-style:none;display:inline-block;">＋ Destinataire</summary>
                                        <div style="position:absolute;z-index:20;right:0;top:100%;background:#fff;border:1px solid var(--c-border);border-radius:6px;padding:.75rem;box-shadow:var(--shadow-md);min-width:320px;margin-top:.25rem;">
                                            <form method="POST">
                                                <?= \App\Core\App::security()->csrfField() ?>
                                                <input type="hidden" name="action" value="add_recipient">
                                                <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
                                                <input type="hidden" name="form_id" value="<?= $form_id ?>">
                                                <label style="font-size:.75rem;color:#595959;display:block;margin-bottom:.25rem;">Courriel du destinataire <span class="req">*</span></label>
                                                <input type="text" name="email" required placeholder="ex: prenom.nom@exemple.invalid ou {{nom_du_champ}}" list="ldap-recipient-suggestions" autocomplete="off" style="width:100%;margin-bottom:.35rem;">
                                                <span class="hint" style="font-size:.7rem;color:#777;display:block;margin-bottom:.5rem;">Email statique ou référence dynamique <code>{{champ}}</code>.</span>
                                                <div style="display:flex;gap:.4rem;justify-content:flex-end;">
                                                    <button type="button" class="btn btn-secondary" style="font-size:.78rem;padding:.3rem .6rem;" onclick="this.closest('details').open=false;">Annuler</button>
                                                    <button type="submit" class="btn btn-primary" style="font-size:.78rem;padding:.3rem .6rem;">Ajouter</button>
                                                </div>
                                            </form>
                                        </div>
                                    </details>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- ── Datalist LDAP pour autocomplétion des destinataires ──
                 (présent une seule fois, référencé par tous les
                 mini-formulaires inline via list="ldap-recipient-suggestions") -->
            <?php if (!empty($steps)): ?>
                <?= render_ldap_datalist('ldap-recipient-suggestions', '', 300) ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
    $html = ob_get_clean();
    return $html === false ? '' : $html;
}

