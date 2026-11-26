<?php
declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AdminFormCrudHandler;
use PHPUnit\Framework\TestCase;

/**
 * Garde statique sur le bouton « Formulaires exemples » (audit 2026-11-26).
 *
 * Bug réel : `adminForms_selectorPanel.php` postait `action=populate_samples`,
 * `AdminFormsController` vérifiait le CSRF puis `AdminFormsHandlers::dispatch()`
 * tombait sur `default => null`. Le POST était accepté sans erreur et sans
 * effet : aucun formulaire d'exemple créé, aucun message. Aucun test ne
 * couvrait le lien entre l'action postée par le template et le `match` du
 * dispatcher — d'où cette garde, sans base de données.
 */
final class AdminFormsSampleActionTest extends TestCase
{
    private const string ACTION = 'populate_samples';

    public function testSelectorPanelPostsPopulateSamplesWithCsrf(): void
    {
        $src = $this->read('src/Render/templates/adminForms_selectorPanel.php');

        self::assertMatchesRegularExpression(
            '/<form\b[^>]*method="POST".*?' . self::ACTION . '.*?<\/form>/s',
            $src,
            'Le bouton « Formulaires exemples » doit poster action=populate_samples dans un formulaire POST.'
        );
        self::assertStringContainsString(
            'name="action" value="' . self::ACTION . '"',
            $src,
            'Le template doit envoyer le champ action attendu par le dispatcher.'
        );
        self::assertStringContainsString(
            'csrfField()',
            $src,
            'Le formulaire POST du panneau doit porter son jeton CSRF (requireCsrf() côté contrôleur).'
        );
    }

    public function testDispatcherRoutesPopulateSamplesToHandler(): void
    {
        self::assertTrue(
            method_exists(AdminFormCrudHandler::class, 'handlePopulateSamples'),
            'AdminFormCrudHandler::handlePopulateSamples() est la cible du routage.'
        );

        $dispatch = $this->read('src/Controller/AdminFormsHandlers.php');
        self::assertMatchesRegularExpression(
            "/'" . self::ACTION . "'\s*=>\s*AdminFormCrudHandler::handlePopulateSamples\(\)/",
            $dispatch,
            "dispatch() doit router action=" . self::ACTION . ' vers le handler (sinon default => null, échec silencieux).'
        );

        $handler = $this->read('src/Controller/AdminFormCrudHandler.php');
        self::assertStringContainsString(
            '\populate_sample_forms(',
            $handler,
            'Le handler doit réutiliser le service existant populate_sample_forms() (SampleFormsService::populate()).'
        );
    }

    private function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relativePath;
        $src = file_get_contents($path);
        if ($src === false) {
            self::fail("Fichier illisible : $path");
        }
        return $src;
    }
}