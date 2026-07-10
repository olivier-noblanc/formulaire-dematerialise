<?php
declare(strict_types=1);

/**
 * LDAP datalist rendering — thin wrapper delegating to App\Render\LdapRenderer.
 *
 * @package lib
 */

/**
 * Generates the HTML of a <datalist> element with LDAP suggestions.
 *
 * @param string $list_id Unique HTML identifier of the datalist
 * @param string $query   LDAP search term (optional)
 * @param int    $limit   Maximum number of results
 * @return string HTML of the <datalist> or empty string if LDAP not configured
 */
function render_ldap_datalist(string $list_id, string $query = '', int $limit = 200): string {
    static $renderer = null;
    if ($renderer === null) {
        $renderer = new \App\Render\LdapRenderer();
    }
    return $renderer->datalist($list_id, $query, $limit);
}
