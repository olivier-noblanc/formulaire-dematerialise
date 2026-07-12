<?php
declare(strict_types=1);

namespace App\View;

use App\Render\HtmlService;
use App\Render\FormRenderer;
use App\Render\ErrorRenderer;
use App\Render\NavigationRenderer;

/**
 * Service de rendu de pages — enveloppe OOP pour les renderers.
 */
final class ViewRenderer
{
    private HtmlService $html;
    private FormRenderer $formRenderer;
    private ErrorRenderer $errorRenderer;
    private NavigationRenderer $navRenderer;

    public function __construct(HtmlService $html)
    {
        $this->html = $html;
        $this->formRenderer = new FormRenderer();
        $this->errorRenderer = new ErrorRenderer();
        $this->navRenderer = new NavigationRenderer();
    }

    public function page(
        string $title,
        string $currentPage = '',
        string $pageCss = '',
        string $content = ''
    ): string {
        return $this->navRenderer->page($title, $currentPage, $pageCss, $content);
    }

    public function header(string $currentPage = '', array $extraAdminLinks = []): string
    {
        return $this->navRenderer->header($currentPage, $extraAdminLinks);
    }

    public function breadcrumb(array $breadcrumbs): string
    {
        return $this->navRenderer->breadcrumb($breadcrumbs);
    }

    public function footer(): string
    {
        return $this->navRenderer->footer();
    }

    public function errorPage(
        int $code,
        string $title,
        string $message,
        string $hint = '',
        string $backUrl = 'index.php'
    ): void {
        $this->errorRenderer->errorPage($code, $title, $message, $hint, $backUrl);
    }

    public function messages(array $messages = []): string
    {
        return $this->errorRenderer->messages($messages);
    }

    public function favicon(): string
    {
        return NavigationRenderer::favicon();
    }

    public function field(
        array $field,
        mixed $postedVal,
        array $fieldErrors,
        string $datalistId = '',
        bool $disabled = false
    ): string {
        return $this->formRenderer->field($field, $postedVal, $fieldErrors, $datalistId, $disabled);
    }

    public function searchBar(
        string $actionUrl,
        string $currentSearch,
        string $placeholder = 'Rechercher...',
        array $hiddenFields = []
    ): string {
        return $this->formRenderer->searchBar($actionUrl, $currentSearch, $placeholder, $hiddenFields);
    }

    public function statusFilter(
        string $currentStatus,
        string $baseUrl,
        string $paramName = 'statut'
    ): string {
        return $this->formRenderer->statusFilter($currentStatus, $baseUrl, $paramName);
    }

    public function submissionData(
        array $data,
        array $exclude = [],
        string $format = 'p'
    ): string {
        return $this->formRenderer->submissionData($data, (array) $exclude, $format);
    }

    public function formProgressIndicator(array $grouped): string
    {
        return $this->formRenderer->formProgressIndicator($grouped);
    }

    public function ldapDatalist(string $listId, string $query = '', int $limit = 200): string
    {
        return (new \App\Render\LdapRenderer())->datalist($listId, $query, $limit);
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
