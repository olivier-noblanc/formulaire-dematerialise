<?php
declare(strict_types=1);

// style.php — Design System 2026 "Institutionnel" v3 — Sidebar Layout
// À inclure via require_once __DIR__ . '/style.php'; dans le <head>
// Zéro JavaScript — Pure CSS + HTML5
// Layout : Sidebar blanche 196px + Contenu principal
// Palette bleu #000091 / rouge #E1000F
//
// Refactor « all-under-600 » (P-STYLE) : le CSS volumineux (~1800 lignes)
// est découpé en 7 sections thématiques sous lib/style_*.css. Ces fichiers
// sont des fragments CSS purs (aucun PHP) inclus via readfile() afin de
// préserver exactement le rendu <style>…</style> historique.
// L'ordre d'inclusion est important (tokens → layout → components → forms
// → responsive → features → onboarding) : les media queries et overrides
// doivent venir après les règles de base.

/**
 * Sections CSS thématiques du design system, dans l'ordre d'inclusion.
 *
 * @var list<string>
 */
$style_sections = [
    'tokens',      // Design tokens (:root) + reset & base
    'layout',      // App layout, sidebar, topbar, main, container, typography
    'components',  // Cards, boutons, messages, formulaires, tables, badges, stats…
    'forms',       // Form layout, pagination, info/warn, error pages, footer, print, animations
    'responsive',  // Media queries : sidebar collapsante (< 768px)
    'features',    // U-08 progression, P-02 brouillons, U-04 refus mobile
    'onboarding',  // U-06 field hints + welcome state + legacy bandeau
    'pages',      // CSS spécifique aux pages (validate, admin, etc.)
];

?>
<style>
<?php foreach ($style_sections as $section) : ?>
<?php readfile(__DIR__ . '/lib/style_' . $section . '.css'); ?>
<?php endforeach; ?>
</style>
