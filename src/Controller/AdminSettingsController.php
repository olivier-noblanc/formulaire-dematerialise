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

        require_once dirname(__DIR__, 2) . '/lib/admin_settings_handlers.php';
        require_once dirname(__DIR__, 2) . '/lib/render_admin_settings.php';

        $postResult = handle_admin_settings_post();

        $pageCss   = admin_settings_page_css();
        $content   = render_admin_settings_content($postResult);
        $afterMain = render_admin_settings_after_main();

        echo render_page('Paramètres', 'settings', $pageCss, $content, ['after_main' => $afterMain]);
    }
}
