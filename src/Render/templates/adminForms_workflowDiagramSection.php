<!-- ══════════════════════════════════════════════════ -->
<!-- SECTION B: Workflow diagram + Steps              -->
<!-- ══════════════════════════════════════════════════ -->
<div class="section-card" id="workflow">
    <div class="section-card-header">
        <h2>🔀 Circuit de validation</h2>
    </div>
    <div class="section-card-body">

        <!-- ── Visual Workflow Diagram ─────────────────── -->
        <?php if ((bool) ($steps_by_ordre)): ?>
            <div class="workflow-diagram">
                <?php
                $ordre_keys = array_keys($steps_by_ordre);
            $last_key = end($ordre_keys);
            ?>
                <?php foreach ($steps_by_ordre as $ordre => $ordre_steps): ?>
                    <div class="workflow-step-group">
                        <?php foreach ($ordre_steps as $idx => $wstep): ?>
                            <div class="workflow-box <?= $wstep['actif'] ? '' : 'inactive' ?> <?= count($ordre_steps) > 1 && $idx > 0 ? 'wf-gap' : '' ?>">
                                <div class="wb-label"><?= \App\Core\App::html()->escape($wstep['label']) ?></div>
                                <div class="wb-ordre">Étape <?= \App\Core\App::html()->escape((string) $ordre) ?></div>
                                <?php if ((bool) ($wstep['recipients'])): ?>
                                    <div class="wb-emails"><?= \App\Core\App::html()->escape(implode(', ', array_column($wstep['recipients'], 'email'))) ?></div>
                                <?php else: ?>
                                    <div class="wb-emails u-fon-6">Aucun destinataire</div>
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

        <hr class="u-bor-bor-mar">

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
                        <?php $step_column = array_column($steps, 'ordre'); ?>
                        <input type="number" name="ordre" required min="1" value="<?= $step_column !== [] ? max($step_column) + 1 : 1 ?>">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Ajouter l'étape</button>
            </form>
        </div>

        <!-- ── Step list ───────────────────────────────── -->
        <?php if ((bool) ($steps)): ?>
            <div class="mt-125">
                <?php foreach ($steps as $step): ?>
                    <?php if ($edit_step_id === $step['id']): ?>
                        <!-- ── Edit step inline ──────────────────── -->
                        <div class="step-card editing" id="step-<?= \App\Core\App::html()->escape($step['id']) ?>">
                            <div class="step-info w-100">
                                <form method="POST">
                                    <?= \App\Core\App::security()->csrfField() ?>
                                    <input type="hidden" name="action" value="update_step">
                                    <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
                                    <input type="hidden" name="form_id" value="<?= $form_id ?>">
                                    <div class="form-grid">
                                        <div class="field">
                                            <label>Libellé<span class="req">*</span></label>
                                            <input type="text" name="label" value="<?= \App\Core\App::html()->escape($step['label']) ?>" required>
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
                                $step_ordre_int = (int) ($step['ordre'] ?? 0);
                        $can_have_condition = $step_ordre_int > 1;

                        $existing_condition = ['field' => '', 'op' => '', 'value' => ''];
                        $raw_condition = (string) ($step['condition'] ?? '');
                        if ($raw_condition !== '') {
                            $decoded = json_decode($raw_condition, true);
                            if (is_array($decoded)) {
                                $existing_condition['field'] = (string) ($decoded['field'] ?? '');
                                $existing_condition['op']   = (string) ($decoded['op'] ?? '');
                                $existing_condition['value'] = (string) ($decoded['value'] ?? '');
                            }
                        }

                        $validator_fields = $form_id !== '' ? \App\Core\App::validatorData()->getFormValidatorFields((string) $form_id) : [];
                        ?>

                                    <?php if ($can_have_condition): ?>
                                        <details class="u-bor-mar-pad">
                                            <summary class="u-cur-fon-fon">🔀 Condition d'exécution (optionnel)</summary>
                                            <div class="form-grid mt-5">
                                                <div class="field">
                                                    <label>Champ validateur à tester</label>
                                                    <select name="condition_field">
                                                        <option value="">— Toujours exécuter (pas de condition) —</option>
                                                        <?php foreach ($validator_fields as $vf): ?>
                                                            <?php $vf_name = (string) ($vf['field_name'] ?? ''); ?>
                                                            <option value="<?= \App\Core\App::html()->escape($vf_name) ?>" <?= $existing_condition['field'] === $vf_name ? 'selected' : '' ?>>
                                                                <?= \App\Core\App::html()->escape((string) ($vf['label'] ?? $vf_name)) ?> (<?= \App\Core\App::html()->escape($vf_name) ?>)
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
                                                            <option value="<?= \App\Core\App::html()->escape($op_val) ?>" <?= $existing_condition['op'] === $op_val ? 'selected' : '' ?>><?= \App\Core\App::html()->escape($op_label) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="field">
                                                    <label>Valeur attendue</label>
                                                    <input type="text" name="condition_value" value="<?= \App\Core\App::html()->escape($existing_condition['value']) ?>" placeholder="ex: Acceptée">
                                                    <span class="hint u-col-fon-13">Utilisé pour « Égal à », « Différent de », « Contient ». Ignoré pour « Non vide » / « Vide ».</span>
                                                </div>
                                            </div>
                                        </details>
                                    <?php else: ?>
                                        <div class="hint-text-4">
                                            ℹ️ La condition d'exécution n'est disponible qu'à partir de l'ordre 2 (la première étape s'exécute toujours).
                                        </div>
                                    <?php endif; ?>

                                    <div class="flex-gap5-4">
                                        <button type="submit" class="btn btn-primary">Enregistrer</button>
                                        <a href="index.php?p=admin_forms&form_id=<?= $form_id ?>#step-<?= $step['id'] ?>" class="btn btn-secondary">Annuler</a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="step-card" id="step-<?= \App\Core\App::html()->escape($step['id']) ?>">
                            <div class="step-info">
                                <span class="step-label"><?= \App\Core\App::html()->escape($step['label']) ?></span>
                                <div class="step-meta">
                                    Ordre <?= \App\Core\App::html()->escape((string) $step['ordre']) ?>
                                    <?php if ($step['actif']): ?>
                                        <span class="badge badge-ok">Actif</span>
                                    <?php else: ?>
                                        <span class="badge u-bac-col">Inactif</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ((bool) ($step['recipients'])): ?>
                                    <div class="recipient-chips">
                                        <?php foreach ($step['recipients'] as $rcpt): ?>
                                            <span class="recipient-chip">
                                                <?= \App\Core\App::html()->displayUser($rcpt['email']) ?>
                                                <form method="POST" class="u-dis-2">
                                                    <?= \App\Core\App::security()->csrfField() ?>
                                                    <input type="hidden" name="action" value="delete_recipient">
                                                    <input type="hidden" name="recipient_id" value="<?= $rcpt['id'] ?>">
                                                    <button type="submit" class="chip-delete" title="Supprimer">×</button>
                                                </form>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="hint-muted-4">Aucun destinataire</div>
                                <?php endif; ?>
                            </div>
                            <div class="step-actions">
                                <a href="index.php?p=admin_forms&form_id=<?= $form_id ?>&edit_step=<?= $step['id'] ?>#step-<?= $step['id'] ?>" class="btn btn-secondary btn-compact-4">Modifier</a>
                                <form method="POST" class="u-dis-2">
                                    <?= \App\Core\App::security()->csrfField() ?>
                                    <input type="hidden" name="action" value="delete_step">
                                    <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-compact-4" data-confirm="Supprimer cette étape ? Les validateurs associés perdront leurs accès.">Supprimer</button>
                                </form>
                                <!-- ── Mini-formulaire inline "＋ Destinataire" ─── -->
                                <details class="u-dis-pos">
                                    <summary class="btn btn-secondary btn-compact-2">＋ Destinataire</summary>
                                    <div class="styled-box-13">
                                        <form method="POST">
                                            <?= \App\Core\App::security()->csrfField() ?>
                                            <input type="hidden" name="action" value="add_recipient">
                                            <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
                                            <input type="hidden" name="form_id" value="<?= $form_id ?>">
                                            <label class="u-col-dis-fon-mar-2">Courriel du destinataire <span class="req">*</span></label>
                                            <div class="flex-gap4" style="align-items: center;">
                                                <input type="text" name="email" required placeholder="ex: prenom.nom@exemple.invalid" list="recipient-templates" autocomplete="off" class="progress-fill-4" style="flex: 1;">
                                                <button type="button" class="btn btn-secondary btn-compact-4" data-insert-template="{{owner}}" title="Insérer {{owner}} (créateur du formulaire)">Propriétaire</button>
                                            </div>
                                            <span class="hint u-col-dis-fon-mar">Email statique ou référence dynamique : <code>{{owner}}</code> (créateur), <code>{{champ}}</code> (valeur d'un champ).</span>
                                            <datalist id="recipient-templates">
                                                <option value="{{owner}}">Propriétaire du formulaire (créateur)</option>
                                                <option value="{{owner_email}}">Email du propriétaire</option>
                                            </datalist>
                                            <div class="flex-gap4">
                                                <button type="button" class="btn btn-secondary btn-compact-4" data-close-details="details">Annuler</button>
                                                <button type="submit" class="btn btn-primary btn-compact-4">Ajouter</button>
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

        <?php if ((bool) ($steps)): ?>
            <?= new \App\Render\LdapRenderer()->datalist('ldap-recipient-suggestions', '', 300) ?>
        <?php endif; ?>
    </div>
</div>

<script>
// Insertion de templates de destinataires dynamiques
document.addEventListener('click', function (e) {
    if (e.target.matches('[data-insert-template]')) {
        const template = e.target.getAttribute('data-insert-template');
        const details = e.target.closest('details');
        const input = details?.querySelector('input[name="email"]');
        if (input) {
            input.value = template;
            input.focus();
        }
    }
});
</script>
