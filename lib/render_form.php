<?php
declare(strict_types=1);

/**
 * Form & UI rendering helpers.
 *
 * render_field() — génération HTML d'un champ dynamique (text/date/select/etc.)
 * render_search_bar() — barre de recherche réutilisable
 * render_status_filter() — filtre par statut
 * render_submission_data() — affichage des données d'une soumission
 * render_form_progress_indicator() — indicateur de progression
 *
 * @package lib
 */

// ── D4 : render_field() — génération HTML d'un champ dynamique ────

/**
 * Rend un champ dynamique en HTML avec support aria pour les erreurs.
 * @param array<string, mixed>  $field        Définition du champ (from form_fields table)
 * @param mixed  $posted_val   Valeur postée (ou null)
 * @param array<string, mixed>  $field_errors Erreurs de validation par field_name
 * @param string $datalist_id  ID du datalist pour autocomplétion LDAP
 * @param bool   $disabled     Si true, le champ est désactivé (prévisualisation)
 * @return string HTML du champ
 */
function render_field(array $field, mixed $posted_val, array $field_errors, string $datalist_id = '', bool $disabled = false): string {
    $name          = h($field['field_name']);
    // S4-UI / Action 1 : anti-jargon sur le label (ex. « Quotité » → « Temps de travail (en %) »,
    // « EPI » → « Équipement de protection individuelle (EPI) »). M. Robert (70 ans) ne comprend
    // pas le jargon RH/administratif. On applique t_jargon AVANT h() pour échapper le HTML ensuite.
    $label         = h(t_jargon($field['label']));
    $req_span      = $field['required'] ? ' <span class="req">*</span>' : '';
    $required_attr = (!$disabled && $field['required'] && $field['field_type'] !== 'checkbox') ? ' required aria-required="true"' : '';
    $error_class   = isset($field_errors[$field['field_name']]) ? ' field-error' : '';
    $disabled_attr = $disabled ? ' disabled' : '';

    // ── U-06 (part 1) : aide en ligne contextuelle automatique par type de champ ──
    // Un agent 40-60 ans qui saisit un formulaire doit savoir quel format attendre
    // sans avoir à deviner. On génère automatiquement un hint (classe .field-hint)
    // + un placeholder quand pertinent, et on le lie au champ via aria-describedby.
    // Le hint personnalisé de la base (colonne `hint` de form_fields) est conservé
    // à part (classe .hint) — il s'agit d'aide métier, pas de format.
    $auto_hint_id      = 'hint-' . $name;
    $auto_hint_text    = '';
    $placeholder       = '';
    $textarea_maxlength = 5000;

    // Détection automatique du type HTML5 basée sur le field_name (pour le cas 'text')
    $fn_lower    = mb_strtolower($field['field_name'], 'UTF-8');
    $html5_type  = 'text';
    $html5_extra = '';

    if (strpos($fn_lower, 'email') !== false || strpos($fn_lower, 'courriel') !== false || strpos($fn_lower, 'mel') !== false) {
        $html5_type  = 'email';
        $html5_extra = ' pattern="[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$"';
    } elseif (strpos($fn_lower, 'tel') !== false || strpos($fn_lower, 'telephone') !== false || strpos($fn_lower, 'portable') !== false || strpos($fn_lower, 'mobile') !== false) {
        $html5_type  = 'tel';
        $html5_extra = ' pattern="[0-9+\s\-.]{6,20}"';
    } elseif (strpos($fn_lower, 'montant') !== false || strpos($fn_lower, 'cout') !== false || strpos($fn_lower, 'prix') !== false || strpos($fn_lower, 'salaire') !== false || strpos($fn_lower, 'nombre_jour') !== false || strpos($fn_lower, 'quantite') !== false) {
        $html5_type  = 'number';
        $html5_extra = ' step="0.01" min="0"';
    } elseif (strpos($fn_lower, 'heure') !== false) {
        $html5_type = 'time';
    } elseif (strpos($fn_lower, 'url') !== false || strpos($fn_lower, 'lien') !== false || strpos($fn_lower, 'site') !== false) {
        $html5_type = 'url';
    }

    // Génération du hint + placeholder selon le type de champ (U-06 part 1)
    $max_size_mo = 0;
    switch ($field['field_type']) {
        case 'date':
            // input type="date" : le navigateur gère le format via date-picker natif,
            // mais un hint textuel rassure les agents peu à l'aise avec le numérique.
            // NB : placeholder ignoré par la plupart des navigateurs sur type=date
            // (le date-picker affiche son propre format), on l'ajoute pour spec UX.
            $auto_hint_text = 'Format : jour/mois/année (JJ/MM/AAAA)';
            $placeholder    = 'JJ/MM/AAAA';
            break;
        case 'email':
            $auto_hint_text = 'Exemple : prenom.nom@exemple.invalid';
            $placeholder    = 'prenom.nom@exemple.invalid';
            break;
        case 'textarea':
            // Toujours indiquer le maximum — la spec demande "~500 caractères si pas
            // de maxlength" ; on adapte au maxlength réel (5000 par défaut).
            $auto_hint_text = 'Texte libre, maximum ' . $textarea_maxlength . ' caractères';
            break;
        case 'file':
            // Hint déjà présent dans l'ancien code (formats + taille max) — on le
            // convertit en .field-hint pour bénéficier du lien aria-describedby.
            $max_size_mo    = round(get_max_file_size() / 1048576, 0);
            $auto_hint_text = 'Formats acceptés : PDF, images, Office, ZIP — Max ' . $max_size_mo . ' Mo';
            break;
        case 'text':
            // Pour les champs texte, le hint dépend du type HTML5 détecté via le field_name
            if ($html5_type === 'email') {
                $auto_hint_text = 'Exemple : prenom.nom@exemple.invalid';
                $placeholder    = 'prenom.nom@exemple.invalid';
            } elseif ($html5_type === 'tel') {
                $auto_hint_text = 'Format : 10 chiffres';
                $placeholder    = '01 23 45 67 89';
            } elseif ($html5_type === 'number') {
                $auto_hint_text = (strpos($html5_extra, 'step="0.01"') !== false)
                    ? 'Saisir un montant (décimal autorisé)'
                    : 'Saisir un nombre entier';
            } elseif ($html5_type === 'time') {
                $auto_hint_text = 'Format : HH:MM (24h)';
                $placeholder    = '14:30';
            } elseif ($html5_type === 'url') {
                $auto_hint_text = 'Exemple : https://www.exemple.fr';
                $placeholder    = 'https://';
            }
            // Pour les champs texte simples sans type HTML5 détecté : pas de hint
            // auto. "Texte libre" est évident et n'apporte rien à l'utilisateur.
            break;
        // 'select' et 'checkbox' : pas d'aide en ligne (le label / l'option par défaut suffit)
    }

    // Hint personnalisé depuis la base (colonne `hint` de form_fields) — aide métier.
    // S4-UI / Action 1 : anti-jargon aussi sur les hints métier (ex. « Indiquez votre
    // quotité en % » → « Indiquez votre temps de travail (en %) en % »).
    $user_hint = !empty($field['hint']) ? '<span class="hint">' . h(t_jargon($field['hint'])) . '</span>' : '';

    // Hint auto (format) — généré par le switch ci-dessus, lié au champ via aria-describedby
    $auto_hint_html = '';
    if ($auto_hint_text !== '') {
        $auto_hint_html = '<span id="' . $auto_hint_id . '" class="field-hint">' . h($auto_hint_text) . '</span>';
    }

    // aria-describedby : lie le champ au hint auto ET à l'erreur éventuelle (RGAA 11.9).
    // L'aide à la saisie est annoncée par le lecteur d'écran en plus du label.
    $described_ids = [];
    if (!$disabled && $auto_hint_text !== '') {
        $described_ids[] = $auto_hint_id;
    }
    if (!$disabled && isset($field_errors[$field['field_name']])) {
        $described_ids[] = 'err-' . $name;
    }
    $aria_attr = '';
    if (!$disabled && !empty($described_ids)) {
        $aria_attr = ' aria-describedby="' . implode(' ', $described_ids) . '"';
    }
    if (!$disabled && isset($field_errors[$field['field_name']])) {
        $aria_attr .= ' aria-invalid="true"';
    }

    $error_html = '';
    if (!$disabled && isset($field_errors[$field['field_name']])) {
        $error_html = '<span id="err-' . $name . '" class="error-hint">' . h($field_errors[$field['field_name']]) . '</span>';
    }

    // Attribut placeholder (uniquement si défini)
    $placeholder_attr = $placeholder !== '' ? ' placeholder="' . h($placeholder) . '"' : '';

    switch ($field['field_type']) {
        case 'email':
            $val       = h($posted_val ?? '');
            $maxlength = ' maxlength="500"';
            $list_attr = !empty($datalist_id) ? ' list="' . h($datalist_id) . '"' : '';
            return <<<HTML
<div class="field"><label for="{$name}">{$label}{$req_span}</label><input type="email" id="{$name}" name="{$name}"{$required_attr}{$aria_attr} class="{$error_class}" value="{$val}"{$maxlength}{$placeholder_attr} pattern="[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$"{$list_attr}{$disabled_attr}>{$auto_hint_html}{$user_hint}{$error_html}</div>
HTML;

        case 'date':
            $val = h($posted_val ?? '');
            return <<<HTML
<div class="field"><label for="{$name}">{$label}{$req_span}</label><input type="date" id="{$name}" name="{$name}"{$required_attr}{$aria_attr} class="{$error_class}" value="{$val}"{$placeholder_attr}{$disabled_attr}>{$auto_hint_html}{$user_hint}{$error_html}</div>
HTML;

        case 'select':
            $opts_raw    = $field['options'] ?? '[]';
            $opts        = json_decode($opts_raw, true) ?: [];
            $options_html = '<option value="">— Sélectionner —</option>';
            foreach ($opts as $opt) {
                $sel = ($posted_val === $opt) ? ' selected' : '';
                $options_html .= '<option value="' . h($opt) . '"' . $sel . '>' . h($opt) . '</option>';
            }
            return <<<HTML
<div class="field"><label for="{$name}">{$label}{$req_span}</label><select id="{$name}" name="{$name}"{$required_attr}{$aria_attr} class="{$error_class}"{$disabled_attr}>{$options_html}</select>{$user_hint}{$error_html}</div>
HTML;

        case 'checkbox':
            $checked = !empty($posted_val) ? ' checked' : '';
            return <<<HTML
<label class="checkbox-item"><input type="checkbox" name="{$name}" value="1"{$checked}{$disabled_attr}> {$label}</label>
HTML;

        case 'textarea':
            $val       = h($posted_val ?? '');
            $maxlength = ' maxlength="' . $textarea_maxlength . '"';
            return <<<HTML
<div class="field full"><label for="{$name}">{$label}{$req_span}</label><textarea id="{$name}" name="{$name}"{$required_attr}{$aria_attr} class="{$error_class}"{$placeholder_attr}{$maxlength}{$disabled_attr}>{$val}</textarea>{$auto_hint_html}{$user_hint}{$error_html}</div>
HTML;

        case 'file':
            $accept = implode(',', array_map(function($ext) { return '.' . $ext; }, get_allowed_extensions()));
            // $max_size_mo déjà calculé dans le switch auto-hint ci-dessus
            return <<<HTML
<div class="field"><label for="{$name}">{$label}{$req_span}</label><input type="file" id="{$name}" name="{$name}"{$required_attr}{$aria_attr} class="{$error_class}" accept="{$accept}"{$disabled_attr}>{$auto_hint_html}{$user_hint}{$error_html}</div>
HTML;

        default: // text — avec détection HTML5 automatique
            $val       = h($posted_val ?? '');
            $maxlength = ' maxlength="500"';
            return <<<HTML
<div class="field"><label for="{$name}">{$label}{$req_span}</label><input type="{$html5_type}" id="{$name}" name="{$name}"{$required_attr}{$aria_attr}{$html5_extra} class="{$error_class}" value="{$val}"{$maxlength}{$placeholder_attr}{$disabled_attr}>{$auto_hint_html}{$user_hint}{$error_html}</div>
HTML;
    }
}

