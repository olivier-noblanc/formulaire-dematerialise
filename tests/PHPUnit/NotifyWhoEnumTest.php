<?php
declare(strict_types=1);

namespace App\Tests;

use App\Enum\NotifyWho;
use App\Render\AdminAlertsRenderer;
use App\Repository\AlertRepository;
use PHPUnit\Framework\TestCase;

/**
 * `alert_rules.notify_who` : cinq sentinelles fermées + courriel dynamique.
 *
 * Ces tests verrouillent la source de vérité unique (enum NotifyWho) là où le
 * code dupliquait auparavant la même liste de cinq chaînes dans le repository,
 * le contrôleur et le renderer.
 */
final class NotifyWhoEnumTest extends TestCase
{
    /**
     * Les cinq sentinelles historiques, dans l'ordre métier.
     *
     * @return list<string>
     */
    private const array SENTINELS = [
        'admin',
        'submitter',
        'validators',
        'admin+submitter',
        'admin+validators',
    ];

    public function testEnumCoversExactlyTheFiveSentinels(): void
    {
        self::assertSame(self::SENTINELS, NotifyWho::values());
    }

    public function testIsSentinelAcceptsSentinelsAndRejectsEverythingElse(): void
    {
        foreach (self::SENTINELS as $sentinel) {
            self::assertTrue(NotifyWho::isSentinel($sentinel), "Sentinelle attendue : {$sentinel}");
        }

        // `custom` est une option d'interface jamais stockée, pas une sentinelle.
        self::assertFalse(NotifyWho::isSentinel(NotifyWho::CUSTOM_OPTION));
        self::assertFalse(NotifyWho::isSentinel(''));
        self::assertFalse(NotifyWho::isSentinel('ADMIN'));
        self::assertFalse(NotifyWho::isSentinel('admin+owner'));
        self::assertFalse(NotifyWho::isSentinel('manager@exemple.invalid'));
    }

    public function testEverySentinelHasADisplayLabel(): void
    {
        foreach (NotifyWho::cases() as $case) {
            self::assertNotSame('', $case->label(), "Libellé manquant pour {$case->value}");
        }
    }

    public function testRepositoryValidationReliesOnTheEnumAndKeepsEmailsDynamic(): void
    {
        foreach (self::SENTINELS as $sentinel) {
            self::assertTrue(AlertRepository::isValidNotifyWho($sentinel));
        }

        self::assertTrue(AlertRepository::isValidNotifyWho('manager@exemple.invalid'));
        self::assertFalse(AlertRepository::isValidNotifyWho('custom'));
        self::assertFalse(AlertRepository::isValidNotifyWho('admin+owner'));
    }

    public function testRendererOffersEverySentinelPlusTheCustomOption(): void
    {
        $html = AdminAlertsRenderer::content(
            '',
            '',
            [['id' => 'f1', 'label' => 'Test Form', 'deadline_field' => 'date_limite']],
            [],
            [],
            '',
            '',
            ['f1' => [['field_name' => 'date_limite', 'label' => 'Date limite']]],
        );

        foreach (self::SENTINELS as $sentinel) {
            self::assertStringContainsString('value="' . $sentinel . '"', $html);
            $label = NotifyWho::from($sentinel)->label();
            self::assertStringContainsString('>' . $label . '</option>', $html);
        }
        self::assertStringContainsString('value="' . NotifyWho::CUSTOM_OPTION . '"', $html);
    }
}