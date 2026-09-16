<?php
declare(strict_types=1);

namespace App\Tests;

use App\Render\MonitoringContext;
use App\Render\MonitoringRenderer;
use PHPUnit\Framework\TestCase;

/**
 * A4 — bannière admin rouge signalant les emails en échec définitif.
 *
 * Fichier : tests/PHPUnit/MonitoringRendererOutboxAlertTest.php
 */
final class MonitoringRendererOutboxAlertTest extends TestCase
{
    public function testOutboxAlertIsEmptyWhenNoFailure(): void
    {
        self::assertSame('', MonitoringRenderer::outboxAlert(0));
        self::assertSame('', MonitoringRenderer::outboxAlert(-1));
    }

    public function testOutboxAlertRendersUnknownBannerWhenCountIsNull(): void
    {
        // F6 : compteur illisible → bannière « état inconnu », jamais silence.
        $html = MonitoringRenderer::outboxAlert(null);

        self::assertNotSame('', $html);
        self::assertStringContainsString('outbox-alert', $html);
        self::assertStringContainsString('inconnu', $html);
    }

    public function testOutboxAlertRendersRedBannerWithCount(): void
    {
        $html = MonitoringRenderer::outboxAlert(3);

        self::assertStringContainsString('outbox-alert', $html);
        self::assertStringContainsString('3', $html);
        self::assertStringContainsString('définitivement', $html);
    }

    public function testContentIncludesBannerWhenFailuresPresent(): void
    {
        $ctx = MonitoringContext::fromLegacyArray(['outbox_failed' => 2]);
        self::assertSame(2, $ctx->outbox_failed);

        $html = MonitoringRenderer::content($ctx);
        self::assertStringContainsString('outbox-alert', $html);
    }

    public function testContentOmitsBannerWhenNoFailure(): void
    {
        $ctx = MonitoringContext::fromLegacyArray(['outbox_failed' => 0]);
        self::assertSame(0, $ctx->outbox_failed);

        $html = MonitoringRenderer::content($ctx);
        self::assertStringNotContainsString('outbox-alert', $html);
    }

    public function testContentShowsUnknownBannerWhenCountIsNull(): void
    {
        $ctx = MonitoringContext::fromLegacyArray(['outbox_failed' => null]);
        self::assertNull($ctx->outbox_failed);

        $html = MonitoringRenderer::content($ctx);
        self::assertStringContainsString('outbox-alert--unknown', $html);
    }

    public function testLegacyContextWithoutCountIsUnknown(): void
    {
        // L'absence de compteur n'est pas un « 0 sain » : c'est un état inconnu.
        self::assertNull(MonitoringContext::fromLegacyArray([])->outbox_failed);
    }
}