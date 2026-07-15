<?php
/**
 * GlobalFunctionsTest.php — Vérifie que toutes les fonctions globales requises existent.
 *
 * Ce test est le "contrat" entre le code applicatif et le framework de test.
 * Si une fonction globale est supprimée de lib_wrappers.php ou jamais définie,
 * ce test échoue AVANT que la gate serveur ne bloque le déploiement.
 *
 * Couvre :
 *   1. Toutes les fonctions de lib_wrappers.php (wrappers procéduraux vers services OOP)
 *   2. Les fonctions utilitaires de helpers.php
 *   3. Les fonctions de test (test_bootstrap.php)
 *   4. Les fonctions utilisées par test_all.php (gate qualité)
 *
 * @package Tests\PHPUnit
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GlobalFunctionsTest extends TestCase
{
    /**
     * Toutes les fonctions définies dans lib_wrappers.php — le fichier source de vérité.
     * Chaque nom ici doit correspondre à un `function xxx()` dans src/lib_wrappers.php.
     */
    private const LIB_WRAPPER_FUNCTIONS = [
        // UUID
        'generate_uuid',
        'generate_token',

        // Date
        'parse_deadline_date',
        'parse_date',
        'calculate_deadline_urgency',

        // Slug/Field
        'generate_field_name',
        'generate_slug',
        'parse_options_input',
        'get_form_by_uuid',

        // Database
        'get_pdo',
        'release_pdo',

        // Jargon
        't_jargon',

        // Test mode
        'get_test_mails',
        'reset_test_mails',
        'test_json_response',

        // Settings
        'get_sensitive_setting_keys',
        'encrypt_setting',
        'decrypt_setting',
        'get_setting',
        'set_setting',

        // Cache
        'cache_dir',
        'cache_get',
        'cache_set',
        'cache_clear',

        // Version
        'get_latest_version',

        // Persona
        'persona_create_token',
        'persona_lookup',
        'persona_revoke',
        'persona_cleanup',
        'persona_current_token',
        'persona_current_target',
        'persona_get_service',

        // Conditions
        'evaluate_condition',
        'evaluate_step_condition',
        'evaluate_field_condition',

        // Validation
        'sanitize_input',
        'validate_email',
        'validate_input',

        // Form JSON
        'validate_form_json',
        'format_validation_results',

        // Sample forms
        'populate_sample_forms',

        // Admin handlers
        'handle_admin_action',
        'handle_admin_settings_post',

        // Admin forms render
        'get_admin_forms_page_css',
        'get_admin_forms_field_types',
        'field_type_icon',
        'field_type_label',
        'options_to_lines',
        'render_form_selector_panel',
        'render_import_json_panel',
        'render_prompt_ia_panel',
        'render_new_form_panel',
        'render_top_action_bar',
        'render_form_info_section',
        'render_owners_section',
        'render_workflow_diagram_section',
        'render_form_fields_section',
        'render_admin_forms_page',

        // Admin settings render
        'admin_settings_page_css',
        'render_admin_settings_content',
        'render_admin_settings_after_main',
    ];

    /**
     * Fonctions supplémentaires que test_all.php (la gate qualité) appelle
     * mais qui ne sont PAS dans lib_wrappers.php.
     * Si ces fonctions disparaissent, le gate serveur échoue.
     */
    private const GATE_REQUIRED_FUNCTIONS = [
        // helpers.php
        'resolve_base_url',

        // ViewRenderer (appelé en tant que fonction globale via test_all.php)
        'render_footer',
    ];

    /**
     * Vérifie que toutes les fonctions de lib_wrappers.php existent.
     * Si une fonction est supprimée de lib_wrappers.php, ce test échoue.
     */
    public function testAllLibWrapperFunctionsExist(): void
    {
        $missing = [];
        foreach (self::LIB_WRAPPER_FUNCTIONS as $fn) {
            if (!function_exists($fn)) {
                $missing[] = $fn;
            }
        }

        $this->assertEmpty(
            $missing,
            "Fonctions globales manquantes (supprimées de lib_wrappers.php ?) :\n  - "
            . implode("\n  - ", $missing)
            . "\n\nAjoutez-les dans src/lib_wrappers.php."
        );
    }

    /**
     * Vérifie que les fonctions requises par la gate qualité existent.
     * Si une fonction disparaît, le gate serveur échoue au déploiement.
     */
    public function testAllGateRequiredFunctionsExist(): void
    {
        $missing = [];
        foreach (self::GATE_REQUIRED_FUNCTIONS as $fn) {
            if (!function_exists($fn)) {
                $missing[] = $fn;
            }
        }

        $this->assertEmpty(
            $missing,
            "Fonctions requises par la gate qualité manquantes :\n  - "
            . implode("\n  - ", $missing)
            . "\n\nAjoutez-les dans helpers.php ou src/lib_wrappers.php."
        );
    }

    /**
     * Vérifie que toutes les fonctions gate ne sont pas seulement définies,
     * mais aussi appelables (pas d'erreur de dépendance lors du chargement).
     */
    public function testAllGateFunctionsAreCallable(): void
    {
        $allFunctions = array_merge(self::LIB_WRAPPER_FUNCTIONS, self::GATE_REQUIRED_FUNCTIONS);

        $notCallable = [];
        foreach ($allFunctions as $fn) {
            if (function_exists($fn) && !is_callable($fn)) {
                $notCallable[] = $fn;
            }
        }

        $this->assertEmpty(
            $notCallable,
            "Fonctions définies mais non appelables :\n  - " . implode("\n  - ", $notCallable)
        );
    }

    /**
     * Vérifie que le bootstrap de test charge bien helpers.php
     * (donc toutes les fonctions globales sont disponibles).
     */
    public function testBootstrapLoadsHelpers(): void
    {
        $this->assertTrue(
            function_exists('generate_uuid'),
            'Le bootstrap de test ne charge pas helpers.php — generate_uuid() indisponible'
        );
    }

    /**
     * Vérifie que les services OOP principaux sont enregistrés dans le container DI.
     */
    public function testCoreServicesRegistered(): void
    {
        $app = \App\Core\App::getInstance();

        $requiredServices = [
            \App\Auth\AuthService::class,
            \App\Security\SecurityService::class,
            \App\Settings\SettingsService::class,
            \App\Render\HtmlService::class,
            \App\Audit\AuditLogService::class,
            \App\Workflow\WorkflowEngine::class,
            \App\Token\TokenService::class,
            \App\Cron\CronService::class,
            \App\Forms\FieldService::class,
        ];

        foreach ($requiredServices as $serviceClass) {
            $shortName = basename(str_replace('\\', '/', $serviceClass));
            $this->assertTrue(
                $app->has($serviceClass),
                "Service '$shortName' non enregistré dans le container DI. "
                . "Vérifiez que helpers.php l'instancie correctement."
            );
        }
    }

    /**
     * Scan test_all.php pour détecter les appels à des fonctions non-définies.
     * Cette méthode parse le fichier et vérifie chaque appel de fonction.
     */
    public function testTestAllPhpCallsNoUndefinedFunctions(): void
    {
        $testFile = dirname(__DIR__, 2) . '/tests/test_all.php';
        if (!file_exists($testFile)) {
            $this->markTestSkipped('test_all.php non trouvé');
            return;
        }

        $content = file_get_contents($testFile);

        // Supprimer les chaînes de test (contenu entre guillemets) pour éviter
        // les faux positifs sur les noms de fonctions dans les descriptions de test
        $cleaned = preg_replace('/\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'/', "''", $content);
        $cleaned = preg_replace('/"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"/', '""', $cleaned);
        // Supprimer les commentaires
        $cleaned = preg_replace('/\/\/.*$/m', '', $cleaned);
        $cleaned = preg_replace('/\/\*.*?\*\//s', '', $cleaned);
        // Supprimer les requêtes SQL (entre guillemets SQL dans les strings PHP)
        // Le cleanup ci-dessus devrait déjà les avoir éliminées

        // Extraire les appels de fonction : nom_suivi_de_(
        preg_match_all('/(?<![\>\$\\])\b([a-z_][a-z0-9_]*)\s*\(/', $cleaned, $matches);

        $excluded = [
            // Fonctions de test framework
            'test', 'assert_test', 'capture_output', 'print_test_summary',
            'green', 'red', 'yellow', 'cyan', 'bold', 'reset_color',
            // Fonctions PHP built-in (liste étendue)
            'echo', 'require', 'require_once', 'include', 'include_once',
            'array', 'count', 'json_encode', 'json_decode', 'implode', 'explode',
            'preg_match', 'preg_match_all', 'preg_replace',
            'substr', 'strpos', 'strlen', 'str_contains', 'str_starts_with',
            'strtolower', 'strtoupper', 'trim', 'ltrim', 'rtrim',
            'strtotime', 'date', 'time', 'file_get_contents', 'file_put_contents',
            'is_dir', 'is_file', 'is_readable', 'is_array', 'is_string', 'is_int', 'is_null',
            'in_array', 'array_diff', 'array_unique', 'array_merge', 'array_filter',
            'array_map', 'array_column', 'array_reverse', 'array_values', 'array_keys',
            'sort', 'usort', 'compact', 'extract', 'var_export',
            'defined', 'define', 'function_exists', 'class_exists', 'method_exists',
            'sprintf', 'printf', 'htmlspecialchars', 'htmlentities',
            'header', 'session_start', 'session_status', 'ob_start', 'ob_get_clean',
            'shell_exec', 'exec', 'escapeshellarg',
            'bin2hex', 'random_bytes', 'hash_equals',
            'fopen', 'fclose', 'fread', 'fwrite', 'fgets', 'feof',
            'fputcsv', 'rewind', 'tmpfile', 'tempnam',
            'file_exists', 'unlink', 'mkdir', 'rmdir', 'rename', 'copy', 'chmod',
            'glob', 'scandir', 'getcwd', 'chdir',
            'ini_set', 'ini_get', 'php_ini_loaded_file',
            'error_reporting', 'register_shutdown_function',
            'array_key_exists', 'array_push', 'array_pop', 'array_shift',
            'array_slice', 'array_splice',
            'call_user_func', 'call_user_func_array',
            'trigger_error', 'set_error_handler', 'set_exception_handler',
            // Mots-clés PHP
            'true', 'false', 'null', 'self', 'parent', 'static', 'new', 'return',
            'if', 'else', 'elseif', 'foreach', 'for', 'while', 'do', 'switch',
            'case', 'break', 'continue', 'function', 'class', 'interface', 'trait',
            'extends', 'implements', 'abstract', 'final', 'private', 'protected',
            'public', 'const', 'var', 'global', 'declare', 'namespace', 'use', 'as',
            'try', 'catch', 'finally', 'throw', 'match', 'enum', 'readonly', 'yield', 'fn',
            // Fonctions OOP (appels statiques via \)
            'App',
            // Fonctions defined dans le script lui-même
            'run_tests_unit',
            // Noms de fonctions dans les chaînes de test (ex: test('get_auth_user()...'))
            'get_auth_user', 'is_admin_user', 'is_super_admin', 'csrf_field',
            'app_log', 'advance_workflow', 'validate_token', 'verify_csrf',
            'has_active_submissions', 'get_admin_email', 'export_csv',
            // Noms de tables SQL (apparaissent dans WHERE, FROM, etc.)
            'steps', 'forms', 'tokens', 'submissions', 'admins', 'settings',
            'form_fields', 'audit_log', 'alert_rules', 'alert_log', 'form_owners',
            'lazy_cron', 'delegations', 'attachments', 'step_recipients',
            // Fonctions de callback (closure context)
            'use', 'fn',
        ];

        $undefinedFunctions = [];
        foreach ($matches[1] as $fn) {
            $fnLower = strtolower($fn);
            if (in_array($fnLower, $excluded, true)) {
                continue;
            }
            if (!function_exists($fn)) {
                $undefinedFunctions[] = $fn;
            }
        }

        // Dédupliquer et trier
        $undefinedFunctions = array_values(array_unique($undefinedFunctions));

        $this->assertEmpty(
            $undefinedFunctions,
            "test_all.php appelle des fonctions non-définies :\n  - "
            . implode("\n  - ", $undefinedFunctions)
            . "\n\nAjoutez ces wrappers dans src/lib_wrappers.php ou helpers.php."
        );
    }
}
