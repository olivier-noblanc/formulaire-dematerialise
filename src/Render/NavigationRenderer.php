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
        if ($cache !== null) return $cache;
        $cache = App::settings()->get('app_name', 'CircuitDémat');
        return $cache;
    }

    /**
     * Renders the favicon link tag.
     */
    public static function favicon(): string
    {
        $svg = App::settings()->get('app_favicon', '');
        if (!empty($svg)) {
            return '<link rel="icon" href="data:image/svg+xml,' . \App\Core\App::html()->escape($svg) . '">';
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
            $pdo = App::db()->getPdo();
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM tokens t
                JOIN submissions s ON s.id = t.submission_id
                WHERE t.email = ? AND t.done_at IS NULL
                  AND (t.expires_at IS NULL OR t.expires_at > datetime('now'))
                  AND s.closed_at IS NULL
            ");
            $stmt->execute([$user]);
            $pending_count = (int)$stmt->fetchColumn();

            $stmt2 = $pdo->prepare("
                SELECT COUNT(*) FROM submissions
                WHERE submitted_by = ? AND status = 'en_cours' AND closed_at IS NULL
            ");
            $stmt2->execute([$user]);
            $my_en_cours_count = (int)$stmt2->fetchColumn();
        } catch (\Throwable $e) {
            error_log('render_nav pending_count error: ' . $e->getMessage());
            $pending_count = -1;
            $my_en_cours_count = -1;
        }

        $persona_active = '';
        $persona_active_email = '';
        $persona_current_token = isset($_GET['persona_token']) ? (string)$_GET['persona_token'] : '';
        if ($is_admin && $persona_current_token !== '' && function_exists('persona_lookup')) {
            $persona_active_email = persona_lookup($persona_current_token);
            if ($persona_active_email !== '') {
                $persona_display = App::html()->displayUserShort($persona_active_email);
                $stop_url = 'index.php?p=persona&action=stop&persona_token=' . urlencode($persona_current_token);
                $persona_active = '<div class="persona-banner" role="status">'
                    . '<span aria-hidden="true">🎭</span> '
                    . 'Mode persona : <strong>' . \App\Core\App::html()->escape($persona_display) . '</strong>'
                    . ' <a href="' . \App\Core\App::html()->escape($stop_url) . '" class="persona-reset">✕ Quitter</a>'
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
        } catch (\Throwable $e) {
            // ignore — section non affichée
        }
        if (!empty($owned_forms)) {
            $nav_html .= '<div class="sidebar-section-title">Mes formulaires</div>';
            $active_cls = ($current_page === 'my_forms') ? ' active' : '';
            $nav_html .= '<a href="index.php?p=my_forms" class="sidebar-item' . $active_cls . '">'
                . '<span class="sidebar-item-icon" aria-hidden="true">📊</span>'
                . '<span class="sidebar-item-label">Suivi de mes formulaires</span>'
                . '</a>';
        }

        $persona_users_json = '[]';
        if ($is_admin && isset($pdo)) {
            try {
                $persona_stmt = $pdo->query("
                    SELECT DISTINCT submitted_by FROM submissions
                    WHERE submitted_by IS NOT NULL AND submitted_by != ''
                    ORDER BY submitted_by LIMIT 50
                ");
                $persona_users = [];
                $persona_rows = $persona_stmt !== false ? $persona_stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
                foreach ($persona_rows as $u) {
                    $persona_users[] = [
                        'email' => $u,
                        'display' => App::html()->displayUserShort($u),
                    ];
                }
                $persona_users_json = json_encode($persona_users, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $user_initials = strtoupper(substr($user, 0, 1));
        $user_short = App::html()->displayUserShort($user);

        $persona_active_short = $persona_active_email !== '' ? App::html()->displayUserShort($persona_active_email) : '';
        $user_card_data_persona = $is_admin ? ' data-persona-users="' . \App\Core\App::html()->escape((string) $persona_users_json) . '"' : '';
        $user_card_data_active  = $persona_active_email !== '' ? ' data-persona-active="' . \App\Core\App::html()->escape($persona_active_email) . '"' : '';

        $displayed_user_short = $persona_active_short !== '' ? $persona_active_short : $user_short;
        $displayed_user_title = $persona_active_email !== '' ? $persona_active_email : $user;
        $displayed_initials   = $persona_active_short !== '' ? strtoupper(substr($persona_active_short, 0, 1)) : $user_initials;

        $user_card_html = '<div class="sidebar-user">'
            . '<div class="sidebar-user-card' . ($is_admin ? ' sidebar-user-card-admin' : '') . '"'
            . ' id="sidebar-user-card" tabindex="0" role="button"'
            . ' aria-label="' . ($is_admin ? 'Cliquer pour changer de persona' : 'Utilisateur connecté') . '"'
            . ' title="' . \App\Core\App::html()->escape($displayed_user_title) . '"'
            . $user_card_data_persona
            . $user_card_data_active
            . '>'
            . '<span class="sidebar-user-avatar' . ($persona_active_email !== '' ? ' persona-active' : '') . '">' . $displayed_initials . '</span>'
            . '<span class="sidebar-user-email">' . \App\Core\App::html()->escape($displayed_user_short) . '</span>'
            . ($is_admin ? '<span class="sidebar-user-chevron" aria-hidden="true">▾</span>' : '')
            . '</div>'
            . '</div>';

        return '<div class="app-layout">'
            . '<nav class="sidebar" aria-label="Navigation principale">'
            .   '<a href="index.php" class="sidebar-brand">'
            .     '<span class="sidebar-logo-mark" aria-hidden="true">&#9670;</span>'
            .     '<span class="sidebar-brand-text">' . \App\Core\App::html()->escape(self::getAppName()) . '</span>'
            .   '</a>'
            .   '<div class="sidebar-nav">'
            .     $nav_html
            .   '</div>'
            .   $user_card_html
            . '</nav>'
            . '<div class="main-area">'
            .   '<div class="content">'
            .   $persona_active;
    }

    /**
     * Generates a breadcrumb navigation.
     *
     * @param array<int, mixed> $breadcrumbs Array of [label, href] from top to bottom
     * @return string HTML of the breadcrumb
     */
    public function breadcrumb(array $breadcrumbs): string
    {
        if (empty($breadcrumbs)) return '';

        $items = [];
        $total = count($breadcrumbs);
        foreach ($breadcrumbs as $i => $crumb) {
            $label = \App\Core\App::html()->escape($crumb[0]);
            if ($i === $total - 1) {
                $items[] = '<span aria-current="page" class="current">' . $label . '</span>';
            } else {
                $items[] = '<a href="' . \App\Core\App::html()->escape($crumb[1]) . '">' . $label . '</a>';
            }
        }

        return '<nav aria-label="Fil d\'Ariane" class="breadcrumb">
  ' . implode(' <span aria-hidden="true" class="separator">›</span> ', $items) . '
</nav>';
    }

    /**
     * Generates the page footer with persona dropdown JS.
     */
    public function footer(): string
    {
        $persona_js = <<<'HTML'
<script>
(function() {
  var card = document.getElementById('sidebar-user-card');
  if (!card || !card.classList.contains('sidebar-user-card-admin')) return;

  var dropdown = document.createElement('div');
  dropdown.className = 'sidebar-persona-dropdown';
  dropdown.id = 'sidebar-persona-dropdown';

  var usersJson = card.getAttribute('data-persona-users') || '[]';
  var activeEmail = card.getAttribute('data-persona-active') || '';
  var users = [];
  try { users = JSON.parse(usersJson); } catch(e) { users = []; }

  var html = '<div class="sidebar-persona-dropdown-header">🎭 Changer de rôle</div>';
  if (activeEmail) {
    var stopUrl = 'index.php?p=persona&action=stop';
    var currentToken = new URLSearchParams(window.location.search).get('persona_token');
    if (currentToken) stopUrl += '&persona_token=' + encodeURIComponent(currentToken);
    html += '<a class="sidebar-persona-option-reset" href="' + stopUrl + '">✕ Revenir en mode admin</a>';
  }
  if (users.length > 0 && !activeEmail) {
    var firstUser = users[0];
    var url = 'index.php?p=persona&action=start&email=' + encodeURIComponent(firstUser.email);
    html += '<a class="sidebar-persona-option" href="' + url + '">'
          + '<span style="font-size:1.1em;margin-right:6px;">👤</span> Vue agent'
          + '<div style="font-size:11px;color:#888;margin-top:2px;">Visualiser l\'interface comme un utilisateur non-admin</div>'
          + '</a>';
  } else if (users.length === 0 && !activeEmail) {
    html += '<div style="padding:8px 12px;font-size:12px;color:#888;">Aucun utilisateur à afficher</div>';
  }
  dropdown.innerHTML = html;
  card.appendChild(dropdown);

  card.addEventListener('click', function(e) {
    e.stopPropagation();
    dropdown.classList.toggle('open');
  });
  document.addEventListener('click', function(e) {
    if (!card.contains(e.target)) {
      dropdown.classList.remove('open');
    }
  });
  card.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      dropdown.classList.toggle('open');
    }
    if (e.key === 'Escape') {
      dropdown.classList.remove('open');
    }
  });
})();
</script>
HTML;

        return '</div><!-- /.content -->'
             . '</div><!-- /.main-area -->'
             . '</div><!-- /.app-layout -->'
             . $persona_js
             . '<footer>'
             . '<a href="index.php?p=changelog" title="Voir le journal des modifications">v' . \App\Core\App::html()->escape(get_latest_version()) . '</a>'
             . ' · ' . \App\Core\App::html()->escape(self::getAppName())
             . '</footer>';
    }

    /**
     * Generates a full HTML page (D1) — eliminates boilerplate duplication.
     *
     * @param array<string, mixed> $options Page options
     */
    public function page(
        string $title,
        string $nav_key,
        string $page_css  = '',
        string $content   = '',
        array  $options   = []
    ): string {
        $container_class = $options['container_class'] ?? 'container';
        $body_attr       = $options['body_attr'] ?? '';
        $before_main     = $options['before_main'] ?? '';
        $after_main      = $options['after_main'] ?? '';
        $nav_extra       = $options['nav_extra'] ?? [];
        $raw_title       = $options['raw_title'] ?? false;

        $page_body_class = 'page-' . preg_replace('/[^a-z0-9_-]/i', '', $nav_key);
        if ($body_attr) {
            if (preg_match('/class=["\']/', $body_attr)) {
                $body_attr = preg_replace('/class=["\']/', 'class="' . $page_body_class . ' ', $body_attr, 1);
            } else {
                $body_attr = 'class="' . $page_body_class . '" ' . $body_attr;
            }
        } else {
            $body_attr = 'class="' . $page_body_class . '"';
        }

        $full_title = $raw_title ? $title : ($title . ' — ' . \App\Core\App::html()->escape(self::getAppName()));

        ob_start();
        if (!headers_sent()) {
            App::security()->sendSecurityHeaders();
        }
        ?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $full_title ?></title>
  <?= self::favicon() ?>
  <link rel="stylesheet" href="assets.php?type=css">
</head>
<body<?= $body_attr ? ' ' . $body_attr : '' ?>>
<a href="#main-content" class="skip-link">Aller au contenu principal</a>
<?= $this->nav($nav_key, $nav_extra) ?>
<?= $before_main ?>
<main class="<?= \App\Core\App::html()->escape($container_class) ?>" id="main-content">
<?= $content ?>
</main>
<?= $after_main ?>
<?= $this->footer() ?>
</body>
</html>
        <?php
        $page_out = ob_get_clean();
        if ($page_out === false) return '';

        $page_out = $this->personaRewriteUrls($page_out);

        return $page_out;
    }

    /**
     * Rewrites URLs in rendered HTML to propagate ?persona_token.
     *
     * @param string $html The rendered HTML
     * @return string The HTML with rewritten URLs
     */
    public function personaRewriteUrls(string $html): string
    {
        $token = isset($_GET['persona_token']) ? (string)$_GET['persona_token'] : '';
        if ($token === '') return $html;

        return preg_replace_callback(
            '/href=(["\'])(index\.php[^"\']*?)\1/',
            function ($m) use ($token) {
                $quote = $m[1];
                $url = $m[2];
                if (str_contains($url, '<?')) return $m[0];
                if (str_contains($url, 'p=persona')) return $m[0];
                if (str_contains($url, 'persona_token=')) return $m[0];

                $anchor = '';
                $url_main = $url;
                $pos = strpos($url, '#');
                if ($pos !== false) {
                    $anchor = substr($url, $pos);
                    $url_main = substr($url, 0, $pos);
                }

                $sep = str_contains($url_main, '?') ? '&' : '?';
                return 'href=' . $quote . $url_main . $sep . 'persona_token=' . urlencode($token) . $anchor . $quote;
            },
            $html
        ) ?? $html;
    }
}
