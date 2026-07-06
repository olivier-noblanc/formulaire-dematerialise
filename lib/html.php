<?php
/**
 * lib_html.php — Helpers de rendu HTML (échappement, pagination, icônes, graphiques).
 *
 * Module Phase 1 du découpage progressif de helpers.php (S3-CTO).
 * Chargé automatiquement par helpers.php via require_once — aucune inclusion
 * manuelle nécessaire. Les fonctions restent disponibles globalement
 * (pas de namespace, pas de classe) — compatibilité ascendante totale.
 *
 * Fonctions exposées :
 *  - h()                 : échappement HTML (htmlspecialchars ENT_QUOTES UTF-8)
 *  - format_file_size()  : formate une taille en octets vers Ko/Mo lisible
 *  - get_file_icon()     : retourne l'emoji icône correspondant à un type MIME
 *  - render_pagination() : génère le HTML de pagination (Précédent/Suivant)
 *  - render_donut_chart() : génère un donut chart SVG/CSS de répartition des statuts
 *
 * Aucune dépendance externe — utilise uniquement des fonctions natives PHP
 * (htmlspecialchars, round, strpos, concaténation de chaînes).
 * Note : render_pagination() appelle h() — dépendance intra-module satisfaite
 * par le chargement de ce même fichier.
 *
 * Plan 3 phases (CTO, REUNION1-CTO §4) :
 *  - Phase 1 (S3, cette version) : fonctions autonomes peu couplées.
 *  - Phase 2 (S4) : fonctions medium-coupling (workflow, mail, LDAP, RGPD).
 *  - Phase 3 (S5+) : fonctions à couplage fort (DB, cache, settings).
 */

function h(?string $val): string {
    return htmlspecialchars($val ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Affiche un email/user de façon épurée (sans le domaine si = user courant).
 *
 * Règles (v9.8.0 — refonte globale) :
 *   1. Si l'email = user courant connecté → "Vous" (bold)
 *   2. Sinon, si le domaine = domaine du user courant → masque le domaine
 *      (ex: 'jean.dupont@exemple.invalid' → 'jean.dupont@')
 *   3. Sinon → email complet (domaine différent = info utile)
 *
 * Le paramètre $current_user permet de surcharger l'user courant (utile
 * pour les tests ou le persona admin qui veut voir comme un autre user).
 *
 * @param string      $email        Email à afficher
 * @param string|null $current_user User courant (défaut: get_auth_user())
 * @param bool        $force_email  True = toujours afficher l'email complet
 *                                  (utile pour les inputs/formulaires)
 * @return string HTML échappé
 */
function display_user(string $email, ?string $current_user = null, bool $force_email = false): string {
    if ($email === '') return '';
    if ($force_email) return h($email);

    if ($current_user === null) {
        $current_user = function_exists('get_auth_user') ? get_auth_user() : '';
    }

    // Cas 1 : email = user courant → "Vous"
    if ($current_user !== '' && strcasecmp($email, $current_user) === 0) {
        return '<strong>Vous</strong>';
    }

    // Cas 2 : masquer le domaine si = domaine du user courant
    if ($current_user !== '' && str_contains($current_user, '@')) {
        $user_domain = strtolower(substr($current_user, strrpos($current_user, '@')));
        $email_lower = strtolower($email);
        if (str_ends_with($email_lower, $user_domain)) {
            $local_part = substr($email, 0, strlen($email) - strlen($user_domain));
            return h($local_part . '@');
        }
    }

    // Cas 3 : email complet
    return h($email);
}

/**
 * Affiche le "local part" d'un email (sans @domaine), pour la user card sidebar.
 *
 * Ex: 'admin.local@exemple.invalid' → 'admin.local'
 *     'admin.local' (sans @) → 'admin.local' (inchangé)
 *
 * @param string $email
 * @return string HTML échappé
 */
function display_user_short(string $email): string {
    if ($email === '') return '';
    // Si contient @, on garde tout ce qui est avant @
    $at_pos = strpos($email, '@');
    if ($at_pos !== false) {
        return h(substr($email, 0, $at_pos));
    }
    // Si format Windows DREETS\prenom.nom → prenom.nom
    if (str_contains($email, '\\')) {
        $parts = explode('\\', $email);
        return h($parts[1] ?? $parts[0]);
    }
    return h($email);
}

/**
 * Construit une URL en propageant automatiquement ?persona_token si présent.
 *
 * v10.0.0 — Refonte persona token-based : propage le token persona dans
 * les URLs construites via cette fonction.
 *
 * Note : pour le HTML rendu via render_page(), un filtre ob_start() réécrit
 * automatiquement TOUTES les URLs href="index.php..." → build_url() n'est
 * utile que pour les URLs construites en PHP pur (redirects, etc.).
 *
 * @param string $url URL relative (ex: 'index.php?p=xxx')
 * @return string URL avec ?persona_token=XXX ajouté si un persona est actif
 */
function build_url(string $url): string {
    $token = isset($_GET['persona_token']) ? (string)$_GET['persona_token'] : '';
    if ($token === '') return $url;

    // Séparer anchor (#xxx) du reste
    $anchor = '';
    $url_without_anchor = $url;
    $anchor_pos = strpos($url, '#');
    if ($anchor_pos !== false) {
        $anchor = substr($url, $anchor_pos);
        $url_without_anchor = substr($url, 0, $anchor_pos);
    }

    // Ajouter persona_token
    $sep = str_contains($url_without_anchor, '?') ? '&' : '?';
    return $url_without_anchor . $sep . 'persona_token=' . urlencode($token) . $anchor;
}

/**
 * Formate la taille d'un fichier en unités lisibles
 */
function format_file_size(int $bytes): string {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' Mo';
    } elseif ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' Ko';
    }
    return $bytes . ' octets';
}

