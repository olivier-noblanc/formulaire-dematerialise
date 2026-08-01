<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Render\MonitoringContext;

final class MonitoringContextTest extends TestCase
{
    public function testClassExists(): void
    {
        self::assertTrue(class_exists(MonitoringContext::class));
    }

    public function testAllPropertiesSetWithDefaultValues(): void
    {
        $ctx = new MonitoringContext(
            total_sub: 0,
            valide_sub: 0,
            en_cours_sub: 0,
            refuse_sub: 0,
            taux_validation: 0.0,
            avg_days: 0.0,
            avg_hours: 0.0,
            bloque_hours: 0,
            tokens_bloques: [],
            active_alerts: [],
            recent_alerts: [],
            by_form_stats: [],
            daily_stats: [],
            smtp_status: 'inconnu',
            smtp_detail: '',
            smtp_debug_log: '',
            mail_logs: [],
            last_remind: '',
            last_alert_check: '',
            audit_filters: [],
            audit_total: 0,
            audit_total_pages: 1,
            audit_page: 1,
            audit_logs: [],
            action_types: [],
            audit_base_url: 'index.php?p=monitoring',
            audit_base_qs: '',
        );

        self::assertSame(0, $ctx->total_sub);
        self::assertSame('inconnu', $ctx->smtp_status);
        self::assertSame(1, $ctx->audit_total_pages);
        self::assertSame([], $ctx->tokens_bloques);
        self::assertSame(0.0, $ctx->taux_validation);
    }

    public function testWithRealisticData(): void
    {
        $ctx = new MonitoringContext(
            total_sub: 150,
            valide_sub: 100,
            en_cours_sub: 40,
            refuse_sub: 10,
            taux_validation: 66.7,
            avg_days: 3.5,
            avg_hours: 0.0,
            bloque_hours: 48,
            tokens_bloques: [
                ['id' => 't1', 'email' => 'test@test.fr', 'sent_at' => '2025-06-01', 'expires_at' => '2025-07-01'],
            ],
            active_alerts: [
                ['id' => 'a1', 'label' => 'Alerte SMTP', 'level' => 'warning'],
            ],
            recent_alerts: [],
            by_form_stats: [
                ['form_label' => 'Formulaire A', 'total' => 50, 'valide' => 40],
            ],
            daily_stats: [],
            smtp_status: 'ok',
            smtp_detail: 'SMTP opérationnel',
            smtp_debug_log: '',
            mail_logs: [],
            last_remind: '2025-07-01 10:00:00',
            last_alert_check: '2025-07-01 10:00:00',
            audit_filters: ['log_action' => 'login', 'log_date_debut' => '2025-01-01'],
            audit_total: 500,
            audit_total_pages: 25,
            audit_page: 1,
            audit_logs: [
                ['created_at' => '2025-07-01', 'action' => 'login', 'actor' => 'admin@test.fr', 'target' => '', 'detail' => 'Login réussi', 'ip' => '127.0.0.1'],
            ],
            action_types: ['login', 'logout', 'create'],
            audit_base_url: 'index.php?p=monitoring',
            audit_base_qs: 'statut=tous',
        );

        self::assertSame(150, $ctx->total_sub);
        self::assertSame('ok', $ctx->smtp_status);
        self::assertCount(1, $ctx->tokens_bloques);
        self::assertSame('login', $ctx->audit_filters['log_action']);
        self::assertSame(25, $ctx->audit_total_pages);
        self::assertCount(1, $ctx->audit_logs);
        self::assertCount(3, $ctx->action_types);
    }

    public function testEdgeCaseValues(): void
    {
        $ctx = new MonitoringContext(
            total_sub: 0,
            valide_sub: 0,
            en_cours_sub: 0,
            refuse_sub: 0,
            taux_validation: 0.0,
            avg_days: 0.0,
            avg_hours: 0.0,
            bloque_hours: 0,
            tokens_bloques: [],
            active_alerts: [],
            recent_alerts: [],
            by_form_stats: [],
            daily_stats: [],
            smtp_status: '',
            smtp_detail: '',
            smtp_debug_log: '',
            mail_logs: [],
            last_remind: '',
            last_alert_check: '',
            audit_filters: [],
            audit_total: 0,
            audit_total_pages: 0,
            audit_page: 0,
            audit_logs: [],
            action_types: [],
            audit_base_url: '',
            audit_base_qs: '',
        );

        self::assertSame(0, $ctx->audit_total_pages);
        self::assertSame('', $ctx->smtp_status);
        self::assertSame([], $ctx->action_types);
    }
}
