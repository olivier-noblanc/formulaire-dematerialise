<?php
declare(strict_types=1);

/**
 * Navigation rendering — header, nav, breadcrumb, footer, page wrapper.
 *
 * @package lib
 */

// ── NAVIGATION PARTAGÉE ────────────────────────────────────────

/**
 * Génère le bandeau de navigation commun à toutes les pages.
 *
 * Alias de render_header() pour compatibilité ascendante.
 * Les pages existantes qui appellent render_nav() continuent de fonctionner.
 *
 * @param string $current_page  Identifiant de la page courante pour marquage actif
 * @param array<string, mixed>  $extra_admin_links Liens admin supplémentaires
 * @return string HTML du bandeau <nav>
 */
function render_nav(string $current_page = '', array $extra_admin_links = []): string {
    return render_header($current_page, $extra_admin_links);
}

/**
 * Génère l'en-tête complet : sidebar + ouverture du contenu.
 *
 * Structure HTML (depuis v9.1.0 — topbar supprimée) :
 *   <div class="app-layout">
 *     <nav class="sidebar">
 *       [Logo losange ◆ + DREETS]
 *       [CTA "+ Nouvelle demande" — mis en évidence en haut]
 *       [Navigation]
 *       [Carte utilisateur]
 *     </nav>
 *     <div class="main-area">
 *       <div class="content">
 *
 * Historique : avant v9.1.0, une barre horizontale (classe CSS topbar)
 * au-dessus du contenu affichait un fil d'Ariane "Accueil" + une cloche
 * + un bouton "Nouvelle demande". Elle a été supprimée car :
 *   - Le fil d'Ariane "Accueil" dupliquait le lien Accueil de la sidebar
 *   - La cloche dupliquait le badge "Mes validations" de la sidebar
 *   - Le bouton "Nouvelle demande" est désormais dans la sidebar
 *
 * Chaque page doit fermer par </div></div> avant render_footer().
 *
 * @param string $current_page  Identifiant de la page courante pour marquage actif
 * @param array<string, mixed>  $extra_admin_links Liens admin supplémentaires (clé => ['href'=>…, 'label'=>…, 'icon'=>…])
 * @return string HTML de l'en-tête complet
 */
