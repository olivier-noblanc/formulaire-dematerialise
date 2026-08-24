<?php /** @var \App\Render\AdminFormsRenderer $this */ ?>
<!-- ══════════════════════════════════════════════════ -->
<!-- SECTION D: Form fields                          -->
<!-- ══════════════════════════════════════════════════ -->
<div class="section-card" id="fields">
    <div class="section-card-header">
        <h2><span aria-hidden="true">📝</span> Champs du formulaire</h2>
        <a href="index.php?p=form_preview&form_id=<?= $form_id ?>" class="btn-preview u-fon" target="_blank"><span aria-hidden="true">👁</span> Prévisualiser</a>
    </div>
    <div class="section-card-body">
        <p class="caption-3">Ces champs définissent le formulaire que les agents rempliront. <span class="required-star">*</span> = champ obligatoire.</p>

        <?php if ((bool) ($form_fields)): ?>
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
                                <td colspan="7" class="u-bac-pad">
                                    <h4 class="heading-primary-2">Modifier le champ</h4>
                                    <form method="POST">
                                        <?= \App\Core\App::security()->csrfField() ?>
                                        <input type="hidden" name="action" value="update_field">
                                        <input type="hidden" name="field_id" value="<?= $ff['id'] ?>">
                                        <input type="hidden" name="form_id" value="<?= $form_id ?>">
                                        <div class="form-grid">
                                            <div class="field">
                                                <label>Libellé<span class="req">*</span></label>
                                                <input type="text" name="ff_label" value="<?= \App\Core\App::html()->escape($ff['label']) ?>" required>
                                            </div>
                                            <div class="field">
                                                <label>Identifiant technique <span class="hint">(auto si vide)</span></label>
                                                <input type="text" name="ff_field_name" value="<?= \App\Core\App::html()->escape($ff['field_name']) ?>" placeholder="Généré automatiquement depuis le libellé">
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
                                                <?php if ((bool) ($existing_groups)): ?>
                                                    <select name="ff_card_group">
                                                        <?php foreach ($existing_groups as $g): ?>
                                                            <option value="<?= \App\Core\App::html()->escape($g) ?>" <?= $ff['card_group'] === $g ? 'selected' : '' ?>><?= \App\Core\App::html()->escape($g) ?></option>
                                                        <?php endforeach; ?>
                                                        <option value="__new__" <?= in_array($ff['card_group'], $existing_groups, true) ? '' : 'selected' ?>>— Nouveau groupe —</option>
                                                    </select>
                                                <?php endif; ?>
                                                <input type="text" name="ff_card_group_new" placeholder="Nom du nouveau groupe" value="" class="mt-3">
                                                <?php if (!($existing_groups)): ?>
                                                    <input type="hidden" name="ff_card_group" value="">
                                                <?php endif; ?>
                                            </div>
                                            <div class="field">
                                                <label>Options <span class="hint">(une par ligne, uniquement pour Sélecteur)</span></label>
                                                <textarea name="ff_options" rows="3" placeholder="Option A&#10;Option B&#10;Option C"><?= \App\Core\App::html()->escape($this->optionsToLines($ff['options'] ?? '')) ?></textarea>
                                            </div>
                                            <div class="field">
                                                <label>Indication <span class="hint">(texte d'aide sous le champ)</span></label>
                                                <input type="text" name="ff_hint" value="<?= \App\Core\App::html()->escape($ff['hint'] ?? '') ?>" placeholder="ex : en euros TTC">
                                            </div>
                                            <div class="field">
                                                <label>Rempli par</label>
                                                <select name="ff_filled_by">
                                                    <option value="demandeur" <?= ($ff['filled_by'] ?? '') === \App\Enum\FilledBy::Demandeur->value || ($ff['filled_by'] ?? '') === '' ? 'selected' : '' ?>>Demandeur</option>
                                                    <option value="validator" <?= ($ff['filled_by'] ?? '') === \App\Enum\FilledBy::Validator->value ? 'selected' : '' ?>>Validateur</option>
                                                </select>
                                            </div>
                                            <div class="field">
                                                <label>Étape de validation <span class="hint">(obligatoire si "Validateur" ; laisser vide pour toutes les étapes)</span></label>
                                                <select name="ff_validator_step">
                                                    <option value="">— Champ global (toutes étapes) —</option>
                                                    <?php foreach ($steps as $s): ?>
                                                        <option value="<?= \App\Core\App::html()->escape($s['id']) ?>" <?= (($ff['validator_step'] ?? '') === $s['id'] || ($ff['validator_step'] ?? '') === $s['label']) ? 'selected' : '' ?>>
                                                            <?= \App\Core\App::html()->escape($s['label']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="field ff-visibility-field">
                                                <label>Visibilité <span class="hint">(uniquement pour les pièces jointes)</span></label>
                                                <select name="ff_visibility">
                                                    <option value="all" <?= (($ff['visibility'] ?? 'all') === \App\Enum\FieldVisibility::All->value) ? 'selected' : '' ?>>Tous (validateurs + owner)</option>
                                                    <option value="owner_only" <?= (($ff['visibility'] ?? 'all') === \App\Enum\FieldVisibility::OwnerOnly->value) ? 'selected' : '' ?>>Owner uniquement (caché des validateurs)</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="field mt-25">
                                            <label class="checkbox-label">
                                                <input type="checkbox" name="ff_required" <?= $ff['required'] ? 'checked' : '' ?>> Champ obligatoire <span class="required-star">*</span>
                                            </label>
                                        </div>
                                        <div class="flex-gap5-mt">
                                            <button type="submit" class="btn btn-primary">Enregistrer</button>
                                            <a href="index.php?p=admin_forms&form_id=<?= $form_id ?>#field-<?= $ff['id'] ?>" class="btn btn-secondary">Annuler</a>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php else: ?>
                            <tr id="field-<?= \App\Core\App::html()->escape($ff['id']) ?>">
                                <td><?= \App\Core\App::html()->escape((string) $ff['ordre']) ?></td>
                                <td><span class="caption-6"><?= \App\Core\App::html()->escape($ff['card_group']) ?></span></td>
                                <td>
                                    <?= \App\Core\App::html()->escape($ff['label']) ?>
                                    <?php if ($ff['required']): ?>
                                        <span class="required-star" title="Champ obligatoire">*</span>
                                    <?php endif; ?>
                                </td>
                                <td><code class="styled-box-2"><?= \App\Core\App::html()->escape($ff['field_name']) ?></code></td>
                                <td>
                                    <span class="field-type-badge">
                                        <?= $this->fieldTypeIcon($ff['field_type']) ?>
                                        <?= $this->fieldTypeLabel($ff['field_type']) ?>
                                    </span>
                                </td>
                                <td title="<?= \App\Core\App::html()->escape($ff['options'] ?? '') ?>" class="preformatted">
                                    <?php
                                    $opts = $ff['options'] ?? '';
                        if ((bool) ($opts)) {
                            $decoded = json_decode($opts, true);
                            if (is_array($decoded)) {
                                echo \App\Core\App::html()->escape(implode(', ', $decoded));
                            } else {
                                echo \App\Core\App::html()->escape($opts);
                            }
                        } else {
                            echo '—';
                        }
                        ?>
                                </td>
                                <td class="actions">
                                    <a href="index.php?p=admin_forms&form_id=<?= $form_id ?>&edit_field=<?= $ff['id'] ?>#field-<?= $ff['id'] ?>" class="btn btn-secondary btn-compact-3">Modifier</a>
                                    <form method="POST" class="u-dis-2">
                                        <?= \App\Core\App::security()->csrfField() ?>
                                        <input type="hidden" name="action" value="delete_field">
                                        <input type="hidden" name="field_id" value="<?= $ff['id'] ?>">
                                        <input type="hidden" name="form_id" value="<?= $form_id ?>">
                                        <button type="submit" class="btn btn-danger btn-compact-3" data-confirm="Supprimer ce champ ? Les données associées seront perdues.">Supprimer</button>
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
                        <?php if ((bool) ($existing_groups)): ?>
                            <select name="ff_card_group">
                                <?php foreach ($existing_groups as $g): ?>
                                    <option value="<?= \App\Core\App::html()->escape($g) ?>" <?= $g === 'Général' ? 'selected' : '' ?>><?= \App\Core\App::html()->escape($g) ?></option>
                                <?php endforeach; ?>
                                <option value="__new__">— Nouveau groupe —</option>
                            </select>
                        <?php else: ?>
                            <input type="hidden" name="ff_card_group" value="">
                        <?php endif; ?>
                        <input type="text" name="ff_card_group_new" placeholder="Nom du nouveau groupe" value="" class="mt-3">
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
                                <option value="<?= \App\Core\App::html()->escape($s['id']) ?>">
                                    <?= \App\Core\App::html()->escape($s['label']) ?>
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
                <div class="field mt-25">
                    <label class="checkbox-label">
                        <input type="checkbox" name="ff_required"> Champ obligatoire <span class="required-star">*</span>
                    </label>
                </div>
                <button type="submit" class="btn btn-primary mt-5">Ajouter le champ</button>
            </form>
        </div>
    </div>
</div>
