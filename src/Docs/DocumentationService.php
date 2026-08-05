<?php
declare(strict_types=1);

namespace App\Docs;

class DocumentationService
{
    private function loadTemplate(string $filename): string
    {
        $filepath = __DIR__ . '/templates/' . $filename;
        if (!file_exists($filepath)) {
            throw new \RuntimeException("Template not found: {$filepath}");
        }
        ob_start();
        include $filepath;
        $html = ob_get_clean();
        return $html === false ? '' : $html;
    }

    public function renderStart(): string
    {
        return $this->loadTemplate('renderStart_section.php');
    }

    public function renderToc(): string
    {
        return $this->loadTemplate('renderToc_section.php');
    }

    public function renderQuickstart(): string
    {
        return $this->loadTemplate('renderQuickstart_section.php');
    }

    public function renderAgent(): string
    {
        return $this->loadTemplate('renderAgent_section.php');
    }

    public function renderValidateur(): string
    {
        return $this->loadTemplate('renderValidateur_section.php');
    }

    public function renderAdmin(): string
    {
        return $this->loadTemplate('admin_section.php');
    }

    public function renderFeatures(): string
    {
        return $this->loadTemplate('renderFeatures_section.php');
    }

    public function renderRoles(): string
    {
        return $this->loadTemplate('renderRoles_section.php');
    }

    public function renderFaq(): string
    {
        return $this->loadTemplate('renderFaq_section.php');
    }

    public function renderRgpd(): string
    {
        $legal_mentions = '';
        try {
            $legal_mentions = \App\Core\App::settings()->get('legal_mentions', '');
        } catch (\Exception $e) {
            $legal_mentions = '';
            error_log('DocumentationService::renderRgpd legal_mentions error: ' . $e->getMessage());
        }

        return $this->loadTemplate('renderRgpd_section.php');
    }

    public function renderTechnique(): string
    {
        return $this->loadTemplate('renderTechnique_section.php');
    }
}
