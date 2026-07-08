<?php
declare(strict_types=1);

namespace App\Tests\Lib;

use PHPUnit\Framework\TestCase;

final class SettingsLibTest extends TestCase
{
    public function testGetSettingReturnsDefaultForMissingKey(): void
    {
        $result = \App\Core\App::settings()->get('nonexistent_key_xyz_' . uniqid(), 'fallback');
        $this->assertSame('fallback', $result);
    }

    public function testSetAndGetRoundtrip(): void
    {
        $key = 'lib_test_setting_' . uniqid();
        \App\Core\App::settings()->set($key, 'hello');
        $this->assertSame('hello', \App\Core\App::settings()->get($key));
    }

    public function testSetWithUpdatedBy(): void
    {
        $key = 'lib_test_updated_' . uniqid();
        \App\Core\App::settings()->set($key, 'val', 'admin@test.com');
        $this->assertSame('val', \App\Core\App::settings()->get($key));
    }

    public function testSetOverwritesPreviousValue(): void
    {
        $key = 'lib_test_overwrite_' . uniqid();
        \App\Core\App::settings()->set($key, 'first');
        \App\Core\App::settings()->set($key, 'second');
        $this->assertSame('second', \App\Core\App::settings()->get($key));
    }

    public function testGetSettingDefaultWhenEmpty(): void
    {
        $result = \App\Core\App::settings()->get('totally_missing_' . uniqid(), 'default_val');
        $this->assertSame('default_val', $result);
    }

    public function testGetSettingReturnsConfigDefault(): void
    {
        $result = \App\Core\App::settings()->get('smtp_host');
        $this->assertIsString($result);
    }
}
