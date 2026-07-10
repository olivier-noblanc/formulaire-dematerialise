<?php
declare(strict_types=1);

/**
 * Façade — les fonctions sont absorbées par App\Render\AdminFormsRenderer.
 *
 * Ce fichier maintient la rétrocompatibilité : les anciens require_once
 * continuent de fonctionner. Les fonctions déléguent maintenant à la classe OOP.
 *
 * @package lib
 * @deprecated Utilisez App\Render\AdminFormsRenderer directement.
 */

// ── Sous-modules absorbés — les wrappers globaux sont dans src/lib_wrappers.php ──
require_once __DIR__ . '/admin_forms_render_css.php';
require_once __DIR__ . '/admin_forms_render_panels.php';
require_once __DIR__ . '/admin_forms_render_form.php';
require_once __DIR__ . '/admin_forms_render_workflow.php';
require_once __DIR__ . '/admin_forms_render_fields.php';