// ── D11 : render_search_bar() — barre de recherche réutilisable ──

/**
 * Génère le HTML d'une barre de recherche avec bouton et lien d'effacement.
 * @param string $action_url    URL d'action du formulaire
 * @param string $current_search Terme de recherche actuel
 * @param string $placeholder   Texte d'aide du champ
 * @param array<string, mixed>  $hidden_fields Champs cachés additionnels [name => value]
 * @return string HTML du formulaire de recherche
 */
function render_search_bar(string $action_url, string $current_search, string $placeholder = 'Rechercher...', array $hidden_fields = []): string {
    $html  = '<form method="GET" action="' . h($action_url) . '" class="search-bar" role="search">';
    // aria-label explicite (U-09 RGAA 11.1) : un placeholder ne remplace pas un label
    $html .= '<input type="text" name="search" value="' . h($current_search) . '" placeholder="' . h($placeholder) . '" aria-label="' . h($placeholder) . '" class="search-input">';
    foreach ($hidden_fields as $hname => $hval) {
        $html .= '<input type="hidden" name="' . h($hname) . '" value="' . h($hval) . '">';
    }
    $html .= '<button type="submit" class="btn btn-secondary" style="font-size:.8rem;padding:.4rem .75rem;">Rechercher</button>';
    if ($current_search !== '') {
        // Build clear URL: same action_url minus the search param
        $clear_url = $action_url;
        $sep = (strpos($clear_url, '?') !== false) ? '&' : '?';
        $parts = [];
        foreach ($hidden_fields as $hname => $hval) {
            $parts[] = h($hname) . '=' . urlencode($hval);
        }
        if (!empty($parts)) {
            $clear_url .= (strpos($clear_url, '?') !== false ? '&' : '?') . implode('&', $parts);
        }
        $html .= ' <a href="' . h($clear_url) . '" class="btn btn-secondary" style="font-size:.8rem;padding:.4rem .75rem;">&#10005; Effacer</a>';
    }
    $html .= '</form>';
    return $html;
}

