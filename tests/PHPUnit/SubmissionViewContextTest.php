<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Render\SubmissionViewContext;

/**
 * Tests unitaires pour SubmissionViewContext DTO.
 *
 * Vérifie que toutes les propriétés du contexte sont correctement
 * typées et accessibles en lecture seule.
 */
final class SubmissionViewContextTest extends TestCase
{
    public function testClassExists(): void
    {
        self::assertTrue(class_exists(SubmissionViewContext::class));
    }

    public function testAllPropertiesSetWithDefaultValues(): void
    {
        $ctx = new SubmissionViewContext(
            sub_id: 'sub-1',
            sub: ['form_label' => 'Test', 'submitted_by' => 'agent@test.fr', 'submitted_at' => '2025-01-01', 'closed_at' => null],
            data: ['field1' => 'val1'],
            status: 'en_cours',
            status_label: 'En cours',
            status_cls: 'badge-warn',
            user: 'agent@test.fr',
            is_admin: false,
            is_form_owner: false,
            nom_agent: 'Agent Test',
            workflow_steps: [],
            all_tokens: [],
            total_steps: 3,
            done_steps: 1,
            progress_pct: 33,
            dl_info: ['urgency' => 'ok'],
            deadline_ts: null,
            days_remaining: 0,
            action_msg: '',
            field_info: [],
            validator_data_rows: [],
            submission_reminds: [],
            total_relances: 0,
            pending_with_relance: [],
            attachments: [],
            delegations: [],
            admin_comment: '',
        );

        self::assertSame('sub-1', $ctx->sub_id);
        self::assertSame('en_cours', $ctx->status);
        self::assertFalse($ctx->is_admin);
        self::assertSame(33, $ctx->progress_pct);
    }

    public function testAdminContextValues(): void
    {
        $ctx = new SubmissionViewContext(
            sub_id: 'sub-2',
            sub: ['form_label' => 'Admin Test', 'submitted_by' => 'user@test.fr', 'submitted_at' => '2025-06-01', 'closed_at' => null],
            data: [],
            status: 'valide',
            status_label: 'Validé',
            status_cls: 'badge-ok',
            user: 'admin@test.fr',
            is_admin: true,
            is_form_owner: true,
            nom_agent: 'Admin',
            workflow_steps: [],
            all_tokens: [],
            total_steps: 5,
            done_steps: 5,
            progress_pct: 100,
            dl_info: [],
            deadline_ts: null,
            days_remaining: 0,
            action_msg: 'Soumission validée',
            field_info: [],
            validator_data_rows: [],
            submission_reminds: [],
            total_relances: 0,
            pending_with_relance: [],
            attachments: [],
            delegations: [],
            admin_comment: 'Commentaire admin',
        );

        self::assertTrue($ctx->is_admin);
        self::assertTrue($ctx->is_form_owner);
        self::assertSame(100, $ctx->progress_pct);
        self::assertSame('Soumission validée', $ctx->action_msg);
        self::assertSame('Commentaire admin', $ctx->admin_comment);
    }

    public function testWithWorkflowSteps(): void
    {
        $steps = [
            ['step_status' => 'validated', 'step_label' => 'Direction', 'ordre' => 1, 'tokens' => []],
        ];

        $ctx = new SubmissionViewContext(
            sub_id: 'sub-3',
            sub: ['form_label' => 'WF Test', 'submitted_by' => 'a@test.fr', 'submitted_at' => '2025-06-01', 'closed_at' => null],
            data: [],
            status: 'en_cours',
            status_label: 'En cours',
            status_cls: 'badge-warn',
            user: 'a@test.fr',
            is_admin: false,
            is_form_owner: false,
            nom_agent: '',
            workflow_steps: $steps,
            all_tokens: [],
            total_steps: 1,
            done_steps: 0,
            progress_pct: 0,
            dl_info: [],
            deadline_ts: null,
            days_remaining: 5,
            action_msg: '',
            field_info: [],
            validator_data_rows: [],
            submission_reminds: [],
            total_relances: 0,
            pending_with_relance: [],
            attachments: [],
            delegations: [],
            admin_comment: '',
        );

        self::assertCount(1, $ctx->workflow_steps);
        self::assertSame('Direction', $ctx->workflow_steps[0]['step_label']);
        self::assertSame(5, $ctx->days_remaining);
    }

    public function testWithAttachmentsAndDelegations(): void
    {
        $attachments = [
            ['id' => 'att1', 'mime_type' => 'application/pdf', 'original_name' => 'doc.pdf', 'file_size' => 1024, 'uploaded_at' => '2025-06-01'],
        ];
        $delegations = [
            ['step_label' => 'RH', 'from_email' => 'a@test.fr', 'to_email' => 'b@test.fr', 'delegated_at' => '2025-06-01', 'reason' => 'Absence'],
        ];

        $ctx = new SubmissionViewContext(
            sub_id: 'sub-4',
            sub: ['form_label' => 'Att Test', 'submitted_by' => 'a@test.fr', 'submitted_at' => '2025-06-01', 'closed_at' => null],
            data: [],
            status: 'en_cours',
            status_label: 'En cours',
            status_cls: 'badge-warn',
            user: 'a@test.fr',
            is_admin: false,
            is_form_owner: false,
            nom_agent: '',
            workflow_steps: [],
            all_tokens: [],
            total_steps: 1,
            done_steps: 0,
            progress_pct: 0,
            dl_info: [],
            deadline_ts: null,
            days_remaining: 0,
            action_msg: '',
            field_info: [],
            validator_data_rows: [],
            submission_reminds: [],
            total_relances: 0,
            pending_with_relance: [],
            attachments: $attachments,
            delegations: $delegations,
            admin_comment: '',
        );

        self::assertCount(1, $ctx->attachments);
        self::assertCount(1, $ctx->delegations);
        self::assertSame('doc.pdf', $ctx->attachments[0]['original_name']);
        self::assertSame('Absence', $ctx->delegations[0]['reason']);
    }
}
