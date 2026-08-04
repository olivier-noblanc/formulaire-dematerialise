<!-- ── New form creation ──────────────────────────────────── -->
<div class="section-card">
    <div class="section-card-header">
        <h2><span aria-hidden="true">📋</span> Créer un nouveau formulaire</h2>
    </div>
    <div class="section-card-body">
        <form method="POST">
            <?= \App\Core\App::security()->csrfField() ?>
            <input type="hidden" name="action" value="add_form">
            <div class="form-grid">
                <div class="field">
                    <label>Libellé (affiché dans l'interface)<span class="req">*</span></label>
                    <input type="text" name="label" required placeholder="ex: Accueil agent" autofocus>
                    <span class="hint">L'identifiant technique (slug) est généré automatiquement à partir du libellé.</span>
                </div>
                <div class="field full-width">
                    <label>Description</label>
                    <textarea name="description" placeholder="Description du formulaire"></textarea>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Créer le formulaire</button>
        </form>
    </div>
</div>
