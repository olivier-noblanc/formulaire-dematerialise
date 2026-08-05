<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Render\SubmissionViewRenderer;
use App\Render\DashboardRenderer;
use App\Render\MonitoringRenderer;
use App\Render\MySubmissionsRenderer;
use App\Render\MyValidationsRenderer;
use App\Render\AdminAlertsRenderer;
use App\Render\AdminFormsRenderer;
use App\Render\StatsRenderer;
use App\Render\ValidateRenderer;
use App\Render\BackupRenderer;
use App\Render\InstallRenderer;

/**
 * Vérifie que chaque renderer produit du HTML dont les classes CSS existent
 * dans les fichiers CSS du projet (lib/*.css).
 *
 * Seules les classes avec des préfixes métier sont vérifiées :
 * wf-, sub-, val-, dl-, dash-, monitor-, alert-, stat-, backup-,
 * progress-, token-, deadline-, grid-, bar-, segment-, chart-, legend-
 */
final class CssCoverageTest extends TestCase
{
    /** @var array<string> Cached all CSS classes from every lib/*.css file */
    private static array $allCssClasses = [];

    protected function setUp(): void
    {
        if (self::$allCssClasses === []) {
            self::$allCssClasses = $this->loadAllCssClasses();
        }
    }

    /**
     * Load all CSS classes from every CSS file in lib/.
     *
     * @return list<string>
     */
    private function loadAllCssClasses(): array
    {
        $cssDir = dirname(__DIR__, 2) . '/lib';
        $files = glob($cssDir . '/*.css');
        $allClasses = [];

        foreach ($files as $file) {
            $cssClasses = $this->extractCssClassesFromFile($file);
            $allClasses = array_merge($allClasses, $cssClasses);
        }

        return array_unique($allClasses);
    }

    /**
     * Extract all CSS class names defined in a specific CSS file.
     *
     * @return list<string>
     */
    private function extractCssClassesFromFile(string $cssFile): array
    {
        $content = (string) file_get_contents($cssFile);
        // Remove PHP template tags that break the regex
        $content = str_replace('<?=', '', $content);
        $content = str_replace('?>', '', $content);
        preg_match_all('/\.([a-zA-Z][\w-]*)/', $content, $matches);
        return array_unique($matches[1]);
    }

    /**
     * Extract all class names used in HTML class="..." attributes.
     *
     * @return list<string>
     */
    private function extractHtmlClasses(string $html): array
    {
        preg_match_all('/class="([^"]*)"/', $html, $matches);
        $classes = [];
        foreach ($matches[1] as $classString) {
            foreach (explode(' ', $classString) as $cls) {
                $cls = trim($cls);
                if ($cls !== '') {
                    $classes[] = $cls;
                }
            }
        }
        return array_unique($classes);
    }

