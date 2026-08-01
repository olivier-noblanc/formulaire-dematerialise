<?php

declare(strict_types=1);

namespace App\Render;

/**
 * Context object for AdminFormsRenderer.
 *
 * Replaces the loose array<string, mixed> $ctx parameter.
 * All properties are typed and enforced by PHPStan.
 */
final readonly class AdminFormsContext
{
    /**
     * @param string              $form_id         Selected form ID (empty = none selected)
     * @param array|null          $form            Form data from DB (id, label, slug, description, actif)
     * @param array<int, array>   $forms           List of all forms for the selector dropdown
     * @param string              $error_msg       Error message to display
     * @param string              $success_msg     Success message to display
     * @param string              $preserved_json  JSON data to preserve in import textarea
     * @param string              $validation_html HTML for JSON validation results
     * @param array<int, array>   $owners          Form owners list
     * @param array<int, array>   $steps           Workflow steps list
     * @param array<int, array<int, array>> $steps_by_ordre Steps grouped by ordre
     * @param string              $edit_step_id    Step ID being edited inline (empty = none)
     * @param array<int, array>   $form_fields     Form fields list
     * @param string              $edit_field_id   Field ID being edited inline (empty = none)
     * @param array<int, string>  $existing_groups Existing card group names
     */
    public function __construct(
        public string $form_id,
        public ?array $form,
        public array $forms,
        public string $error_msg,
        public string $success_msg,
        public string $preserved_json,
        public string $validation_html,
        public array $owners,
        public array $steps,
        public array $steps_by_ordre,
        public string $edit_step_id,
        public array $form_fields,
        public string $edit_field_id,
        public array $existing_groups,
    ) {}
}
