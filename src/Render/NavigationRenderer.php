<?php
declare(strict_types=1);

namespace App\Render;

use App\Core\App;

/**
 * Navigation rendering — header, nav, breadcrumb, footer, page wrapper.
 */
final class NavigationRenderer
{
    /**
     * Returns the application name from settings (cached per request).
     */
    public static function getAppName(): string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = App::settings()->get('app_name', 'CircuitDémat');
        return $cache;
    }

    /**
     * Renders the favicon link tag.
     */
    public static function favicon(): string
    {
        $svg = App::settings()->get('app_favicon', '');
        if ($svg !== '' && $svg !== '0') {
            return '<link rel="icon" href="data:image/svg+xml,' . App::html()->escape($svg) . '">';
        }
        return '<link rel="icon" href="data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 100 100\'><rect width=\'100\' height=\'100\' rx=\'20\' fill=\'%231E40AF\'/><text x=\'50\' y=\'78\' font-size=\'80\' text-anchor=\'middle\' fill=\'white\' font-family=\'Arial\'>&#9670;</text></svg>">';
    }

    /**
     * Generates the shared navigation bar.
     * Alias of header() for backward compatibility.
     *
     * @param string $current_page  Current page identifier for active marking
     * @param array<string, mixed>  $extra_admin_links Additional admin links
     * @return string HTML of the <nav>
     */
    public function nav(string $current_page = '', array $extra_admin_links = []): string
    {
        return $this->header($current_page, $extra_admin_links);
    }

    /**
     * Generates the complete header: sidebar + content opening.
     *
     * @param string $current_page  Current page identifier for active marking
     * @param array<string, mixed>  $extra_admin_links Additional admin links
     * @return string HTML of the complete header
     */
    public function header(string $current_page = '', array $extra_admin_links = []): string
    {
        $user = App::auth()->getUser();
        $is_admin = App::auth()->isAdmin();
        $is_admin_eff = App::auth()->isAdminEffective();

        $pending_count = 0;
        $my_en_cours_count = 0;
        try {
            $pending_count = App::tokenRepo()->countPendingForEmail($user);
            $my_en_cours_count = App::submissionRepo()->countEnCoursBySubmitter($user);
        } catch (\Throwable $e) {
            error_log('render_nav pending_count error: ' . $e->getMessage());
            $pending_count = -1;
            $my_en_cours_count = -1;
        }

        $persona_active = '';
        $persona_active_email = '';
        $persona_current_token = isset($_GET['persona_token']) ? (string) $_GET['persona_token'] : '';
        if ($is_admin && $persona_current_token !== '' && function_exists('persona_lookup')) {
            $persona_active_email = persona_lookup($persona_current_token);
            if ($persona_active_email !== '') {
                $persona_display = App::html()->displayUserShort($persona_active_email);
                $persona_active = '<div class="persona-banner" role="status">'
                    . '<span aria-hidden="true">🎭</span> '
                    . 'Mode persona : <strong>' . App::html()->escape($persona_display) . '</strong>'
                    . ' <form method="POST" action="index.php?p=persona&action=stop" class="u-dis">'
                    . App::security()->csrfField()
                    . '<input type="hidden" name="persona_token" value="' . App::html()->escape($persona_current_token) . '">'
                    . '<button type="submit" class="persona-reset u-bac-bor-col-cur-pad-tex">✕ Quitter</button>'
                    . '</form>'
                    . '</div>';
            }
        }

        $main_links = [
            'accueil'         => ['href' => 'index.php',                  'label' => 'Formulaires',     'icon' => '📝'],
            'mes_demandes'    => ['href' => 'index.php?p=my_submissions', 'label' => 'Mes demandes',    'icon' => '📋'],
            'mes_validations' => ['href' => 'index.php?p=my_validations', 'label' => 'Mes validations', 'icon' => '✅'],
            'docs'            => ['href' => 'index.php?p=docs',           'label' => 'Documentation',   'icon' => '📖'],
        ];

        $admin_links = [
            'forms'      => ['href' => 'index.php?p=admin_forms',      'label' => 'Gérer formulaires', 'icon' => '⚙️'],
            'dashboard'  => ['href' => 'index.php?p=dashboard',        'label' => 'Supervision',       'icon' => '📊'],
            'settings'   => ['href' => 'index.php?p=admin_settings',   'label' => 'Paramètres',        'icon' => '🔧'],
        ];

        $nav_html = '';
        $nav_html .= '<div class="sidebar-section-title">Navigation</div>';
        foreach ($main_links as $key => $link) {
            $active_cls = ($current_page === $key) ? ' active' : '';
            $badge = '';
            if ($key === 'mes_demandes' && $my_en_cours_count > 0) {
                $badge = '<span class="sidebar-badge" aria-label="' . $my_en_cours_count . ' en cours">' . $my_en_cours_count . '</span>';
            }
            if ($key === 'mes_validations' && $pending_count > 0) {
                $badge = '<span class="sidebar-badge" aria-label="' . $pending_count . ' en attente">' . $pending_count . '</span>';
            }
            $nav_html .= '<a href="' . $link['href'] . '" class="sidebar-item' . $active_cls . '">'
                . '<span class="sidebar-item-icon" aria-hidden="true">' . $link['icon'] . '</span>'
                . '<span class="sidebar-item-label">' . $link['label'] . '</span>'
                . $badge
                . '</a>';
        }

        if ($is_admin_eff) {
            $nav_html .= '<div class="sidebar-section-title">Administration</div>';
            foreach ($admin_links as $key => $link) {
                $active_cls = ($current_page === $key) ? ' active' : '';
                $nav_html .= '<a href="' . $link['href'] . '" class="sidebar-item' . $active_cls . '">'
                    . '<span class="sidebar-item-icon" aria-hidden="true">' . $link['icon'] . '</span>'
                    . '<span class="sidebar-item-label">' . $link['label'] . '</span>'
                    . '</a>';
            }
            foreach ($extra_admin_links as $key => $link) {
                $active_cls = ($current_page === $key) ? ' active' : '';
                $nav_html .= '<a href="' . $link['href'] . '" class="sidebar-item' . $active_cls . '">'
                    . '<span class="sidebar-item-icon" aria-hidden="true">' . $link['icon'] . '</span>'
                    . '<span class="sidebar-item-label">' . $link['label'] . '</span>'
                    . '</a>';
            }
        }

        $owned_forms = [];
        try {
            $owned_forms = App::auth()->getOwnedForms();
        } catch (\Throwable) {
            // ignore — section non affichée
        }
        if ($owned_forms !== []) {
            $nav_html .= '<div class="sidebar-section-title">Mes formulaires</div>';
            $active_cls = ($current_page === 'my_forms') ? ' active' : '';
            $nav_html .= '<a href="index.php?p=my_forms" class="sidebar-item' . $active_cls . '">'
                . '<span class="sidebar-item-icon" aria-hidden="true">📊</span>'
                . '<span class="sidebar-item-label">Suivi de mes formulaires</span>'
                . '</a>';
        }

        // v10.28.0 : self-agent mode — pas de liste d'autres users.
        // L'admin voit l'interface avec ses propres droits réduits.
        $persona_self_email = '';
        if ($is_admin) {
            $persona_self_email = $user;
        }

        $user_initials = strtoupper(substr($user, 0, 1));
        $user_short = App::html()->displayUserShort($user);

        $persona_active_short = $persona_active_email !== '' ? App::html()->displayUserShort($persona_active_email) : '';
        $user_card_data_persona = $is_admin ? ' data-persona-self="' . App::html()->escape($persona_self_email) . '"' : '';
        $user_card_data_active  = $persona_active_email !== '' ? ' data-persona-active="' . App::html()->escape($persona_active_email) . '"' : '';
        $csrfToken = $is_admin ? App::security()->generateCsrfToken() : '';
        $user_card_data_csrf = $is_admin ? ' data-csrf-token="' . App::html()->escape($csrfToken) . '"' : '';

        $displayed_user_short = $persona_active_short !== '' ? $persona_active_short : $user_short;
        $displayed_user_title = $persona_active_email !== '' ? $persona_active_email : $user;
        $displayed_initials   = $persona_active_short !== '' ? strtoupper(substr($persona_active_short, 0, 1)) : $user_initials;

        $user_card_html = '<div class="sidebar-user">'
            . '<div class="sidebar-user-card' . ($is_admin ? ' sidebar-user-card-admin' : '') . '"'
            . ' id="sidebar-user-card" tabindex="0" role="button"'
            . ' aria-label="' . ($is_admin ? 'Cliquer pour changer de persona' : 'Utilisateur connecté') . '"'
            . ' title="' . App::html()->escape($displayed_user_title) . '"'
            . $user_card_data_persona
            . $user_card_data_active
            . $user_card_data_csrf
            . '>'
            . '<span class="sidebar-user-avatar' . ($persona_active_email !== '' ? ' persona-active' : '') . '">' . $displayed_initials . '</span>'
            . '<span class="sidebar-user-email">' . App::html()->escape($displayed_user_short) . '</span>'
            . ($is_admin ? '<span class="sidebar-user-chevron" aria-hidden="true">▾</span>' : '')
            . '</div>'
            . '</div>';

        return '<div class="app-layout">'
            . '<nav class="sidebar" aria-label="Navigation principale">'
            . '<a href="index.php" class="sidebar-brand">'
            . '<span class="sidebar-logo-mark" aria-hidden="true">&#9670;</span>'
            . '<span class="sidebar-brand-text">' . App::html()->escape(self::getAppName()) . '</span>'
            . '</a>'
            . '<div class="sidebar-nav">'
            . $nav_html
            . '</div>'
            . $user_card_html
            . '</nav>'
            . '<div class="main-area">'
            . '<div class="content">'
            . $persona_active;
    }

    /**
     * Generates the page footer with persona dropdown JS.
     */
    public function footer(): string
    {
        ob_start();
        $script_nonce = App::security()->getScriptNonce();
        require __DIR__ . '/templates/persona_js.php';
        $persona_js = (string) ob_get_clean();

        return '</div><!-- /.content -->'
             . '</div><!-- /.main-area -->'
             . '</div><!-- /.app-layout -->'
             . $persona_js
             . '<footer>'
             . '<a href="index.php?p=changelog" title="Voir le journal des modifications">v' . App::html()->escape(get_latest_version()) . '</a>'
             . ' · ' . App::html()->escape(self::getAppName())
             . '</footer>';
    }
}
