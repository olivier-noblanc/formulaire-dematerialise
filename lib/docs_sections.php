<?php
declare(strict_types=1);

/**
 * Documentation sections — point d'entrée unique.
 *
 * Ce fichier charge la classe DocumentationService et définit
 * les wrappers globaux pour rétrocompatibilité.
 *
 * @package lib
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Docs\DocumentationService;

/**
 * Retourne une instance de DocumentationService (singleton lazy).
 */
function docs_service(): DocumentationService
{
    static $instance = null;
    if ($instance === null) {
        $instance = new DocumentationService();
    }
    return $instance;
}

function render_docs_section_start(): string
{
    return docs_service()->renderStart();
}

function render_docs_section_toc(): string
{
    return docs_service()->renderToc();
}

function render_docs_section_quickstart(): string
{
    return docs_service()->renderQuickstart();
}

function render_docs_section_agent(): string
{
    return docs_service()->renderAgent();
}

function render_docs_section_validateur(): string
{
    return docs_service()->renderValidateur();
}

function render_docs_section_admin(): string
{
    return docs_service()->renderAdmin();
}

function render_docs_section_features(): string
{
    return docs_service()->renderFeatures();
}

function render_docs_section_roles(): string
{
    return docs_service()->renderRoles();
}

function render_docs_section_faq(): string
{
    return docs_service()->renderFaq();
}

function render_docs_section_rgpd(): string
{
    return docs_service()->renderRgpd();
}

function render_docs_section_technique(): string
{
    return docs_service()->renderTechnique();
}
