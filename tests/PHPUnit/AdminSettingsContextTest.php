<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Render\AdminSettingsContext;

final class AdminSettingsContextTest extends TestCase
{
    public function testClassExists(): void
    {
        self::assertTrue(class_exists(AdminSettingsContext::class));
    }

    public function testAllPropertiesSetWithDefaultValues(): void
    {
        $ctx = new AdminSettingsContext(
            success: '',
            error: '',
            test: '',
            verify_result: null,
        );

        self::assertSame('', $ctx->success);
        self::assertSame('', $ctx->error);
        self::assertSame('', $ctx->test);
        self::assertNull($ctx->verify_result);
    }

    public function testWithRealisticData(): void
    {
        $ctx = new AdminSettingsContext(
            success: 'Paramètres enregistrés',
            error: '',
            test: 'Test réussi',
            verify_result: [
                'verify' => ['ok' => true, 'detail' => 'Adresse vérifiée'],
                'mode' => 'ldap',
                'format_valid' => true,
            ],
        );

        self::assertSame('Paramètres enregistrés', $ctx->success);
        self::assertSame('Test réussi', $ctx->test);
        self::assertIsArray($ctx->verify_result);
        self::assertTrue($ctx->verify_result['verify']['ok']);
    }

    public function testWithNullVerifyResult(): void
    {
        $ctx = new AdminSettingsContext(
            success: 'Sauvegardé',
            error: 'Erreur de sauvegarde',
            test: '',
            verify_result: null,
        );

        self::assertSame('Sauvegardé', $ctx->success);
        self::assertSame('Erreur de sauvegarde', $ctx->error);
        self::assertNull($ctx->verify_result);
    }
}