/**
 * Retourne l'icône correspondant au type de fichier
 */
function get_file_icon(string $mime_type): string {
    // Order matters: check most specific MIME patterns first.
    // 'sheet'/'excel' must be checked before 'document' because xlsx MIME contains 'document'
    // e.g. application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
    if (strpos($mime_type, 'pdf') !== false) return '📄';
    if (strpos($mime_type, 'image') !== false) return '🖼';
    if (strpos($mime_type, 'sheet') !== false || strpos($mime_type, 'excel') !== false) return '📊';
    if (strpos($mime_type, 'presentation') !== false || strpos($mime_type, 'powerpoint') !== false) return '📽';
    if (strpos($mime_type, 'word') !== false || strpos($mime_type, 'document') !== false) return '📝';
    if (strpos($mime_type, 'zip') !== false) return '📦';
    if (strpos($mime_type, 'text') !== false) return '📃';
    return '📎';
}

/**
 * Génère la pagination HTML.
 * @param int $page Page actuelle
 * @param int $total_pages Nombre total de pages
 * @param string $base_url URL de base (sans le paramètre page)
 * @return string HTML de la pagination
 */
function render_pagination(int $page, int $total_pages, string $base_url): string {
    if ($total_pages <= 1) return '';
    $html = '<div class="pagination" style="display:flex;gap:.5rem;align-items:center;margin:1.5rem 0;flex-wrap:wrap;">';
    $sep = (strpos($base_url, '?') !== false) ? '&' : '?';
    if ($page > 1) {
        $html .= '<a href="' . h($base_url . $sep . 'page=' . ($page - 1)) . '" class="btn btn-secondary" style="font-size:.8rem;padding:.3rem .75rem;">← Précédent</a>';
    }
    $html .= '<span style="font-size:.85rem;color:var(--c-text-secondary);">Page ' . $page . ' / ' . $total_pages . '</span>';
    if ($page < $total_pages) {
        $html .= '<a href="' . h($base_url . $sep . 'page=' . ($page + 1)) . '" class="btn btn-secondary" style="font-size:.8rem;padding:.3rem .75rem;">Suivant →</a>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Génère le HTML d'un donut chart de répartition des statuts.
 * @param int $total Nombre total de soumissions
 * @param int $valide Nombre de soumissions validées
 * @param int $en_cours Nombre de soumissions en cours
 * @param int $refuse Nombre de soumissions refusées
 * @return string HTML du donut chart avec légende
 */
function render_donut_chart(int $total, int $valide, int $en_cours, int $refuse): string {
    if ($total <= 0) {
        return '<div class="chart-row">'
             . '<div class="donut-chart" style="background:#e0e0e0;">'
             . '<div class="donut-center">'
             . '<span class="donut-value">0</span>'
             . '<span class="donut-label">Total</span>'
             . '</div></div>'
             . '<div class="chart-legend">'
             . '<div class="legend-item"><span class="legend-dot" style="background:#1a6b3c;"></span><strong>Validées</strong> : 0 (0%)</div>'
             . '<div class="legend-item"><span class="legend-dot" style="background:#b45309;"></span><strong>En cours</strong> : 0 (0%)</div>'
             . '<div class="legend-item"><span class="legend-dot" style="background:#c0392b;"></span><strong>Refusées</strong> : 0 (0%)</div>'
             . '</div></div>';
    }

    $p_valide = round(($valide / $total) * 100);
    $p_en_cours = round(($en_cours / $total) * 100);
    $p_refuse = round(($refuse / $total) * 100);
    // Ajuster pour que ça fasse 100%
    $diff = 100 - $p_valide - $p_en_cours - $p_refuse;
    $p_valide += $diff; // Compenser les arrondis

    // Construire le conic-gradient
    $g_valide_end = $p_valide;
    $g_en_cours_end = $p_valide + $p_en_cours;
    $gradient = "conic-gradient(#1a6b3c 0% {$g_valide_end}%, #b45309 {$g_valide_end}% {$g_en_cours_end}%, #c0392b {$g_en_cours_end}% 100%)";

    return '<div class="chart-row">'
         . '<div class="donut-chart" style="background:' . $gradient . ';">'
         . '<div class="donut-center">'
         . '<span class="donut-value">' . $total . '</span>'
         . '<span class="donut-label">Total</span>'
         . '</div></div>'
         . '<div class="chart-legend">'
         . '<div class="legend-item"><span class="legend-dot" style="background:#1a6b3c;"></span><strong>Validées</strong> : ' . $valide . ' (' . $p_valide . '%)</div>'
         . '<div class="legend-item"><span class="legend-dot" style="background:#b45309;"></span><strong>En cours</strong> : ' . $en_cours . ' (' . $p_en_cours . '%)</div>'
         . '<div class="legend-item"><span class="legend-dot" style="background:#c0392b;"></span><strong>Refusées</strong> : ' . $refuse . ' (' . $p_refuse . '%)</div>'
         . '</div></div>';
}
