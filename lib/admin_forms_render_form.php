<?php
declare(strict_types=1);

/**
 * Sections "Formulaire" de la page admin_forms.php.
 *
 * Extrait de {@see render_admin_forms_page()} — contient :
 *  - {@see render_top_action_bar()}       : barre d'actions (aperçu,
 *    export JSON, retour tableau de bord).
 *  - {@see render_form_info_section()}    : SECTION A — infos du
 *    formulaire (libellé, description, actif) + duplication/suppression.
 *  - {@see render_owners_section()}       : section « Propriétaires du
 *    formulaire » (gestion des propriétaires + lien suivi propriétaire).
 *
 * @package lib
 */

/**
 * Barre d'actions supérieure : prévisualisation, export JSON, retour
 * tableau de bord. Affichée uniquement si un formulaire est sélectionné.
 *
 * @param array<string,mixed> $ctx Contexte (clés utilisées : form_id, form)
 * @return string HTML de la barre d'actions
 */
function render_top_action_bar(array $ctx): string {
    $form_id = $ctx['form_id'] ?? '';
    $form    = $ctx['form']    ?? null;
    if (!$form) {
        return '';
    }

    ob_start();
    ?>
    <!-- ── Top action bar ──────────────────────────────── -->
    <div style="display:flex;gap:.75rem;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;">
        <a href="index.php?p=form_preview&form_id=<?= $form_id ?>" class="btn-preview" target="_blank"><span aria-hidden="true">👁</span> Prévisualiser le formulaire</a>
        <form method="POST" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="export_form">
            <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
            <button type="submit" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;"><span aria-hidden="true">📤</span> Exporter JSON</button>
        </form>
        <a href="index.php?p=dashboard" class="btn btn-secondary">← Tableau de bord</a>
    </div>
    <?php
    $html = ob_get_clean();
    return $html === false ? '' : $html;
}

/**
 * SECTION A : Informations du formulaire (libellé, description, actif)
 * + actions dupliquer/supprimer.
 *
 * @param array<string,mixed> $ctx Contexte (clés utilisées : form)
 * @return string HTML de la section A
 */
function render_form_info_section(array $ctx): string {
    $form = $ctx['form'] ?? null;
    if (!$form) {
        return '';
    }

    ob_start();
    ?>
    <!-- ══════════════════════════════════════════════════ -->
    <!-- SECTION A: Form info                             -->
    <!-- ══════════════════════════════════════════════════ -->
    <div class="section-card">
        <div class="section-card-header">
            <h2><span aria-hidden="true">📋</span> Informations du formulaire</h2>
            <form method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="duplicate_form">
                <input type="hidden" name="source_form_id" value="<?= $form['id'] ?>">
                <button type="submit" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;"><span aria-hidden="true">📋</span> Dupliquer</button>
            </form>
            <form method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_form">
                <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                <button type="submit" style="background:#c0392b;color:#fff;border:none;border-radius:3px;padding:.3rem .7rem;cursor:pointer;font-size:.8rem;font-family:inherit;" onclick="return confirm('Supprimer ce formulaire et toutes ses données ? Cette action est irréversible.');">Supprimer</button>
            </form>
        </div>
        <div class="section-card-body">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_form">
                <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                <div class="form-grid">
                    <div class="field">
                        <label>Libellé (affiché dans l'interface)<span class="req">*</span></label>
                        <input type="text" name="label" value="<?= h($form['label']) ?>" required>
                        <span class="hint">Identifiant technique : <code><?= h($form['slug']) ?></code> (généré automatiquement)</span>
                    </div>
                    <div class="field full-width">
                        <label>Description</label>
                        <textarea name="description" placeholder="Description du formulaire"><?= h($form['description']) ?></textarea>
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
    <?php
    $html = ob_get_clean();
    return $html === false ? '' : $html;
}

/**
 * Section « Propriétaires du formulaire » : liste/ajout/retrait des
 * propriétaires + accès au tableau de suivi propriétaire.
 *
 * @param array<string,mixed> $ctx Contexte (clés utilisées : form, form_id, owners)
 * @return string HTML de la section propriétaires
 */
function render_owners_section(array $ctx): string {
    $form    = $ctx['form']    ?? null;
    $form_id = $ctx['form_id'] ?? '';
    $owners  = $ctx['owners']  ?? [];
    if (!$form) {
        return '';
    }

    ob_start();
    ?>
    <!-- Section Propriétaires du formulaire -->
    <div class="section-card" id="owners">
        <div class="section-card-header">
            <h2>👥 Propriétaires du formulaire</h2>
        </div>
        <div class="section-card-body">
        <p class="hint" style="margin-bottom:1rem;">Les propriétaires peuvent accéder au tableau de suivi spécifique de ce formulaire via la page <a href="index.php?p=form_tracking&f=<?= h($form['id'] ?? '') ?>">Suivi propriétaire</a>.</p>

        <?php if (!empty($owners)): ?>
            <table class="data-table" style="margin-bottom:1rem;">
                <thead>
                    <tr>
                        <th>Courriel</th>
                        <th>Ajouté le</th>
                        <th style="width:80px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($owners as $owner): ?>
                    <tr>
                        <td><?= display_user($owner['email']) ?></td>
                        <td><?= h($owner['added_at']) ?></td>
                        <td>
                            <a href="index.php?p=confirm_action&action=remove_owner&id=<?= $owner['id'] ?>&form_id=<?= $form_id ?>" class="btn btn-sm btn-danger">Retirer</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color:#595959;font-style:italic;margin-bottom:1rem;">Aucun propriétaire défini. Seuls les administrateurs peuvent voir le tableau de suivi.</p>
        <?php endif; ?>

        <form method="POST" action="index.php?p=admin_forms&form_id=<?= $form_id ?>#owners">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_owner">
            <input type="hidden" name="form_id" value="<?= $form_id ?>">
            <div style="display:flex;gap:.5rem;align-items:center;">
                <input type="email" name="owner_email" placeholder="prenom.nom@<?= h(get_setting('email_domain', 'dreets.gouv.fr')) ?>" required style="flex:1;">
                <button type="submit" class="btn btn-primary">Ajouter un propriétaire</button>
            </div>
        </form>

        <?php if (!empty($owners)): ?>
            <div style="margin-top:1rem;">
                <a href="index.php?p=form_tracking&f=<?= h($form['id'] ?? '') ?>" class="btn btn-secondary"><span aria-hidden="true">📊</span> Ouvrir le tableau de suivi</a>
            </div>
        <?php endif; ?>
        </div>
    </div>
    <?php
    $html = ob_get_clean();
    return $html === false ? '' : $html;
}
