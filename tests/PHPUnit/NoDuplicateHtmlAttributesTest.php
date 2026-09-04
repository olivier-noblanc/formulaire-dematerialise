<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Régression — Attributs HTML dupliqués dans le code de rendu.
 *
 * Symptôme historique (lanes dates/tokens + rendu/navigation, 2026-09-01) :
 * des balises générées par les renderers portent DEUX attributs `class=`
 * (ex. `<div class="msg-error" role="alert" class="u-mb-05">`). Le second
 * attribut est ignoré par les navigateurs (comportement HTML5) : les
 * classes utilitaires (u-mt-1, u-mb-05...) sont silencieusement perdues.
 *
 * Test de scan statique (même approche que Bug08 / test_no_broken_urls) :
 * pour chaque balise HTML détectée dans une ligne source, on collecte les
 * noms d'attributs et on refuse tout doublon dans une même balise.
 *
 * Limitations documentées (KISS) :
 *  - scan ligne par ligne : une balise construite sur plusieurs lignes
 *    physiques n'est pas vérifiée ;
 *  - seules les valeurs entre guillemets doubles sont parsées (convention
 *    du codebase).
 *
 * Fichier : tests/PHPUnit/NoDuplicateHtmlAttributesTest.php
 *
 * @package tests\App\Tests
 */
final class NoDuplicateHtmlAttributesTest extends TestCase
{
    /**
     * Scan de tout src/ + assets/ : aucune balise ne doit porter un attribut dupliqué.
     */
    public function testNoDuplicateAttributesInGeneratedHtml(): void
    {
        $root = dirname(__DIR__, 2);
        $violations = [];

        foreach (['src', 'assets'] as $dir) {
            $base = $root . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            /** @var \SplFileInfo $file */
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $src = (string) file_get_contents($file->getPathname());
                $violations = array_merge($violations, $this->scanLines($path, $src));
            }
        }

        self::assertSame(
            [],
            $violations,
            "Attributs HTML dupliqués détectés (le 2e attribut est ignoré par les navigateurs) :\n"
            . implode("\n", $violations)
        );
    }

    /**
     * Scanne chaque ligne : détecte les balises contenant 2+ attributs du même nom.
     *
     * @return list<string>
     */
    private function scanLines(string $path, string $src): array
    {
        $violations = [];
        foreach (explode("\n", $src) as $n => $line) {
            // Balise ouvrante : <tag ...> sur la ligne (exclut </closing> et <!--)
            if (preg_match_all('/<[a-z][a-z0-9]*\b[^>]*>/i', $line, $tags) === false) {
                continue;
            }
            foreach ($tags[0] as $tag) {
                // Attributs : nom HTML ou aria-/data-, suivi de '="' (valeur
                // entre guillemets doubles — convention du codebase). Exclut
                // ?p=... &id=... (lookbehind) et les valeurs non quotées
                // (ex. "DC=dreets" dans un title=).
                if (preg_match_all('/(?<![?&\w])((?:aria|data)-[a-z-]+|[a-z][a-z0-9_.-]*)\s*=\s*"/i', $tag, $attrs) === false) {
                    continue;
                }
                $names = $attrs[1];
                $seen = [];
                foreach ($names as $attr) {
                    $key = strtolower($attr);
                    if (isset($seen[$key])) {
                        $violations[] = sprintf('%s:%d : attribut "%s" dupliqué dans %s', $path, $n + 1, $attr, $this->shortTag($tag));
                        continue 2;
                    }
                    $seen[$key] = true;
                }
            }
        }
        return $violations;
    }

    /**
     * Tronque une balise pour l'affichage du message d'erreur.
     */
    private function shortTag(string $tag): string
    {
        return strlen($tag) > 80 ? substr($tag, 0, 77) . '...' : $tag;
    }
}