<!-- ══════════════════════════════════════════════════ -->
<!-- SECTION A: Form info                             -->
<!-- ══════════════════════════════════════════════════ -->
<div class="section-card">
    <div class="section-card-header">
        <h2><span aria-hidden="true">📋</span> Informations du formulaire</h2>
        <form method="POST" class="u-dis-2">
            <?= \App\Core\App::security()->csrfField() ?>
            <input type="hidden" name="action" value="duplicate_form">
            <input type="hidden" name="source_form_id" value="<?= $form['id'] ?>">
            <button type="submit" class="btn btn-secondary btn-xs-4"><span aria-hidden="true">📋</span> Dupliquer</button>
        </form>
        <form method="POST" class="u-dis-2">
            <?= \App\Core\App::security()->csrfField() ?>
            <input type="hidden" name="action" value="delete_form">
            <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
            <button type="submit" data-confirm="Supprimer ce formulaire et toutes ses données ? Cette action est irréversible." class="styled-box-6">Supprimer</button>
        </form>
    </div>
    <div class="section-card-body">
        <form method="POST">
            <?= \App\Core\App::security()->csrfField() ?>
            <input type="hidden" name="action" value="update_form">
            <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
            <div class="form-grid">
                <div class="field">
                    <label>Libellé (affiché dans l'interface)<span class="req">*</span></label>
                    <input type="text" name="label" value="<?= \App\Core\App::html()->escape($form['label']) ?>" required>
                    <span class="hint">Identifiant technique : <code><?= \App\Core\App::html()->escape($form['slug']) ?></code> (généré automatiquement)</span>
                </div>
                <div class="field full-width">
                    <label>Description</label>
                    <textarea name="description" placeholder="Description du formulaire"><?= \App\Core\App::html()->escape($form['description']) ?></textarea>
                </div>
                <div class="field">
                    <label class="checkbox-label">
                        <input type="checkbox" name="actif" <?= $form['actif'] ? 'checked' : '' ?>> Formulaire actif
                    </label>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Enregistrer</button>
        </form>
    </div>
</div>
