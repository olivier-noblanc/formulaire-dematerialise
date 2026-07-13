<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use App\Core\App;

/**
 * Vérifie que TOUS les contrôleurs peuvent être instanciés sans erreur.
 *
 * Si un service manque dans le container DI, le constructeur de BaseController
 * lèvera une RuntimeException — ce test le capturera immédiatement.
 */
final class ControllerRegistryTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function controllersProvider(): array
    {
        return [
            'accueil'          => [\App\Controller\IndexController::class],
            'changelog'        => [\App\Controller\ChangelogController::class],
            'dashboard'        => [\App\Controller\DashboardController::class],
            'form'             => [\App\Controller\FormController::class],
            'health'           => [\App\Controller\HealthController::class],
            'rgpd'             => [\App\Controller\RgpdController::class],
            'backup'           => [\App\Controller\BackupController::class],
            'confirm_action'   => [\App\Controller\ConfirmActionController::class],
            'download'         => [\App\Controller\DownloadController::class],
            'persona'          => [\App\Controller\PersonaController::class],
            'my_forms'         => [\App\Controller\MyFormsController::class],
            'screenshot'       => [\App\Controller\ScreenshotController::class],
            'stats'            => [\App\Controller\StatsController::class],
            'form_preview'     => [\App\Controller\FormPreviewController::class],
            'admin_alerts'     => [\App\Controller\AdminAlertsController::class],
            'admin_settings'   => [\App\Controller\AdminSettingsController::class],
            'admin_access'     => [\App\Controller\AdminAccessController::class],
            'admin_forms'      => [\App\Controller\AdminFormsController::class],
            'monitoring'       => [\App\Controller\MonitoringController::class],
            'my_submissions'   => [\App\Controller\MySubmissionsController::class],
            'my_validations'   => [\App\Controller\MyValidationsController::class],
            'form_tracking'    => [\App\Controller\FormTrackingController::class],
            'submission_view'  => [\App\Controller\SubmissionViewController::class],
            'docs'             => [\App\Controller\DocsController::class],
            'validate'         => [\App\Controller\ValidateController::class],
        ];
    }

    /**
     * Test que chaque contrôleur peut être instancié sans erreur DI.
     * Le constructeur de BaseController résout ~15 services + 8 repositories.
     */
    #[DataProvider('controllersProvider')]
    public function testControllerCanBeInstantiated(string $controllerClass): void
    {
        $controller = new $controllerClass();
        $this->assertInstanceOf($controllerClass, $controller);
    }

    /**
     * Vérifie que helpers.php et bootstrap.php enregistrent les mêmes services.
     */
    public function testHelpersAndBootstrapRegisterSameServices(): void
    {
        $helpersContent = file_get_contents(dirname(__DIR__, 2) . '/helpers.php');
        $bootstrapContent = file_get_contents(dirname(__DIR__, 2) . '/src/bootstrap.php');

        $pattern = '/->set\(\s*([A-Za-z\\\\]+)::class/';
        preg_match_all($pattern, $helpersContent, $helpersMatches);
        preg_match_all($pattern, $bootstrapContent, $bootstrapMatches);

        $helpersServices = array_map(fn($s) => basename($s), $helpersMatches[1]);
        $bootstrapServices = array_map(fn($s) => basename($s), $bootstrapMatches[1]);

        $helpersOnly = array_diff($helpersServices, $bootstrapServices);
        $bootstrapOnly = array_diff($bootstrapServices, $helpersServices);

        $this->assertEmpty(
            $helpersOnly,
            "Services only in helpers.php (missing from bootstrap.php): " . implode(', ', $helpersOnly)
        );
        $this->assertEmpty(
            $bootstrapOnly,
            "Services only in bootstrap.php (missing from helpers.php): " . implode(', ', $bootstrapOnly)
        );
    }

    /**
     * Vérifie que tous les fichiers référencés par require_once dans les contrôleurs existent.
     */
    public function testAllRequiredFilesExist(): void
    {
        $projectRoot = dirname(__DIR__, 2);

        // Baseline files
        $this->assertFileExists($projectRoot . '/helpers.php');
        $this->assertFileExists($projectRoot . '/src/bootstrap.php');
        $this->assertFileExists($projectRoot . '/vendor/autoload.php');

        // Scan all controllers for require_once calls
        $controllerDir = $projectRoot . '/src/Controller';
        $files = glob($controllerDir . '/*.php');

        $missing = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (preg_match_all('/require_once\s+.*?;\s*$/m', $content, $matches)) {
                foreach ($matches[0] as $line) {
                    // Extract the path from: require_once dirname(__DIR__, N) . '/path/to/file.php';
                    if (preg_match('/dirname\(__DIR__,\s*(\d+)\)\s*\.\s*[\'"]([^\'"]+)/', $line, $pathMatch)) {
                        $depth = (int) $pathMatch[1];
                        $relativePath = $pathMatch[2];
                        $resolved = $projectRoot;
                        for ($i = 0; $i < $depth; $i++) {
                            $resolved = dirname($resolved);
                        }
                        $fullPath = $resolved . $relativePath;
                        if (!file_exists($fullPath)) {
                            $missing[] = basename($file) . ' requires ' . $relativePath . ' (resolved: ' . $fullPath . ')';
                        }
                    }
                }
            }
        }

        $this->assertEmpty(
            $missing,
            "Files referenced by require_once in controllers but NOT found on disk:\n"
            . implode("\n", $missing)
        );
    }

    /**
     * Vérifie que chaque service utilisé dans les contrôleurs est enregistré.
     */
    public function testAllServicesUsedByControllersAreRegistered(): void
    {
        $helpersContent = file_get_contents(dirname(__DIR__, 2) . '/helpers.php');

        preg_match_all('/->set\(\s*\\\\?App\\\\([A-Za-z\\\\]+)::class/', $helpersContent, $m);
        $registeredShortNames = array_map(fn($s) => basename(str_replace('\\\\', '\\', $s)), $m[1]);

        $controllerDir = dirname(__DIR__, 2) . '/src/Controller';
        $files = glob($controllerDir . '/*.php');

        $missing = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (preg_match_all('/->get\(\s*\\\\?App\\\\([A-Za-z\\\\]+)::class\)/', $content, $getServiceMatches)) {
                foreach ($getServiceMatches[1] as $svc) {
                    $shortName = basename(str_replace('\\\\', '\\', $svc));
                    if (!in_array($shortName, $registeredShortNames, true)) {
                        $missing[$svc] = basename($file);
                    }
                }
            }
        }

        $this->assertEmpty(
            $missing,
            "Services used in controllers but NOT registered in helpers.php:\n"
            . implode("\n", array_map(
                fn($svc, $file) => "  - $svc (used in $file)",
                array_keys($missing),
                array_values($missing)
            ))
        );
    }
}
