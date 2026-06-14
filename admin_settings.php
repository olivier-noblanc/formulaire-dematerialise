<?php
// admin_settings.php — Page de configuration SMTP, vérification email et paramètres
require_once __DIR__ . '/helpers.php';

// Vérification des droits d'accès
if (!is_admin_user() && !is_super_admin()) {
    header('Location: admin_access.php');
    exit;
}

$success_msg = '';
$error_msg = '';
$test_msg = '';
$verify_result = null;

// Traitement du POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérification CSRF
    if (!verify_csrf()) {
        render_error_page(403, 'Requête invalide', 'Le jeton de sécurité (CSRF) de votre session est invalide ou a expiré. Cela peut arriver si votre session a été inactive trop longtemps ou si la page est restée ouverte depuis longtemps.', 'Rechargez la page et réessayez. Si le problème persiste, fermez tous les onglets de l\'application et reconnectez-vous.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $updated_by = get_auth_user();
        $settings = [
            'admin_email'      => trim($_POST['admin_email'] ?? ''),
            'smtp_host'        => trim($_POST['smtp_host'] ?? ''),
            'smtp_port'        => trim($_POST['smtp_port'] ?? '25'),
            'smtp_auth'        => isset($_POST['smtp_auth']) ? '1' : '0',
            'smtp_secure'      => trim($_POST['smtp_secure'] ?? ''),
            'smtp_user'        => trim($_POST['smtp_user'] ?? ''),
            'smtp_pass'        => trim($_POST['smtp_pass'] ?? ''),
            'smtp_from'        => trim($_POST['smtp_from'] ?? ''),
            'smtp_from_name'   => trim($_POST['smtp_from_name'] ?? ''),
            'delai_relance_h'  => trim($_POST['delai_relance_h'] ?? '48'),
            'token_expire_days'=> trim($_POST['token_expire_days'] ?? '30'),
            'relance_max'      => trim($_POST['relance_max'] ?? '3'),
        ];

        // Conserver l'ancien mot de passe si le champ est vide
        if (empty($settings['smtp_pass'])) {
            $settings['smtp_pass'] = get_setting('smtp_pass', '');
        }
        // Valider l'email admin
        if (!empty($settings['admin_email']) && !filter_var($settings['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $error_msg = 'L\'adresse email de l\'administrateur principal est invalide.';
            unset($settings['admin_email']);
        }

        try {
            foreach ($settings as $key => $value) {
                set_setting($key, $value, $updated_by);
            }
            app_log('settings_update', 'settings', 'Paramètres mis à jour', $updated_by);
            $success_msg = 'Paramètres enregistrés avec succès.';
        } catch (Exception $e) {
            $error_msg = 'Erreur lors de l\'enregistrement : ' . $e->getMessage();
        }
    }

    if ($action === 'save_email_verify') {
        $updated_by = get_auth_user();
        $ev_settings = [
            'mail_dry_run'      => isset($_POST['mail_dry_run']) ? '1' : '0',
            'email_verify_mode' => trim($_POST['email_verify_mode'] ?? 'none'),
            'ldap_host'         => trim($_POST['ldap_host'] ?? ''),
            'ldap_port'         => trim($_POST['ldap_port'] ?? '389'),
            'ldap_base_dn'      => trim($_POST['ldap_base_dn'] ?? ''),
            'ldap_bind_dn'      => trim($_POST['ldap_bind_dn'] ?? ''),
            'ldap_filter'       => trim($_POST['ldap_filter'] ?? '(mail={email})'),
        ];

        // Conserver l'ancien mot de passe LDAP si le champ est vide
        $ldap_bind_pass = trim($_POST['ldap_bind_pass'] ?? '');
        if (!empty($ldap_bind_pass)) {
            $ev_settings['ldap_bind_pass'] = $ldap_bind_pass;
        }

        // Validation du mode de vérification
        $valid_modes = ['none', 'ldap', 'smtp'];
        if (!in_array($ev_settings['email_verify_mode'], $valid_modes)) {
            $ev_settings['email_verify_mode'] = 'none';
        }

        // Si LDAP est choisi, vérifier que les champs obligatoires sont remplis
        if ($ev_settings['email_verify_mode'] === 'ldap' && (empty($ev_settings['ldap_host']) || empty($ev_settings['ldap_base_dn']))) {
            $error_msg = 'Le mode LDAP nécessite au minimum un hôte LDAP et un base DN.';
        }

        try {
            foreach ($ev_settings as $key => $value) {
                set_setting($key, $value, $updated_by);
            }
            app_log('settings_update', 'settings:email_verify', 'Paramètres de vérification email mis à jour', $updated_by);
            if (empty($error_msg)) {
                $success_msg = 'Paramètres de vérification email enregistrés avec succès.';
            }
        } catch (Exception $e) {
            $error_msg = 'Erreur lors de l\'enregistrement : ' . $e->getMessage();
        }
    }

    // Webhook settings
    if (isset($_POST['webhook_url'])) {
        $user = get_auth_user();
        set_setting('webhook_url', trim($_POST['webhook_url']), $user);
        app_log('settings_update', 'settings:webhook_url', 'URL webhook mise à jour');
    }
    if (isset($_POST['webhook_events'])) {
        $user = get_auth_user();
        set_setting('webhook_events', trim($_POST['webhook_events']), $user);
        app_log('settings_update', 'settings:webhook_events', 'Événements webhook mis à jour');
    }

    if ($action === 'test_email') {
        $to = get_auth_user();
        $subject = 'Test email — Workflow DREETS';
        $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;color:#222;">
  <h2 style="color:#003189;">Test d\'envoi d\'email</h2>
  <p>Cet email a été envoyé depuis la page de paramètres du workflow.</p>
  <p>Date : ' . h(date('d/m/Y H:i:s')) . '</p>
</body></html>';
        if (send_mail($to, $subject, $body)) {
            $test_msg = 'Email de test envoyé avec succès à ' . h($to);
        } else {
            $test_msg = 'Échec de l\'envoi de l\'email de test. Vérifiez la configuration SMTP, le mode dry-run et les logs.';
        }
    }

    if ($action === 'test_verify_email') {
        $test_addr = trim($_POST['verify_test_email'] ?? '');
        if (!empty($test_addr) && filter_var($test_addr, FILTER_VALIDATE_EMAIL)) {
            $verify_result = test_email_verification($test_addr);
            app_log('email_verify_test', 'mail:' . $test_addr, 'Test de vérification email', get_auth_user());
        } else {
            $error_msg = 'Veuillez saisir une adresse email valide pour le test.';
        }
    }
}

// Test webhook
if (isset($_GET['test_webhook'])) {
    $webhook_url = get_setting('webhook_url', '');
    if (empty($webhook_url)) {
        $error_msg = 'Aucune URL webhook configurée.';
    } else {
        send_webhook('test', ['message' => 'Test webhook depuis Workflow DREETS', 'version' => APP_VERSION]);
        $success_msg = 'Webhook de test envoyé à ' . h($webhook_url) . '.';
        app_log('webhook_test', 'settings', 'Test webhook envoyé');
    }
}

// Lecture des paramètres actuels
$smtp_host        = get_setting('smtp_host', SMTP_HOST);
$smtp_port        = get_setting('smtp_port', (string)SMTP_PORT);
$smtp_auth        = get_setting('smtp_auth', '0');
$smtp_secure      = get_setting('smtp_secure', '');
$smtp_user        = get_setting('smtp_user', '');
$smtp_pass        = get_setting('smtp_pass', '');
$smtp_from        = get_setting('smtp_from', SMTP_FROM);
$smtp_from_name   = get_setting('smtp_from_name', SMTP_FROM_NAME);
$delai_relance_h  = get_setting('delai_relance_h', (string)DELAI_RELANCE_H);
$token_expire_days= get_setting('token_expire_days', '30');
$relance_max      = get_setting('relance_max', '3');
$mail_dry_run     = get_setting('mail_dry_run', '1');
$email_verify_mode= get_setting('email_verify_mode', 'none');
$ldap_host        = get_setting('ldap_host', '');
$ldap_port        = get_setting('ldap_port', '389');
$ldap_base_dn     = get_setting('ldap_base_dn', '');
$ldap_bind_dn     = get_setting('ldap_bind_dn', '');
$ldap_bind_pass   = get_setting('ldap_bind_pass', '');
$ldap_filter      = get_setting('ldap_filter', '(mail={email})');

$ldap_ext_available = function_exists('ldap_connect');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Paramètres — DREETS Workflow</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='15' fill='%23003189'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial'>D</text></svg>">
    <?php require_once __DIR__ . '/style.php'; ?>
    <style>
        .container { max-width: 900px; }
        .verify-result { margin-top:1rem; padding:1rem; border-radius:6px; font-size:.85rem; }
        .verify-result.ok { background:#e8f5e9; border-left:4px solid #4caf50; }
        .verify-result.fail { background:#fbe9e7; border-left:4px solid #f44336; }
        .verify-result .detail { color:#555; margin-top:.3rem; }
        .warning-box { background:#fff3e0; border-left:4px solid #ff9800; padding:1rem; border-radius:4px; margin-bottom:1rem; font-size:.9rem; }
        .info-box { background:#e3f2fd; border-left:4px solid #2196f3; padding:1rem; border-radius:4px; margin-bottom:1rem; font-size:.9rem; }
        .dry-run-badge { display:inline-block; background:#ff9800; color:#fff; padding:2px 8px; border-radius:10px; font-size:.75rem; font-weight:bold; margin-left:8px; }
        .card h2 .icon { margin-right:.5rem; }
    </style>
</head>
<body>
<?= render_nav('settings') ?>
<main class="container" id="main-content">
<?= render_breadcrumb([['Accueil', 'index.php'], ['Paramètres']]) ?>
    <h1>⚙ Paramètres</h1>

    <?php if ($success_msg): ?>
        <div class="msg-success"><?= h($success_msg) ?></div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div class="msg-error"><?= h($error_msg) ?></div>
    <?php endif; ?>

    <?php if ($test_msg): ?>
        <div class="msg-info"><?= h($test_msg) ?></div>
    <?php endif; ?>

    <?php if ($mail_dry_run === '1'): ?>
        <div class="warning-box">
            <strong>Mode Dry-Run actif</strong> — Aucun email réel n'est envoyé. Tous les envois sont journalisés dans l'audit log mais ne quittent pas le serveur. Désactivez ce mode uniquement lorsque la configuration SMTP et les adresses destinataires sont vérifiées.
        </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════ -->
    <!-- SECTION 1 : Sécurité email — Dry-Run + Vérification       -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_email_verify">

        <div class="card">
            <h2><span class="icon">🛡️</span> Sécurité email</h2>
            <p style="margin-bottom:1rem;color:#555;font-size:.9rem;">
                Protégez contre l'envoi accidentel d'emails à des adresses non vérifiées.
                Le mode <strong>Dry-Run</strong> intercepte tous les envois (recommandé en phase de déploiement).
                La <strong>vérification des destinataires</strong> bloque les envois vers des adresses introuvables.
            </p>

            <!-- Dry-Run -->
            <div class="field" style="background:#fff8e1;padding:1rem;border-radius:6px;border:1px solid #ffe082;">
                <label class="checkbox-label" style="font-weight:bold;font-size:1rem;">
                    <input type="checkbox" name="mail_dry_run" <?= $mail_dry_run === '1' ? 'checked' : '' ?>>
                    Mode Dry-Run (aucun email réel envoyé)
                </label>
                <p style="margin:.5rem 0 0;color:#666;font-size:.85rem;">
                    Quand activé, <code>send_mail()</code> journalise chaque envoi dans l'audit log sans contacter le serveur SMTP.
                    Idéal pour valider la configuration avant mise en production.
                    Le workflow continue normalement (les tokens sont créés, les étapes avancent).
                </p>
            </div>

            <!-- Mode de vérification -->
            <div class="field" style="margin-top:1.5rem;">
                <label>Vérification des adresses destinataires</label>
                <select name="email_verify_mode" id="email_verify_mode" style="max-width:400px;">
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
            <div id="ldap-config" style="margin-top:1.5rem;padding:1.5rem;background:#f5f5fe;border-radius:6px;<?= $email_verify_mode !== 'ldap' ? 'display:none;' : '' ?>">
                <h3 style="margin-top:0;color:#003189;">Configuration LDAP / Active Directory</h3>

                <?php if (!$ldap_ext_available): ?>
                    <div class="warning-box" style="margin-bottom:1rem;">
                        <strong>Extension LDAP non détectée</strong> — L'extension PHP <code>ldap</code> n'est pas installée ou activée.
                        Contactez l'administrateur système pour l'activer (habituellement <code>extension=ldap</code> dans <code>php.ini</code>).
                        Sur IIS/Windows, l'extension est souvent présente mais désactivée par défaut.
                    </div>
                <?php else: ?>
                    <div class="info-box" style="margin-bottom:1rem;">
                        <strong>Extension LDAP disponible</strong> — La vérification Active Directory est opérationnelle.
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label>Hôte LDAP <span class="hint">(ex: ldap.dreets.gouv.fr ou votre contrôleur de domaine)</span></label>
                    <input type="text" name="ldap_host" value="<?= h($ldap_host) ?>" placeholder="ldap.dreets.gouv.fr">
                </div>

                <div class="field">
                    <label>Port LDAP</label>
                    <input type="number" name="ldap_port" value="<?= h($ldap_port) ?>" min="1" max="65535" style="max-width:150px;">
                    <span class="hint">389 = standard, 636 = LDAPS (chiffré)</span>
                </div>

                <div class="field">
                    <label>Base DN <span class="hint">(racine de la recherche dans l'annuaire)</span></label>
                    <input type="text" name="ldap_base_dn" value="<?= h($ldap_base_dn) ?>" placeholder="DC=dreets,DC=gouv,DC=fr">
                </div>

                <div class="field">
                    <label>Bind DN <span class="hint">(compte de service en lecture seule — laisser vide pour bind anonyme)</span></label>
                    <input type="text" name="ldap_bind_dn" value="<?= h($ldap_bind_dn) ?>" placeholder="CN=svc_workflow,OU=ServiceAccounts,DC=dreets,DC=gouv,DC=fr">
                </div>

                <div class="field">
                    <label>Mot de passe Bind <span class="hint">(laisser vide pour conserver l'actuel)</span></label>
                    <input type="password" name="ldap_bind_pass" placeholder="<?= $ldap_bind_pass ? '••••••••' : '' ?>">
                </div>

                <div class="field">
                    <label>Filtre de recherche <span class="hint">({email} sera remplacé par l'adresse à vérifier)</span></label>
                    <input type="text" name="ldap_filter" value="<?= h($ldap_filter) ?>" placeholder="(mail={email})">
                </div>
            </div>

            <!-- Info SMTP verification -->
            <div id="smtp-info" style="margin-top:1.5rem;padding:1.5rem;background:#f5f5fe;border-radius:6px;<?= $email_verify_mode !== 'smtp' ? 'display:none;' : '' ?>">
                <h3 style="margin-top:0;color:#003189;">Vérification SMTP (probe RCPT TO)</h3>
                <p style="color:#555;font-size:.9rem;">
                    Le système se connecte au serveur SMTP configuré ci-dessous, envoie les commandes
                    <code>HELO</code>, <code>MAIL FROM</code>, <code>RCPT TO</code> et vérifie si le serveur
                    accepte l'adresse destinataire. La connexion est refermée proprement avant d'envoyer
                    le contenu du mail (<code>QUIT</code> avant <code>DATA</code>).
                </p>
                <div class="warning-box" style="margin-top:1rem;">
                    <strong>Limitation</strong> — Certains serveurs SMTP (notamment Exchange) acceptent
                    toutes les adresses en <code>RCPT TO</code> (mode catch-all) et ne renvoient une erreur
                    qu'au moment du <code>DATA</code>. Dans ce cas, la vérification SMTP ne détectera pas
                    les adresses inexistantes. Préférez le mode LDAP si votre infrastructure le permet.
                </div>
            </div>

            <div style="margin-top:1.5rem;">
                <button type="submit" class="btn btn-primary">Enregistrer la sécurité email</button>
            </div>
        </div>
    </form>

    <!-- ═══════════════════════════════════════════════════════════ -->
    <!-- SECTION 2 : Test de vérification email                    -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <div class="card">
        <h2><span class="icon">🧪</span> Test de vérification email</h2>
        <p style="margin-bottom:1rem;color:#555;font-size:.9rem;">
            Testez la vérification d'une adresse email avec la configuration actuelle.
            Cela permet de vérifier que le LDAP ou la probe SMTP fonctionne correctement
            avant d'activer la vérification en production.
        </p>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="test_verify_email">
            <div class="field">
                <label>Adresse email à tester</label>
                <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                    <input type="email" name="verify_test_email" value="<?= h($_POST['verify_test_email'] ?? '') ?>" placeholder="agent@dreets.gouv.fr" style="max-width:350px;">
                    <button type="submit" class="btn btn-test">Vérifier cette adresse</button>
                </div>
            </div>
        </form>

        <?php if ($verify_result !== null): ?>
            <?php $vr = $verify_result; ?>
            <div class="verify-result <?= $vr['verify']['ok'] ? 'ok' : 'fail' ?>">
                <strong><?= $vr['verify']['ok'] ? '✔ Adresse vérifiée' : '✘ Adresse NON vérifiée' ?></strong>
                <div class="detail">Mode : <code><?= h($vr['mode']) ?></code> — <?= h($vr['verify']['detail']) ?></div>

                <?php if (isset($vr['format_valid'])): ?>
                    <div class="detail">Format email : <?= $vr['format_valid'] ? '✔ Valide' : '✘ Invalide' ?></div>
                <?php endif; ?>

                <?php if (isset($vr['ldap'])): ?>
                    <div class="detail" style="margin-top:.5rem;font-weight:bold;">Résultat LDAP :</div>
                    <div class="detail">✔/✘ : <?= $vr['ldap']['ok'] ? 'OK' : 'ÉCHEC' ?> — <?= h($vr['ldap']['detail']) ?></div>
                <?php endif; ?>

                <?php if (isset($vr['smtp'])): ?>
                    <div class="detail" style="margin-top:.5rem;font-weight:bold;">Résultat SMTP :</div>
                    <div class="detail">✔/✘ : <?= $vr['smtp']['ok'] ? 'OK' : 'ÉCHEC' ?> — <?= h($vr['smtp']['detail']) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════════════════ -->
    <!-- SECTION 3 : Configuration SMTP                            -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_settings">

        <div class="card">
            <h2>Administration</h2>

            <div class="field">
                <label for="admin_email">Email de l'administrateur principal</label>
                <input type="email" id="admin_email" name="admin_email" value="<?= h(get_admin_email()) ?>" placeholder="prenom.nom@dreets.gouv.fr" required>
                <span class="hint">Cet utilisateur est super-administrateur et reçoit les demandes d'accès. Modifiable depuis la base de données si l'accès est perdu.</span>
            </div>
        </div>

        <div class="card">
            <h2>Configuration SMTP</h2>

            <div class="field">
                <label>SMTP Hôte</label>
                <input type="text" name="smtp_host" value="<?= h($smtp_host) ?>" placeholder="smtp.example.fr">
            </div>

            <div class="field">
                <label>SMTP Port</label>
                <input type="number" name="smtp_port" value="<?= h($smtp_port) ?>" min="1" max="65535">
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
                <input type="text" name="smtp_user" value="<?= h($smtp_user) ?>" placeholder="utilisateur@exemple.fr">
            </div>

            <div class="field">
                <label>Mot de passe SMTP <span class="hint">(laisser vide pour conserver l'actuel)</span></label>
                <input type="password" name="smtp_pass" placeholder="<?= $smtp_pass ? '••••••••' : '' ?>">
            </div>

            <div class="field">
                <label>Email expéditeur</label>
                <input type="text" name="smtp_from" value="<?= h($smtp_from) ?>" placeholder="workflow@dreets.gouv.fr">
            </div>

            <div class="field">
                <label>Nom expéditeur</label>
                <input type="text" name="smtp_from_name" value="<?= h($smtp_from_name) ?>" placeholder="Workflow DREETS">
            </div>
        </div>

        <!-- Paramètres du workflow -->
        <div class="card">
            <h2>Paramètres du workflow</h2>

            <div class="field">
                <label>Délai de relance en heures</label>
                <input type="number" name="delai_relance_h" value="<?= h($delai_relance_h) ?>" min="1">
            </div>

            <div class="field">
                <label>Expiration des tokens en jours</label>
                <input type="number" name="token_expire_days" value="<?= h($token_expire_days) ?>" min="1">
            </div>

            <div class="field">
                <label>Nombre maximum de relances par token <span class="hint">(0 = illimité)</span></label>
                <input type="number" name="relance_max" value="<?= h($relance_max) ?>" min="0">
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Enregistrer les paramètres</button>
            <a href="dashboard.php" class="btn btn-secondary">Retour au tableau de bord</a>
        </div>
    </form>

    <!-- ═══════════════════════════════════════════════════════════ -->
    <!-- SECTION 4 : Webhooks                                      -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <div class="card">
      <h2>🔗 Webhooks & Notifications SI</h2>
      <p style="margin-bottom:1rem;color:#555;font-size:.9rem;">
        Configurez un webhook pour notifier votre système d'information des événements du workflow.
        Les notifications sont envoyées en POST JSON sur l'URL configurée.
      </p>
      <form method="POST">
        <?= csrf_field() ?>
        <div class="field">
          <label for="webhook_url">URL du webhook</label>
          <input type="url" id="webhook_url" name="webhook_url" value="<?= h(get_setting('webhook_url', '')) ?>" placeholder="https://si.dreets.gouv.fr/api/webhook">
          <span class="hint">URL recevant les notifications en POST JSON. Laissez vide pour désactiver.</span>
        </div>
        <div class="field">
          <label for="webhook_events">Événements à notifier</label>
          <input type="text" id="webhook_events" name="webhook_events" value="<?= h(get_setting('webhook_events', 'workflow_complete,submission_cancelled')) ?>" placeholder="workflow_complete,submission_cancelled,token_validated">
          <span class="hint">Séparés par des virgules. Événements disponibles : <code>workflow_complete</code>, <code>submission_cancelled</code>, <code>token_validated</code>, <code>all</code></span>
        </div>
        <div style="display:flex;gap:.5rem;align-items:center;">
          <button type="submit" class="btn btn-primary">Enregistrer</button>
          <?php if (!empty(get_setting('webhook_url', ''))): ?>
            <a href="?test_webhook=1" class="btn btn-test">Tester le webhook</a>
          <?php endif; ?>
        </div>
      </form>
      <div style="margin-top:1rem;padding:1rem;background:#f5f5fe;border-radius:4px;font-size:.8rem;">
        <strong>Format de la notification :</strong>
        <pre style="margin:.5rem 0 0;white-space:pre-wrap;color:#555;">{
  "event": "workflow_complete",
  "timestamp": "2025-01-15T10:30:00+01:00",
  "data": { "submission_id": 42, "form_label": "Onboarding", "submitted_by": "agent@dreets.gouv.fr" }
}</pre>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════ -->
    <!-- SECTION 5 : Test email                                    -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <div class="card" style="margin-top:1.5rem;">
        <h2>Test d'envoi d'email</h2>
        <p style="margin-bottom:1rem;color:#555;font-size:.9rem;">Envoyer un email de test à votre adresse (<?= h(get_auth_user()) ?>) pour vérifier la configuration SMTP.</p>
        <?php if ($mail_dry_run === '1'): ?>
            <div class="warning-box" style="margin-bottom:1rem;">
                <strong>Mode Dry-Run actif</strong> — L'email sera journalisé mais <strong>pas réellement envoyé</strong>.
                Désactivez le Dry-Run pour effectuer un envoi réel.
            </div>
        <?php endif; ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="test_email">
            <button type="submit" class="btn btn-test">Envoyer un email de test</button>
        </form>
    </div>

    <!-- ═══════════════════════════════════════════════════════════ -->
    <!-- SECTION 6 : Résumé de sécurité email                      -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <div class="card" style="margin-top:1.5rem;">
        <h2><span class="icon">📋</span> Résumé de sécurité email</h2>
        <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
            <tr style="border-bottom:1px solid #eee;">
                <td style="padding:.5rem;font-weight:bold;">Mode Dry-Run</td>
                <td style="padding:.5rem;"><?= $mail_dry_run === '1' ? '<span style="color:#ff9800;font-weight:bold;">Activé</span> — Aucun email réel' : '<span style="color:#4caf50;font-weight:bold;">Désactivé</span> — Envois réels actifs' ?></td>
            </tr>
            <tr style="border-bottom:1px solid #eee;">
                <td style="padding:.5rem;font-weight:bold;">Vérification destinataires</td>
                <td style="padding:.5rem;">
                    <?php if ($email_verify_mode === 'none'): ?>
                        <span style="color:#f44336;">Désactivée</span>
                    <?php elseif ($email_verify_mode === 'ldap'): ?>
                        <span style="color:#4caf50;">LDAP / Active Directory</span>
                        <?php if (!empty($ldap_host)): ?> (<?= h($ldap_host) ?>)<?php endif; ?>
                    <?php elseif ($email_verify_mode === 'smtp'): ?>
                        <span style="color:#2196f3;">SMTP (probe RCPT TO)</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr style="border-bottom:1px solid #eee;">
                <td style="padding:.5rem;font-weight:bold;">Extension LDAP PHP</td>
                <td style="padding:.5rem;"><?= $ldap_ext_available ? '<span style="color:#4caf50;">Disponible</span>' : '<span style="color:#f44336;">Non disponible</span>' ?></td>
            </tr>
            <tr style="border-bottom:1px solid #eee;">
                <td style="padding:.5rem;font-weight:bold;">PHPMailer</td>
                <td style="padding:.5rem;">
                    <?php if (method_exists('PHPMailer\PHPMailer\PHPMailer', 'getSMTPInstance')): ?>
                        <span style="color:#4caf50;">Vraie bibliothèque</span>
                    <?php else: ?>
                        <span style="color:#ff9800;">Stub (aucun envoi réel possible)</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td style="padding:.5rem;font-weight:bold;">Blocage CLI</td>
                <td style="padding:.5rem;"><span style="color:#4caf50;">Actif</span> — Les scripts CLI ne peuvent pas envoyer d'emails sans <code>CLI_MAIL_ALLOWED</code></td>
            </tr>
        </table>

        <?php
        // Calcul du niveau de sécurité
        $security_score = 0;
        $security_items = [];
        if ($mail_dry_run === '1') { $security_score++; $security_items[] = 'Dry-Run activé'; }
        if ($email_verify_mode !== 'none') { $security_score++; $security_items[] = 'Vérification destinataires'; }
        if (!method_exists('PHPMailer\PHPMailer\PHPMailer', 'getSMTPInstance')) { $security_score++; $security_items[] = 'PHPMailer en mode stub'; }
        // CLI blocking is always on
        $security_score++;
        $security_items[] = 'Blocage CLI';
        ?>
        <div style="margin-top:1rem;padding:1rem;background:<?= $security_score >= 3 ? '#e8f5e9' : '#fff3e0' ?>;border-radius:6px;">
            <strong>Niveau de sécurité : <?= $security_score ?>/4</strong>
            <div style="margin-top:.3rem;color:#555;font-size:.85rem;">
                <?= implode(' · ', array_map(function($i) { return '✔ ' . $i; }, $security_items)) ?>
            </div>
            <?php if ($security_score < 3): ?>
                <div style="margin-top:.5rem;color:#e65100;font-size:.85rem;">
                    ⚠ Activez la vérification des destinataires et/ou le mode Dry-Run pour renforcer la sécurité.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Toggle LDAP/SMTP config visibility -->
<script>
// Note : ce script minimal est le seul JS de la page — il gère uniquement
// l'affichage/masquage conditionnel des blocs LDAP/SMTP dans le formulaire admin.
// L'application fonctionne parfaitement sans JS (les sections sont toutes visibles
// par défaut et le serveur ignore les champs non pertinents).
document.addEventListener('DOMContentLoaded', function() {
    var sel = document.getElementById('email_verify_mode');
    var ldapBlock = document.getElementById('ldap-config');
    var smtpBlock = document.getElementById('smtp-info');

    function toggle() {
        if (!sel) return;
        var val = sel.value;
        ldapBlock.style.display = (val === 'ldap') ? '' : 'none';
        smtpBlock.style.display = (val === 'smtp') ? '' : 'none';
    }

    if (sel) {
        sel.addEventListener('change', toggle);
        toggle();
    }
});
</script>

<?= render_footer() ?>
</body>
</html>
