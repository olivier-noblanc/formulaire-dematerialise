<?php
declare(strict_types=1);

/**
 * Section "Champs du formulaire" de la page admin_forms.php.
 *
 * Extrait de {@see render_admin_forms_page()} — contient :
 *  - {@see render_form_fields_section()} : SECTION D — table des champs
 *    du formulaire + ajout/édition/suppression d'un champ.
 *
 * @package lib
 */

/**
 * SECTION D : Champs du formulaire.
 *
 * Affiche la table des champs existants (avec édition inline si
 * `$edit_field_id` correspond), puis le formulaire d'ajout d'un
 * nouveau champ. Un script masque automatiquement le sélecteur
 * d'étape de validation quand le champ n'est pas rempli par un
 * validateur.
 *
 * @param array<string,mixed> $ctx Contexte (clés utilisées :
 *        form_id, form_fields, edit_field_id, existing_groups, steps)
 * @return string HTML de la section D
 */
function render_form_fields_section(array $ctx): string {
    $form_id         = $ctx['form_id']         ?? '';
    $form_fields     = $ctx['form_fields']     ?? [];
    $edit_field_id   = $ctx['edit_field_id']   ?? '';
    $existing_groups = $ctx['existing_groups'] ?? [];
    $steps           = $ctx['steps']           ?? [];
    $field_types     = get_admin_forms_field_types();

    ob_start();
    ?>
    <!-- ══════════════════════════════════════════════════ -->
    <!-- SECTION D: Form fields                          -->
    <!-- ══════════════════════════════════════════════════ -->
    <div class="section-card" id="fields">
        <div class="section-card-header">
            <h2><span aria-hidden="true">📝</span> Champs du formulaire</h2>
            <a href="index.php?p=form_preview&form_id=<?= $form_id ?>" class="btn-preview" target="_blank" style="font-size:.8rem;"><span aria-hidden="true">👁</span> Prévisualiser</a>
        </div>
        <div class="section-card-body">
            <p style="font-size:.85rem;color:#666;margin-bottom:1rem;">Ces champs définissent le formulaire que les agents rempliront. <span class="required-star">*</span> = champ obligatoire.</p>

            <?php if (!empty($form_fields)): ?>
                <table class="fields-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Groupe</th>
                            <th>Libellé</th>
                            <th>Identifiant</th>
                            <th>Type</th>
                            <th>Options</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($form_fields as $ff): ?>
                            <?php if ($edit_field_id === $ff['id']): ?>
                                <!-- ── Edit field inline ──────────────── -->
                                <tr>
                                    <td colspan="7" style="background:#f0f4ff;padding:1rem;">
                                        <h4 style="margin-bottom:.75rem;color:#003189;">Modifier le champ</h4>
                                        <form method="POST">
                                            <?= \App\Core\App::security()->csrfField() ?>
                                            <input type="hidden" name="action" value="update_field">
                                            <input type="hidden" name="field_id" value="<?= $ff['id'] ?>">
                                            <input type="hidden" name="form_id" value="<?= $form_id ?>">
                                            <div class="form-grid">
                                                <div class="field">
                                                    <label>Libellé<span class="req">*</span></label>
                                                    <input type="text" name="ff_label" value="<?= h($ff['label']) ?>" required>
                                                </div>
                                                <div class="field">
                                                    <label>Identifiant technique <span class="hint">(auto si vide)</span></label>
                                                    <input type="text" name="ff_field_name" value="<?= h($ff['field_name']) ?>" placeholder="Généré automatiquement depuis le libellé">
                                                </div>
                                                <div class="field">
                                                    <label>Type de champ</label>
                                                    <select name="ff_field_type">
                                                        <?php foreach ($field_types as $val => $lbl): ?>
                                                            <option value="<?= $val ?>" <?= $ff['field_type'] === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="field">
                                                    <label>Ordre</label>
                                                    <input type="number" name="ff_ordre" value="<?= $ff['ordre'] ?>" min="0">
                                                </div>
                                                <div class="field">
                                                    <label>Groupe (carte)</label>
                                                    <?php if (!empty($existing_groups)): ?>
                                                        <select name="ff_card_group">
                                                            <?php foreach ($existing_groups as $g): ?>
                                                                <option value="<?= h($g) ?>" <?= $ff['card_group'] === $g ? 'selected' : '' ?>><?= h($g) ?></option>
                                                            <?php endforeach; ?>
                                                            <option value="__new__" <?= !in_array($ff['card_group'], $existing_groups) ? 'selected' : '' ?>>— Nouveau groupe —</option>
                                                        </select>
                                                    <?php endif; ?>
                                                    <input type="text" name="ff_card_group_new" placeholder="Nom du nouveau groupe" style="margin-top:.3rem;" value="">
                                                    <?php if (empty($existing_groups)): ?>
                                                        <input type="hidden" name="ff_card_group" value="">
                                                    <?php endif; ?>
                                                </div>
                                                <div class="field">
                                                    <label>Options <span class="hint">(une par ligne, uniquement pour Sélecteur)</span></label>
                                                    <textarea name="ff_options" rows="3" placeholder="Option A&#10;Option B&#10;Option C"><?= h(options_to_lines($ff['options'] ?? '')) ?></textarea>
                                                </div>
                                                <div class="field">
                                                    <label>Indication <span class="hint">(texte d'aide sous le champ)</span></label>
                                                    <input type="text" name="ff_hint" value="<?= h($ff['hint'] ?? '') ?>" placeholder="ex : en euros TTC">
                                                </div>
                                                <div class="field">
                                                    <label>Rempli par</label>
                                                    <select name="ff_filled_by">
                                                        <option value="demandeur" <?= ($ff['filled_by'] ?? '') === 'demandeur' || ($ff['filled_by'] ?? '') === '' ? 'selected' : '' ?>>Demandeur</option>
                                                        <option value="validator" <?= ($ff['filled_by'] ?? '') === 'validator' ? 'selected' : '' ?>>Validateur</option>
                                                    </select>
                                                </div>
                                                <div class="field">
                                                    <label>Étape de validation <span class="hint">(obligatoire si "Validateur" ; laisser vide pour toutes les étapes)</span></label>
                                                    <select name="ff_validator_step">
                                                        <option value="">— Champ global (toutes étapes) —</option>
                                                        <?php foreach ($steps as $s): ?>
                                                            <option value="<?= h($s['id']) ?>" <?= (($ff['validator_step'] ?? '') === $s['id'] || ($ff['validator_step'] ?? '') === $s['label']) ? 'selected' : '' ?>>
                                                                <?= h($s['label']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="field ff-visibility-field">
                                                    <label>Visibilité <span class="hint">(uniquement pour les pièces jointes)</span></label>
                                                    <select name="ff_visibility">
                                                        <option value="all" <?= (($ff['visibility'] ?? 'all') === 'all') ? 'selected' : '' ?>>Tous (validateurs + owner)</option>
                                                        <option value="owner_only" <?= (($ff['visibility'] ?? 'all') === 'owner_only') ? 'selected' : '' ?>>Owner uniquement (caché des validateurs)</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="field" style="margin-top:.25rem;">
                                                <label class="checkbox-label">
                                                    <input type="checkbox" name="ff_required" <?= $ff['required'] ? 'checked' : '' ?>> Champ obligatoire <span class="required-star">*</span>
                                                </label>
                                            </div>
                                            <div style="display:flex;gap:.5rem;margin-top:.5rem;">
                                                <button type="submit" class="btn btn-primary">Enregistrer</button>
                                                <a href="index.php?p=admin_forms&form_id=<?= $form_id ?>#field-<?= $ff['id'] ?>" class="btn btn-secondary">Annuler</a>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <tr id="field-<?= h($ff['id']) ?>">
                                    <td><?= h((string)$ff['ordre']) ?></td>
                                    <td><span style="font-size:.8rem;color:#666;"><?= h($ff['card_group']) ?></span></td>
                                    <td>
                                        <?= h($ff['label']) ?>
                                        <?php if ($ff['required']): ?>
                                            <span class="required-star" title="Champ obligatoire">*</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><code style="font-size:.78rem;background:#eef;padding:.1rem .3rem;border-radius:2px;"><?= h($ff['field_name']) ?></code></td>
                                    <td>
                                        <span class="field-type-badge">
                                            <?= field_type_icon($ff['field_type']) ?>
                                            <?= field_type_label($ff['field_type']) ?>
                                        </span>
                                    </td>
                                    <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($ff['options'] ?? '') ?>">
                                        <?php
                                        $opts = $ff['options'] ?? '';
                                        if (!empty($opts)) {
                                            $decoded = json_decode($opts, true);
                                            if (is_array($decoded)) {
                                                echo h(implode(', ', $decoded));
                                            } else {
                                                echo h($opts);
                                            }
                                        } else {
                                            echo '—';
                                        }
                                        ?>
                                    </td>
                                    <td class="actions">
                                        <a href="index.php?p=admin_forms&form_id=<?= $form_id ?>&edit_field=<?= $ff['id'] ?>#field-<?= $ff['id'] ?>" class="btn btn-secondary" style="font-size:.76rem;padding:.25rem .5rem;">Modifier</a>
                                        <form method="POST" style="display:inline;">
                                            <?= \App\Core\App::security()->csrfField() ?>
                                            <input type="hidden" name="action" value="delete_field">
                                            <input type="hidden" name="field_id" value="<?= $ff['id'] ?>">
                                            <input type="hidden" name="form_id" value="<?= $form_id ?>">
                                            <button type="submit" class="btn btn-danger" style="font-size:.76rem;padding:.25rem .5rem;" onclick="return confirm('Supprimer ce champ ? Les données associées seront perdues.');">Supprimer</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon" aria-hidden="true">📝</div>
                    <p>Aucun champ défini pour ce formulaire.</p>
                </div>
            <?php endif; ?>

            <!-- ── Add field form ──────────────────────────── -->
            <div class="add-sub-card">
                <h4>＋ Ajouter un champ</h4>
                <form method="POST">
                    <?= \App\Core\App::security()->csrfField() ?>
                    <input type="hidden" name="action" value="add_field">
                    <input type="hidden" name="form_id" value="<?= $form_id ?>">
                    <div class="form-grid">
                        <div class="field">
                            <label>Libellé<span class="req">*</span></label>
                            <input type="text" name="ff_label" required placeholder="ex: Nom, Date de début">
                        </div>
                        <div class="field">
                            <label>Identifiant technique <span class="hint">(auto si vide)</span></label>
                            <input type="text" name="ff_field_name" placeholder="Généré automatiquement depuis le libellé">
                        </div>
                        <div class="field">
                            <label>Type de champ</label>
                            <select name="ff_field_type">
                                <?php foreach ($field_types as $val => $lbl): ?>
                                    <option value="<?= $val ?>"><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Ordre</label>
                            <input type="number" name="ff_ordre" min="0" value="<?= count($form_fields) + 1 ?>">
                        </div>
                        <div class="field">
                            <label>Groupe (carte)</label>
                            <?php if (!empty($existing_groups)): ?>
                                <select name="ff_card_group">
                                    <?php foreach ($existing_groups as $g): ?>
                                        <option value="<?= h($g) ?>" <?= $g === 'Général' ? 'selected' : '' ?>><?= h($g) ?></option>
                                    <?php endforeach; ?>
                                    <option value="__new__">— Nouveau groupe —</option>
                                </select>
                            <?php else: ?>
                                <input type="hidden" name="ff_card_group" value="">
                            <?php endif; ?>
                            <input type="text" name="ff_card_group_new" placeholder="Nom du nouveau groupe" style="margin-top:.3rem;" value="">
                        </div>
                        <div class="field full-width">
                            <label>Options <span class="hint">(une par ligne, uniquement pour Sélecteur)</span></label>
                            <textarea name="ff_options" rows="3" placeholder="Option A&#10;Option B&#10;Option C"></textarea>
                        </div>
                        <div class="field full-width">
                            <label>Indication <span class="hint">(texte d'aide sous le champ)</span></label>
                            <input type="text" name="ff_hint" placeholder="ex : en euros TTC">
                        </div>
                        <div class="field">
                            <label>Rempli par</label>
                            <select name="ff_filled_by">
                                <option value="demandeur" selected>Demandeur</option>
                                <option value="validator">Validateur</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Étape de validation <span class="hint">(obligatoire si "Validateur" ; laisser vide pour toutes les étapes)</span></label>
                            <select name="ff_validator_step">
                                <option value="">— Champ global (toutes étapes) —</option>
                                <?php foreach ($steps as $s): ?>
                                    <option value="<?= h($s['id']) ?>">
                                        <?= h($s['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field ff-visibility-field">
                            <label>Visibilité <span class="hint">(uniquement pour les pièces jointes)</span></label>
                            <select name="ff_visibility">
                                <option value="all" selected>Tous (validateurs + owner)</option>
                                <option value="owner_only">Owner uniquement (caché des validateurs)</option>
                            </select>
                        </div>
                    </div>
                    <div class="field" style="margin-top:.25rem;">
                        <label class="checkbox-label">
                            <input type="checkbox" name="ff_required"> Champ obligatoire <span class="required-star">*</span>
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin-top:.5rem;">Ajouter le champ</button>
                </form>
            </div>
</div>
    </div>
    <?php
    $html = ob_get_clean();
    return $html === false ? '' : $html;
}