    /**
     * Filter classes that match a set of prefixes.
     *
     * @param list<string> $classes
     * @param list<string> $prefixes
     * @return list<string>
     */
    private function filterByPrefixes(array $classes, array $prefixes): array
    {
        return array_values(array_filter($classes, function (string $cls) use ($prefixes): bool {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($cls, $prefix)) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * Assert that every HTML class with given prefixes exists in ANY CSS file.
     * Excludes dynamic CSS classes (generated at runtime by DynamicCssService).
     */
    private function assertPrefixedClassesCovered(array $htmlClasses, string $rendererName): void
    {
        $prefixes = ['wf-', 'sub-', 'val-', 'dl-', 'dash-', 'monitor-', 'alert-', 'stat', 'backup-', 'progress-', 'token-', 'deadline-', 'grid-', 'bar-', 'segment-', 'chart-', 'legend-'];
        $relevantHtmlClasses = $this->filterByPrefixes($htmlClasses, $prefixes);
        // Exclure les classes dynamiques générées par DynamicCssService (bar-w-N, seg-val-N, etc.)
        $dynamicPrefixes = ['bar-w-', 'seg-val-', 'seg-enc-', 'seg-ref-', 'pw-', 'ipw-', 'mp-', 'donut-'];
        $relevantHtmlClasses = array_filter($relevantHtmlClasses, function ($cls) use ($dynamicPrefixes) {
            foreach ($dynamicPrefixes as $dp) {
                if (str_starts_with($cls, $dp)) {
                    return false;
                }
            }
            return true;
        });
        $missing = array_diff($relevantHtmlClasses, self::$allCssClasses);

        self::assertEmpty(
            $missing,
            "$rendererName uses prefixed classes not found in any CSS file: " . implode(', ', array_values($missing))
        );
    }

    /**
     * Assert that every HTML class with given prefixes exists in the specific CSS file.
     * Excludes dynamic CSS classes (generated at runtime by DynamicCssService).
     */
    private function assertCssClassesInFile(array $htmlClasses, array $cssClasses, array $prefixes, string $rendererName): void
    {
        $relevantHtmlClasses = $this->filterByPrefixes($htmlClasses, $prefixes);
        // Exclure les classes dynamiques
        $dynamicPrefixes = ['bar-w-', 'seg-val-', 'seg-enc-', 'seg-ref-', 'pw-', 'ipw-', 'mp-', 'donut-'];
        $relevantHtmlClasses = array_filter($relevantHtmlClasses, function ($cls) use ($dynamicPrefixes) {
            foreach ($dynamicPrefixes as $dp) {
                if (str_starts_with($cls, $dp)) {
                    return false;
                }
            }
            return true;
        });
        $missing = array_diff($relevantHtmlClasses, $cssClasses);

        self::assertEmpty(
            $missing,
            "$rendererName uses classes not found in its CSS file: " . implode(', ', array_values($missing))
        );
    }

    // ═══════════════════════════════════════════════════════════════
    //  SubmissionViewRenderer → submission_view_page.css
    // ═══════════════════════════════════════════════════════════════

    public function testSubmissionViewRendererCssCoverage(): void
    {
        $cssClasses = $this->extractCssClassesFromFile(__DIR__ . '/../../lib/submission_view_page.css');
        $renderer = new SubmissionViewRenderer();

        $steps = [
            [
                'step_status' => 'validated', 'step_label' => 'Directeur', 'ordre' => 1,
                'tokens' => [[
                    'id' => 'tok1', 'submission_id' => 'sub1', 'step_id' => 's1',
                    'email' => 'validateur@test.fr', 'token' => 'abc',
                    'sent_at' => '2025-01-01', 'done_at' => '2025-01-02',
                    'relance_at' => null, 'expires_at' => null,
                    'relance_count' => 0, 'step_label' => 'Directeur', 'ordre' => 1,
                ]],
            ],
            [
                'step_status' => 'current', 'step_label' => 'RH', 'ordre' => 2,
                'tokens' => [[
                    'id' => 'tok2', 'submission_id' => 'sub1', 'step_id' => 's2',
                    'email' => 'rh@test.fr', 'token' => 'def',
                    'sent_at' => '2025-01-03', 'done_at' => null,
                    'relance_at' => '2025-01-05', 'expires_at' => null,
                    'relance_count' => 1, 'step_label' => 'RH', 'ordre' => 2,
                ]],
            ],
            [
                'step_status' => 'upcoming', 'step_label' => 'DSI', 'ordre' => 3, 'tokens' => [],
            ],
        ];

        // renderWorkflowDiagram
        $html1 = $renderer->renderWorkflowDiagram($steps, 'en_cours');
        $classes1 = $this->extractHtmlClasses($html1);
        self::assertCssClassesInFile($classes1, $cssClasses, ['wf-', 'sub-'], 'SubmissionViewRenderer::renderWorkflowDiagram');

        // renderProgress
        $html2 = $renderer->renderProgress(50, 1, 3);
        $classes2 = $this->extractHtmlClasses($html2);
        self::assertCssClassesInFile($classes2, $cssClasses, ['progress-', 'sub-'], 'SubmissionViewRenderer::renderProgress');

        // renderDeadline
        $html3 = $renderer->renderDeadline(['urgency' => 'critical'], time() + 3600, 1, 'en_cours');
        $classes3 = $this->extractHtmlClasses($html3);
        self::assertCssClassesInFile($classes3, $cssClasses, ['deadline-', 'dl-'], 'SubmissionViewRenderer::renderDeadline');

        // renderDelegations
        $html4 = $renderer->renderDelegations([
            ['step_label' => 'RH', 'from_email' => 'a@test.fr', 'to_email' => 'b@test.fr', 'delegated_at' => '2025-01-01', 'reason' => 'Absence'],
        ]);
        $classes4 = $this->extractHtmlClasses($html4);
        self::assertCssClassesInFile($classes4, $cssClasses, ['val-'], 'SubmissionViewRenderer::renderDelegations');

        // renderValidationHistory
        $html5 = $renderer->renderValidationHistory(['validations' => [
            ['action' => 'valider', 'step_label' => 'RH', 'email' => 'rh@test.fr', 'date' => '2025-01-02', 'commentaire' => 'OK'],
            ['action' => 'refuser', 'step_label' => 'DSI', 'email' => 'dsi@test.fr', 'date' => '2025-01-03', 'done_by' => 'other@test.fr'],
        ]]);
        $classes5 = $this->extractHtmlClasses($html5);
        self::assertCssClassesInFile($classes5, $cssClasses, ['val-'], 'SubmissionViewRenderer::renderValidationHistory');

        // renderHeader
        $sub = ['form_label' => 'Test', 'submitted_by' => 'agent@test.fr', 'submitted_at' => '2025-01-01'];
        $html6 = $renderer->renderHeader($sub, 'sub-1', 'Agent', 'En cours', 'badge-en-cours');
        $classes6 = $this->extractHtmlClasses($html6);
        self::assertCssClassesInFile($classes6, $cssClasses, ['sub-'], 'SubmissionViewRenderer::renderHeader');
    }

    // ═══════════════════════════════════════════════════════════════
    //  DashboardRenderer → dashboard_page.css
    // ═══════════════════════════════════════════════════════════════

    public function testDashboardRendererCssCoverage(): void
    {
        $cssClasses = $this->extractCssClassesFromFile(__DIR__ . '/../../lib/dashboard_page.css');

        // toolbar — token-grid, token-badge, token-ok, token-wait, token-pend are in dashboard_page.css
        $html1 = DashboardRenderer::toolbar('tous', '', '', [['slug' => 'test', 'label' => 'Test']]);
        $classes1 = $this->extractHtmlClasses($html1);
        self::assertCssClassesInFile($classes1, $cssClasses, ['token-', 'toolbar-', 'admin-'], 'DashboardRenderer::toolbar');

        // table with tokens
        $row = [
            'id' => 'sub1', 'form_id' => 'f1', 'data' => '{"prenom":"Jean","nom":"Dupont"}',
            'submitted_by' => 'agent@test.fr', 'submitted_at' => '2025-01-01',
            'closed_at' => null, 'status' => 'en_cours', 'admin_comment' => '',
            'form_label' => 'Test', 'form_slug' => 'test', 'deadline_field' => '',
        ];
        $tokens = [
            'sub1' => [
                ['submission_id' => 'sub1', 'id' => 't1', 'token' => 'tok', 'relance_count' => 0,
                 'expires_at' => null, 'email' => 'rh@test.fr', 'done_at' => null,
                 'sent_at' => '2025-01-01', 'step_id' => 's1', 'label' => 'RH',
                 'step_label' => 'RH', 'ordre' => 1],
            ],
        ];
        $tableRenderer = new \App\Render\DashboardTableRenderer();
        $html2 = $tableRenderer->table([$row], $tokens);
        $classes2 = $this->extractHtmlClasses($html2);
        self::assertCssClassesInFile($classes2, $cssClasses, ['token-', 'ordre-', 'detail-'], 'DashboardTableRenderer::table');
    }

    // ═══════════════════════════════════════════════════════════════
    //  MonitoringRenderer → monitoring_page.css
    // ═══════════════════════════════════════════════════════════════

    public function testMonitoringRendererCssCoverage(): void
    {
        $cssClasses = $this->extractCssClassesFromFile(__DIR__ . '/../../lib/monitoring_page.css');

        // activeAlerts — alert-row.urgent, alert-row.warning, alert-row.ok
        $html1 = MonitoringRenderer::activeAlerts([
            ['days_remaining' => 2, 'form_label' => 'Test', 'nom_agent' => 'Agent',
             'deadline_formatted' => '01/01/2025', 'pending_steps' => 1],
            ['days_remaining' => -1, 'form_label' => 'Test2', 'nom_agent' => 'Agent2',
             'deadline_formatted' => '15/01/2025', 'pending_steps' => 3],
        ]);
        $classes1 = $this->extractHtmlClasses($html1);
        self::assertCssClassesInFile($classes1, $cssClasses, ['alert-', 'days-'], 'MonitoringRenderer::activeAlerts');
    }

    // ═══════════════════════════════════════════════════════════════
    //  MySubmissionsRenderer → style_pages.css
    // ═══════════════════════════════════════════════════════════════

    public function testMySubmissionsRendererCssCoverage(): void
    {
        // MySubmissionsRenderer uses style_pages.css for sub-*, deadline-*, tl-* classes
        // and shared CSS (style_components.css) for empty-* classes
        $pagesCss = $this->extractCssClassesFromFile(__DIR__ . '/../../lib/style_pages.css');
        $componentsCss = $this->extractCssClassesFromFile(__DIR__ . '/../../lib/style_components.css');
        $cssClasses = array_unique(array_merge($pagesCss, $componentsCss));

        // Empty submissions
        $html1 = MySubmissionsRenderer::content([], 'tous', 0, 0, 0, 0, 0, '', []);
        $classes1 = $this->extractHtmlClasses($html1);
        self::assertCssClassesInFile($classes1, $cssClasses, ['sub-', 'empty-'], 'MySubmissionsRenderer::content (empty)');

        // With submissions — sub-card, sub-card-header, sub-card-title, sub-card-date, sub-card-body
        $html2 = MySubmissionsRenderer::content(
            [[
                'id' => 'sub1', 'form_id' => 'f1', 'form_label' => 'Test',
                'form_slug' => 'test', 'data' => json_encode(['prenom' => 'Jean', 'nom' => 'Dupont']),
                'status' => 'en_cours', 'submitted_at' => '2025-01-01',
                'deadline_field' => '', 'admin_comment' => '',
                'progress_pct' => 50, 'progress_done' => 1, 'progress_total' => 2,
                'workflow_steps' => [
                    ['step_status' => 'validated', 'step_label' => 'RH'],
                    ['step_status' => 'current', 'step_label' => 'DSI'],
                ],
            ]],
            'en_cours', 1, 1, 0, 0, 0, '', []
        );
        $classes2 = $this->extractHtmlClasses($html2);
        self::assertCssClassesInFile($classes2, $cssClasses, ['sub-', 'deadline-', 'tl-', 'inline-progress-', 'card-actions', 'refusal-', 'validation-box'], 'MySubmissionsRenderer::content (with data)');
    }

    // ═══════════════════════════════════════════════════════════════
    //  MyValidationsRenderer → style_pages.css
    // ═══════════════════════════════════════════════════════════════

    public function testMyValidationsRendererCssCoverage(): void
    {
        $cssClasses = $this->extractCssClassesFromFile(__DIR__ . '/../../lib/style_pages.css');

        // Pending tab
        $html1 = MyValidationsRenderer::content(
            [[
                'token_id' => 't1', 'token' => 'tok', 'sent_at' => '2025-01-01',
                'expires_at' => null, 'relance_count' => 0, 'step_id' => 's1',
                'email' => 'rh@test.fr', 'step_label' => 'RH', 'ordre' => 1,
                'submission_id' => 'sub1', 'data' => json_encode(['prenom' => 'Jean', 'nom' => 'Dupont']),
                'submitted_at' => '2025-01-01', 'sub_status' => 'en_cours',
                'form_label' => 'Test', 'form_slug' => 'test',
            ]],
            [], 'pending', 1, 0, '', '', [
                'sub1' => [['submission_id' => 'sub1', 'id' => 's1', 'label' => 'RH', 'ordre' => 1, 'dones' => '']],
            ], [], 'test@test.fr'
        );
        $classes1 = $this->extractHtmlClasses($html1);
        self::assertCssClassesInFile($classes1, $cssClasses, ['wf-', 'validation-', 'vc-', 'expired-'], 'MyValidationsRenderer::content (pending)');

        // Done tab
        $html2 = MyValidationsRenderer::content(
            [],
            [[
                'token_id' => 't1', 'done_at' => '2025-01-02', 'sent_at' => '2025-01-01',
                'step_label' => 'RH', 'ordre' => 1, 'submission_id' => 'sub1',
                'data' => json_encode(['prenom' => 'Jean', 'nom' => 'Dupont']),
                'submitted_at' => '2025-01-01', 'sub_status' => 'valide',
                'form_label' => 'Test', 'form_slug' => 'test',
            ]],
            'done', 0, 1, '', '', [], [], 'test@test.fr'
        );
        $classes2 = $this->extractHtmlClasses($html2);
        self::assertCssClassesInFile($classes2, $cssClasses, ['validation-', 'done-', 'vc-'], 'MyValidationsRenderer::content (done)');
    }

    // ═══════════════════════════════════════════════════════════════
    //  AdminAlertsRenderer → admin_settings_page.css + style_pages.css
    // ═══════════════════════════════════════════════════════════════

    public function testAdminAlertsRendererCssCoverage(): void
    {
        // AdminAlertsRenderer uses admin_settings_page.css for alert-, rule-, deadline-, script-,
        // days-, cond-, notify- classes, and shared CSS for health-*, custom-email-*
        $adminCss = $this->extractCssClassesFromFile(__DIR__ . '/../../lib/admin_settings_page.css');
        $pagesCss = $this->extractCssClassesFromFile(__DIR__ . '/../../lib/style_pages.css');
        $monitoringCss = $this->extractCssClassesFromFile(__DIR__ . '/../../lib/monitoring_page.css');
        $componentsCss = $this->extractCssClassesFromFile(__DIR__ . '/../../lib/style_components.css');
        $cssClasses = array_unique(array_merge($adminCss, $pagesCss, $monitoringCss, $componentsCss));

        $html = AdminAlertsRenderer::content(
            '', '',
            [['id' => 'f1', 'label' => 'Test Form', 'deadline_field' => 'date_limite']],
            [[
                'id' => 'r1', 'form_id' => 'f1', 'days_before' => 5,
                'condition_type' => 'steps_incomplete', 'notify_who' => 'admin',
                'label' => 'Alerte J-5', 'actif' => 1, 'created_at' => '2025-01-01',
                'form_label' => 'Test Form', 'form_slug' => 'test', 'deadline_field' => 'date_limite',
            ]],
            [], '', '',
            ['f1' => [['field_name' => 'date_limite', 'label' => 'Date limite']]]
        );
        $classes = $this->extractHtmlClasses($html);
        self::assertCssClassesInFile($classes, $cssClasses, ['alert-', 'rule-', 'deadline-', 'script-', 'health-', 'days-', 'cond-', 'notify-', 'custom-email-'], 'AdminAlertsRenderer::content');
    }

    // ═══════════════════════════════════════════════════════════════
    //  AdminFormsRenderer → admin_forms_page.css
    // ═══════════════════════════════════════════════════════════════

    public function testAdminFormsRendererCssCoverage(): void
    {
        // AdminFormsRenderer uses admin_forms_page.css for section-, workflow-, step-, etc.
        // and shared CSS for actions, empty-, field-type-, etc.
        $adminCss = $this->extractCssClassesFromFile(__DIR__ . '/../../lib/admin_forms_page.css');
        $componentsCss = $this->extractCssClassesFromFile(__DIR__ . '/../../lib/style_components.css');
        $pagesCss = $this->extractCssClassesFromFile(__DIR__ . '/../../lib/style_pages.css');
        $cssClasses = array_unique(array_merge($adminCss, $componentsCss, $pagesCss));
        $renderer = AdminFormsRenderer::getInstance();

        // renderWorkflowDiagramSection
        $ctx1 = new \App\Render\AdminFormsContext(
            form_id: 'f1',
            form: null,
            forms: [],
            error_msg: '',
            success_msg: '',
            preserved_json: '',
            validation_html: '',
            owners: [],
            steps: [['id' => 's1', 'label' => 'RH', 'ordre' => 1, 'actif' => true, 'recipients' => [['id' => 'r1', 'email' => 'rh@test.fr']], 'condition' => '']],
            steps_by_ordre: [1 => [['id' => 's1', 'label' => 'RH', 'ordre' => 1, 'actif' => true, 'recipients' => [['id' => 'r1', 'email' => 'rh@test.fr']], 'condition' => '']]],
            edit_step_id: '',
            form_fields: [],
            edit_field_id: '',
            existing_groups: [],
        );
        $html1 = $renderer->renderWorkflowDiagramSection($ctx1);
        $classes1 = $this->extractHtmlClasses($html1);
        self::assertCssClassesInFile($classes1, $cssClasses, ['section-', 'workflow-', 'add-sub-', 'step-', 'recipient-', 'chip-', 'form-grid', 'field-type-', 'required-star', 'ff-', 'fields-table', 'actions', 'empty-'], 'AdminFormsRenderer::renderWorkflowDiagramSection');

        // renderFormFieldsSection
        $ctx2 = new \App\Render\AdminFormsContext(
            form_id: 'f1',
            form: null,
            forms: [],
            error_msg: '',
            success_msg: '',
            preserved_json: '',
            validation_html: '',
            owners: [],
            steps: [['id' => 's1', 'label' => 'RH', 'ordre' => 1]],
            steps_by_ordre: [],
            edit_step_id: '',
            form_fields: [['id' => 'ff1', 'label' => 'Nom', 'field_type' => 'text', 'field_name' => 'nom',
                'options' => null, 'hint' => '', 'required' => 1, 'ordre' => 1,
                'card_group' => 'Général', 'filled_by' => 'demandeur', 'validator_step' => '',
                'visibility' => 'all']],
            edit_field_id: '',
            existing_groups: ['Général'],
        );
        $html2 = $renderer->renderFormFieldsSection($ctx2);
        $classes2 = $this->extractHtmlClasses($html2);
        self::assertCssClassesInFile($classes2, $cssClasses, ['section-', 'fields-table', 'field-type-', 'required-star', 'add-sub-', 'form-grid', 'ff-', 'actions', 'empty-'], 'AdminFormsRenderer::renderFormFieldsSection');
    }

    // ═══════════════════════════════════════════════════════════════
    //  StatsRenderer → dashboard_page.css
    // ═══════════════════════════════════════════════════════════════

    public function testStatsRendererCssCoverage(): void
    {
        // StatsRenderer uses dashboard_page.css for badge-*, grid-*, chart-legend, legend-*
        // and style_pages.css for period-tabs, bar-chart, stacked-bar, segment-*
        $dashboardCss = $this->extractCssClassesFromFile(__DIR__ . '/../../lib/dashboard_page.css');
        $componentsCss = $this->extractCssClassesFromFile(__DIR__ . '/../../lib/style_components.css');
        $pagesCss = $this->extractCssClassesFromFile(__DIR__ . '/../../lib/style_pages.css');
        $cssClasses = array_unique(array_merge($dashboardCss, $componentsCss, $pagesCss));

        $html = StatsRenderer::content(
            'week',
            ['total' => 10, 'valide' => 5, 'en_cours' => 3, 'refuse' => 2,
             'taux_validation' => 50.0, 'avg_days' => 2.5, 'today' => 1,
             'this_week' => 3, 'this_month' => 8, 'tokens_pending' => 2,
             'attachments_count' => 5, 'attachments_size' => 1024000],
            [['period' => '2025-W01', 'total' => 5, 'valide' => 3, 'en_cours' => 2]],
            [['label' => 'Test', 'slug' => 'test', 'total' => 10, 'en_cours' => 3, 'valide' => 5, 'refuse' => 2, 'avg_seconds' => 172800.0]],
            [['email' => 'validateur@test.fr', 'total' => 5, 'done' => 3, 'pending' => 2, 'avg_response_seconds' => 7200.0]],
            'semaine', 1024000
        );
        $classes = $this->extractHtmlClasses($html);
        self::assertCssClassesInFile($classes, $cssClasses, ['period-', 'bar-', 'segment-', 'stacked-', 'chart-', 'legend-', 'grid-'], 'StatsRenderer::content');
    }

    // ═══════════════════════════════════════════════════════════════
    //  ValidateRenderer → style_features.css + style_pages.css
    // ═══════════════════════════════════════════════════════════════

    public function testValidateRendererCssCoverage(): void
    {
        $featuresCss = $this->extractCssClassesFromFile(__DIR__ . '/../../lib/style_features.css');
        $pagesCss = $this->extractCssClassesFromFile(__DIR__ . '/../../lib/style_pages.css');
        $cssClasses = array_unique(array_merge($featuresCss, $pagesCss));

        $data = json_encode(['prenom' => 'Jean', 'nom' => 'Dupont']);
        $html = ValidateRenderer::content(
            'test-token', '', null, null,
            ['status' => 'pending', 'data' => [
                'id' => 'sub1', 'data' => $data, 'step_id' => '1',
                'step_label' => 'Validation RH', 'form_id' => 'f1',
                'submitted_by' => 'agent@test.fr', 'submitted_at' => '2025-01-01',
            ]],
            [['id' => '1', 'label' => 'RH', 'ordre' => 1, 'dones' => '', 'emails' => 'rh@test.fr']],
            [], [], [], [], [], '', ''
        );
        $classes = $this->extractHtmlClasses($html);
        self::assertCssClassesInFile($classes, $cssClasses, ['wf-prog-', 'btn-validate', 'btn-refuse-', 'refusal-', 'what-to-do-', 'submit-buttons', 'validation-details', 'back-link'], 'ValidateRenderer::content (pending)');
    }

    // ═══════════════════════════════════════════════════════════════
    //  BackupRenderer → backup_page.css
    // ═══════════════════════════════════════════════════════════════

    public function testBackupRendererCssCoverage(): void
    {
        $cssClasses = $this->extractCssClassesFromFile(__DIR__ . '/../../lib/backup_page.css');

        $renderer = new BackupRenderer();
        $html = $renderer->renderContent(
            '/path/to/db',
            ['file_exists' => true, 'file_size_readable' => '10 Mo', 'file_modified' => '01/01/2025',
             'row_counts' => ['forms' => 5, 'submissions' => 10], 'oldest_submission' => '01/01/2025',
             'newest_submission' => '15/01/2025', 'page_size' => 4096, 'page_count' => 2560,
             'db_size_pages' => '10 Mo', 'freelist_count' => 100, 'free_pages' => '400 Ko'],
            null, '', '', ''
        );
        $classes = $this->extractHtmlClasses($html);
        self::assertCssClassesInFile($classes, $cssClasses, ['backup-', 'info-', 'danger-', 'purge-', 'stat-table', 'upload-zone'], 'BackupRenderer::renderContent');
    }

    // ═══════════════════════════════════════════════════════════════
    //  InstallRenderer → install_page.css
    // ═══════════════════════════════════════════════════════════════

    public function testInstallRendererCssCoverage(): void
    {
        $cssClasses = $this->extractCssClassesFromFile(__DIR__ . '/../../lib/install_page.css');

        $renderer = new InstallRenderer();

        // renderStepper
        $html1 = $renderer->renderStepper(2);
        $classes1 = $this->extractHtmlClasses($html1);
        self::assertCssClassesInFile($classes1, $cssClasses, ['stepper', 'step-'], 'InstallRenderer::renderStepper');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Cross-check: all prefixed classes across all renderers
    // ═══════════════════════════════════════════════════════════════

    public function testAllPrefixedClassesExistSomewhere(): void
    {
        $renderer = new SubmissionViewRenderer();

        $steps = [
            ['step_status' => 'validated', 'step_label' => 'RH', 'ordre' => 1, 'tokens' => [
                ['id' => '1', 'submission_id' => 's1', 'step_id' => '1', 'email' => 'rh@test.fr', 'token' => 't',
                 'sent_at' => null, 'done_at' => '2025-01-02', 'relance_at' => null, 'expires_at' => null,
                 'relance_count' => 0, 'step_label' => 'RH', 'ordre' => 1],
            ]],
            ['step_status' => 'current', 'step_label' => 'DSI', 'ordre' => 2, 'tokens' => [
                ['id' => '2', 'submission_id' => 's1', 'step_id' => '2', 'email' => 'dsi@test.fr', 'token' => 'u',
                 'sent_at' => '2025-01-03', 'done_at' => null, 'relance_at' => '2025-01-05', 'expires_at' => null,
                 'relance_count' => 2, 'step_label' => 'DSI', 'ordre' => 2],
            ]],
            ['step_status' => 'upcoming', 'step_label' => 'SG', 'ordre' => 3, 'tokens' => []],
        ];

        $html = '';
        $html .= $renderer->renderWorkflowDiagram($steps, 'en_cours');
        $html .= $renderer->renderWorkflowDiagram($steps, 'refuse');
        $html .= $renderer->renderProgress(75, 2, 3);
        $html .= $renderer->renderProgress(0, 0, 3);
        $html .= $renderer->renderProgress(100, 3, 3);
        $html .= $renderer->renderDeadline(['urgency' => 'overdue'], time() - 86400, -1, 'en_cours');
        $html .= $renderer->renderDeadline(['urgency' => 'critical'], time() + 3600, 1, 'en_cours');
        $html .= $renderer->renderDeadline(['urgency' => 'ok'], time() + 86400 * 10, 10, 'en_cours');
        $html .= $renderer->renderDelegations([
            ['step_label' => 'RH', 'from_email' => 'a@test.fr', 'to_email' => 'b@test.fr', 'delegated_at' => '2025-01-01', 'reason' => 'Absence'],
        ]);
        $html .= $renderer->renderActions('en_cours', true, 'agent@test.fr', 'agent@test.fr', 'sub-1');
        $html .= $renderer->renderFormData(['prenom' => 'Jean', 'nom' => 'Dupont'], [
            'prenom' => ['card_group' => 'Identité', 'label' => 'Prénom'],
            'nom' => ['card_group' => 'Identité', 'label' => 'Nom'],
        ]);
        $html .= $renderer->renderValidationHistory(['validations' => [
            ['action' => 'valider', 'step_label' => 'RH', 'email' => 'rh@test.fr', 'date' => '2025-01-02', 'commentaire' => 'OK'],
            ['action' => 'refuser', 'step_label' => 'DSI', 'email' => 'dsi@test.fr', 'date' => '2025-01-03', 'done_by' => 'other@test.fr'],
        ]]);
        $html .= $renderer->renderAttachments([
            ['id' => 'a1', 'mime_type' => 'application/pdf', 'original_name' => 'doc.pdf', 'file_size' => 1024, 'uploaded_at' => '2025-01-01'],
        ]);

        $classes = $this->extractHtmlClasses($html);
        self::assertPrefixedClassesCovered($classes, 'SubmissionViewRenderer (comprehensive)');
    }
}
