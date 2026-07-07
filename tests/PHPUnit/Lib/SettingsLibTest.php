<?php
declare(strict_types=1);

namespace App\Tests\Lib;

use PHPUnit\Framework\TestCase;

final class SettingsLibTest extends TestCase
{
    public function testGetSettingReturnsDefaultForMissingKey(): void
    {
        $result = get_setting('nonexistent_key_xyz_' . uniqid(), 'fallback');
        $this->assertSame('fallback', $result);
    }

    public function testSetAndGetRoundtrip(): void
    {
        $key = 'lib_test_setting_' . uniqid();
        set_setting($key, 'hello');
        $this->assertSame('hello', get_setting($key));
    }

    public function testSetWithUpdatedBy(): void
    {
        $key = 'lib_test_updated_' . uniqid();
        set_setting($key, 'val', 'admin@test.com');
        $this->assertSame('val', get_setting($key));
    }

    public function testSetOverwritesPreviousValue(): void
    {
        $key = 'lib_test_overwrite_' . uniqid();
        set_setting($key, 'first');
        set_setting($key, 'second');
        $this->assertSame('second', get_setting($key));
    }

    public function testGetSettingDefaultWhenEmpty(): void
    {
        $result = get_setting('totally_missing_' . uniqid(), 'default_val');
        $this->assertSame('default_val', $result);
    }

    public function testGetSettingReturnsConfigDefault(): void
    {
        $result = get_setting('smtp_host');
        $this->assertIsString($result);
    }
}
