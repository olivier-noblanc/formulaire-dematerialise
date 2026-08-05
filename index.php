<?php
// index.php — Front controller unique (router)
//
// TOUT passe par ici. Aucun autre fichier .php à la racine n'est un point d'entrée.
// Les pages sont dans pages/ et sont chargées par ce router.
//
// URL : index.php?p=admin_settings  →  pages/admin_settings.php
// URL : index.php (sans ?p=)        →  pages/accueil.php (page d'accueil)
//
// Sécurité : le paramètre p est validé contre une whitelist de pages autorisées.
// Pas d'URL rewriting nécessaire — le query string fait le routage.

require_once __DIR__ . '/helpers.php';

// ── Whitelist des pages autorisées ──
// Chaque entrée correspond à un fichier pages/<key>.php
$ALLOWED_PAGES = [
    // Pages publiques
    'accueil'           => 'Page d\'accueil',
    'form'              => 'Formulaire',
    'validate'          => 'Validation',
    'my_submissions'    => 'Mes demandes',
    'my_validations'    => 'Mes validations',
    'docs'              => 'Documentation',
    'changelog'         => 'Journal des modifications',
    'my_forms'          => 'Mes formulaires',  // v10.1.9 — page dédiée owners
    'download'          => 'Téléchargement',
    'form_preview'      => 'Prévisualisation',
    'form_tracking'     => 'Suivi',
    'submission_view'   => 'Détail soumission',
    'confirm_action'    => 'Confirmation',
    'screenshot'        => 'Capture d\'écran',
    'rgpd'              => 'RGPD',
    // Pages admin
    'admin_access'      => 'Accès admin',
    'admin_alerts'      => 'Alertes',
    'admin_forms'       => 'Gestion formulaires',
    'admin_settings'    => 'Paramètres',
    'backup'            => 'Sauvegarde',
    'dashboard'         => 'Supervision',
    'health'            => 'Santé système',
    'monitoring'        => 'Surveillance',
    'stats'             => 'Statistiques',
    'persona'           => 'Persona',  // v10.0.0 — route dédiée persona (start/stop)
];

// ── Router ──
// Détection de la page : priorité à ?p=, puis déduction depuis les paramètres
// (ex: ?form_id=XXX → admin_forms, ?token=XXX → validate, ?id=XXX → submission_view)
$page = $_GET['p'] ?? '';
if ($page === '') {
    // Auto-détection : si form_id est présent sans ?p=, c'est admin_forms
    if (isset($_GET['form_id']) && $_GET['form_id'] !== '') {
        $page = 'admin_forms';
    } elseif (isset($_GET['token']) && $_GET['token'] !== '') {
        $page = 'validate';
    } elseif (isset($_GET['id']) && $_GET['id'] !== '' && !isset($_GET['action'])) {
        // ?id=XXX sans ?action= → submission_view (mais pas si ?action= qui est pour admin_access)
        $page = 'submission_view';
    } else {
        $page = 'accueil';
    }
}
$page = preg_replace('/[^a-z_]/', '', $page) ?? ''; // Sanitize : lettres + underscore uniquement

if (!array_key_exists($page, $ALLOWED_PAGES)) {
    http_response_code(404);
    (new \App\Render\ErrorRenderer())->errorPage(404, 'Page introuvable',
        'La page demandée n\'existe pas.',
        'Vérifiez l\'adresse ou retournez à l\'accueil.');
    // errorPage() appelle exit() — pas besoin de exit ici
}

// ── Mapping pages → Controllers OOP ──
// Les contrôleurs migrés sont utilisés quand disponibles
$CONTROLLER_MAP = [
    'accueil' => \App\Controller\IndexController::class,
    'changelog' => \App\Controller\ChangelogController::class,
    'dashboard' => \App\Controller\DashboardController::class,
    'form' => \App\Controller\FormController::class,
    'health' => \App\Controller\HealthController::class,
    'rgpd' => \App\Controller\RgpdController::class,
    'backup' => \App\Controller\BackupController::class,
    'confirm_action' => \App\Controller\ConfirmActionController::class,
    'download' => \App\Controller\DownloadController::class,
    'persona' => \App\Controller\PersonaController::class,
    'my_forms' => \App\Controller\MyFormsController::class,
    'screenshot' => \App\Controller\ScreenshotController::class,
    'stats' => \App\Controller\StatsController::class,
    'form_preview' => \App\Controller\FormPreviewController::class,
    'admin_alerts' => \App\Controller\AdminAlertsController::class,
    'admin_settings' => \App\Controller\AdminSettingsController::class,
    'admin_access' => \App\Controller\AdminAccessController::class,
    'admin_forms' => \App\Controller\AdminFormsController::class,
    'monitoring' => \App\Controller\MonitoringController::class,
    'my_submissions' => \App\Controller\MySubmissionsController::class,
    'my_validations' => \App\Controller\MyValidationsController::class,
    'form_tracking' => \App\Controller\FormTrackingController::class,
    'submission_view' => \App\Controller\SubmissionViewController::class,
    'docs' => \App\Controller\DocsController::class,
    'validate' => \App\Controller\ValidateController::class,
];

if (array_key_exists($page, $CONTROLLER_MAP)) {
    $controllerClass = $CONTROLLER_MAP[$page];
    $controller = new $controllerClass();
    $controller->handle();
    exit;
}

// B17 fix (audit 2026-07-26) : le fallback $pageFile = pages/$page.php était
// unreachable — $CONTROLLER_MAP couvre exhaustivement $ALLOWED_PAGES (25 vs 25
// entrées), et le dossier pages/ n'existe plus (toutes les pages sont migrées
// vers des controllers OOP dans src/Controller/). La whitelist check ligne 66
// retourne déjà 404 si la page n'est pas autorisée. Si on arrive ici (page dans
// la whitelist mais pas dans le CONTROLLER_MAP), c'est un bug de cohérence.
(new \App\Render\ErrorRenderer())->errorPage(500, 'Configuration incomplète',
    "La page '{$page}' est dans la whitelist mais n'a pas de contrôleur associé.",
    'Contactez l\'administrateur — le CONTROLLER_MAP doit être synchronisé avec ALLOWED_PAGES.');
