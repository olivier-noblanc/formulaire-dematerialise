<?php
declare(strict_types=1);

namespace App\Render;

/**
 * Render de la page admin_settings.php (Paramètres admin).
 *
 * Absorbe les 3 fonctions de lib/render_admin_settings.php en une classe OOP.
 */
final class AdminSettingsRenderer
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * CSS propre à la page admin_settings.php.
     */
    public function getPageCss(): string
    {
        static $css = null;
        if ($css === null) {
            $css = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/admin_settings_page.css');
        }
        return $css;
    }

    /**
     * Compose le contenu HTML de la page admin_settings.php.
     */
    public function renderContent(AdminSettingsContext $state): string
    {
        $success_msg   = $state->success;
        $error_msg     = $state->error;
        $test_msg      = $state->test;
        $verify_result = $state->verify_result;

        // Lecture des paramètres actuels
        $smtp_host             = \App\Core\App::settings()->get('smtp_host');
        $smtp_port             = \App\Core\App::settings()->get('smtp_port');
        $smtp_auth             = \App\Core\App::settings()->get('smtp_auth', '0');
        $smtp_secure           = \App\Core\App::settings()->get('smtp_secure', '');
        $smtp_user             = \App\Core\App::settings()->get('smtp_user', '');
        $smtp_pass             = \App\Core\App::settings()->get('smtp_pass', '');
        $smtp_from             = \App\Core\App::settings()->get('smtp_from');
        $smtp_from_name        = \App\Core\App::settings()->get('smtp_from_name');
        $delai_relance_h       = \App\Core\App::settings()->get('delai_relance_h');
        $token_expire_days     = \App\Core\App::settings()->get('token_expire_days', '30');
        $relance_max           = \App\Core\App::settings()->get('relance_max', '3');
        $retention_months      = \App\Core\App::settings()->get('retention_months', '24');
        $mail_dry_run          = \App\Core\App::settings()->get('mail_dry_run', '1');
        $email_verify_mode     = \App\Core\App::settings()->get('email_verify_mode', 'none');
        $ldap_host             = \App\Core\App::settings()->get('ldap_host', '');
        $ldap_port             = \App\Core\App::settings()->get('ldap_port', '389');
        $ldap_base_dn          = \App\Core\App::settings()->get('ldap_base_dn', '');
        $ldap_bind_dn          = \App\Core\App::settings()->get('ldap_bind_dn', '');
        $ldap_bind_pass        = \App\Core\App::settings()->get('ldap_bind_pass', '');
        $ldap_filter           = \App\Core\App::settings()->get('ldap_filter', '(mail={email})');
        $ldap_suggest_enabled  = \App\Core\App::settings()->get('ldap_suggest_enabled', '0');
        $ldap_suggest_filter   = \App\Core\App::settings()->get('ldap_suggest_filter', '(|(cn=*{query}*)(mail=*{query}*)(sn=*{query}*)(givenName=*{query}*))');

        $ldap_ext_available = function_exists('ldap_connect');

        ob_start();
        ?>
        <h1>⚙ Paramètres</h1>

        <nav class="anchor-nav" aria-label="Navigation des sections">
          <a href="#section-email-security">🛡️ Sécurité</a>
          <a href="#section-email-test">🧪 Test vérif.</a>
          <a href="#section-admin">👤 Admin</a>
          <a href="#section-smtp">📧 SMTP</a>
          <a href="#section-workflow">⚙️ Workflow</a>
          <a href="#section-email-send">📤 Test envoi</a>
          <a href="#section-email-summary">📋 Résumé</a>
        </nav>

        <?= new ErrorRenderer()->messages(['success' => $success_msg, 'error' => $error_msg, 'info' => $test_msg]) ?>

        <?php if ($mail_dry_run === '1'): ?>
            <div class="warning-box">
                <strong>Mode Dry-Run actif</strong> — Aucun email réel n'est envoyé. Tous les envois sont journalisés dans l'audit log mais ne quittent pas le serveur. Désactivez ce mode uniquement lorsque la configuration SMTP et les adresses destinataires sont vérifiées.
            </div>
        <?php endif; ?>

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

        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- SECTION 2 : Test de vérification email                    -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <div class="card" id="section-email-test">
            <h2><span class="icon">🧪</span> Test de vérification email</h2>
            <p class="caption-2">
                Testez la vérification d'une adresse email avec la configuration actuelle.
                Cela permet de vérifier que le LDAP ou la probe SMTP fonctionne correctement
                avant d'activer la vérification en production.
            </p>
            <form method="POST">
                <?= \App\Core\App::security()->csrfField() ?>
                <input type="hidden" name="action" value="test_verify_email">
                <div class="field">
                    <label>Adresse email à tester</label>
                    <div class="flex-gap5-3">
                        <input type="email" name="verify_test_email" value="<?= \App\Core\App::html()->escape($_POST['verify_test_email'] ?? '') ?>" placeholder="agent@<?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('email_domain', 'exemple.invalid')) ?>" class="u-max-3">
                        <button type="submit" class="btn btn-test">Vérifier cette adresse</button>
                    </div>
                </div>
            </form>

            <?php if ($verify_result !== null): ?>
                <?php $vr = $verify_result; ?>
                <div class="verify-result <?= $vr['verify']['ok'] ? 'ok' : 'fail' ?>">
                    <strong><?= $vr['verify']['ok'] ? '✔ Adresse vérifiée' : '✘ Adresse NON vérifiée' ?></strong>
                    <div class="detail">Mode : <code><?= \App\Core\App::html()->escape($vr['mode']) ?></code> — <?= \App\Core\App::html()->escape($vr['verify']['detail']) ?></div>

                    <?php if (isset($vr['format_valid'])): ?>
                        <div class="detail">Format email : <?= $vr['format_valid'] ? '✔ Valide' : '✘ Invalide' ?></div>
                    <?php endif; ?>

                    <?php if (isset($vr['ldap'])): ?>
                        <div class="detail u-fon-mar">Résultat LDAP :</div>
                        <div class="detail">✔/✘ : <?= $vr['ldap']['ok'] ? 'OK' : 'ÉCHEC' ?> — <?= \App\Core\App::html()->escape($vr['ldap']['detail']) ?></div>
                    <?php endif; ?>

                    <?php if (isset($vr['smtp'])): ?>
                        <div class="detail u-fon-mar">Résultat SMTP :</div>
                        <div class="detail">✔/✘ : <?= $vr['smtp']['ok'] ? 'OK' : 'ÉCHEC' ?> — <?= \App\Core\App::html()->escape($vr['smtp']['detail']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- SECTION 3 : Configuration SMTP                            -->
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

        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- SECTION 4 : Test email                                    -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <div class="card mt-15-2" id="section-email-send">
            <h2>Test d'envoi d'email</h2>
            <p class="caption-2">Envoyer un email de test à votre adresse (<?= \App\Core\App::html()->escape(\App\Core\App::auth()->getUser()) ?>) pour vérifier la configuration SMTP.</p>
            <?php if ($mail_dry_run === '1'): ?>
                <div class="warning-box mb-1">
                    <strong>Mode Dry-Run actif</strong> — L'email sera journalisé mais <strong>pas réellement envoyé</strong>.
                    Désactivez le Dry-Run pour effectuer un envoi réel.
                </div>
            <?php endif; ?>
            <form method="POST">
                <?= \App\Core\App::security()->csrfField() ?>
                <input type="hidden" name="action" value="test_email">
                <button type="submit" class="btn btn-test">Envoyer un email de test</button>
            </form>
        </div>

        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- SECTION 5 : Résumé de sécurité email                      -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <div class="card mt-15-2" id="section-email-summary">
            <h2><span class="icon">📋</span> Résumé de sécurité email</h2>
            <table class="progress-fill-3">
                <tr class="u-bor-2">
                    <td class="u-fon-pad">Mode Dry-Run</td>
                    <td class="p-5"><?= $mail_dry_run === '1' ? '<span class="u-col-fon-6">Activé</span> — Aucun email réel' : '<span class="u-col-fon-4">Désactivé</span> — Envois réels actifs' ?></td>
                </tr>
                <tr class="u-bor-2">
                    <td class="u-fon-pad">Vérification destinataires</td>
                    <td class="p-5">
                        <?php if ($email_verify_mode === 'none'): ?>
                            <span class="text-f44336">Désactivée</span>
                        <?php elseif ($email_verify_mode === 'ldap'): ?>
                            <span class="text-4caf50">LDAP / Active Directory</span>
                            <?php if ($ldap_host !== '' && $ldap_host !== '0'): ?> (<?= \App\Core\App::html()->escape($ldap_host) ?>)<?php endif; ?>
                        <?php elseif ($email_verify_mode === 'smtp'): ?>
                            <span class="text-info">SMTP (probe RCPT TO)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr class="u-bor-2">
                    <td class="u-fon-pad">Extension LDAP PHP</td>
                    <td class="p-5"><?= $ldap_ext_available ? '<span class="text-4caf50">Disponible</span>' : '<span class="text-f44336">Non disponible</span>' ?></td>
                </tr>
                <tr class="u-bor-2">
                    <td class="u-fon-pad">PHPMailer</td>
                    <td class="p-5">
                        <?php
                        /** @phpstan-ignore-next-line */
                        if (method_exists('PHPMailer\PHPMailer\PHPMailer', 'getSMTPInstance')): ?>
                            <span class="text-4caf50">Vraie bibliothèque</span>
                        <?php else: ?>
                            <span class="text-ff9800">Stub (aucun envoi réel possible)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td class="u-fon-pad">Blocage CLI</td>
                    <td class="p-5"><span class="text-4caf50">Actif</span> — Les scripts CLI ne peuvent pas envoyer d'emails sans <code>CLI_MAIL_ALLOWED</code></td>
                </tr>
            </table>

            <?php
            $security_score = 0;
        $security_items = [];
        if ($mail_dry_run === '1') {
            $security_score++;
            $security_items[] = 'Dry-Run activé';
        }
        if ($email_verify_mode !== 'none') {
            $security_score++;
            $security_items[] = 'Vérification destinataires';
        }
        /** @phpstan-ignore-next-line */
        if (!method_exists('PHPMailer\PHPMailer\PHPMailer', 'getSMTPInstance')) {
            $security_score++;
            $security_items[] = 'PHPMailer en mode stub';
        }
        $security_score++;
        $security_items[] = 'Blocage CLI';
        ?>
            <div class="<?= $security_score >= 3 ? 'score-ok' : 'score-warn' ?>">
                <strong>Niveau de sécurité : <?= $security_score ?>/4</strong>
                <div class="hint-muted-3">
                    <?= implode(' · ', array_map(fn(string $i) => '✔ ' . $i, $security_items)) ?>
                </div>
                <?php if ($security_score < 3): ?>
                    <div class="hint-warning">
                        ⚠ Activez la vérification des destinataires et/ou le mode Dry-Run pour renforcer la sécurité.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
        $content = ob_get_clean();
        return $content === false ? '' : $content;
    }

    /**
     * Scripts JS à injecter après le contenu principal.
     *
     * Le fichier lib/admin_settings_scripts.js contient 2 blocs <script> inline.
     * Depuis le 2026-08-01, script-src n'a plus 'unsafe-inline' — chaque <script>
     * inline doit avoir un nonce CSP. On injecte le nonce dynamiquement via
     * str_replace (même pattern que NavigationRenderer::footer() pour le persona).
     */
    public function renderAfterMain(): string
    {
        static $after_main = null;
        if ($after_main === null) {
            $raw = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/admin_settings_scripts.js');
            // Injecter le nonce CSP sur chaque <script> inline.
            // str_replace remplace TOUTES les occurrences (2 blocs <script>).
            $after_main = str_replace(
                '<script>',
                '<script nonce="' . \App\Core\App::security()->getScriptNonce() . '">',
                $raw
            );
        }
        return $after_main;
    }
}
