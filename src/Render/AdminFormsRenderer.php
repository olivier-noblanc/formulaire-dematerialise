<?php
declare(strict_types=1);

namespace App\Render;

use App\Enum\FieldVisibility;
use App\Enum\FilledBy;
use App\Enum\FieldType;

/**
 * Render de la page "Gestion des formulaires" (admin_forms.php).
 *
 * Classe mince — le HTML des sections est dans src/Render/templates/.
 */
final class AdminFormsRenderer
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    // ── CSS ──────────────────────────────────────────────────────

    /**
     * CSS spécifique à la page admin_forms.php.
     */
    public function getPageCss(): string
    {
        return $this->loadTemplate('adminForms_pageCss.php');
    }

    // ── Field type helpers ───────────────────────────────────────

    /**
     * Catalogue des types de champ (label avec icône) pour les sélecteurs.
     *
     * @return array<string, string>
     */
    public function getFormFieldTypes(): array
    {
        return [
            FieldType::Text->value     => '<span aria-hidden="true">📝</span> Texte',
            FieldType::Email->value    => '<span aria-hidden="true">📧</span> Courriel',
            FieldType::Date->value     => '<span aria-hidden="true">📅</span> Date',
            FieldType::Select->value   => '<span aria-hidden="true">📋</span> Sélecteur',
            FieldType::Checkbox->value => '<span aria-hidden="true">☑</span> Case à cocher',
            FieldType::Textarea->value => '<span aria-hidden="true">📝</span> Zone de texte',
            FieldType::File->value     => '<span aria-hidden="true">📎</span> Fichier',
        ];
    }

    /**
     * Icône HTML pour un type de champ donné.
     */
    public function fieldTypeIcon(string $type): string
    {
        $icons = [
            FieldType::Text->value     => '<span aria-hidden="true">📝</span>',
            FieldType::Email->value    => '<span aria-hidden="true">📧</span>',
            FieldType::Date->value     => '<span aria-hidden="true">📅</span>',
            FieldType::Select->value   => '<span aria-hidden="true">📋</span>',
            FieldType::Checkbox->value => '<span aria-hidden="true">☑️</span>',
            FieldType::Textarea->value => '<span aria-hidden="true">📝</span>',
            FieldType::File->value     => '<span aria-hidden="true">📎</span>',
        ];
        return $icons[$type] ?? '<span aria-hidden="true">📄</span>';
    }

    /**
     * Libellé humain pour un type de champ donné.
     */
    public function fieldTypeLabel(string $type): string
    {
        $labels = [
            FieldType::Text->value     => 'Texte',
            FieldType::Email->value    => 'Courriel',
            FieldType::Date->value     => 'Date',
            FieldType::Select->value   => 'Sélecteur',
            FieldType::Checkbox->value => 'Case à cocher',
            FieldType::Textarea->value => 'Zone de texte',
            FieldType::File->value     => 'Fichier',
        ];
        return $labels[$type] ?? $type;
    }

    /**
     * Convertit un JSON d'options en lignes de texte (pour textarea).
     */
    public function optionsToLines(?string $json): string
    {
        if (!((bool)($json))) return '';
        $decoded = json_decode($json, true);
        if (is_array($decoded)) return implode("\n", $decoded);
        return $json;
    }

    // ── Panels ───────────────────────────────────────────────────

    /**
     * Panneau « Sélecteur de formulaire » + actions globales.
     */
    public function renderSelectorPanel(AdminFormsContext $ctx): string
    {
        return $this->loadTemplate('adminForms_selectorPanel.php', $ctx);
    }

    /**
     * Panneau « Importer un formulaire depuis JSON ».
     */
    public function renderImportJsonPanel(AdminFormsContext $ctx): string
    {
        return $this->loadTemplate('adminForms_importJsonPanel.php', $ctx);
    }

    /**
     * Panneau « Prompt IA » : prompt pré-rempli à copier-coller.
     */
    public function renderPromptIaPanel(AdminFormsContext $ctx): string
    {
        return $this->loadTemplate('adminForms_promptIaPanel.php', $ctx);
    }

    /**
     * Panneau « Créer un nouveau formulaire ».
     */
    public function renderNewFormPanel(AdminFormsContext $ctx): string
    {
        return $this->loadTemplate('adminForms_newFormPanel.php', $ctx);
    }

    // ── Form sections ────────────────────────────────────────────

    /**
     * Barre d'actions supérieure : prévisualisation, export JSON, retour.
     */
    public function renderTopActionBar(AdminFormsContext $ctx): string
    {
        if (!((bool)$ctx->form)) {
            return '';
        }
        return $this->loadTemplate('adminForms_topActionBar.php', $ctx);
    }

    /**
     * SECTION A : Informations du formulaire + actions dupliquer/supprimer.
     */
    public function renderFormInfoSection(AdminFormsContext $ctx): string
    {
        if (!((bool)$ctx->form)) {
            return '';
        }
        return $this->loadTemplate('adminForms_formInfoSection.php', $ctx);
    }

    /**
     * Section « Propriétaires du formulaire ».
     */
    public function renderOwnersSection(AdminFormsContext $ctx): string
    {
        if (!((bool)$ctx->form)) {
            return '';
        }
        return $this->loadTemplate('adminForms_ownersSection.php', $ctx);
    }

