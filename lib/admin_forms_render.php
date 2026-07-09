<?php
declare(strict_types=1);

/**
 * Rendu HTML de la page admin_forms.php — FAÇADE.
 *
 * Ce module est le point d'entrée du rendu de la page "Gestion des
 * formulaires". Il contient :
 *  - Les helpers de types de champ : {@see field_type_icon()},
 *    {@see field_type_label()}, {@see options_to_lines()}.
 *  - Le catalogue de types de champ : {@see get_admin_forms_field_types()}.
 *  - La fonction principale {@see render_admin_forms_page()} qui orchestre
 *    les sous-fonctions de rendu réparties dans les modules suivants :
 *    - {@see get_admin_forms_page_css()}              — CSS spécifique
 *      (lib/admin_forms_render_css.php).
 *    - {@see render_form_selector_panel()} et al.     — panneaux du haut
 *      (lib/admin_forms_render_panels.php).
 *    - {@see render_form_info_section()} et al.       — section A + barre
 *      d'actions + propriétaires (lib/admin_forms_render_form.php).
 *    - {@see render_workflow_diagram_section()}   — section B : circuit
 *      + destinataires inline (lib/admin_forms_render_workflow.php).
 *    - {@see render_form_fields_section()}            — section D
 *      (lib/admin_forms_render_fields.php).
 *
 * Le contexte (données fetchées, messages d'état) est passé via un
 * tableau associatif `$ctx`.
 *
 * @package lib
 */

// ── Chargement des sous-modules de rendu (consolidés) ──────────
// Les sous-fichiers sont inclus directement pour réduire le nombre de communautés.
require_once __DIR__ . '/admin_forms_render_css.php';
require_once __DIR__ . '/admin_forms_render_panels.php';
require_once __DIR__ . '/admin_forms_render_form.php';
require_once __DIR__ . '/admin_forms_render_workflow.php';
require_once __DIR__ . '/admin_forms_render_fields.php';

// ── Field type helpers ─────────────────────────────────────────

/** Catalogue des types de champ (label avec icône) pour les sélecteurs.  * @return array<string, mixed>
 */
function get_admin_forms_field_types(): array {
    return [
        'text'     => '<span aria-hidden="true">📝</span> Texte',
        'email'    => '<span aria-hidden="true">📧</span> Courriel',
        'date'     => '<span aria-hidden="true">📅</span> Date',
        'select'   => '<span aria-hidden="true">📋</span> Sélecteur',
        'checkbox' => '<span aria-hidden="true">☑</span> Case à cocher',
        'textarea' => '<span aria-hidden="true">📝</span> Zone de texte',
        'file'     => '<span aria-hidden="true">📎</span> Fichier',
    ];
}

/** Icône HTML pour un type de champ donné. */
function field_type_icon(string $type): string {
    $icons = [
        'text'     => '<span aria-hidden="true">📝</span>',
        'email'    => '<span aria-hidden="true">📧</span>',
        'date'     => '<span aria-hidden="true">📅</span>',
        'select'   => '<span aria-hidden="true">📋</span>',
        'checkbox' => '<span aria-hidden="true">☑️</span>',
        'textarea' => '<span aria-hidden="true">📝</span>',
        'file'     => '<span aria-hidden="true">📎</span>',
    ];
    return $icons[$type] ?? '<span aria-hidden="true">📄</span>';
}

/** Libellé humain pour un type de champ donné. */
function field_type_label(string $type): string {
    $labels = [
        'text'     => 'Texte',
        'email'    => 'Courriel',
        'date'     => 'Date',
        'select'   => 'Sélecteur',
        'checkbox' => 'Case à cocher',
        'textarea' => 'Zone de texte',
        'file'     => 'Fichier',
    ];
    return $labels[$type] ?? $type;
}

/** Convertit un JSON d'options en lignes de texte (pour textarea). */
function options_to_lines(?string $json): string {
    if (empty($json)) return '';
    $decoded = json_decode($json, true);
    if (is_array($decoded)) return implode("\n", $decoded);
    return $json;
}

// ── Page rendering ─────────────────────────────────────────────

/**
 * Rend la page complète "Gestion des formulaires".
 *
 * @param array<string,mixed> $ctx Contexte contenant :
 *        - forms           (array)      Liste des formulaires pour le sélecteur
 *        - form_id         (string)     ID du formulaire sélectionné
 *        - form            (array|null) Données du formulaire sélectionné
 *        - steps           (array)      Étapes du formulaire
 *        - form_fields     (array)      Champs du formulaire
 *        - existing_groups (array)      Groupes de cartes existants
 *        - edit_step_id    (string)     ID de l'étape en cours d'édition
 *        - edit_field_id   (string)     ID du champ en cours d'édition
 *        - error_msg       (string)     Message d'erreur à afficher
 *        - success_msg     (string)     Message de succès à afficher
 *        - validation_html (string)     HTML de validation JSON
 *        - preserved_json  (string)     JSON préservé pour le textarea
 *        - steps_by_ordre  (array)      Étapes groupées par ordre
 *        - owners          (array)      Propriétaires du formulaire
 */
function render_admin_forms_page(array $ctx): void {
    $form_id      = $ctx['form_id']      ?? '';
    $form         = $ctx['form']         ?? null;
    $error_msg    = $ctx['error_msg']    ?? '';
    $success_msg  = $ctx['success_msg']  ?? '';

    $page_css = get_admin_forms_page_css();

    ob_start();
    ?>
    <h1><span aria-hidden="true">⚙</span> Gestion des formulaires</h1>

    <?php if (!empty($success_msg)): ?>
        <div class="msg-success" role="status" aria-live="polite"><?= h($success_msg) ?></div>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
        <div class="msg-error" role="alert" aria-live="assertive"><?= h($error_msg) ?></div>
    <?php endif; ?>

    <?= render_form_selector_panel($ctx) ?>
    <?= render_import_json_panel($ctx) ?>
    <?= render_prompt_ia_panel($ctx) ?>

    <?php if (empty($form_id)): ?>
        <?= render_new_form_panel($ctx) ?>
    <?php else: ?>
        <?php if ($form): ?>
            <?= render_top_action_bar($ctx) ?>
            <?= render_form_info_section($ctx) ?>
            <?= render_workflow_diagram_section($ctx) ?>
            <?= render_form_fields_section($ctx) ?>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($form): ?>
        <?= render_owners_section($ctx) ?>
    <?php endif; ?>
</div>
<?php
    $content = ob_get_clean();
    if ($content === false) {
        $content = '';
    }
    echo render_page('Gestion des formulaires', 'forms', $page_css, $content);
}
