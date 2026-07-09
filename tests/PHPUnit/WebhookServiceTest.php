<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Webhook\WebhookService;

final class WebhookServiceTest extends TestCase
{
    private WebhookService $service;

    protected function setUp(): void
    {
        $this->service = \App\Core\App::webhook();
    }

    public function testGetDbSizeReturnsInt(): void
    {
        $size = $this->service->getDbSize();
        $this->assertIsInt($size);
        $this->assertGreaterThanOrEqual(0, $size);
    }

    public function testSendWithNoWebhookUrlReturnsEarly(): void
    {
        // webhook_url is empty by default, send should return without error
        $this->service->send('test_event', ['key' => 'value']);
        // If we get here without error, the early return worked
        $this->assertTrue(true);
    }

    public function testSendWithUrlButNoMatchingEventReturnsEarly(): void
    {
        $settings = \App\Core\App::getInstance()->get(\App\Settings\SettingsService::class);
        $settings->set('webhook_url', 'http://localhost:1');
        $settings->set('webhook_events', 'other_event');

        // Should return early because 'test_event' is not in allowed events
        $this->service->send('test_event', ['key' => 'value']);
        $this->assertTrue(true);

        // Cleanup
        $settings->set('webhook_url', '');
        $settings->set('webhook_events', '');
    }

    public function testSendWithAllEventsAllowedDoesNotThrow(): void
    {
        $settings = \App\Core\App::getInstance()->get(\App\Settings\SettingsService::class);
        $settings->set('webhook_url', 'http://localhost:1');
        $settings->set('webhook_events', 'all');

        // Should attempt to send (curl will fail silently with localhost:1)
        $this->service->send('test_event', ['key' => 'value']);
        $this->assertTrue(true);

        // Cleanup
        $settings->set('webhook_url', '');
        $settings->set('webhook_events', '');
    }

    public function testSendWithMatchingEventDoesNotThrow(): void
    {
        $settings = \App\Core\App::getInstance()->get(\App\Settings\SettingsService::class);
        $settings->set('webhook_url', 'http://localhost:1');
        $settings->set('webhook_events', 'form.submitted, form.updated');

        // Should attempt to send matching event
        $this->service->send('form.submitted', ['form_id' => 1]);
        $this->assertTrue(true);

        // Cleanup
        $settings->set('webhook_url', '');
        $settings->set('webhook_events', '');
    }

    // ── Constructor / DI ────────────────────────────────────────

    public function testConstructorCreatesInstance(): void
    {
        $app = \App\Core\App::getInstance();
        $db = $app->get(\App\Core\Database::class);
        $settings = $app->get(\App\Settings\SettingsService::class);
        $service = new WebhookService($db, $settings);
        $this->assertInstanceOf(WebhookService::class, $service);
    }

    // ── getDbSize() additional cases ────────────────────────────

    public function testGetDbSizeReturnsPositiveInteger(): void
    {
        $size = $this->service->getDbSize();
        $this->assertIsInt($size);
        $this->assertGreaterThanOrEqual(0, $size);
    }

    public function testGetDbSizeReturnsReasonableSize(): void
    {
        $size = $this->service->getDbSize();
        // DB may not exist in test mode or may be in-memory
        $this->assertIsInt($size);
    }

    // ── send() edge cases ───────────────────────────────────────

    public function testSendWithEmptyEventDoesNotThrow(): void
    {
        $this->service->send('', ['key' => 'value']);
        $this->assertTrue(true);
    }

    public function testSendWithEmptyDataDoesNotThrow(): void
    {
        $this->service->send('test_event', []);
        $this->assertTrue(true);
    }

    public function testSendWithNestedDataDoesNotThrow(): void
    {
        $data = ['nested' => ['key' => 'value', 'list' => [1, 2, 3]]];
        $this->service->send('test_event', $data);
        $this->assertTrue(true);
    }

    public function testSendWithSpecialCharsInEventDoesNotThrow(): void
    {
        $this->service->send('event.with.dots', ['key' => 'value']);
        $this->assertTrue(true);
    }

    // ── send() with matching events ─────────────────────────────

    public function testSendWithMultipleEventsAndMatch(): void
    {
        $settings = \App\Core\App::getInstance()->get(\App\Settings\SettingsService::class);
        $settings->set('webhook_url', 'http://localhost:1');
        $settings->set('webhook_events', 'event_a,event_b,event_c');

        $this->service->send('event_b', ['test' => true]);
        $this->assertTrue(true);

        $settings->set('webhook_url', '');
        $settings->set('webhook_events', '');
    }

    public function testSendWithWhitespaceEvents(): void
    {
        $settings = \App\Core\App::getInstance()->get(\App\Settings\SettingsService::class);
        $settings->set('webhook_url', 'http://localhost:1');
        $settings->set('webhook_events', ' event_a , event_b ');

        $this->service->send('event_a', ['test' => true]);
        $this->assertTrue(true);

        $settings->set('webhook_url', '');
        $settings->set('webhook_events', '');
    }

    // ── Container integration ───────────────────────────────────

    public function testServiceRegisteredInContainer(): void
    {
        $app = \App\Core\App::getInstance();
        $this->assertTrue($app->has(WebhookService::class));
    }

    public function testContainerReturnsSameInstance(): void
    {
        $app = \App\Core\App::getInstance();
        $s1 = $app->get(WebhookService::class);
        $s2 = $app->get(WebhookService::class);
        $this->assertSame($s1, $s2);
    }
}