// ── D12 : render_status_filter() — filtre par statut réutilisable ──

/**
 * Génère les liens de filtre par statut (Tous / En cours / Validés / Refusés).
 * @param string $current_status Statut actif (tous|en_cours|valide|refuse)
 * @param string $base_url       URL de base à laquelle ajouter le paramètre de statut
 * @param string $param_name     Nom du paramètre d'URL (défaut : statut)
 * @return string HTML des liens de filtre
 */
function render_status_filter(string $current_status, string $base_url, string $param_name = 'statut'): string {
    $statuses = [
        'tous'     => '📊 Tous',
        'en_cours' => '⏳ En cours',
        'valide'   => '✓ Validés',
        'refuse'   => '❌ Refusés',
        'annule'   => '🗑 Annulés',
    ];
    $html = '<div class="filtres">';
    foreach ($statuses as $status => $label) {
        $sep   = (strpos($base_url, '?') !== false) ? '&' : '?';
        $active = ($current_status === $status) ? ' actif' : '';
        $html .= '<a href="' . h($base_url . $sep . $param_name . '=' . $status) . '" class="' . $active . '">' . $label . '</a>';
    }
    $html .= '</div>';
    return $html;
}

// ── D20 : render_submission_data() — affichage des données d'une soumission ──

/**
 * Affiche les données d'une soumission sous forme de paires clé/valeur.
 * @param array<string, mixed>  $data    Données de la soumission (décodées du JSON)
 * @param list<string>  $exclude Clés à exclure de l'affichage
 * @param string $format  Format de sortie : 'p' (paragraphe), 'inline' (en ligne), 'grid' (grille vc-data)
 * @return string HTML des données formatées
 */
