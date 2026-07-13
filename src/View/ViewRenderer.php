<?php

declare(strict_types=1);

namespace App\View;

use App\Render\ErrorRenderer;
use App\Render\FormRenderer;
use App\Render\HtmlService;
use App\Render\NavigationRenderer;

/**
 * Service de rendu de pages — enveloppe OOP pour les renderers.
 */
final readonly class ViewRenderer
{
    private FormRenderer $formRenderer;
    private ErrorRenderer $errorRenderer;
    private NavigationRenderer $navigationRenderer;

    public function __construct(private HtmlService $htmlService)
    {
        $this->formRenderer = new FormRenderer();
        $this->errorRenderer = new ErrorRenderer();
        $this->navigationRenderer = new NavigationRenderer();
    }

    public function page(
        string $title,
        string $currentPage = '',
        string $pageCss = '',
        string $content = ''
    ): string {
        return $this->navigationRenderer->page($title, $currentPage, $pageCss, $content);
    }

    /** @param array<string, string> $extraAdminLinks */
    public function header(string $currentPage = '', array $extraAdminLinks = []): string
    {
        return $this->navigationRenderer->header($currentPage, $extraAdminLinks);
    }

    /** @param array<int, array{label: string, url: string}> $breadcrumbs */
    public function breadcrumb(array $breadcrumbs): string
    {
        return $this->navigationRenderer->breadcrumb($breadcrumbs);
    }

    public function footer(): string
    {
        return $this->navigationRenderer->footer();
    }

    public function errorPage(
        int $code,
        string $title,
        string $message,
        string $hint = '',
        string $backUrl = 'index.php'
    ): never {
        $this->errorRenderer->errorPage($code, $title, $message, $hint, $backUrl);
    }

    /** @param array<string, string> $messages */
    public function messages(array $messages = []): string
    {
        return $this->errorRenderer->messages($messages);
    }

    public function favicon(): string
    {
        return NavigationRenderer::favicon();
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, string> $fieldErrors
     */
    public function field(
        array $field,
        mixed $postedVal,
        array $fieldErrors,
        string $datalistId = '',
        bool $disabled = false
    ): string {
        return $this->formRenderer->field($field, $postedVal, $fieldErrors, $datalistId, $disabled);
    }

    /** @param array<string, string> $hiddenFields */
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

    /**
     * @param array<string, mixed> $data
     * @param list<string> $exclude
     */
    public function submissionData(
        array $data,
        array $exclude = [],
        string $format = 'p'
    ): string {
        return $this->formRenderer->submissionData($data, $exclude, $format);
    }

    /** @param array<string, array<int, array<string, mixed>>> $grouped */
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
        return $this->htmlService->h($val);
    }

    public function tJargon(string $text): string
    {
        return $this->htmlService->tJargon($text);
    }
}
