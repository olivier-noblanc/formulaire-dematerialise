<!-- ── Top action bar ──────────────────────────────── -->
<div class="flex-gap75">
    <a href="index.php?p=form_preview&form_id=<?= $form_id ?>" class="btn-preview" target="_blank"><span aria-hidden="true">👁</span> Prévisualiser le formulaire</a>
    <form method="POST" class="u-dis-2">
        <?= \App\Core\App::security()->csrfField() ?>
        <input type="hidden" name="action" value="export_form">
        <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
        <button type="submit" class="btn btn-secondary btn-xs-4"><span aria-hidden="true">📤</span> Exporter JSON</button>
    </form>
    <a href="index.php?p=dashboard" class="btn btn-secondary">← Tableau de bord</a>
</div>
