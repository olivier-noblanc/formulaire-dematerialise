<?php
declare(strict_types=1);

namespace App\Tests\Lib;

use PHPUnit\Framework\TestCase;

final class DatabaseLibTest extends TestCase
{
    public function testGetPdoReturnsPdoInstance(): void
    {
        $pdo = \App\Core\App::db()->getPdo();
        self::assertInstanceOf(\PDO::class, $pdo);
    }

    public function testGetPdoReturnsSameInstance(): void
    {
        $pdo1 = \App\Core\App::db()->getPdo();
        $pdo2 = \App\Core\App::db()->getPdo();
        self::assertSame($pdo1, $pdo2);
    }

    public function testGetFormByUuidReturnsNullForMissing(): void
    {
        $result = get_form_by_uuid('00000000-0000-0000-0000-000000000000');
        self::assertNull($result);
    }

    public function testGenerateFieldNameBasic(): void
    {
        self::assertSame('date_de_prise_de_poste', generate_field_name('Date de prise de poste'));
    }

    public function testGenerateFieldNameAccents(): void
    {
        $result = generate_field_name("Type d'arrivée");
        self::assertSame('type_d_arrivee', $result);
    }

    public function testGenerateFieldNameSpecialChars(): void
    {
        $result = generate_field_name('Email / Téléphone');
        self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $result);
    }

    public function testGenerateFieldNameEmpty(): void
    {
        $result = generate_field_name('');
        self::assertSame('champ', $result);
    }

    public function testParseOptionsInputJsonPassthrough(): void
    {
        $json = '["Option A","Option B"]';
        self::assertSame($json, parse_options_input($json));
    }

    public function testParseOptionsInputMultilineToJSON(): void
    {
        $input = "Option A\nOption B\nOption C";
        $result = parse_options_input($input);
        $decoded = json_decode($result, true);
        self::assertIsArray($decoded);
        self::assertCount(3, $decoded);
        self::assertSame('Option A', $decoded[0]);
        self::assertSame('Option C', $decoded[2]);
    }

    public function testParseOptionsInputEmptyReturnsNull(): void
    {
        self::assertNull(parse_options_input(''));
        self::assertNull(parse_options_input('   '));
    }

    public function testParseOptionsInputSingleOption(): void
    {
        $result = parse_options_input('Only One');
        $decoded = json_decode($result, true);
        self::assertSame(['Only One'], $decoded);
    }

    public function testGenerateSlugReturnsString(): void
    {
        $slug = generate_slug('Formulaire Test');
        self::assertIsString($slug);
        self::assertNotEmpty($slug);
    }

    public function testGenerateSlugBasic(): void
    {
        $slug = generate_slug('Formulaire Test');
        self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $slug);
    }
}