function render_header(string $current_page = '', array $extra_admin_links = []): string {
    $user = get_auth_user();
    $is_admin = is_admin_user();           // réel (sécurité — pour le persona)
    // v9.9.0 — is_admin_effective = false si persona actif → masque la sidebar admin
    $is_admin_eff = is_admin_effective();

    // Compteur de validations en attente pour le badge
    $pending_count = 0;
    // v9.7.0 — Issue 2 : compteur de demandes en cours pour le badge "Mes demandes"
    $my_en_cours_count = 0;
    try {
        $pdo = get_pdo();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM tokens t
            JOIN submissions s ON s.id = t.submission_id
            WHERE t.email = ? AND t.done_at IS NULL
              AND (t.expires_at IS NULL OR t.expires_at > datetime('now'))
              AND s.closed_at IS NULL
        ");
        $stmt->execute([$user]);
        $pending_count = (int)$stmt->fetchColumn();

        // Issue 2 : nombre de soumissions en cours soumises par l'user courant
        // (badge iPhone rouge sur "Mes demandes" si > 0)
        $stmt2 = $pdo->prepare("
            SELECT COUNT(*) FROM submissions
            WHERE submitted_by = ? AND status = 'en_cours' AND closed_at IS NULL
        ");
        $stmt2->execute([$user]);
        $my_en_cours_count = (int)$stmt2->fetchColumn();
    } catch (\Throwable $e) {
        error_log('render_nav pending_count error: ' . $e->getMessage());
        $pending_count = -1; // Signal d'erreur pour affichage UI
        $my_en_cours_count = -1;
    }

    // v10.0.0 — Persona token-based (refonte) :
    // Le persona est activé via ?persona_token=XXX (géré par pages/persona.php).
    // Ici on affiche juste le bandeau si un persona est actif.
    $persona_active = '';
    $persona_active_email = '';
    $persona_current_token = isset($_GET['persona_token']) ? (string)$_GET['persona_token'] : '';
    if ($is_admin && $persona_current_token !== '' && function_exists('persona_lookup')) {
        $persona_active_email = persona_lookup($persona_current_token);
        if ($persona_active_email !== '') {
            $persona_display = display_user_short($persona_active_email);
            // Bouton "Quitter" → route persona?action=stop (qui révoque le token)
            $stop_url = 'index.php?p=persona&action=stop&persona_token=' . urlencode($persona_current_token);
            $persona_active = '<div class="persona-banner" role="status">'
                . '<span aria-hidden="true">🎭</span> '
                . 'Mode persona : <strong>' . h($persona_display) . '</strong>'
                . ' <a href="' . h($stop_url) . '" class="persona-reset">✕ Quitter</a>'
                . '</div>';
        }
    }

    // Liens principaux — toujours visibles
    // v10.0.7 — Audit UX/copywriting :
    //   - "Accueil" remplacé par "Formulaires" (plus explicite : l'accueil
    //     affiche les formulaires, autant l'appeler par son nom)
    //   - Le brand logo (◆ CircuitDémat) reste cliquable vers index.php
    //     (convention standard), mais on ne duplique plus avec "Accueil"
    //   - "Mes demandes" gardé : clair dans le contexte RH (l'agent sait
    //     que ses demandes = ses formulaires soumis)
    //   - "Mes validations" gardé : clair (le validateur sait ce qu'il doit valider)
    $main_links = [
        'accueil'         => ['href' => 'index.php',                  'label' => 'Formulaires',     'icon' => '📝'],
        'mes_demandes'    => ['href' => 'index.php?p=my_submissions', 'label' => 'Mes demandes',    'icon' => '📋'],
        'mes_validations' => ['href' => 'index.php?p=my_validations', 'label' => 'Mes validations', 'icon' => '✅'],
        'docs'            => ['href' => 'index.php?p=docs',           'label' => 'Documentation',   'icon' => '📖'],
    ];

    // Liens admin — toujours présents pour les admins
    // v10.0.7 — "Formulaires" → "Gérer formulaires" pour lever l'ambiguïté
    // avec le "Formulaires" de la navigation agent (qui = remplir un formulaire)
    $admin_links = [
        'forms'      => ['href' => 'index.php?p=admin_forms',      'label' => 'Gérer formulaires', 'icon' => '⚙️'],
        'dashboard'  => ['href' => 'index.php?p=dashboard',        'label' => 'Supervision',       'icon' => '📊'],
        'settings'   => ['href' => 'index.php?p=admin_settings',   'label' => 'Paramètres',        'icon' => '🔧'],
    ];

    // ── Build sidebar nav items ──────────────────────────────
    $nav_html = '';
    // v10.0.7 — CTA "＋ Nouvelle demande" supprimé (redondant avec le 1er
    // item "Formulaires" qui mène à l'accueil = liste des formulaires).
    // Less is more : un seul point d'entrée vers les formulaires.
    $nav_html .= '<div class="sidebar-section-title">Navigation</div>';
    foreach ($main_links as $key => $link) {
        $active_cls = ($current_page === $key) ? ' active' : '';
        $badge = '';
        // v9.7.0 — Issue 2 : badge sur "Mes demandes" si l'user a des demandes en cours
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

    // v9.9.0 — Section admin masquée si persona actif (is_admin_effective = false)
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

    // v10.1.8 — Section "Mes formulaires" : un seul item dans la sidebar
    // (pas 40 items si l'user owns 40 formulaires). Le lien mène vers l'accueil
    // qui affiche la section "Mes formulaires (suivi)" avec toutes les cartes.
    $owned_forms = [];
    try {
        $owned_forms = get_owned_forms($user);
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

    // v9.9.0 — Persona users list : préparé pour tous les admins RÉELS (même
    // en mode persona, pour pouvoir changer de persona ou quitter).
    // Ne dépend PAS de is_admin_effective.
    $persona_users_json = '[]';
    if ($is_admin && isset($pdo)) {
        try {
            $persona_stmt = $pdo->query("
                SELECT DISTINCT submitted_by FROM submissions
                WHERE submitted_by IS NOT NULL AND submitted_by != ''
                ORDER BY submitted_by LIMIT 50
            ");
            $persona_users = [];
            foreach ($persona_stmt->fetchAll(\PDO::FETCH_COLUMN) as $u) {
                $persona_users[] = [
                    'email' => $u,
                    'display' => display_user_short($u),
                ];
            }
            $persona_users_json = json_encode($persona_users, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            // ignore — le menu persona ne sera juste pas disponible
        }
    }

    // ── User initials for avatar ─────────────────────────────
    $user_initials = strtoupper(substr($user, 0, 1));
    $user_short = display_user_short($user);  // v9.8.0 — sans @domaine

    // ── User card avec persona intégré (v9.8.0, refonte v10.0.0) ──
    // La user card affiche le local part (sans @domaine).
    // Si admin, un clic ouvre un menu persona pour visualiser en tant qu'un autre user.
    // v10.0.0 — utilise $persona_active_email (défini plus haut via token lookup)
    $persona_active_short = $persona_active_email !== '' ? display_user_short($persona_active_email) : '';
    $user_card_data_persona = $is_admin ? ' data-persona-users="' . h($persona_users_json) . '"' : '';
    $user_card_data_active  = $persona_active_email !== '' ? ' data-persona-active="' . h($persona_active_email) . '"' : '';

    // Si persona actif, on affiche le persona (pas l'admin réel)
    $displayed_user_short = $persona_active_short !== '' ? $persona_active_short : $user_short;
    $displayed_user_title = $persona_active_email !== '' ? $persona_active_email : $user;
    $displayed_initials   = $persona_active_short !== '' ? strtoupper(substr($persona_active_short, 0, 1)) : $user_initials;

    $user_card_html = '<div class="sidebar-user">'
        . '<div class="sidebar-user-card' . ($is_admin ? ' sidebar-user-card-admin' : '') . '"'
        . ' id="sidebar-user-card" tabindex="0" role="button"'
        . ' aria-label="' . ($is_admin ? 'Cliquer pour changer de persona' : 'Utilisateur connecté') . '"'
        . ' title="' . h($displayed_user_title) . '"'
        . $user_card_data_persona
        . $user_card_data_active
        . '>'
        . '<span class="sidebar-user-avatar' . ($persona_active_email !== '' ? ' persona-active' : '') . '">' . $displayed_initials . '</span>'
        . '<span class="sidebar-user-email">' . h($displayed_user_short) . '</span>'
        . ($is_admin ? '<span class="sidebar-user-chevron" aria-hidden="true">▾</span>' : '')
        . '</div>'
        . '</div>';

    // ── Output full layout (topbar supprimée en v9.1.0) ──────
    return '<div class="app-layout">'
        . '<nav class="sidebar" aria-label="Navigation principale">'
        .   '<a href="index.php" class="sidebar-brand">'
        .     '<span class="sidebar-logo-mark" aria-hidden="true">&#9670;</span>'
        .     '<span class="sidebar-brand-text">' . h(get_app_name()) . '</span>'
        .   '</a>'
        .   '<div class="sidebar-nav">'
        .     $nav_html
        .   '</div>'
        .   $user_card_html
        . '</nav>'
        . '<div class="main-area">'
        .   '<div class="content">'
        .   $persona_active;  // v9.7.0 — bandeau "Mode persona" si actif
}

/**
 * Génère un fil d'Ariane.
 *
 * @param array<int, mixed> $breadcrumbs Tableau de [label, href] du plus haut au plus bas
 *                           Le dernier élément est la page courante (sans lien)
 * @return string HTML du fil d'Ariane
 */
function render_breadcrumb(array $breadcrumbs): string {
    if (empty($breadcrumbs)) return '';

    $items = [];
    $total = count($breadcrumbs);
    foreach ($breadcrumbs as $i => $crumb) {
        $label = h($crumb[0]);
        if ($i === $total - 1) {
            // Dernier = page courante
            $items[] = '<span aria-current="page" class="current">' . $label . '</span>';
        } else {
            $items[] = '<a href="' . h($crumb[1]) . '">' . $label . '</a>';
        }
    }

    return '<nav aria-label="Fil d\'Ariane" class="breadcrumb">
  ' . implode(' <span aria-hidden="true" class="separator">›</span> ', $items) . '
</nav>';
}

// ── FOOTER ────────────────────────────────────────────────────
function render_footer(): string {
    // v9.8.0 — JS pour le dropdown persona (user card admin)
    $persona_js = <<<'HTML'
<script>
(function() {
  var card = document.getElementById('sidebar-user-card');
  if (!card || !card.classList.contains('sidebar-user-card-admin')) return;

  // Créer le dropdown
  var dropdown = document.createElement('div');
  dropdown.className = 'sidebar-persona-dropdown';
  dropdown.id = 'sidebar-persona-dropdown';

  var usersJson = card.getAttribute('data-persona-users') || '[]';
  var activeEmail = card.getAttribute('data-persona-active') || '';
  var users = [];
  try { users = JSON.parse(usersJson); } catch(e) { users = []; }

  var html = '<div class="sidebar-persona-dropdown-header">🎭 Changer de rôle</div>';
  if (activeEmail) {
    // v10.0.0 — Quitter via la route dédiée persona?action=stop
    var stopUrl = 'index.php?p=persona&action=stop';
    var currentToken = new URLSearchParams(window.location.search).get('persona_token');
    if (currentToken) stopUrl += '&persona_token=' + encodeURIComponent(currentToken);
    html += '<a class="sidebar-persona-option-reset" href="' + stopUrl + '">✕ Revenir en mode admin</a>';
  }
  // v10.0.1 — Au lieu de lister tous les noms d'users (ridicule car toujours
  // le même), on propose un seul bouton "Vue agent" qui prend le 1er user
  // non-admin trouvé. L'objectif est de downgrader le rôle, pas d'imiter
  // un user spécifique.
  if (users.length > 0 && !activeEmail) {
    // Le 1er user de la liste = un agent au hasard (le ORDER BY submitted_by
    // garantit un ordre stable)
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

  // Toggle au clic
  card.addEventListener('click', function(e) {
    e.stopPropagation();
    dropdown.classList.toggle('open');
  });
  // Fermer si clic ailleurs
  document.addEventListener('click', function(e) {
    if (!card.contains(e.target)) {
      dropdown.classList.remove('open');
    }
  });
  // Fermer si Échap
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
         . '<a href="index.php?p=changelog" title="Voir le journal des modifications">v' . h(get_latest_version()) . '</a>'
         . ' · ' . h(get_app_name())
         . '</footer>';
}

// ── APP NAME & FAVICON (from DB) ───────────────────────────
function get_app_name(): string {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = get_setting('app_name', 'CircuitDémat');
    return $cache;
}

function render_favicon(): string {
    $svg = get_setting('app_favicon', '');
    if (!empty($svg)) {
        return '<link rel="icon" href="data:image/svg+xml,' . h($svg) . '">';
    }
    // Favicon par défaut : losange bleu (cohérent avec le sidebar-logo-mark ◆)
    // Attention : utiliser le caractère ◆ directement (pas \u25C6 qui n'est
    // pas interprété dans les data URIs SVG → affiche "250" littéralement)
    return '<link rel="icon" href="data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 100 100\'><rect width=\'100\' height=\'100\' rx=\'20\' fill=\'%231E40AF\'/><text x=\'50\' y=\'78\' font-size=\'80\' text-anchor=\'middle\' fill=\'white\' font-family=\'Arial\'>&#9670;</text></svg>">';
}

// ── RENDER PAGE (D1) ──────────────────────────────────────────
// Elimine la duplication du boilerplate HTML (DOCTYPE→</html>)
// sur toutes les pages du projet.
//
// Usage :
//   $page_css = 'body { padding:0; } .container { max-width:960px; }';
//   ob_start(); // then close PHP, output <h1>…</h1> … then reopen PHP
//   $content = ob_get_clean();
//   echo render_page('Titre', 'nav_key', $page_css, $content, [
//       'container_class' => 'container',
//       'nav_extra'       => [],        // 2e argument de render_nav()
//       'before_main'     => '',        // HTML entre render_nav() et <main>
//       'after_main'      => '',        // HTML entre </main> et render_footer()
//       'body_attr'       => '',        // attributs supplémentaires sur <body>
//       'raw_title'       => false,     // true = titre brut, sans " — AppName"
//   ]);
/** @param array<string, mixed> $options */
function render_page(
    string $title,
    string $nav_key,
    string $page_css  = '',
    string $content   = '',
    array  $options   = []
): string {
    // $page_css est DEPRECATED — tout le CSS est désormais servi par assets.php
    // avec cache HTTP (ETag + 304). Ce paramètre est conservé pour rétrocompat
    // mais IGNORE. Si du CSS spécifique est nécessaire, l'ajouter dans un
    // fichier lib/*_page.css inclus par assets.php.
    $container_class = $options['container_class'] ?? 'container';
    $body_attr       = $options['body_attr'] ?? '';
    $before_main     = $options['before_main'] ?? '';
    $after_main      = $options['after_main'] ?? '';
    $nav_extra       = $options['nav_extra'] ?? [];
    $raw_title       = $options['raw_title'] ?? false;

    // Ajouter une classe page-specific sur <body> pour permettre le scoping CSS.
    // Format : page-<nav_key> (ex: page-mes_validations, page-forms, page-settings).
    // Utilisé par lib/style_pages.css pour appliquer des règles uniquement sur
    // une page donnée (ex: .page-mes_validations .badge { ... }) au lieu de
    // définir des règles .badge génériques qui écrasent les autres pages.
    $page_body_class = 'page-' . preg_replace('/[^a-z0-9_-]/i', '', $nav_key);
    if ($body_attr) {
        // Si body_attr existe déjà, on ajoute la classe dedans
        if (preg_match('/class=["\']/', $body_attr)) {
            $body_attr = preg_replace('/class=["\']/', 'class="' . $page_body_class . ' ', $body_attr, 1);
        } else {
            $body_attr = 'class="' . $page_body_class . '" ' . $body_attr;
        }
    } else {
        $body_attr = 'class="' . $page_body_class . '"';
    }

    $full_title = $raw_title ? $title : ($title . ' — ' . h(get_app_name()));

    ob_start();
    // Sécurité (S-12) : headers de sécurité HTTP
    // Déjà envoyés globalement par send_security_headers(), mais on s'assure
    // qu'ils sont présents même si un output a commencé avant render_page()
    if (!headers_sent()) {
        send_security_headers();
    }
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $full_title ?></title>
  <?= render_favicon() ?>
  <link rel="stylesheet" href="assets.php?type=css">
  <?php // $page_css est deprecated — tout le CSS est servi par assets.php avec cache HTTP ?>
</head>
<body<?= $body_attr ? ' ' . $body_attr : '' ?>>
<a href="#main-content" class="skip-link">Aller au contenu principal</a>
<?= render_nav($nav_key, $nav_extra) ?>
<?= $before_main ?>
<main class="<?= h($container_class) ?>" id="main-content">
<?= $content ?>
</main>
<?= $after_main ?>
<?= render_footer() ?>
</body>
</html>
    <?php
    $page_out = ob_get_clean();
    if ($page_out === false) return '';

    // v10.0.0 — Refonte persona token-based :
    // Réécriture à la volée de toutes les URLs "index.php..." pour ajouter
    // ?persona_token=XXX si un persona est actif. Évite de modifier 80+ URLs
    // à la main dans tout le codebase.
    $page_out = persona_rewrite_urls($page_out);

    return $page_out;
}

/**
 * v10.0.0 — Réécrit les URLs dans le HTML rendu pour propager ?persona_token.
 *
 * Cherche tous les href="index.php..." et href='index.php...' et ajoute
 * ?persona_token=XXX (ou &persona_token=XXX) si un persona est actif.
 *
 * Ne touche PAS :
 *   - Les href avec variables PHP (<?= ?>) — laissés tels quels
 *   - Les URLs absolues (http://, https://, //, mailto:, tel:)
 *   - Les anchors purs (#xxx)
 *   - Les href vers la route persona elle-même (évite boucle)
 *
 * @param string $html Le HTML rendu
 * @return string Le HTML avec URLs réécrites
 */
function persona_rewrite_urls(string $html): string {
    $token = isset($_GET['persona_token']) ? (string)$_GET['persona_token'] : '';
    if ($token === '') return $html;

    // Pattern : href="index.php..." ou href='index.php...'
    // On capture l'URL complète (sans les quotes)
    return preg_replace_callback(
        '/href=(["\'])(index\.php[^"\']*?)\1/',
        function ($m) use ($token) {
            $quote = $m[1];
            $url = $m[2];
            // Skip si URL contient une variable PHP
            if (str_contains($url, '<?')) return $m[0];
            // Skip la route persona elle-même
            if (str_contains($url, 'p=persona')) return $m[0];
            // Skip si persona_token déjà présent
            if (str_contains($url, 'persona_token=')) return $m[0];

            // Séparer anchor
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
    );
}
