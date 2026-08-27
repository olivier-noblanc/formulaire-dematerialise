<?php

declare(strict_types=1);

namespace App\Render;

use App\Enum\SubmissionField;

/**
 * Renderer pour la page Détail d'une soumission (submission_view).
 *
 * Classe mince — le HTML des sections est dans src/Render/templates/.
 * Chaque méthode publique correspond à un template du répertoire templates/.
 */
final class SubmissionViewRenderer
{
    private static ?string $templatesDir = null;

    // ── Template loader ──────────────────────────────────────────

    /**
     * Charge un template PHP depuis le répertoire templates/ et retourne son contenu.
     *
     * @param array<string, mixed> $vars
     */
    private function loadTemplate(string $filename, array $vars = []): string
    {
        self::$templatesDir ??= __DIR__ . '/templates/';
        $filepath = self::$templatesDir . $filename;
        if (!file_exists($filepath)) {
            throw new \RuntimeException("Template not found: {$filepath}");
        }
        extract($vars, EXTR_OVERWRITE);
        $result = require $filepath;
        return is_string($result) ? $result : '';
    }

    // ── CSS ──────────────────────────────────────────────────────

    /**
     * CSS spécifique à la page submission_view.
     */
    public function pageCss(): string
    {
        $cssPath = __DIR__ . '/../../lib/submission_view_page.css';
        if (!file_exists($cssPath)) {
            return '';
        }
        $css = (string) file_get_contents($cssPath);
        return '<style>' . $css . '</style>';
    }

    // ── Sections ─────────────────────────────────────────────────

    /**
     * Diagramme du circuit de validation (workflow).
     *
     * @param list<array{step_status: string, step_label: string, ordre: int, tokens?: list<array>}> $steps
     */
    public function renderWorkflowDiagram(array $steps, string $status): string
    {
        return $this->loadTemplate('renderWorkflowDiagram.php', [
            'workflow_steps' => $steps,
            'status'         => $status,
        ]);
    }

    /**
     * Barre de progression.
     */
    public function renderProgress(int $pct, int $done, int $total): string
    {
        return $this->loadTemplate('renderProgress.php', [
            'progress_pct' => $pct,
            'done_steps'   => $done,
            'total_steps'  => $total,
        ]);
    }

    /**
     * Encart de délai / échéance.
     *
     * @param array{urgency?: string} $dlInfo
     */
    public function renderDeadline(array $dlInfo, int $deadlineTs, int $daysRemaining, string $status): string
    {
        return $this->loadTemplate('renderDeadline.php', [
            'dl_info'         => $dlInfo,
            'deadline_ts'     => $deadlineTs,
            'days_remaining'  => $daysRemaining,
            'status'          => $status,
        ]);
    }

    /**
     * Bloc des délégations.
     *
     * @param list<array{step_label?: string, from_email?: string, to_email?: string, delegated_at?: string, reason?: string}> $delegations
     */
    public function renderDelegations(array $delegations): string
    {
        return $this->loadTemplate('renderDelegations.php', [
            'delegations' => $delegations,
        ]);
    }

    /**
     * Historique des validations.
     *
     * @param array{validations?: list<array{action?: string, step_label?: string, email?: string, date?: string, done_by?: string, commentaire?: string}>} $sub
     */
    public function renderValidationHistory(array $sub): string
    {
        return $this->loadTemplate('renderValidationHistory.php', [
            'data' => $sub,
        ]);
    }

    /**
     * En-tête de la soumission.
     *
     * @param array{form_label?: string, submitted_by?: string, submitted_at?: string, closed_at?: string|null} $sub
     */
    public function renderHeader(array $sub, string $subId, string $nomAgent, string $statusLabel, string $statusCls): string
    {
        return $this->loadTemplate('renderHeader.php', [
            'sub'          => $sub,
            'sub_id'       => $subId,
            'nom_agent'    => $nomAgent,
            'status_label' => $statusLabel,
            'status_cls'   => $statusCls,
        ]);
    }

    /**
     * Boutons d'action (annuler, supprimer).
     */
    public function renderActions(string $status, bool $isAdmin, string $submittedBy, string $user, string $subId): string
    {
        return $this->loadTemplate('renderActions.php', [
            'status'       => $status,
            'is_admin'     => $isAdmin,
            'submitted_by' => $submittedBy,
            'user'         => $user,
            'sub_id'       => $subId,
        ]);
    }

    /**
     * Données du formulaire sous forme de grille.
     *
     * @param array<string, mixed> $data
     * @param array<string, array{card_group?: string, label?: string}> $fieldInfo
     */
    public function renderFormData(array $data, array $fieldInfo): string
    {
        return $this->loadTemplate('renderFormData.php', [
            'data'       => $data,
            'field_info' => $fieldInfo,
        ]);
    }

    /**
     * Tableau des pièces jointes.
     *
     * @param list<array{id?: string, mime_type?: string, original_name?: string, file_size?: int, uploaded_at?: string}> $attachments
     */
    public function renderAttachments(array $attachments): string
    {
        return $this->loadTemplate('renderAttachments.php', [
            'attachments' => $attachments,
        ]);
    }

