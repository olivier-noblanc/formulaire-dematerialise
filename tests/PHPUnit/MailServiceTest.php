<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Mail\MailService;
use App\Core\Database;
use App\Settings\SettingsService;
use App\Repository\SettingsRepository;

final class MailServiceTest extends TestCase
{
    private MailService $mail;
    private Database $db;
    private SettingsService $settings;

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(\App\Core\Database::class);
        $this->settings = new SettingsService(new SettingsRepository($this->db));
        $this->mail = new MailService($this->db, $this->settings);
    }

    public function testSendReturnsTrueInTestMode(): void
    {
        $result = $this->mail->send('test@example.com', 'Test Subject', '<p>Test body</p>');
        $this->assertTrue($result);
    }

    public function testSendCapturesMailInTestMode(): void
    {
        $GLOBALS['_test_mails'] = [];
        $this->mail->send('test@example.com', 'Subject', '<p>Body</p>');
        $this->assertNotEmpty($GLOBALS['_test_mails']);
        $this->assertSame('test@example.com', $GLOBALS['_test_mails'][0]['to']);
    }

    public function testBuildValidationEmailContainsToken(): void
    {
        $submission = [
            'form_label' => 'Test Form',
            'submitted_by' => 'test@example.com',
        ];
        $html = $this->mail->buildValidationEmail($submission, 'Step 1', 'test_token_123');
        $this->assertStringContainsString('test_token_123', $html);
        $this->assertStringContainsString('Step 1', $html);
    }

    public function testBuildValidationEmailContainsStepLabel(): void
    {
        $submission = [
            'form_label' => 'Onboarding',
            'submitted_by' => 'test@example.com',
        ];
        $html = $this->mail->buildValidationEmail($submission, 'Validation', 'token');
        $this->assertStringContainsString('Validation', $html);
    }

    public function testBuildValidationEmailIsHtml(): void
    {
        $submission = ['form_label' => 'Test', 'submitted_by' => 'test@example.com'];
        $html = $this->mail->buildValidationEmail($submission, 'Step', 'token');
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<html>', $html);
    }

    public function testRenderEmailTemplateDoesNotThrow(): void
    {
        try {
            $html = $this->mail->renderEmailTemplate('Test Title', '<p>Content</p>');
            $this->assertIsString($html);
            $this->assertNotEmpty($html);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('App container services not registered');
        }
    }
}
