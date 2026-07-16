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
        $this->assertStringContainsString('class="workflow-diagram"', $html);
        $this->assertStringContainsString('class="wf-flow"', $html);
    }

    public function testRenderWorkflowDiagramContainsCardTitle(): void
    {
        $steps = [];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'en_cours');
        $this->assertStringContainsString('Circuit de validation', $html);
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
        $this->assertStringContainsString('class="wf-step validated"', $html);
        $this->assertStringNotContainsString('class="wf-step done"', $html);
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
        $this->assertStringContainsString('class="wf-step current"', $html);
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
        $this->assertStringContainsString('class="wf-step upcoming"', $html);
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
        $this->assertStringContainsString('class="wf-step refused"', $html);
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
        $this->assertStringContainsString('class="wf-ordre"', $html);
        $this->assertStringContainsString('Étape 3', $html);
        $this->assertStringContainsString('class="wf-label"', $html);
        $this->assertStringContainsString('Directeur', $html);
        $this->assertStringContainsString('class="wf-validators"', $html);
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
        $this->assertStringNotContainsString('wf-step-label', $html);
        $this->assertStringNotContainsString('wf-step-detail', $html);
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
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ── Connector between steps ─────────────────────────────────

    public function testRenderWorkflowDiagramShowsConnectorBetweenSteps(): void
    {
        $steps = [
            ['step_status' => 'validated', 'step_label' => 'A', 'ordre' => 1, 'tokens' => []],
            ['step_status' => 'current', 'step_label' => 'B', 'ordre' => 2, 'tokens' => []],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'en_cours');
        $this->assertStringContainsString('class="wf-connector"', $html);
        $this->assertStringContainsString('→', $html);
    }

    // ── No connector before first step ──────────────────────────

    public function testRenderWorkflowDiagramNoConnectorBeforeFirstStep(): void
    {
        $steps = [
            ['step_status' => 'validated', 'step_label' => 'A', 'ordre' => 1, 'tokens' => []],
        ];
        $html = $this->renderer->renderWorkflowDiagram($steps, 'valide');
        $this->assertStringNotContainsString('wf-connector', $html);
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
        $this->assertStringContainsString('wf-check', $html);
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
        $this->assertStringContainsString('wf-pending', $html);
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
        $this->assertStringContainsString('3 rappels', $html);
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
        $this->assertStringContainsString('En attente de démarrage', $html);
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
        $this->assertStringContainsString('class="wf-step validated"', $html);
        $this->assertStringContainsString('class="wf-step current"', $html);
        $this->assertStringContainsString('class="wf-step upcoming"', $html);

        // Les labels
        $this->assertStringContainsString('Directeur', $html);
        $this->assertStringContainsString('Contrôleur', $html);
        $this->assertStringContainsString('Comptable', $html);

        // Les connecteurs (2 entre 3 étapes)
        preg_match_all('/wf-connector/', $html, $connectors);
        $this->assertCount(2, $connectors[0]);

        // La relance
        $this->assertStringContainsString('1 rappel', $html);

        // Le message d'attente pour l'étape vide
        $this->assertStringContainsString('En attente de démarrage', $html);
    }
}
