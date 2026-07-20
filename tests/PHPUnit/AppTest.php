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
    public function testGetInstanceReturnsSingleton(): void
    {
        $app1 = App::getInstance();
        $app2 = App::getInstance();
        $this->assertSame($app1, $app2);
    }

    public function testSetAndGetService(): void
    {
        $app = App::getInstance();
        // Use a unique key to avoid clobbering bootstrap services
        $key = 'App\\Tests\\DummyService_' . uniqid();
        $app->set($key, new \stdClass());
        $this->assertInstanceOf(\stdClass::class, $app->get($key));
    }

    public function testGetThrowsForUnregisteredService(): void
    {
        $app = App::getInstance();
        $this->expectException(\RuntimeException::class);
        $app->get('App\\NonExistent\\Service_' . uniqid());
    }

    public function testStaticDbMethod(): void
    {
        $db = App::db();
        $this->assertInstanceOf(Database::class, $db);
    }

    public function testStaticHtmlMethod(): void
    {
        $html = App::html();
        $this->assertInstanceOf(HtmlService::class, $html);
    }

    public function testHasReturnsTrueForRegisteredService(): void
    {
        $app = App::getInstance();
        $this->assertTrue($app->has(Database::class));
    }

    public function testHasReturnsFalseForUnregisteredService(): void
    {
        $app = App::getInstance();
        $this->assertFalse($app->has('App\\NonExistent\\Service_' . uniqid()));
    }
}
