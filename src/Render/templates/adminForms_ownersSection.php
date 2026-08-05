<!-- Section Propriétaires du formulaire -->
<div class="section-card" id="owners">
    <div class="section-card-header">
        <h2>👥 Propriétaires du formulaire</h2>
    </div>
    <div class="section-card-body">
    <p class="hint mb-1">Les propriétaires peuvent accéder au tableau de suivi spécifique de ce formulaire via la page <a href="index.php?p=form_tracking&f=<?= \App\Core\App::html()->escape($form['id'] ?? '') ?>">Suivi propriétaire</a>.</p>

    <?php if ((bool)($owners)): ?>
        <table class="data-table mb-1">
            <thead>
                <tr>
                    <th>Courriel</th>
                    <th>Ajouté le</th>
                    <th class="u-wid-2">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($owners as $owner): ?>
                <tr>
                    <td><?= \App\Core\App::html()->displayUser($owner['email']) ?></td>
                    <td><?= \App\Core\App::html()->escape($owner['added_at']) ?></td>
                    <td>
                        <a href="index.php?p=confirm_action&action=remove_owner&id=<?= $owner['id'] ?>&form_id=<?= $form_id ?>" class="btn btn-sm btn-danger">Retirer</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="heading-colored-2">Aucun propriétaire défini. Seuls les administrateurs peuvent voir le tableau de suivi.</p>
    <?php endif; ?>

    <form method="POST" action="index.php?p=admin_forms&form_id=<?= $form_id ?>#owners">
        <?= \App\Core\App::security()->csrfField() ?>
        <input type="hidden" name="action" value="add_owner">
        <input type="hidden" name="form_id" value="<?= $form_id ?>">
        <div class="flex-gap5-6">
            <input type="email" name="owner_email" placeholder="prenom.nom@<?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('email_domain', 'exemple.invalid')) ?>" required class="flex-1">
            <button type="submit" class="btn btn-primary">Ajouter un propriétaire</button>
        </div>
    </form>

    <?php if ((bool)($owners)): ?>
        <div class="mt-1">
            <a href="index.php?p=form_tracking&f=<?= \App\Core\App::html()->escape($form['id'] ?? '') ?>" class="btn btn-secondary"><span aria-hidden="true">📊</span> Ouvrir le tableau de suivi</a>
        </div>
    <?php endif; ?>
    </div>
</div>