// ── Workflow section ──────────────────────────────────────────

    /**
     * SECTION B : Circuit de validation (diagramme visuel + liste des étapes).
     */
    public function renderWorkflowDiagramSection(AdminFormsContext $ctx): string
    {
        return $this->loadTemplate('adminForms_workflowDiagramSection.php', $ctx);
    }

    // ── Fields section ───────────────────────────────────────────

    /**
     * SECTION D : Champs du formulaire.
     */
    public function renderFormFieldsSection(AdminFormsContext $ctx): string
    {
        return $this->loadTemplate('adminForms_formFieldsSection.php', $ctx);
    }

    // ── Page rendering ───────────────────────────────────────────

    /**
     * Rend la page complète "Gestion des formulaires".
     */
    public function renderPage(AdminFormsContext $ctx): void
    {
        $form_id      = $ctx->form_id;
        $form         = $ctx->form;
        $error_msg    = $ctx->error_msg;
        $success_msg  = $ctx->success_msg;

        $page_css = $this->getPageCss();

        ob_start();
        ?>
        <h1><span aria-hidden="true">⚙</span> Gestion des formulaires</h1>

        <?php if ((bool)($success_msg)): ?>
            <div class="msg-success" role="status" aria-live="polite"><?= \App\Core\App::html()->escape($success_msg) ?></div>
        <?php endif; ?>

        <?php if ((bool)($error_msg)): ?>
            <div class="msg-error" role="alert" aria-live="assertive"><?= \App\Core\App::html()->escape($error_msg) ?></div>
        <?php endif; ?>

        <?= $this->renderSelectorPanel($ctx) ?>
        <?= $this->renderImportJsonPanel($ctx) ?>
        <?= $this->renderPromptIaPanel($ctx) ?>

        <?php if (!((bool)($form_id))): ?>
            <?= $this->renderNewFormPanel($ctx) ?>
        <?php else: ?>
            <?php if ($form): ?>
                <?= $this->renderTopActionBar($ctx) ?>
                <?= $this->renderFormInfoSection($ctx) ?>
                <?= $this->renderWorkflowDiagramSection($ctx) ?>
                <?= $this->renderFormFieldsSection($ctx) ?>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($form): ?>
            <?= $this->renderOwnersSection($ctx) ?>
        <?php endif; ?>
</div>
<?php
        $content = ob_get_clean();
        if ($content === false) {
            $content = '';
        }
        echo new PageRenderer()->page('Gestion des formulaires', 'forms', $page_css, $content);
    }

    // ── Template loader ──────────────────────────────────────────

    /**
     * Charge un template depuis src/Render/templates/ avec le contexte donné.
     *
     * Le template a accès à $this (AdminFormsRenderer) et aux variables
     * extraites du contexte : $form_id, $form, $forms, $error_msg, $success_msg,
     * $preserved_json, $validation_html, $owners, $steps, $steps_by_ordre,
     * $edit_step_id, $form_fields, $edit_field_id, $existing_groups.
     */
    private function loadTemplate(string $filename, ?AdminFormsContext $ctx = null): string
    {
        $filepath = __DIR__ . '/templates/' . $filename;
        if (!file_exists($filepath)) {
            throw new \RuntimeException("Template not found: {$filepath}");
        }

        // Défauts sûrs : garantit que les variables sont toujours définies dans les
        // templates (surchargées par le contexte ci-dessous quand il est présent).
        // Évite les false-positives LSP « undefined variable ».
        $form_id         = '';
        $form            = null;
        $forms           = [];
        $error_msg       = '';
        $success_msg     = '';
        $preserved_json  = '';
        $validation_html = '';
        $owners          = [];
        $steps           = [];
        $steps_by_ordre  = [];
        $edit_step_id    = '';
        $form_fields     = [];
        $edit_field_id   = '';
        $existing_groups = [];
        $field_types     = [];
        $relance_delai_h = 48;
        $relance_max     = 3;

        if ($ctx instanceof \App\Render\AdminFormsContext) {
            $form_id         = $ctx->form_id;
            $form            = $ctx->form;
            $forms           = $ctx->forms;
            $error_msg       = $ctx->error_msg;
            $success_msg     = $ctx->success_msg;
            $preserved_json  = $ctx->preserved_json;
            $validation_html = $ctx->validation_html;
            $owners          = $ctx->owners;
            $steps           = $ctx->steps;
            $steps_by_ordre  = $ctx->steps_by_ordre;
            $edit_step_id    = $ctx->edit_step_id;
            $form_fields     = $ctx->form_fields;
            $edit_field_id   = $ctx->edit_field_id;
            $existing_groups = $ctx->existing_groups;
            $field_types     = $this->getFormFieldTypes();
            $relance_delai_h = $form['relance_delai_h'] ?? 48;
            $relance_max     = $form['relance_max']     ?? 3;
        }

        ob_start();
        include $filepath;
        $html = ob_get_clean();
        return $html === false ? '' : $html;
    }
}
