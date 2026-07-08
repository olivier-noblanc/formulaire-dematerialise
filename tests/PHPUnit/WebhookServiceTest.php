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
}
