<!-- ═══════════════════════════════════════════════════════════ -->
<!-- SECTION 1 : Sécurité email — Dry-Run + Vérification       -->
<!-- ═══════════════════════════════════════════════════════════ -->
<form method="POST">
    <?= \App\Core\App::security()->csrfField() ?>
    <input type="hidden" name="action" value="save_email_verify">

    <div class="card" id="section-email-security">
        <h2><span class="icon">🛡️</span> Sécurité email</h2>
        <p class="caption-2">
            Protégez contre l'envoi accidentel d'emails à des adresses non vérifiées.
            Le mode <strong>Dry-Run</strong> intercepte tous les envois (recommandé en phase de déploiement).
            La <strong>vérification des destinataires</strong> bloque les envois vers des adresses introuvables.
        </p>

        <!-- Dry-Run -->
        <div class="field styled-box-3">
            <label class="checkbox-label u-fon-fon-2">
                <input type="checkbox" name="mail_dry_run" <?= $mail_dry_run === '1' ? 'checked' : '' ?>>
                Mode Dry-Run (aucun email réel envoyé)
            </label>
            <p class="caption-5">
                Quand activé, <code>send_mail()</code> journalise chaque envoi dans l'audit log sans contacter le serveur SMTP.
                Idéal pour valider la configuration avant mise en production.
                Le workflow continue normalement (les tokens sont créés, les étapes avancent).
            </p>
        </div>

        <!-- Mode de vérification -->
        <div class="field mt-15-2">
            <label>Vérification des adresses destinataires</label>
            <select name="email_verify_mode" id="email_verify_mode" class="u-max-4">
                <option value="none" <?= $email_verify_mode === 'none' ? 'selected' : '' ?>>Aucune vérification</option>
                <option value="ldap" <?= $email_verify_mode === 'ldap' ? 'selected' : '' ?>>LDAP / Active Directory</option>
                <option value="smtp" <?= $email_verify_mode === 'smtp' ? 'selected' : '' ?>>SMTP (probe RCPT TO)</option>
            </select>
            <span class="hint">
                Avant chaque envoi, le système vérifie que l'adresse du destinataire existe.
                <strong>LDAP</strong> = interrogation de l'AD (fiable, recommandé si disponible).
                <strong>SMTP</strong> = probe du serveur mail (moins fiable, certains serveurs acceptent tout).
            </span>
        </div>

        <!-- Configuration LDAP (affichée si mode ldap) -->
        <div id="ldap-config" class="config-block <?= $email_verify_mode !== 'ldap' ? 'config-hidden' : '' ?>">
            <h3 class="heading-primary">Configuration LDAP / Active Directory</h3>

            <?php if (!$ldap_ext_available): ?>
                <div class="warning-box mb-1">
                    <strong>Extension LDAP non détectée</strong> — L'extension PHP <code>ldap</code> n'est pas installée ou activée.
                    Contactez l'administrateur système pour l'activer (habituellement <code>extension=ldap</code> dans <code>php.ini</code>).
                    Sur IIS/Windows, l'extension est souvent présente mais désactivée par défaut.
                </div>
            <?php else: ?>
                <div class="info-box mb-1">
                    <strong>Extension LDAP disponible</strong> — La vérification Active Directory est opérationnelle.
                </div>
            <?php endif; ?>

            <div class="field">
                <label>Hôte LDAP <span class="info-tooltip" title="Adresse de l'annuaire d'entreprise (ex: ldap.exemple.invalid)" aria-label="Aide technique : Adresse de l'annuaire d'entreprise (ex: ldap.exemple.invalid)" tabindex="0" role="button">ℹ️</span> <span class="hint">(ex: ldap.exemple.invalid ou votre contrôleur de domaine)</span></label>
                <input type="text" name="ldap_host" value="<?= \App\Core\App::html()->escape($ldap_host) ?>" placeholder="ldap.exemple.invalid">
            </div>

            <div class="field">
                <label>Port LDAP <span class="info-tooltip" title="Port LDAP (389=standard, 636=chiffré)" aria-label="Aide technique : Port LDAP (389=standard, 636=chiffré)" tabindex="0" role="button">ℹ️</span></label>
                <input type="number" name="ldap_port" value="<?= \App\Core\App::html()->escape($ldap_port) ?>" min="1" max="65535" class="u-max-2">
                <span class="hint">389 = standard, 636 = LDAPS (chiffré)</span>
            </div>

            <div class="field">
                <label>Base DN <span class="info-tooltip" title="Base de recherche LDAP (ex: DC=dreets,DC=gouv,DC=fr)" aria-label="Aide technique : Base de recherche LDAP (ex: DC=dreets,DC=gouv,DC=fr)" tabindex="0" role="button">ℹ️</span> <span class="hint">(racine de la recherche dans l'annuaire)</span></label>
                <input type="text" name="ldap_base_dn" value="<?= \App\Core\App::html()->escape($ldap_base_dn) ?>" placeholder="DC=dreets,DC=gouv,DC=fr">
            </div>

            <div class="field">
                <label>Bind DN <span class="info-tooltip" title="Compte de service pour LDAP (ex: CN=svc_workflow,OU=ServiceAccounts,DC=dreets,DC=gouv,DC=fr)" aria-label="Aide technique : Compte de service pour LDAP (ex: CN=svc_workflow,OU=ServiceAccounts,DC=dreets,DC=gouv,DC=fr)" tabindex="0" role="button">ℹ️</span> <span class="hint">(compte de service en lecture seule — laisser vide pour bind anonyme)</span></label>
                <input type="text" name="ldap_bind_dn" value="<?= \App\Core\App::html()->escape($ldap_bind_dn) ?>" placeholder="CN=svc_workflow,OU=ServiceAccounts,DC=dreets,DC=gouv,DC=fr">
            </div>

            <div class="field">
                <label>Mot de passe Bind <span class="hint">(laisser vide pour conserver l'actuel)</span></label>
                <input type="password" name="ldap_bind_pass" placeholder="<?= $ldap_bind_pass !== '' && $ldap_bind_pass !== '0' ? '••••••••' : '' ?>">
            </div>

            <div class="field">
                <label>Filtre de recherche <span class="hint">({email} sera remplacé par l'adresse à vérifier)</span></label>
                <input type="text" name="ldap_filter" value="<?= \App\Core\App::html()->escape($ldap_filter) ?>" placeholder="(mail={email})">
            </div>

            <hr class="u-bor-bor-mar-2">

            <h3 class="heading-primary">Suggestions d'emails (autocomplétion)</h3>
            <p class="caption-4">
                Active la suggestion d'adresses email issues de l'annuaire LDAP dans les champs courriel des formulaires et lors de l'ajout de destinataires.
                <strong>Pur HTML5</strong> — utilise l'élément <code>&lt;datalist&gt;</code> natif du navigateur, aucun JavaScript requis.
                L'agent commence à taper et le navigateur propose les adresses correspondantes.
            </p>
            <div class="field">
                <label class="checkbox-label fw-bold">
                    <input type="checkbox" name="ldap_suggest_enabled" <?= $ldap_suggest_enabled === '1' ? 'checked' : '' ?>>
                    Activer les suggestions LDAP sur les champs courriel
                </label>
                <p class="caption-5">
                    Quand activé, les champs de type « Courriel » dans les formulaires publics et le champ « Ajouter un destinataire » dans l'administration
                    proposeront automatiquement les adresses de l'annuaire. Les résultats sont mis en cache 30 minutes pour ne pas surcharger le serveur LDAP.
                </p>
            </div>
            <div class="field">
                <label>Filtre de suggestion <span class="hint">({query} sera remplacé par le terme de recherche)</span></label>
                <input type="text" name="ldap_suggest_filter" value="<?= \App\Core\App::html()->escape($ldap_suggest_filter) ?>" placeholder="(|(cn=*{query}*)(mail=*{query}*)(sn=*{query}*)(givenName=*{query}*))">
                <span class="hint">Filtre LDAP pour la recherche d'autocomplétion. Par défaut, cherche sur le nom complet (cn), l'email, le nom de famille (sn) et le prénom (givenName).</span>
            </div>
        </div>

        <!-- Info SMTP verification -->
        <div id="smtp-info" class="config-block <?= $email_verify_mode !== 'smtp' ? 'config-hidden' : '' ?>">
            <h3 class="heading-primary">Vérification SMTP (probe RCPT TO)</h3>
            <p class="caption-9">
                Le système se connecte au serveur SMTP configuré ci-dessous, envoie les commandes
                <code>HELO</code>, <code>MAIL FROM</code>, <code>RCPT TO</code> et vérifie si le serveur
                accepte l'adresse destinataire. La connexion est refermée proprement avant d'envoyer
                le contenu du mail (<code>QUIT</code> avant <code>DATA</code>).
            </p>
            <div class="warning-box mt-1">
                <strong>Limitation</strong> — Certains serveurs SMTP (notamment Exchange) acceptent
                toutes les adresses en <code>RCPT TO</code> (mode catch-all) et ne renvoient une erreur
                qu'au moment du <code>DATA</code>. Dans ce cas, la vérification SMTP ne détectera pas
                les adresses inexistantes. Préférez le mode LDAP si votre infrastructure le permet.
            </div>
        </div>

        <div class="mt-15-2">
            <button type="submit" class="btn btn-primary">Enregistrer la sécurité email</button>
        </div>
    </div>
</form>
