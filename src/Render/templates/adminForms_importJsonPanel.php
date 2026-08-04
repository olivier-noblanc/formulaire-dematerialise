<!-- ── Import JSON panel ──────────────────────────────────── -->
<div id="import-panel" class="<?= !empty($preserved_json) ? '' : 'hidden' ?> mb-15">
    <div class="section-card">
        <div class="section-card-header">
            <h2><span aria-hidden="true">📥</span> Importer un formulaire depuis JSON</h2>
        </div>
        <div class="section-card-body">
            <p class="caption-3">Collez un JSON décrivant un formulaire <strong>et son circuit de validation</strong> (exporté depuis cette page ou généré par une IA). Le format attendu : <code>{ "form": { "label": "..." }, "fields": [...], "steps": [...] }</code></p>

            <?php if (!empty($validation_html)): ?>
                <?= $validation_html ?>
            <?php endif; ?>

            <form method="POST">
                <?= \App\Core\App::security()->csrfField() ?>
                <div class="field">
                    <label>Données JSON<span class="req">*</span></label>
                    <textarea name="json_data" rows="12" placeholder='{"schema_version":"1.0","form":{"label":"Mon formulaire","description":"..."},"fields":[{"label":"Nom","field_type":"text","field_name":"nom","required":1,"card_group":"Général","filled_by":"demandeur"},{"label":"Décision","field_type":"select","field_name":"decision","options":["Accepté","Refusé"],"required":1,"card_group":"Décision","filled_by":"validator","validator_step":"Validation manager"}],"steps":[{"label":"Validation manager","ordre":1,"recipients":["manager@<?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('email_domain', 'exemple.invalid')) ?>"]}]}' class="u-fon-fon"><?= \App\Core\App::html()->escape($preserved_json) ?></textarea>
                </div>
                <div class="flex-gap5-7">
                    <input type="hidden" name="action" value="validate_json" id="import-action-input">
                    <button type="submit" class="btn btn-secondary u-fon-2"><span aria-hidden="true">🔍</span> Valider le JSON</button>
                    <button type="submit" class="btn btn-primary u-fon-2" data-set-input="#import-action-input=import_form"><span aria-hidden="true">📥</span> Importer le formulaire</button>
                </div>
            </form>
        </div>
    </div>

</div>