    public function renderContent(SubmissionViewContext $ctx): string
    {
        $html = '';

        // Header
        $html .= $this->renderHeader(
            $ctx->sub,
            $ctx->sub_id,
            $ctx->nom_agent,
            $ctx->status_label,
            $ctx->status_cls,
        );

        // Workflow diagram
        $html .= $this->renderWorkflowDiagram($ctx->workflow_steps, $ctx->status);

        // Progress
        $html .= $this->renderProgress($ctx->progress_pct, $ctx->done_steps, $ctx->total_steps);

        // Deadline
        $html .= $this->renderDeadline($ctx->dl_info, $ctx->deadline_ts ?? 0, $ctx->days_remaining, $ctx->status);

        // Form data
        $html .= $this->renderFormData($ctx->data, $ctx->field_info);

        // Validator data
        $html .= $this->renderValidatorData($ctx->validator_data_rows);

        // Attachments
        $html .= $this->renderAttachments($ctx->attachments);

        // Delegations
        $html .= $this->renderDelegations($ctx->delegations);

        // Validation history
        $html .= $this->renderValidationHistory([SubmissionField::VALIDATIONS->value => $this->buildValidationHistory($ctx->all_tokens)]);

        // Remind history
        $html .= $this->renderRemindHistory($ctx->submission_reminds);

        // Actions
        $html .= $this->renderActions($ctx->status, $ctx->is_admin, (string) ($ctx->sub['submitted_by'] ?? ''), $ctx->user, $ctx->sub_id);

        return $html;
    }

    // ── Private helpers ──────────────────────────────────────────

    /**
     * @param list<array> $allTokens
     * @return list<array{action: string, step_label: string, email: string, date: string, done_by?: string, commentaire?: string}>
     */
    private function buildValidationHistory(array $allTokens): array
    {
        $history = [];
        foreach ($allTokens as $tk) {
            if ((bool) ($tk['done_at'])) {
                $entry = [
                    'action'     => (string) ($tk['action'] ?? 'valider'),
                    'step_label' => (string) ($tk['step_label'] ?? ''),
                    'email'      => (string) ($tk['email'] ?? ''),
                    'date'       => (string) ($tk['done_at'] ?? ''),
                ];
                if ((bool) ($tk['filled_by'])) {
                    $entry['done_by'] = (string) $tk['filled_by'];
                }
                $history[] = $entry;
            }
        }
        return $history;
    }

    /**
     * @param list<array> $rows
     */
    private function renderValidatorData(array $rows): string
    {
        $templatePath = __DIR__ . '/templates/renderValidatorData.php';
        if (!file_exists($templatePath)) {
            return '';
        }

        if ($rows === []) {
            return '';
        }

        $items = '';
        foreach ($rows as $vd) {
            $fieldLabel = \App\Core\App::html()->escape((string) ($vd['field_label'] ?? $vd['field_name'] ?? ''));
            $value      = \App\Core\App::html()->escape((string) ($vd['value'] ?? ''));
            $filledBy   = \App\Core\App::html()->escape((string) ($vd['filled_by_email'] ?? ''));
            $filledAt   = \App\Core\App::html()->escape((string) ($vd['filled_at'] ?? ''));

            $items .= <<<HTML
                    <tr>
                      <td>{$fieldLabel}</td>
                      <td>{$value}</td>
                      <td>{$filledBy}</td>
                      <td>{$filledAt}</td>
                    </tr>
                HTML;
        }

        return <<<HTML
                <div class="card">
                  <h2><span aria-hidden="true">✏️</span> Données validateur</h2>
                  <table>
                    <thead><tr><th>Champ</th><th>Valeur</th><th>Rempli par</th><th>Date</th></tr></thead>
                    <tbody>{$items}</tbody>
                  </table>
                </div>
            HTML;
    }

    /**
     * @param list<array{detail?: string, created_at?: string, actor?: string}> $reminds
     */
    private function renderRemindHistory(array $reminds): string
    {
        if ($reminds === []) {
            return '';
        }

        $items = '';
        foreach ($reminds as $r) {
            $detail = \App\Core\App::html()->escape((string) ($r['detail'] ?? ''));
            $date   = \App\Core\App::html()->escape((string) ($r['created_at'] ?? ''));
            $actor  = \App\Core\App::html()->escape((string) ($r['actor'] ?? ''));
            $items .= <<<HTML
                    <div class="val-item">
                      <div class="val-icon" aria-hidden="true">🔔</div>
                      <div class="val-content">
                        <div class="val-header">{$detail}</div>
                        <div class="val-detail">{$date} par {$actor}</div>
                      </div>
                    </div>
                HTML;
        }

        return <<<HTML
                <div class="card">
                  <h2><span aria-hidden="true">🔔</span> Historique des relances</h2>
                  {$items}
                </div>
            HTML;
    }
}
