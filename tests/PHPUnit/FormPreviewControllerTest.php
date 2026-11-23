<?php
declare(strict_types=1);

namespace App\Tests;

use App\Controller\FormPreviewController;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class FormPreviewControllerTest extends TestCase
{
    /**
     * La page preview dépend du container global, de l'authentification et du
     * renderer de page : son handle() n'est pas isolable sans bootstrap HTTP.
     * Ce test appelle donc la frontière stricte utilisée par le template ; le
     * test d'intégration devra rester dans la suite HTTP/e2e.
     *
     */
    #[DataProvider('previewRequiredProvider')]
    public function testPreviewRequiredMarkerUsesNormalizedContract(mixed $required, bool $expected): void
    {
        $method = new \ReflectionMethod(FormPreviewController::class, 'isRequired');
        $method->setAccessible(true);
        self::assertSame($expected, $method->invoke(null, ['required' => $required]));
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function previewRequiredProvider(): iterable
    {
        yield 'normalized zero' => [0, false];
        yield 'normalized one' => [1, true];
        // Les chaînes brutes ne font volontairement pas partie du contrat du
        // contrôleur : FieldService les convertit avant cette frontière.
        yield 'raw zero rejected by strict boundary' => ['0', false];
        yield 'raw one rejected by strict boundary' => ['1', false];
    }
}
