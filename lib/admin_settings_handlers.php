<?php
declare(strict_types=1);
use App\Core\App;

/**
 * POST handlers admin_settings.php — SMTP, vérification email, webhooks.
 *
 * Extrait du bloc POST inline de admin_settings.php pour garder ce fichier
 * sous 600 lignes (refactor « all-under-600 »).
 *
 * Fonction principale :
 *  - handle_admin_settings_post() : traite $_POST et retourne un tableau
 *                                   [success, error, test, verify_result].
 *
 * Contrat de retour (tableau associatif) :
 *  - 'success'       (string)        → message de succès affiché en haut de page
 *  - 'error'         (string)        → message d'erreur affiché en haut de page
 *  - 'test'          (string)        → message d'info (résultat test email)
 *  - 'verify_result' (array|null)    → résultat détaillé du test_verify_email
 *
 * Comportement strictement identique à l'ancien bloc POST inline : CSRF requis,
 * validation email admin, conservation des mots de passe SMTP/LDAP vides,
 * logs d'audit, mode dry-run respecté.
 *
 * @package lib
 * @see /admin_settings.php
 */

// ── Dispatcher POST ────────────────────────────────────────────

/**
 * Traite les soumissions POST de la page admin_settings.php.
 *
 * Actions gérées (toutes protégées par CSRF) :
 *  - save_settings      : enregistre identité app, admin email, SMTP, workflow
 *  - save_email_verify  : enregistre dry-run + mode vérification (LDAP/SMTP)
 *  - (champ) webhook_url   : enregistre l'URL webhook (non gated par $action)
 *  - (champ) webhook_events: enregistre les événements webhook (non gated)
 *  - test_email         : envoie un email de test à l'admin connecté
 *  - test_verify_email  : teste la vérification d'une adresse
 *  - test_webhook       : envoie un webhook de test
 *
 * @return array{success:string,error:string,test:string,verify_result:mixed}
 */
function handle_admin_settings_post(): array
{
    $success_msg   = '';
    $error_msg     = '';
    $test_msg      = '';
    $verify_result = null;

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return ['success' => $success_msg, 'error' => $error_msg, 'test' => $test_msg, 'verify_result' => $verify_result];
    }

    // Vérification CSRF
    App::security()->requireCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        [$success_msg, $error_msg] = admin_settings_handle_save_settings();
    }

    if ($action === 'save_email_verify') {
        [$success_msg, $error_msg] = admin_settings_handle_save_email_verify($error_msg);
    }

    // Webhook settings — non gated par $action, déclenchés par la présence
    // des champs webhook_url / webhook_events dans le POST.
    if (isset($_POST['webhook_url'])) {
        $user = get_auth_user();
        \App\Core\App::settings()->set('webhook_url', trim($_POST['webhook_url']), $user);
        App::audit()->log('settings_update', 'settings:webhook_url', 'URL webhook mise à jour');
    }
    if (isset($_POST['webhook_events'])) {
        $user = get_auth_user();
        \App\Core\App::settings()->set('webhook_events', trim($_POST['webhook_events']), $user);
        App::audit()->log('settings_update', 'settings:webhook_events', 'Événements webhook mis à jour');
    }

    if ($action === 'test_email') {
        $test_msg = admin_settings_handle_test_email();
    }

    if ($action === 'test_verify_email') {
        [$verify_result, $error_msg] = admin_settings_handle_test_verify_email($error_msg);
    }

    if ($action === 'test_webhook') {
        [$success_msg, $error_msg] = admin_settings_handle_test_webhook($success_msg, $error_msg);
    }

    return ['success' => $success_msg, 'error' => $error_msg, 'test' => $test_msg, 'verify_result' => $verify_result];
}

// ── Handlers individuels ───────────────────────────────────────

/**
 * Handler de l'action `save_settings` : enregistre identité app, admin email,
 * SMTP et paramètres du workflow.
 *
 * Conserve l'ancien mot de passe SMTP si le champ est vide. Valide l'email
 * admin et le supprime du tableau si invalide (mais enregistre les autres).
 *
 * @return array{0:string,1:string} [success_msg, error_msg]
 */
