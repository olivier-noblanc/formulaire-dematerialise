<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Render\AdminAlertsRenderer;

/**
 * B7 — une règle days_before=0 (Jour J) doit porter la classe CSS "passed",
 * pas "urgent".
 */
final class AdminAlertsRendererDaysBadgeTest extends TestCase
{
    public function testDaysBeforeZeroUsesPassedClass(): void
    {
        $rule = [
            'id' => 'r0', 'form_id' => 'f1', 'days_before' => 0,
            'condition_type' => 'steps_incomplete', 'notify_who' => 'admin',
            'label' => 'Alerte Jour J', 'actif' => 1, 'created_at' => '2025-01-01',
            'form_label' => 'Test Form', 'form_slug' => 'test', 'deadline_field' => 'date_limite',
        ];
        $html = AdminAlertsRenderer::content(
            '', '',
            [['id' => 'f1', 'label' => 'Test Form', 'deadline_field' => 'date_limite']],
            [$rule],
            [], '', '',
            ['f1' => [['field_name' => 'date_limite', 'label' => 'Date limite']]]
        );

        self::assertStringContainsString('days-badge passed', $html);
        self::assertStringNotContainsString('days-badge urgent', $html);
    }
}