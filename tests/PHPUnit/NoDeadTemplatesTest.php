<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * P0-6 (2026-09-03) — suppression du template mort src/Controller/templates/form_content.php.
 *
 * FormRenderer::loadTemplate() charge exclusivement
 * src/Render/templates/form_content.php ; la copie sous src/Controller/templates/
 * n'était require/include nulle part (zéro référence dans src/, tests/, index.php,
 * helpers.php) mais restait maintenue en parallèle lors des correctifs —
 * double source de vérité pour le HTML du formulaire.
 *
 * Fichier : tests/PHPUnit/NoDeadTemplatesTest.php
 */
final class NoDeadTemplatesTest extends TestCase
{
    public function testDeadControllerFormContentTemplateRemoved(): void
    {
        self::assertFileDoesNotExist(
            dirname(__DIR__, 2) . '/src/Controller/templates/form_content.php',
            'P0-6 : la copie morte src/Controller/templates/form_content.php doit être supprimée'
        );
    }

    public function testNoSourceReferencesToControllerTemplatesDir(): void
    {
        // Garde-fou anti-réintroduction : aucun code de src/ ne doit pointer
        // vers le dossier src/Controller/templates/.
        $hits = [];
        $srcDir = dirname(__DIR__, 2) . '/src';
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if ($content !== false && str_contains($content, 'Controller/templates')) {
                $hits[] = $file->getPathname();
            }
        }
        self::assertSame([], $hits, 'Aucune référence à Controller/templates ne doit subsister dans src/');
    }
}
