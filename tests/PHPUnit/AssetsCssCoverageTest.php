<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Vérifie que TOUS les fichiers CSS de lib/ sont réellement servis aux navigateurs.
 *
 * Bug attrapé (2026-08-06) : lib/style_utility.css était absent de $sections
 * d'assets.php → toutes les classes utilitaires (styled-box-6, text-danger,
 * text-success, fw-bold, ...) n'étaient jamais chargées en prod : le bouton
 * "Supprimer" d'admin_forms s'affichait en style navigateur brut (gris, bordure
 * noire) au lieu du rouge #c0392b défini dans style_utility.css.
 *
 * CssCoverageTest ne peut pas attraper ça : il vérifie que les classes HTML
 * existent dans les FICHIERS lib/*.css, pas que ces fichiers sont chargés.
 */
final class AssetsCssCoverageTest extends TestCase
{
    /**
     * Sections extraites de $sections dans assets.php.
     *
     * @return list<string>
     */
    private function assetsSections(): array
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/assets.php');
        preg_match('/\$sections\s*=\s*\[(.*?)\];/s', $src, $m);
        $this->assertNotSame('', $m[1] ?? '', 'Impossible d\'extraire $sections depuis assets.php');
        preg_match_all("/'([a-z_]+)'/", $m[1], $names);

        return $names[1];
    }

    /**
     * Noms extraits de $pageCssFiles dans assets.php.
     *
     * @return list<string>
     */
    private function assetsPageCssFiles(): array
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/assets.php');
        preg_match('/\$pageCssFiles\s*=\s*\[(.*?)\];/s', $src, $m);
        $this->assertNotSame('', $m[1] ?? '', 'Impossible d\'extraire $pageCssFiles depuis assets.php');
        preg_match_all("/'([a-z_]+)'/", $m[1], $names);

        return $names[1];
    }

    /**
     * Noms de tous les fichiers CSS de lib/ (sans extension).
     *
     * @return list<string>
     */
    private function libCssFiles(): array
    {
        $names = [];
        foreach (glob(dirname(__DIR__, 2) . '/lib/*.css') ?: [] as $file) {
            $names[] = basename($file, '.css');
        }
        sort($names);

        return $names;
    }

    /**
     * Un CSS *_page.css absent d'assets.php est-il chargé par un renderer
     * (pageCss()/getPageCss() inline, ex: install_page.css) ?
     */
    private function pageCssLoadedByRenderer(string $cssName): bool
    {
        foreach (glob(dirname(__DIR__, 2) . '/src/Render/*.php') ?: [] as $renderer) {
            $content = (string) file_get_contents($renderer);
            if (str_contains($content, $cssName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Chaque lib/style_*.css doit avoir sa section dans $sections d'assets.php
     * (seule source servie — style.php est déprécié).
     */
    public function testAllStyleCssFilesAreServed(): void
    {
        $sections = $this->assetsSections();
        $missing  = [];

        foreach ($this->libCssFiles() as $name) {
            if (str_starts_with($name, 'style_')) {
                $section = substr($name, strlen('style_'));
                if (!in_array($section, $sections, true)) {
                    $missing[] = $name;
                }
            }
        }

        self::assertSame(
            [],
            $missing,
            'Fichiers lib/style_*.css absents de $sections dans assets.php (jamais servis) : ' . implode(', ', $missing)
        );
    }

    /**
     * Chaque lib/*_page.css doit être dans $pageCssFiles d'assets.php,
     * ou chargé inline par un renderer (pageCss()).
     */
    public function testAllPageCssFilesAreServed(): void
    {
        $pageCssFiles = $this->assetsPageCssFiles();
        $missing      = [];

        foreach ($this->libCssFiles() as $name) {
            if (str_ends_with($name, '_page')) {
                if (!in_array($name, $pageCssFiles, true) && !$this->pageCssLoadedByRenderer($name)) {
                    $missing[] = $name;
                }
            }
        }

        self::assertSame(
            [],
            $missing,
            'Fichiers lib/*_page.css absents de $pageCssFiles dans assets.php et non chargés par un renderer (jamais servis) : ' . implode(', ', $missing)
        );
    }
}