function render_submission_data(array $data, array $exclude = ['validations', 'csrf_token'], string $format = 'p'): string {
    $html = '';
    foreach ($data as $k => $v) {
        if (empty($v)) continue;
        if (in_array($k, $exclude, true)) continue;
        $label   = h(ucfirst(str_replace('_', ' ', preg_replace('/^[a-z]+_/', '', $k) ?? $k)));
        $display = $v === '1' ? '<span aria-hidden="true">&#10003;</span>' . ($format === 'grid' ? ' Oui' : '') : h((string)$v);
        if ($format === 'inline') {
            $html .= '<strong>' . $label . ' :</strong> ' . $display . ' &nbsp;';
        } elseif ($format === 'grid') {
            $html .= '<div class="vc-data-item"><div class="vc-data-label">' . $label . '</div><div class="vc-data-value">' . $display . '</div></div>';
        } else {
            $html .= '<p><strong>' . $label . ' :</strong> ' . $display . '</p>';
        }
    }
    return $html;
}

// ═══════════════════════════════════════════════════════════════
// U-08 — INDICATEUR DE PROGRESSION DU FORMULAIRE
// S2-CTO : aide les agents (population 40-60 ans) à savoir où ils
// en sont dans un formulaire multi-sections. Si le formulaire n'a
// qu'une section, on n'affiche rien (évite le bruit visuel).
// ═══════════════════════════════════════════════════════════════

