<?php
declare(strict_types=1);

/**
 * LDAP datalist rendering.
 *
 * render_ldap_datalist() génère un <datalist> HTML pour autocomplétion
 * d'emails depuis LDAP / Active Directory.
 *
 * @package lib
 */

// ── RENDU DATALIST LDAP ─────────────────────────────────────────

/**
 * Génère le HTML d'un élément <datalist> avec les suggestions LDAP.
 * Pur HTML5 — aucun JavaScript requis. Le navigateur gère le filtrage natif.
 *
 * @param string $list_id Identifiant HTML unique du datalist
 * @param string $query   Terme de recherche LDAP (optionnel)
 * @param int    $limit   Nombre max de résultats
 * @return string HTML du <datalist> ou chaîne vide si LDAP non configuré
 */
function render_ldap_datalist(string $list_id, string $query = '', int $limit = 200): string {
    $results = ldap_suggest($query, $limit);
    if (empty($results)) {
        return '';
    }

    $html = '<datalist id="' . h($list_id) . '">';
    foreach ($results as $entry) {
        // La valeur est l'email, le label affiche le nom
        $html .= '<option value="' . h($entry['email']) . '" label="' . h($entry['cn']) . '">';
    }
    $html .= '</datalist>';

    return $html;
}
