<?php
declare(strict_types=1);

// admin_settings.php — Page de configuration SMTP, vérification email et paramètres
//
// Le rendu HTML est extrait vers lib/render_admin_settings.php et les POST
// handlers vers lib/admin_settings_handlers.php pour garder ce fichier
// sous 600 lignes (refactor « all-under-600 »).
//  - lib/admin_settings_handlers.php : dispatcher POST (save_settings,
//                                      save_email_verify, test_email,
//                                      test_verify_email, test_webhook,
//                                      webhook_url/events)
//  - lib/render_admin_settings.php  : fonctions de rendu (CSS, sections
//                                      sécurité/SMTP/workflow/webhooks/résumé,
//                                      scripts JS toggle + scroll-spy)
//  - lib/admin_settings_page.css    : CSS volumineux de la page (chargé via
//                                      admin_settings_page_css())
//  - lib/admin_settings_scripts.js  : scripts JS de la page (chargés via
//                                      render_admin_settings_after_main())
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/lib/admin_settings_handlers.php';
require_once dirname(__DIR__) . '/lib/render_admin_settings.php';
use App\Core\App;

// Vérification des droits d'accès
App::auth()->requireAdmin();

// Traitement du POST — délégué au dispatcher lib/admin_settings_handlers.php.
// CSRF, validation et conservation des passwords sont gérés dans le handler.
$post_result = handle_admin_settings_post();

// Rendu — délégué à lib/render_admin_settings.php
$page_css   = admin_settings_page_css();
$content    = render_admin_settings_content($post_result);
$after_main = render_admin_settings_after_main();

echo render_page('Paramètres', 'settings', $page_css, $content, ['after_main' => $after_main]);
