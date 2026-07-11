<?php
declare(strict_types=1);

namespace App\Render;

/**
 * LDAP datalist rendering — generates <datalist> HTML for email autocompletion.
 */
final class LdapRenderer
{
    /**
     * Generates HTML <datalist> with LDAP suggestions.
     * Pure HTML5 — no JavaScript required.
     *
     * @param string $list_id Unique HTML datalist identifier
     * @param string $query   LDAP search term (optional)
     * @param int    $limit   Maximum number of results
     * @return string HTML <datalist> or empty string if LDAP not configured
     */
    public function datalist(string $list_id, string $query = '', int $limit = 200): string
    {
        $results = \App\Core\App::emailVerify()->ldapSuggest($query, $limit);
        if (empty($results)) {
            return '';
        }

        $html = '<datalist id="' . \App\Core\App::html()->escape($list_id) . '">';
        foreach ($results as $entry) {
            $html .= '<option value="' . \App\Core\App::html()->escape($entry['email']) . '" label="' . \App\Core\App::html()->escape($entry['cn']) . '">';
        }
        $html .= '</datalist>';

        return $html;
    }
}
