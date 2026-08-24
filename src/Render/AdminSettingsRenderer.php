<?php
declare(strict_types=1);

namespace App\Render;

/**
 * Render de la page admin_settings.php (Paramètres admin).
 *
 * Le HTML des sections est dans src/Render/templates/admin_settings_*.
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
     *
     * Les sections de rendu sont déléguées aux templates dans src/Render/templates/.
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
        $token_expire_days     = \App\Core\App::settings()->get('token_expire_days', '30');
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

        $vars = ['mail_dry_run' => $mail_dry_run, 'email_verify_mode' => $email_verify_mode, 'ldap_ext_available' => $ldap_ext_available, 'ldap_host' => $ldap_host, 'ldap_port' => $ldap_port, 'ldap_base_dn' => $ldap_base_dn, 'ldap_bind_dn' => $ldap_bind_dn, 'ldap_bind_pass' => $ldap_bind_pass, 'ldap_filter' => $ldap_filter, 'ldap_suggest_enabled' => $ldap_suggest_enabled, 'ldap_suggest_filter' => $ldap_suggest_filter, 'smtp_host' => $smtp_host, 'smtp_port' => $smtp_port, 'smtp_auth' => $smtp_auth, 'smtp_secure' => $smtp_secure, 'smtp_user' => $smtp_user, 'smtp_pass' => $smtp_pass, 'smtp_from' => $smtp_from, 'smtp_from_name' => $smtp_from_name, 'token_expire_days' => $token_expire_days, 'retention_months' => $retention_months];

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
            <?= $this->loadTemplate('admin_settings_warning.php') ?>
        <?php endif; ?>

        <?= $this->loadTemplate('admin_settings_section_security.php', $vars) ?>
        <?= $this->loadTemplate('admin_settings_section_verify_test.php', $vars + ['verify_result' => $verify_result]) ?>
        <?= $this->loadTemplate('admin_settings_section_smtp.php', $vars) ?>
        <?= $this->loadTemplate('admin_settings_section_test_send.php', $vars) ?>
        <?= $this->loadTemplate('admin_settings_section_summary.php', $vars) ?>
    </div>
    <?php
        $content = ob_get_clean();
        return $content === false ? '' : $content;
    }

    /**
     * Scripts JS à injecter après le contenu principal.
     *
     * Le fichier lib/admin_settings_scripts.js contient 2 blocs <script> inline
     * avec le placeholder __CSP_NONCE_PLACEHOLDER__. On remplace le placeholder
     * par le nonce CSP de la requête courante (même pattern que
     * NavigationRenderer::footer() pour le persona JS).
     *
     * IMPORTANT : on ne cache que le contenu BRUT (sans nonce), pas le résultat
     * avec nonce. Le serveur PHP -S est un process unique qui gère plusieurs
     * requêtes — chaque requête a un nonce différent (généré par requête dans
     * SecurityService). Si on cache le résultat avec nonce, la 2e requête
     * réutilise un nonce périmé → violation CSP.
     */
    public function renderAfterMain(): string
    {
        static $raw_scripts = null;
        if ($raw_scripts === null) {
            $raw_scripts = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/admin_settings_scripts.js');
        }
        // Remplacer le placeholder par le nonce CSP À CHAQUE APPEL
        // (le nonce change à chaque requête — ne pas le cacher).
        return str_replace(
            '__CSP_NONCE_PLACEHOLDER__',
            'nonce="' . \App\Core\App::security()->getScriptNonce() . '"',
            $raw_scripts
        );
    }

    /**
     * Charge un template depuis src/Render/templates/ avec les variables données.
     *
     * Les variables du tableau $vars sont extraites dans la portée locale du template
     * via extract(). Le template accède ainsi à chaque clé comme une variable locale
     * (ex: $mail_dry_run, $ldap_host, etc.).
     *
     * @param array<string, string|int|bool|array|null> $vars
     */
    private function loadTemplate(string $filename, array $vars = []): string
    {
        $filepath = __DIR__ . '/templates/' . $filename;
        if (!file_exists($filepath)) {
            throw new \RuntimeException("Template not found: {$filepath}");
        }

        extract($vars);
        unset($vars);

        ob_start();
        include $filepath;
        $html = ob_get_clean();
        return $html === false ? '' : $html;
    }
}
