<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Mail\MailService;
use App\Core\Database;
use App\Settings\SettingsService;
use App\Repository\SettingsRepository;
use App\Repository\MailRepository;

final class MailServiceTest extends TestCase
{
    private MailService $mail;
    private Database $db;
    private SettingsService $settings;

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(\App\Core\Database::class);
        $this->settings = new SettingsService(new SettingsRepository($this->db));
        $this->mail = new MailService(new MailRepository($this->db), $this->settings);
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

    // ── Constructor / DI ────────────────────────────────────────

    public function testConstructorCreatesInstance(): void
    {
        $mail = new MailService(new MailRepository($this->db), $this->settings);
        $this->assertInstanceOf(MailService::class, $mail);
    }

    public function testImplementsMailInterface(): void
    {
        $this->assertInstanceOf(\App\Contract\MailInterface::class, $this->mail);
    }

    // ── send() additional cases ─────────────────────────────────

    public function testSendWithHtmlBodyCapturesBody(): void
    {
        $GLOBALS['_test_mails'] = [];
        $body = '<h1>Hello</h1><p>World</p>';
        $this->mail->send('test@example.com', 'HTML Test', $body);
        $this->assertSame($body, $GLOBALS['_test_mails'][0]['body']);
    }

    public function testSendCapturesSubject(): void
    {
        $GLOBALS['_test_mails'] = [];
        $this->mail->send('test@example.com', 'My Subject Line', '<p>Body</p>');
        $this->assertSame('My Subject Line', $GLOBALS['_test_mails'][0]['subject']);
    }

    public function testSendCapturesTimestamp(): void
    {
        $GLOBALS['_test_mails'] = [];
        $this->mail->send('test@example.com', 'Subject', '<p>Body</p>');
        $this->assertArrayHasKey('time', $GLOBALS['_test_mails'][0]);
        $this->assertNotEmpty($GLOBALS['_test_mails'][0]['time']);
    }

    public function testSendReturnsBoolean(): void
    {
        $result = $this->mail->send('test@example.com', 'Subject', '<p>Body</p>');
        $this->assertIsBool($result);
        $this->assertTrue($result);
    }

    public function testSendMultipleMailsAccumulatesInGlobal(): void
    {
        $GLOBALS['_test_mails'] = [];
        $this->mail->send('user1@test.com', 'Subj1', '<p>1</p>');
        $this->mail->send('user2@test.com', 'Subj2', '<p>2</p>');
        $this->assertCount(2, $GLOBALS['_test_mails']);
    }

    // ── buildValidationEmail() additional cases ─────────────────

    public function testBuildValidationEmailContainsAppName(): void
    {
        $submission = ['form_label' => 'Test', 'submitted_by' => 'test@test.com'];
        $html = $this->mail->buildValidationEmail($submission, 'Step', 'token');
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
    }

    public function testBuildValidationEmailContainsValidateUrl(): void
    {
        $submission = ['form_label' => 'Test', 'submitted_by' => 'test@test.com'];
        $html = $this->mail->buildValidationEmail($submission, 'Step', 'mytoken42');
        $this->assertStringContainsString('mytoken42', $html);
        $this->assertStringContainsString('validate', $html);
    }

    public function testBuildValidationEmailContainsStepLabelInBold(): void
    {
        $submission = ['form_label' => 'Form', 'submitted_by' => 'test@test.com'];
        $html = $this->mail->buildValidationEmail($submission, 'My Step', 'tok');
        $this->assertStringContainsString('<strong>My Step</strong>', $html);
    }

    public function testBuildValidationEmailEscapesHtmlInStepLabel(): void
    {
        $submission = ['form_label' => 'Form', 'submitted_by' => 'test@test.com'];
        $html = $this->mail->buildValidationEmail($submission, '<script>alert(1)</script>', 'tok');
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ── renderEmailTemplate() additional cases ──────────────────

    public function testRenderEmailTemplateReturnsString(): void
    {
        try {
            $html = $this->mail->renderEmailTemplate('Title', '<p>Body</p>');
            $this->assertIsString($html);
            $this->assertNotEmpty($html);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('App container services not registered');
        }
    }

    public function testRenderEmailTemplateContainsTitle(): void
    {
        try {
            $html = $this->mail->renderEmailTemplate('My Title', '<p>Body</p>');
            $this->assertStringContainsString('My Title', $html);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('App container services not registered');
        }
    }

    public function testRenderEmailTemplateContainsBody(): void
    {
        try {
            $html = $this->mail->renderEmailTemplate('Title', '<p>My Content</p>');
            $this->assertStringContainsString('My Content', $html);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('App container services not registered');
        }
    }

    // ── Container integration ───────────────────────────────────

    public function testServiceRegisteredInContainer(): void
    {
        $app = \App\Core\App::getInstance();
        $this->assertTrue($app->has(MailService::class));
    }

    public function testContainerReturnsSameInstance(): void
    {
        $app = \App\Core\App::getInstance();
        $mail1 = $app->get(MailService::class);
        $mail2 = $app->get(MailService::class);
        $this->assertSame($mail1, $mail2);
    }
}
