<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Core\Config;

final class ConfigTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        $this->config = new Config();
    }

    public function testGetDefaultsReturnsArray(): void
    {
        $defaults = $this->config->getDefaults();
        $this->assertIsArray($defaults);
        $this->assertArrayHasKey('smtp_host', $defaults);
        $this->assertArrayHasKey('admin_email', $defaults);
    }

    public function testGetReturnsDefaultValue(): void
    {
        $this->assertSame('default', $this->config->get('nonexistent', 'default'));
    }

    public function testGetReturnsConfigValue(): void
    {
        $this->assertSame('localhost', $this->config->get('smtp_host'));
    }

    public function testGetBaseUrl(): void
    {
        $baseUrl = $this->config->getBaseUrl();
        $this->assertNotEmpty($baseUrl);
        $this->assertStringContainsString('localhost', $baseUrl);
    }

    public function testGetDbPath(): void
    {
        $dbPath = $this->config->getDbPath();
        $this->assertNotEmpty($dbPath);
        $this->assertStringContainsString('workflow.db', $dbPath);
    }

    public function testIsTestMode(): void
    {
        // In test environment, TEST_MODE should be true
        $this->assertTrue($this->config->isTestMode());
    }

    public function testGetAppName(): void
    {
        $this->assertSame('CircuitDémat — DREETS BFC', $this->config->getAppName());
    }
}