/**
 * Rend l'indicateur de progression pour un formulaire multi-sections (U-08).
 * Si le formulaire n'a qu'une seule section (ou zéro), retourne une chaîne vide.
 *
 * Le composant affiche "Étape X sur Y" + une barre de progression qui se
 * remplit en temps réel via un script vanilla JS minimal (cf. form.php).
 * Accessibilité : role="progressbar" + aria-valuemin/max/now.
 *
 * @param array<string, mixed> $grouped Sections regroupées (clé = titre section, valeur = champs)
 * @return string HTML de l'indicateur, ou '' si mono-section
 */
function render_form_progress_indicator(array $grouped): string {
    $section_count = count($grouped);
    if ($section_count <= 1) return '';

    // Compter le nombre total de champs saisissables (hors file — non pré-remplissable)
    $total_fields = 0;
    foreach ($grouped as $fields) {
        foreach ($fields as $f) {
            if (isset($f['field_type']) && $f['field_type'] !== 'file') {
                $total_fields++;
            }
        }
    }
    if ($total_fields === 0) return '';

    $html  = '<div class="form-progress" aria-live="polite">';
    $html .=   '<div class="form-progress-header">';
    $html .=     '<span class="form-progress-label">Étape <strong id="form-progress-current">0</strong> sur ' . $section_count . '</span>';
    $html .=     '<span class="form-progress-count"><span id="form-progress-filled">0</span> / ' . $total_fields . ' champ(s) rempli(s)</span>';
    $html .=   '</div>';
    $html .=   '<div class="form-progress-bar" role="progressbar" '
             . 'aria-valuemin="0" aria-valuemax="' . $total_fields . '" aria-valuenow="0" '
             . 'aria-label="Progression de la saisie du formulaire" id="form-progress-bar">';
    $html .=     '<div class="form-progress-fill" id="form-progress-fill" style="width:0%;"></div>';
    $html .=   '</div>';
    // Métadonnées pour le JS minimal vanilla (form.php)
    $html .=   '<input type="hidden" id="form-progress-total-fields" value="' . $total_fields . '">';
    $html .=   '<input type="hidden" id="form-progress-section-count" value="' . $section_count . '">';
    $html .= '</div>';
    return $html;
}
