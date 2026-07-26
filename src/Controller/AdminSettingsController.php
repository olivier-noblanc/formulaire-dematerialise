<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Contrôleur de la page Paramètres admin (SMTP, vérification email, etc.).
 */
final class AdminSettingsController extends BaseController
{
    public function handle(): void
    {
        App::auth()->requireAdmin();

        $postResult = AdminSettingsHandlers::handlePost();

        $pageCss   = admin_settings_page_css();
        $content   = render_admin_settings_content($postResult);
        $afterMain = render_admin_settings_after_main();

        echo new \App\Render\NavigationRenderer()->page('Paramètres', 'settings', $pageCss, $content, ['after_main' => $afterMain]);
    }
}
