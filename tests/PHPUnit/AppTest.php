<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Core\App;
use App\Core\Database;
use App\Core\Config;
use App\Auth\AuthService;
use App\Settings\SettingsService;
use App\Security\SecurityService;
use App\Cache\CacheService;
use App\Render\HtmlService;

final class AppTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset singleton for clean tests
        $reflection = new \ReflectionClass(App::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    public function testGetInstanceReturnsSingleton(): void
    {
        $app1 = App::getInstance();
        $app2 = App::getInstance();
        $this->assertSame($app1, $app2);
    }

    public function testSetAndGetService(): void
    {
        $app = App::getInstance();
        $db = new Database();
        $app->set(Database::class, $db);
        $this->assertSame($db, $app->get(Database::class));
    }

    public function testGetThrowsForUnregisteredService(): void
    {
        $app = App::getInstance();
        $this->expectException(\RuntimeException::class);
        $app->get('NonExistentService');
    }

    public function testStaticDbMethod(): void
    {
        $app = App::getInstance();
        $app->set(Database::class, new Database());
        $db = App::db();
        $this->assertInstanceOf(Database::class, $db);
    }

    public function testStaticConfigMethod(): void
    {
        $app = App::getInstance();
        $app->set(Config::class, new Config());
        $config = App::config();
        $this->assertInstanceOf(Config::class, $config);
    }

    public function testStaticHtmlMethod(): void
    {
        $app = App::getInstance();
        $app->set(HtmlService::class, new HtmlService());
        $html = App::html();
        $this->assertInstanceOf(HtmlService::class, $html);
    }
}