function admin_settings_handle_save_settings(): array
{
    $success_msg = '';
    $error_msg   = '';
    $updated_by  = get_auth_user();
    $settings    = [
        'app_name'          => trim($_POST['app_name'] ?? ''),
        'app_favicon'       => trim($_POST['app_favicon'] ?? ''),
        'admin_email'       => trim($_POST['admin_email'] ?? ''),
        'smtp_host'         => trim($_POST['smtp_host'] ?? ''),
        'smtp_port'         => trim($_POST['smtp_port'] ?? '25'),
        'smtp_auth'         => isset($_POST['smtp_auth']) ? '1' : '0',
        'smtp_secure'       => trim($_POST['smtp_secure'] ?? ''),
        'smtp_user'         => trim($_POST['smtp_user'] ?? ''),
        'smtp_pass'         => trim($_POST['smtp_pass'] ?? ''),
        'smtp_from'         => trim($_POST['smtp_from'] ?? ''),
        'smtp_from_name'    => trim($_POST['smtp_from_name'] ?? ''),
        'delai_relance_h'   => trim($_POST['delai_relance_h'] ?? '48'),
        'token_expire_days' => trim($_POST['token_expire_days'] ?? '30'),
        'relance_max'       => trim($_POST['relance_max'] ?? '3'),
        'retention_months'  => trim($_POST['retention_months'] ?? '24'),
    ];

    // Conserver l'ancien mot de passe si le champ est vide
    if (empty($settings['smtp_pass'])) {
        $settings['smtp_pass'] = \App\Core\App::settings()->get('smtp_pass', '');
    }
    // Valider l'email admin
    if (!empty($settings['admin_email']) && !filter_var($settings['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $error_msg = 'L\'adresse email de l\'administrateur principal est invalide.';
        unset($settings['admin_email']);
    }

    try {
        foreach ($settings as $key => $value) {
            \App\Core\App::settings()->set($key, $value, $updated_by);
        }
        App::audit()->log('settings_update', 'settings', 'Paramètres mis à jour', $updated_by);
        $success_msg = 'Paramètres enregistrés avec succès.';
    } catch (Exception $e) {
        $error_msg = 'Erreur lors de l\'enregistrement : ' . $e->getMessage();
    }

    return [$success_msg, $error_msg];
}

/**
 * Handler de l'action `save_email_verify` : enregistre le mode dry-run et la
 * configuration de vérification des destinataires (LDAP ou SMTP).
 *
 * Conserve l'ancien mot de passe LDAP si le champ est vide. Valide le mode et
 * exige host + base_dn si LDAP est sélectionné.
 *
 * @param string $error_msg Message d'erreur préexistant (cumul).
 * @return array{0:string,1:string} [success_msg, error_msg]
 */
function admin_settings_handle_save_email_verify(string $error_msg): array
{
    $success_msg = '';
    $updated_by  = get_auth_user();
    $ev_settings = [
        'mail_dry_run'         => isset($_POST['mail_dry_run']) ? '1' : '0',
        'email_verify_mode'    => trim($_POST['email_verify_mode'] ?? 'none'),
        'ldap_host'            => trim($_POST['ldap_host'] ?? ''),
        'ldap_port'            => trim($_POST['ldap_port'] ?? '389'),
        'ldap_base_dn'         => trim($_POST['ldap_base_dn'] ?? ''),
        'ldap_bind_dn'         => trim($_POST['ldap_bind_dn'] ?? ''),
        'ldap_filter'          => trim($_POST['ldap_filter'] ?? '(mail={email})'),
        'ldap_suggest_enabled' => isset($_POST['ldap_suggest_enabled']) ? '1' : '0',
        'ldap_suggest_filter'  => trim($_POST['ldap_suggest_filter'] ?? '(|(cn=*{query}*)(mail=*{query}*)(sn=*{query}*)(givenName=*{query}*))'),
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
            \App\Core\App::settings()->set($key, $value, $updated_by);
        }
        App::audit()->log('settings_update', 'settings:email_verify', 'Paramètres de vérification email mis à jour', $updated_by);
        if (empty($error_msg)) {
            $success_msg = 'Paramètres de vérification email enregistrés avec succès.';
        }
    } catch (Exception $e) {
        $error_msg = 'Erreur lors de l\'enregistrement : ' . $e->getMessage();
    }

    return [$success_msg, $error_msg];
}

/**
 * Handler de l'action `test_email` : envoie un email de test à l'admin
 * connecté et retourne le message de résultat (succès ou échec détaillé).
 *
 * Utilise send_mail_detailed() pour récupérer le message d'erreur PHPMailer
 * exact en cas d'échec — facilite le diagnostic SMTP depuis la page Paramètres.
 */
function admin_settings_handle_test_email(): string
{
    $to      = get_auth_user();
    $subject = 'Test email — ' . get_app_name();
    $body    = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;color:#222;">
  <h2 style="color:#003189;">Test d\'envoi d\'email</h2>
  <p>Cet email a été envoyé depuis la page de paramètres du workflow.</p>
  <p>Date : ' . h(date('d/m/Y H:i:s')) . '</p>
</body></html>';
    $result = send_mail_detailed($to, $subject, $body);
    if ($result['success']) {
        $status_label = [
            'sent'    => 'envoyé',
            'dry_run' => 'intercepté (dry-run)',
        ][$result['status']] ?? $result['status'];
        return 'Email de test ' . $status_label . ' avec succès à ' . h($to);
    }
    // Échec : inclure le message d'erreur PHPMailer pour diagnostic
    $err = $result['error'] !== '' ? $result['error'] : 'erreur inconnue';
    return 'Échec de l\'envoi de l\'email de test à ' . h($to) . ' — ' . h($err) . ' (statut: ' . h($result['status']) . '). Vérifiez la configuration SMTP, le mode dry-run et le journal des emails dans Surveillance.';
}

/**
 * Handler de l'action `test_verify_email` : teste la vérification d'une
 * adresse email avec la configuration actuelle (LDAP ou probe SMTP).
 *
 * @param string $error_msg Message d'erreur préexistant (cumul).
 * @return array{0:mixed,1:string} [verify_result, error_msg]
 */
function admin_settings_handle_test_verify_email(string $error_msg): array
{
    $test_addr = trim($_POST['verify_test_email'] ?? '');
    if (!empty($test_addr) && filter_var($test_addr, FILTER_VALIDATE_EMAIL)) {
        $verify_result = test_email_verification($test_addr);
        App::audit()->log('email_verify_test', 'mail:' . $test_addr, 'Test de vérification email', get_auth_user());
        return [$verify_result, $error_msg];
    }
    $error_msg = 'Veuillez saisir une adresse email valide pour le test.';
    return [null, $error_msg];
}

/**
 * Handler de l'action `test_webhook` : envoie un webhook de test si une URL
 * est configurée, sinon renvoie un message d'erreur.
 *
 * @param string $success_msg Message de succès préexistant (cumul).
 * @param string $error_msg   Message d'erreur préexistant (cumul).
 * @return array{0:string,1:string} [success_msg, error_msg]
 */
function admin_settings_handle_test_webhook(string $success_msg, string $error_msg): array
{
    $webhook_url = \App\Core\App::settings()->get('webhook_url', '');
    if (empty($webhook_url)) {
        $error_msg = 'Aucune URL webhook configurée.';
        return [$success_msg, $error_msg];
    }
    send_webhook('test', ['message' => 'Test webhook depuis ' . get_app_name(), 'version' => get_latest_version()]);
    $success_msg = 'Webhook de test envoyé à ' . h($webhook_url) . '.';
    App::audit()->log('webhook_test', 'settings', 'Test webhook envoyé');
    return [$success_msg, $error_msg];
}
