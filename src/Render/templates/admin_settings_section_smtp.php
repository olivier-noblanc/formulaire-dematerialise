<!-- ═══════════════════════════════════════════════════════════ -->
<!-- SECTION 3 : Configuration SMTP + Admin + Workflow         -->
<!-- ═══════════════════════════════════════════════════════════ -->
<form method="POST">
    <?= \App\Core\App::security()->csrfField() ?>
    <input type="hidden" name="action" value="save_settings">

    <div class="card">
        <h2>Identité de l'application</h2>

        <div class="field">
            <label for="app_name">Nom de l'application</label>
            <input type="text" id="app_name" name="app_name" value="<?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('app_name', 'CircuitDémat')) ?>" placeholder="CircuitDémat">
            <span class="hint">Ce nom est affiché dans la barre latérale, les titres de pages, les emails et le pied de page. Modifiable à tout moment.</span>
        </div>

        <div class="field">
            <label for="app_favicon">Favicon (SVG)</label>
            <textarea id="app_favicon" name="app_favicon" rows="3" placeholder="<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'>...</svg>" class="u-fon-fon"><?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('app_favicon', '')) ?></textarea>
            <span class="hint">Code SVG du favicon. Laisser vide pour le favicon par défaut (losange bleu avec la première lettre du nom). Le contenu est inséré dans <code>data:image/svg+xml,</code> — ne pas mettre l'en-tête <code>&lt;?xml</code> ni échapper les caractères.</span>
        </div>
    </div>

    <div class="card" id="section-admin">
        <h2>Administration</h2>

        <div class="field">
            <label for="admin_email">Email de l'administrateur principal</label>
            <input type="email" id="admin_email" name="admin_email" value="<?= \App\Core\App::html()->escape(\App\Core\App::auth()->getAdminEmail()) ?>" placeholder="prenom.nom@<?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('email_domain', 'exemple.invalid')) ?>" required>
            <span class="hint">Cet utilisateur est super-administrateur et reçoit les demandes d'accès. Modifiable depuis la base de données si l'accès est perdu.</span>
        </div>
    </div>

    <div class="card" id="section-smtp">
        <h2>Configuration SMTP</h2>

        <div class="field">
            <label>SMTP Hôte <span class="info-tooltip" title="Adresse du serveur email (ex: smtp.social.gouv.fr)" aria-label="Aide technique : Adresse du serveur email (ex: smtp.social.gouv.fr)" tabindex="0" role="button">ℹ️</span></label>
            <input type="text" name="smtp_host" value="<?= \App\Core\App::html()->escape($smtp_host) ?>" placeholder="smtp.example.fr">
        </div>

        <div class="field">
            <label>SMTP Port <span class="info-tooltip" title="Port du serveur email (25=standard, 587=chiffré, 465=SSL)" aria-label="Aide technique : Port du serveur email (25=standard, 587=chiffré, 465=SSL)" tabindex="0" role="button">ℹ️</span></label>
            <input type="number" name="smtp_port" value="<?= \App\Core\App::html()->escape($smtp_port) ?>" min="1" max="65535">
        </div>

        <div class="field">
            <label class="checkbox-label">
                <input type="checkbox" name="smtp_auth" <?= $smtp_auth === '1' ? 'checked' : '' ?>>
                Authentification SMTP
            </label>
        </div>

        <div class="field">
            <label>Chiffrement</label>
            <select name="smtp_secure">
                <option value="" <?= $smtp_secure === '' ? 'selected' : '' ?>>Aucun</option>
                <option value="tls" <?= $smtp_secure === 'tls' ? 'selected' : '' ?>>TLS</option>
                <option value="ssl" <?= $smtp_secure === 'ssl' ? 'selected' : '' ?>>SSL</option>
            </select>
        </div>

        <div class="field">
            <label>Utilisateur SMTP <span class="hint">(utilisé uniquement si l'authentification est activée)</span></label>
            <input type="text" name="smtp_user" value="<?= \App\Core\App::html()->escape($smtp_user) ?>" placeholder="utilisateur@exemple.fr">
        </div>

        <div class="field">
            <label>Mot de passe SMTP <span class="hint">(laisser vide pour conserver l'actuel)</span></label>
            <input type="password" name="smtp_pass" placeholder="<?= $smtp_pass !== '' && $smtp_pass !== '0' ? '••••••••' : '' ?>">
        </div>

        <div class="field">
            <label>Email expéditeur <span class="info-tooltip" title="Adresse email d'expéditeur (ex: workflow@exemple.invalid)" aria-label="Aide technique : Adresse email d'expéditeur (ex: workflow@exemple.invalid)" tabindex="0" role="button">ℹ️</span></label>
            <input type="text" name="smtp_from" value="<?= \App\Core\App::html()->escape($smtp_from) ?>" placeholder="workflow@<?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('email_domain', 'exemple.invalid')) ?>">
        </div>

        <div class="field">
            <label>Nom expéditeur <span class="info-tooltip" title="Nom affiché pour l'expéditeur (ex: CircuitDémat)" aria-label="Aide technique : Nom affiché pour l'expéditeur (ex: CircuitDémat)" tabindex="0" role="button">ℹ️</span></label>
            <input type="text" name="smtp_from_name" value="<?= \App\Core\App::html()->escape($smtp_from_name) ?>" placeholder="CircuitDémat">
        </div>
    </div>

    <!-- Paramètres du workflow -->
    <div class="card" id="section-workflow">
        <h2>Paramètres du workflow</h2>

        <div class="field">
            <label>Délai de relance en heures <span class="info-tooltip" title="Délai en heures avant envoi d'un rappel (ex: 48 pour 2 jours)" aria-label="Aide technique : Délai en heures avant envoi d'un rappel (ex: 48 pour 2 jours)" tabindex="0" role="button">ℹ️</span></label>
            <input type="number" name="delai_relance_h" value="<?= \App\Core\App::html()->escape($delai_relance_h) ?>" min="1">
        </div>

        <div class="field">
            <label>Expiration des tokens en jours <span class="info-tooltip" title="Durée de validité des liens de validation en jours (ex: 30)" aria-label="Aide technique : Durée de validité des liens de validation en jours (ex: 30)" tabindex="0" role="button">ℹ️</span></label>
            <input type="number" name="token_expire_days" value="<?= \App\Core\App::html()->escape($token_expire_days) ?>" min="1">
        </div>

        <div class="field">
            <label>Nombre maximum de relances par token <span class="hint">(0 = illimité)</span></label>
            <input type="number" name="relance_max" value="<?= \App\Core\App::html()->escape($relance_max) ?>" min="0">
        </div>

        <div class="field">
            <label>Durée de conservation des demandes (mois) <span class="info-tooltip" title="Durée de conservation des demandes en mois (ex: 24 pour 2 ans)" aria-label="Aide technique : Durée de conservation des demandes en mois (ex: 24 pour 2 ans)" tabindex="0" role="button">ℹ️</span></label>
            <input type="number" name="retention_months" value="<?= \App\Core\App::html()->escape($retention_months) ?>" min="1" max="120">
            <span class="hint">Conformité RGPD : les demandes clôturées sont purgées automatiquement après cette durée (voir <a href="index.php?p=rgpd">Protection des données</a>).</span>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Enregistrer les paramètres</button>
        <a href="index.php?p=dashboard" class="btn btn-secondary">Retour au tableau de bord</a>
    </div>
</form>
