<?php
declare(strict_types=1);

namespace App\View;

use App\Render\HtmlService;

/**
 * Service de rendu de pages — enveloppe render_page(), render_header(), etc.
 *
 * Les fonctions globales render_*() restent définies dans lib/ mais cette
 * classe expose une API OOP et permet l'injection de dépendances.
 */
final class ViewRenderer
{
    private HtmlService $html;

    public function __construct(HtmlService $html)
    {
        $this->html = $html;
    }

    public function page(
        string $title,
        string $currentPage = '',
        string $pageCss = '',
        string $content = ''
    ): string {
        return render_page($title, $currentPage, $pageCss, $content);
    }

    public function header(string $currentPage = '', array $extraAdminLinks = []): string
    {
        return render_header($currentPage, $extraAdminLinks);
    }

    public function breadcrumb(array $breadcrumbs): string
    {
        return render_breadcrumb($breadcrumbs);
    }

    public function footer(): string
    {
        return render_footer();
    }

    public function errorPage(
        int $code,
        string $title,
        string $message,
        string $hint = '',
        string $backUrl = 'index.php'
    ): void {
        render_error_page($code, $title, $message, $hint, $backUrl);
    }

    public function messages(array $messages = []): string
    {
        return render_messages($messages);
    }

    public function favicon(): string
    {
        return render_favicon();
    }

    public function field(
        array $field,
        mixed $postedVal,
        array $fieldErrors,
        string $datalistId = '',
        bool $disabled = false
    ): string {
        return render_field($field, $postedVal, $fieldErrors, $datalistId, $disabled);
    }

    public function searchBar(
        string $actionUrl,
        string $currentSearch,
        string $placeholder = 'Rechercher...',
        array $hiddenFields = []
    ): string {
        return render_search_bar($actionUrl, $currentSearch, $placeholder, $hiddenFields);
    }

    public function statusFilter(
        string $currentStatus,
        string $baseUrl,
        string $paramName = 'statut'
    ): string {
        return render_status_filter($currentStatus, $baseUrl, $paramName);
    }

    public function submissionData(
        array $data,
        array $exclude = ['validations', 'csrf_token'],
        string $format = 'p'
    ): string {
        return render_submission_data($data, $exclude, $format);
    }

    public function formProgressIndicator(array $grouped): string
    {
        return render_form_progress_indicator($grouped);
    }

    public function ldapDatalist(string $listId, string $query = '', int $limit = 200): string
    {
        return render_ldap_datalist($listId, $query, $limit);
    }

    public function h(?string $val): string
    {
        return $this->html->h($val);
    }

    public function tJargon(string $text): string
    {
        return $this->html->tJargon($text);
    }
}
