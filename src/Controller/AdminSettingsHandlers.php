<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * POST handlers admin_settings.php — SMTP, vérification email, webhooks.
 *
 * Contrat de retour (tableau associatif) :
 *  - 'success'       (string)
 *  - 'error'         (string)
 *  - 'test'          (string)
 *  - 'verify_result' (array|null)
 */
final class AdminSettingsHandlers
{
    /**
     * Traite les soumissions POST de la page admin_settings.php.
     *
     * @return array{success:string,error:string,test:string,verify_result:mixed}
     */
    public static function handlePost(): array
    {
        $success_msg   = '';
        $error_msg     = '';
        $test_msg      = '';
        $verify_result = null;

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return ['success' => $success_msg, 'error' => $error_msg, 'test' => $test_msg, 'verify_result' => $verify_result];
        }

        App::security()->requireCsrf();

        $action = $_POST['action'] ?? '';

        if ($action === 'save_settings') {
            [$success_msg, $error_msg] = self::handleSaveSettings();
        }

        if ($action === 'save_email_verify') {
            [$success_msg, $error_msg] = self::handleSaveEmailVerify($error_msg);
        }

        if (isset($_POST['webhook_url'])) {
            $user = App::auth()->getUser();
            App::settings()->set('webhook_url', trim($_POST['webhook_url']), $user);
            App::audit()->log('settings_update', 'settings:webhook_url', 'URL webhook mise à jour');
        }
        if (isset($_POST['webhook_events'])) {
            $user = App::auth()->getUser();
            App::settings()->set('webhook_events', trim($_POST['webhook_events']), $user);
            App::audit()->log('settings_update', 'settings:webhook_events', 'Événements webhook mis à jour');
        }

        if ($action === 'test_email') {
            $test_msg = self::handleTestEmail();
        }

        if ($action === 'test_verify_email') {
            [$verify_result, $error_msg] = self::handleTestVerifyEmail($error_msg);
        }

        if ($action === 'test_webhook') {
            [$success_msg, $error_msg] = self::handleTestWebhook($success_msg, $error_msg);
        }

        return ['success' => $success_msg, 'error' => $error_msg, 'test' => $test_msg, 'verify_result' => $verify_result];
    }

    public static function handleSaveSettings(): array
    {
        $success_msg = '';
        $error_msg   = '';
        $updated_by  = App::auth()->getUser();
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

        if (empty($settings['smtp_pass'])) {
            $settings['smtp_pass'] = App::settings()->get('smtp_pass', '');
        }
        if (!empty($settings['admin_email']) && !filter_var($settings['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $error_msg = 'L\'adresse email de l\'administrateur principal est invalide.';
            unset($settings['admin_email']);
        }

        try {
            foreach ($settings as $key => $value) {
                App::settings()->set($key, $value, $updated_by);
            }
            App::audit()->log('settings_update', 'settings', 'Paramètres mis à jour', $updated_by);
            $success_msg = 'Paramètres enregistrés avec succès.';
        } catch (\Exception $e) {
            $error_msg = 'Erreur lors de l\'enregistrement : ' . $e->getMessage();
        }

        return [$success_msg, $error_msg];
    }

    public static function handleSaveEmailVerify(string $error_msg): array
    {
        $success_msg = '';
        $updated_by  = App::auth()->getUser();
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

        $ldap_bind_pass = trim($_POST['ldap_bind_pass'] ?? '');
        if (!empty($ldap_bind_pass)) {
            $ev_settings['ldap_bind_pass'] = $ldap_bind_pass;
        }

        $valid_modes = ['none', 'ldap', 'smtp'];
        if (!in_array($ev_settings['email_verify_mode'], $valid_modes)) {
            $ev_settings['email_verify_mode'] = 'none';
        }

        if ($ev_settings['email_verify_mode'] === 'ldap' && (empty($ev_settings['ldap_host']) || empty($ev_settings['ldap_base_dn']))) {
            $error_msg = 'Le mode LDAP nécessite au minimum un hôte LDAP et un base DN.';
        }

        try {
            foreach ($ev_settings as $key => $value) {
                App::settings()->set($key, $value, $updated_by);
            }
            App::audit()->log('settings_update', 'settings:email_verify', 'Paramètres de vérification email mis à jour', $updated_by);
            if (empty($error_msg)) {
                $success_msg = 'Paramètres de vérification email enregistrés avec succès.';
            }
        } catch (\Exception $e) {
            $error_msg = 'Erreur lors de l\'enregistrement : ' . $e->getMessage();
        }

        return [$success_msg, $error_msg];
    }

    public static function handleTestEmail(): string
    {
        $to      = App::auth()->getUser();
        $subject = 'Test email — ' . \get_app_name();
        $body    = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;color:#222;">
  <h2 style="color:#003189;">Test d\'envoi d\'email</h2>
  <p>Cet email a été envoyé depuis la page de paramètres du workflow.</p>
  <p>Date : ' . \h(date('d/m/Y H:i:s')) . '</p>
</body></html>';
        $result = \send_mail_detailed($to, $subject, $body);
        if ($result['success']) {
            $status_label = [
                'sent'    => 'envoyé',
                'dry_run' => 'intercepté (dry-run)',
            ][$result['status']] ?? $result['status'];
            return 'Email de test ' . $status_label . ' avec succès à ' . \h($to);
        }
        $err = $result['error'] !== '' ? $result['error'] : 'erreur inconnue';
        return 'Échec de l\'envoi de l\'email de test à ' . \h($to) . ' — ' . \h($err) . ' (statut: ' . \h($result['status']) . '). Vérifiez la configuration SMTP, le mode dry-run et le journal des emails dans Surveillance.';
    }

    public static function handleTestVerifyEmail(string $error_msg): array
    {
        $test_addr = trim($_POST['verify_test_email'] ?? '');
        if (!empty($test_addr) && filter_var($test_addr, FILTER_VALIDATE_EMAIL)) {
            $verify_result = \test_email_verification($test_addr);
            App::audit()->log('email_verify_test', 'mail:' . $test_addr, 'Test de vérification email', App::auth()->getUser());
            return [$verify_result, $error_msg];
        }
        $error_msg = 'Veuillez saisir une adresse email valide pour le test.';
        return [null, $error_msg];
    }

    public static function handleTestWebhook(string $success_msg, string $error_msg): array
    {
        $webhook_url = App::settings()->get('webhook_url', '');
        if (empty($webhook_url)) {
            $error_msg = 'Aucune URL webhook configurée.';
            return [$success_msg, $error_msg];
        }
        \send_webhook('test', ['message' => 'Test webhook depuis ' . \get_app_name(), 'version' => \get_latest_version()]);
        $success_msg = 'Webhook de test envoyé à ' . \h($webhook_url) . '.';
        App::audit()->log('webhook_test', 'settings', 'Test webhook envoyé');
        return [$success_msg, $error_msg];
    }
}
