<?php
declare(strict_types=1);

/**
 * Documentation sections — point d'entrée unique.
 *
 * Ce fichier charge toutes les sections de documentation en un seul require.
 * Remplace les 11 require_once individuels dans pages/docs.php.
 *
 * @package lib
 */

require_once __DIR__ . '/docs_section_start.php';
require_once __DIR__ . '/docs_section_toc.php';
require_once __DIR__ . '/docs_section_quickstart.php';
require_once __DIR__ . '/docs_section_agent.php';
require_once __DIR__ . '/docs_section_validateur.php';
require_once __DIR__ . '/docs_section_admin.php';
require_once __DIR__ . '/docs_section_features.php';
require_once __DIR__ . '/docs_section_roles.php';
require_once __DIR__ . '/docs_section_faq.php';
require_once __DIR__ . '/docs_section_rgpd.php';
require_once __DIR__ . '/docs_section_technique.php';
