<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Render\AdminFormsContext;

final class AdminFormsContextTest extends TestCase
{
    public function testClassExists(): void
    {
        self::assertTrue(class_exists(AdminFormsContext::class));
    }

    public function testRequiredPropertiesAreSet(): void
    {
        $ctx = new AdminFormsContext(
            form_id: 'f1',
            form: null,
            forms: [],
            error_msg: '',
            success_msg: '',
            preserved_json: '',
            validation_html: '',
            owners: [],
            steps: [],
            steps_by_ordre: [],
            edit_step_id: '',
            form_fields: [],
            edit_field_id: '',
            existing_groups: [],
        );

        self::assertSame('f1', $ctx->form_id);
        self::assertNull($ctx->form);
        self::assertSame([], $ctx->forms);
        self::assertSame('', $ctx->error_msg);
        self::assertSame('', $ctx->success_msg);
    }

    public function testWithFormData(): void
    {
        $form = [
            'id' => 'f1',
            'label' => 'Test Form',
            'slug' => 'test-form',
            'description' => 'A test form',
            'actif' => true,
        ];

        $ctx = new AdminFormsContext(
            form_id: 'f1',
            form: $form,
            forms: [$form],
            error_msg: '',
            success_msg: '',
            preserved_json: '',
            validation_html: '',
            owners: [],
            steps: [],
            steps_by_ordre: [],
            edit_step_id: '',
            form_fields: [],
            edit_field_id: '',
            existing_groups: [],
        );

        self::assertIsArray($ctx->form);
        self::assertSame('Test Form', $ctx->form['label']);
        self::assertCount(1, $ctx->forms);
    }

    public function testWithWorkflowSteps(): void
    {
        $steps = [
            ['id' => 's1', 'label' => 'Step 1', 'ordre' => 1, 'actif' => true, 'condition' => '', 'recipients' => []],
            ['id' => 's2', 'label' => 'Step 2', 'ordre' => 2, 'actif' => true, 'condition' => '', 'recipients' => []],
        ];

        $ctx = new AdminFormsContext(
            form_id: 'f1',
            form: null,
            forms: [],
            error_msg: '',
            success_msg: '',
            preserved_json: '',
            validation_html: '',
            owners: [],
            steps: $steps,
            steps_by_ordre: [1 => [$steps[0]], 2 => [$steps[1]]],
            edit_step_id: '',
            form_fields: [],
            edit_field_id: '',
            existing_groups: [],
        );

        self::assertCount(2, $ctx->steps);
        self::assertArrayHasKey(1, $ctx->steps_by_ordre);
        self::assertArrayHasKey(2, $ctx->steps_by_ordre);
    }

    public function testWithFormFields(): void
    {
        $fields = [
            ['id' => 'ff1', 'label' => 'Name', 'field_name' => 'name', 'field_type' => 'text', 'ordre' => 1, 'card_group' => 'General', 'filled_by' => 'demandeur'],
        ];

        $ctx = new AdminFormsContext(
            form_id: 'f1',
            form: null,
            forms: [],
            error_msg: '',
            success_msg: '',
            preserved_json: '',
            validation_html: '',
            owners: [],
            steps: [],
            steps_by_ordre: [],
            edit_step_id: '',
            form_fields: $fields,
            edit_field_id: '',
            existing_groups: ['General'],
        );

        self::assertCount(1, $ctx->form_fields);
        self::assertSame(['General'], $ctx->existing_groups);
    }
}
