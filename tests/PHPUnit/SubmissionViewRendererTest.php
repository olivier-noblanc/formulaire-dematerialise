<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Render\SubmissionViewRenderer;

/**
 * Tests unitaires pour SubmissionViewRenderer.
 *
 * Vérifie que le HTML produit contient les bonnes classes CSS
 * correspondant au fichier lib/submission_view_page.css.
 */
final class SubmissionViewRendererTest extends TestCase
{
    private SubmissionViewRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new SubmissionViewRenderer();
    }

    // ── renderWorkflowDiagram: structure HTML ───────────────────

    public function testRenderWorkflowDiagramReturnsHtmlWithFlowContainer(): void
    {
        $steps = [
            ['step_status' => 'validated', 'step_label' => 'Directeur', 'ordre' => 1, 'tokens' => []],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'valide');
        self::assertStringContainsString('class="workflow-diagram"', $html);
        self::assertStringContainsString('class="wf-flow"', $html);
    }

    public function testRenderWorkflowDiagramContainsCardTitle(): void
    {
        $steps = [];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'en_cours');
        self::assertStringContainsString('Circuit de validation', $html);
    }

    // ── CSS classes: validated ──────────────────────────────────

    public function testRenderWorkflowDiagramUsesValidatedClassForDoneSteps(): void
    {
        $steps = [
            [
                'step_status' => 'validated',
                'step_label'  => 'Étape 1',
                'ordre'       => 1,
                'tokens'      => [
                    ['email' => 'a@test.com', 'done_at' => '2025-01-01 10:00:00', 'relance_count' => 0],
                ],
            ],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'valide');
        self::assertStringContainsString('class="wf-step validated"', $html);
        self::assertStringNotContainsString('class="wf-step done"', $html);
    }

    // ── CSS classes: current ────────────────────────────────────

    public function testRenderWorkflowDiagramUsesCurrentClassForPendingSteps(): void
    {
        $steps = [
            [
                'step_status' => 'current',
                'step_label'  => 'Validation',
                'ordre'       => 1,
                'tokens'      => [
                    ['email' => 'b@test.com', 'done_at' => null, 'relance_count' => 0],
                ],
            ],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'en_cours');
        self::assertStringContainsString('class="wf-step current"', $html);
    }

    // ── CSS classes: upcoming ───────────────────────────────────

    public function testRenderWorkflowDiagramUsesUpcomingClassForFutureSteps(): void
    {
        $steps = [
            [
                'step_status' => 'upcoming',
                'step_label'  => 'À venir',
                'ordre'       => 2,
                'tokens'      => [],
            ],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'en_cours');
        self::assertStringContainsString('class="wf-step upcoming"', $html);
    }

    // ── CSS classes: refused ────────────────────────────────────

    public function testRenderWorkflowDiagramUsesRefusedClassWhenCurrentAndRefused(): void
    {
        $steps = [
            [
                'step_status' => 'current',
                'step_label'  => 'Rejetée',
                'ordre'       => 1,
                'tokens'      => [
                    ['email' => 'c@test.com', 'done_at' => null, 'relance_count' => 0],
                ],
            ],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'refuse');
        self::assertStringContainsString('class="wf-step refused"', $html);
    }

    // ── Sub-elements: wf-ordre, wf-label, wf-validators ────────

    public function testRenderWorkflowDiagramContainsOrdreLabelValidators(): void
    {
        $steps = [
            [
                'step_status' => 'validated',
                'step_label'  => 'Directeur',
                'ordre'       => 3,
                'tokens'      => [
                    ['email' => 'd@test.com', 'done_at' => '2025-01-01', 'relance_count' => 0],
                ],
            ],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'valide');
        self::assertStringContainsString('class="wf-ordre"', $html);
        self::assertStringContainsString('Étape 3', $html);
        self::assertStringContainsString('class="wf-label"', $html);
        self::assertStringContainsString('Directeur', $html);
        self::assertStringContainsString('class="wf-validators"', $html);
    }

    // ── No legacy classes ───────────────────────────────────────

    public function testRenderWorkflowDiagramDoesNotUseLegacyClasses(): void
    {
        $steps = [
            [
                'step_status' => 'validated',
                'step_label'  => 'Test',
                'ordre'       => 1,
                'tokens'      => [
                    ['email' => 'e@test.com', 'done_at' => '2025-01-01', 'relance_count' => 0],
                ],
            ],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'valide');
        // Vérifie que les anciennes classes CSS ne sont pas utilisées
        self::assertStringNotContainsString('wf-step-label', $html);
        self::assertStringNotContainsString('wf-step-detail', $html);
    }

    // ── Step label escaping ─────────────────────────────────────

    public function testRenderWorkflowDiagramEscapesStepLabel(): void
    {
        $steps = [
            [
                'step_status' => 'upcoming',
                'step_label'  => '<script>alert(1)</script>',
                'ordre'       => 1,
                'tokens'      => [],
            ],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'en_cours');
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    // ── Connector between steps ─────────────────────────────────

    public function testRenderWorkflowDiagramShowsConnectorBetweenSteps(): void
    {
        $steps = [
            ['step_status' => 'validated', 'step_label' => 'A', 'ordre' => 1, 'tokens' => []],
            ['step_status' => 'current', 'step_label' => 'B', 'ordre' => 2, 'tokens' => []],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'en_cours');
        self::assertStringContainsString('class="wf-connector"', $html);
        self::assertStringContainsString('→', $html);
    }

    // ── No connector before first step ──────────────────────────

    public function testRenderWorkflowDiagramNoConnectorBeforeFirstStep(): void
    {
        $steps = [
            ['step_status' => 'validated', 'step_label' => 'A', 'ordre' => 1, 'tokens' => []],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'valide');
        self::assertStringNotContainsString('wf-connector', $html);
    }

    // ── Token icons ─────────────────────────────────────────────

    public function testRenderWorkflowDiagramShowsCheckIconForDoneTokens(): void
    {
        $steps = [
            [
                'step_status' => 'validated',
                'step_label'  => 'OK',
                'ordre'       => 1,
                'tokens'      => [
                    ['email' => 'done@test.com', 'done_at' => '2025-01-01 10:00:00', 'relance_count' => 0],
                ],
            ],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'valide');
        self::assertStringContainsString('wf-check', $html);
    }

    public function testRenderWorkflowDiagramShowsPendingIconForCurrentTokens(): void
    {
        $steps = [
            [
                'step_status' => 'current',
                'step_label'  => 'En cours',
                'ordre'       => 1,
                'tokens'      => [
                    ['email' => 'pending@test.com', 'done_at' => null, 'relance_count' => 0],
                ],
            ],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'en_cours');
        self::assertStringContainsString('wf-pending', $html);
    }

    // ── Relance count ───────────────────────────────────────────

    public function testRenderWorkflowDiagramShowsRelanceCount(): void
    {
        $steps = [
            [
                'step_status' => 'current',
                'step_label'  => 'Relancé',
                'ordre'       => 1,
                'tokens'      => [
                    ['email' => 'relance@test.com', 'done_at' => null, 'relance_count' => 3],
                ],
            ],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'en_cours');
        self::assertStringContainsString('3 rappels', $html);
    }

    // ── Empty step: waiting message ─────────────────────────────

    public function testRenderWorkflowDiagramShowsWaitingMessageForEmptySteps(): void
    {
        $steps = [
            [
                'step_status' => 'upcoming',
                'step_label'  => 'Vide',
                'ordre'       => 1,
                'tokens'      => [],
            ],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'en_cours');
        self::assertStringContainsString('En attente de démarrage', $html);
    }

    // ── Multiple steps: full structure ──────────────────────────

    // ── REGRESSION: step_label/step_id keys (not label/id) ─────

    public function testRenderWorkflowDiagramUsesStepLabelKeyNotLabel(): void
    {
        // Regression: AdminFormsController & FormPreviewController used $ws['label']
        // instead of $ws['step_label']. The renderer must consume step_label.
        $steps = [
            [
                'step_status' => 'validated',
                'step_label'  => 'Direction',
                'ordre'       => 1,
                'tokens'      => [
                    ['email' => 'a@test.com', 'done_at' => '2025-01-01', 'relance_count' => 0],
                ],
            ],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'valide');
        self::assertStringContainsString('Direction', $html);
        self::assertStringContainsString('class="wf-label"', $html);
    }

    public function testRenderWorkflowDiagramDoesNotRequireLabelOrIdKey(): void
    {
        // Regression: code accessing $ws['label'] or $ws['id'] would crash
        // when only step_label/step_id are present in the array.
        // Verify renderer does NOT access a key called 'label' or 'id'.
        $steps = [
            [
                'step_status' => 'current',
                'step_label'  => 'Test',
                'ordre'       => 1,
                'tokens'      => [],
            ],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'en_cours');
        self::assertStringContainsString('Test', $html);
    }

    // ── REGRESSION: urlencode null TypeError ───────────────────

    public function testRenderWorkflowDiagramWithNullInStepLabel(): void
    {
        // Regression: PHP 8.1+ rejects null to urlencode(). Renderers must
        // not pass null to urlencode even if data is malformed.
        $steps = [
            [
                'step_status' => 'upcoming',
                'step_label'  => '',
                'ordre'       => 1,
                'tokens'      => [],
            ],
        ];
        // Should not throw TypeError even with empty label
        $html = $this->renderer->renderWorkflowDiagram($steps, 'en_cours');
        self::assertIsString($html);
    }

    // ── REGRESSION: no legacy class wf-step-label in renderer ──

    public function testRenderWorkflowDiagramNeverEmitsWfStepLabelClass(): void
    {
        // Regression: AdminFormsController used class="wf-step-label"
        // instead of class="wf-label". Renderer must never emit the legacy class.
        $steps = [
            [
                'step_status' => 'validated',
                'step_label'  => 'Chef de service',
                'ordre'       => 1,
                'tokens'      => [
                    ['email' => 'x@test.com', 'done_at' => '2025-01-01', 'relance_count' => 0],
                ],
            ],
            [
                'step_status' => 'current',
                'step_label'  => 'Directeur',
                'ordre'       => 2,
                'tokens'      => [
                    ['email' => 'y@test.com', 'done_at' => null, 'relance_count' => 0],
                ],
            ],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'en_cours');
        self::assertStringNotContainsString('wf-step-label', $html);
        self::assertStringContainsString('class="wf-label"', $html);
    }

    // ── Multiple steps: full structure ──────────────────────────

    public function testRenderWorkflowDiagramMultipleSteps(): void
    {
        $steps = [
            [
                'step_status' => 'validated',
                'step_label'  => 'Directeur',
                'ordre'       => 1,
                'tokens'      => [
                    ['email' => 'a@test.com', 'done_at' => '2025-01-01', 'relance_count' => 0],
                ],
            ],
            [
                'step_status' => 'current',
                'step_label'  => 'Contrôleur',
                'ordre'       => 2,
                'tokens'      => [
                    ['email' => 'b@test.com', 'done_at' => null, 'relance_count' => 1],
                ],
            ],
            [
                'step_status' => 'upcoming',
                'step_label'  => 'Comptable',
                'ordre'       => 3,
                'tokens'      => [],
            ],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'en_cours');

        // Les 3 étapes avec les bonnes classes
        self::assertStringContainsString('class="wf-step validated"', $html);
        self::assertStringContainsString('class="wf-step current"', $html);
        self::assertStringContainsString('class="wf-step upcoming"', $html);

        // Les labels
        self::assertStringContainsString('Directeur', $html);
        self::assertStringContainsString('Contrôleur', $html);
        self::assertStringContainsString('Comptable', $html);

        // Les connecteurs (2 entre 3 étapes)
        preg_match_all('/wf-connector/', $html, $connectors);
        self::assertCount(2, $connectors[0]);

        // La relance
        self::assertStringContainsString('1 rappel', $html);

        // Le message d'attente pour l'étape vide
        self::assertStringContainsString('En attente de démarrage', $html);
    }

    // ── Tooltips workflow ────────────────────────────────────────

    public function testRenderWorkflowCheckTooltipShowsValidatorAndDate(): void
    {
        $steps = [
            [
                'step_status' => 'validated',
                'step_label'  => 'OK',
                'ordre'       => 1,
                'tokens'      => [
                    [
                        'email' => 'done@test.com',
                        'done_at' => '2025-06-15 14:30:00',
                        'sent_at' => '2025-06-10 09:00:00',
                        'relance_count' => 0,
                        'relance_at' => null,
                        'expires_at' => null,
                    ],
                ],
            ],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'valide');
        self::assertStringContainsString('title="', $html);
        self::assertStringContainsString('done@test.com', $html);
        self::assertStringContainsString('15/06/2025', $html);
    }

    public function testRenderWorkflowCheckTooltipShowsRelanceDetails(): void
    {
        $steps = [
            [
                'step_status' => 'validated',
                'step_label'  => 'OK',
                'ordre'       => 1,
                'tokens'      => [
                    [
                        'email' => 'done@test.com',
                        'done_at' => '2025-06-15 14:30:00',
                        'sent_at' => '2025-06-10 09:00:00',
                        'relance_count' => 2,
                        'relance_at' => '2025-06-14 10:00:00',
                        'expires_at' => null,
                    ],
                ],
            ],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'valide');
        self::assertStringContainsString('2 rappels', $html);
        self::assertStringContainsString('14/06/2025', $html);
    }

    public function testRenderWorkflowPendingTooltipShowsEmailDateAndExpiry(): void
    {
        $steps = [
            [
                'step_status' => 'current',
                'step_label'  => 'En cours',
                'ordre'       => 1,
                'tokens'      => [
                    [
                        'email' => 'pending@test.com',
                        'done_at' => null,
                        'sent_at' => '2025-06-10 09:00:00',
                        'relance_count' => 0,
                        'relance_at' => null,
                        'expires_at' => '2025-06-24 09:00:00',
                    ],
                ],
            ],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'en_cours');
        self::assertStringContainsString('title="', $html);
        self::assertStringContainsString('10/06/2025', $html);
        self::assertStringContainsString('24/06/2025', $html);
    }

    public function testRenderWorkflowPendingTooltipShowsRelanceWithLastDate(): void
    {
        $steps = [
            [
                'step_status' => 'current',
                'step_label'  => 'En cours',
                'ordre'       => 1,
                'tokens'      => [
                    [
                        'email' => 'pending@test.com',
                        'done_at' => null,
                        'sent_at' => '2025-06-10 09:00:00',
                        'relance_count' => 1,
                        'relance_at' => '2025-06-18 11:00:00',
                        'expires_at' => '2025-06-24 09:00:00',
                    ],
                ],
            ],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'en_cours');
        self::assertStringContainsString('1 rappel', $html);
        self::assertStringContainsString('18/06/2025', $html);
    }
}
