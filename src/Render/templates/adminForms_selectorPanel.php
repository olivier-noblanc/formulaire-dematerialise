<!-- ── Form selector ──────────────────────────────────────── -->
<div class="form-selector">
    <form method="GET" class="u-ali-dis-gap">
        <select name="form_id">
            <option value="">— Sélectionner un formulaire —</option>
            <?php foreach ($forms as $f): ?>
                <option value="<?= $f['id'] ?>" <?= $form_id == $f['id'] ? 'selected' : '' ?>>
                    <?= \App\Core\App::html()->escape($f['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm-3">OK</button>
    </form>
    <a href="index.php?p=admin_forms" class="btn btn-primary">＋ Nouveau formulaire</a>
    <button type="button" data-toggle="#import-panel" class="btn btn-secondary btn-sm-3"><span aria-hidden="true">📥</span> Importer JSON</button>
    <button type="button" data-toggle="#ai-prompt-panel" class="btn btn-secondary btn-sm-3"><span aria-hidden="true">🤖</span> Prompt IA</button>
    <form method="POST" class="u-dis-2">
        <?= \App\Core\App::security()->csrfField() ?>
        <input type="hidden" name="action" value="populate_samples">
        <button type="submit" class="btn btn-secondary btn-sm-3"><span aria-hidden="true">📦</span> Formulaires exemples</button>
    </form>
</div>
