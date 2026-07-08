<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Mail\MailerService;
use App\Core\Database;
use App\Settings\SettingsService;

final class MailerServiceTest extends TestCase
{
    private MailerService $mailer;
    private Database $db;
    private SettingsService $settings;

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(\App\Core\Database::class);
        $this->settings = new SettingsService($this->db);
        $this->mailer = new MailerService($this->db, $this->settings);
    }

    public function testSendReturnsBoolean(): void
    {
        $result = $this->mailer->send('test@example.com', 'Test Subject', '<p>Test body</p>');
        $this->assertIsBool($result);
    }

    public function testSendReturnsTrueInTestMode(): void
    {
        $result = $this->mailer->send('test@example.com', 'Test Subject', '<p>Test body</p>');
        $this->assertTrue($result);
    }

    public function testSendDetailedReturnsArrayWithRequiredKeys(): void
    {
        $result = $this->mailer->sendDetailed('test@example.com', 'Subject', '<p>Body</p>');
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('smtp_log', $result);
        $this->assertArrayHasKey('status', $result);
    }

    public function testSendDetailedCapturesMailInTestMode(): void
    {
        $GLOBALS['_test_mails'] = [];
        $this->mailer->sendDetailed('test@example.com', 'Subject', '<p>Body</p>');
        $this->assertNotEmpty($GLOBALS['_test_mails']);
        $this->assertSame('test@example.com', $GLOBALS['_test_mails'][0]['to']);
    }

    public function testSendDetailedStatusDryRunInTestMode(): void
    {
        $result = $this->mailer->sendDetailed('test@example.com', 'Subject', '<p>Body</p>');
        $this->assertSame('dry_run', $result['status']);
    }

    public function testSendDetailedReturnsBlockedForInvalidEmail(): void
    {
        $result = $this->mailer->sendDetailed('not-an-email', 'Subject', '<p>Body</p>');
        $this->assertFalse($result['success']);
        $this->assertSame('blocked', $result['status']);
        $this->assertStringContainsString('invalide', $result['error']);
    }

    public function testLogAttemptDoesNotThrow(): void
    {
        $this->mailer->logAttempt('test@example.com', 'Test', 'sent', '', '', 'test_actor', '127.0.0.1');
        $this->assertTrue(true);
    }

    public function testGetRecentLogsReturnsArray(): void
    {
        $logs = $this->mailer->getRecentLogs(10);
        $this->assertIsArray($logs);
    }

    public function testRenderEmailTemplateReturnsHtml(): void
    {
        $html = $this->mailer->renderEmailTemplate('Test Title', '<p>Content</p>');
        $this->assertIsString($html);
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('Test Title', $html);
        $this->assertStringContainsString('Content', $html);
    }

    public function testRenderEmailTemplateEscapesTitle(): void
    {
        $html = $this->mailer->renderEmailTemplate('<script>alert(1)</script>', '<p>Body</p>');
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testBuildMailHtmlReturnsHtmlWithTokenUrl(): void
    {
        $submission = [
            'form_label' => 'Test Form',
            'data' => json_encode(['nom' => 'Dupont', 'email' => 'dupont@test.com']),
        ];
        $html = $this->mailer->buildMailHtml($submission, 'Étape 1', 'abc123token');
        $this->assertIsString($html);
        $this->assertStringContainsString('abc123token', $html);
        $this->assertStringContainsString('Étape 1', $html);
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
    }

    public function testBuildMailHtmlSkipsEmptyValues(): void
    {
        $submission = [
            'form_label' => 'Form',
            'data' => json_encode(['nom' => '', 'prenom' => 'Jean']),
        ];
        $html = $this->mailer->buildMailHtml($submission, 'Step', 'tok');
        $this->assertStringNotContainsString('Nom', $html);
        $this->assertStringContainsString('Prenom', $html);
    }

    public function testBuildMailHtmlSkipsValidationsKey(): void
    {
        $submission = [
            'form_label' => 'Form',
            'data' => json_encode(['nom' => 'Dupont', 'validations' => ['something']]),
        ];
        $html = $this->mailer->buildMailHtml($submission, 'Step', 'tok');
        $this->assertStringNotContainsString('validations', $html);
    }

    public function testBuildMailHtmlEncodesSpecialChars(): void
    {
        $submission = [
            'form_label' => 'Form',
            'data' => json_encode(['nom' => '<b>Test&Co</b>']),
        ];
        $html = $this->mailer->buildMailHtml($submission, 'Step', 'tok');
        $this->assertStringContainsString('&lt;b&gt;', $html);
        $this->assertStringNotContainsString('<b>Test&Co</b>', $html);
    }
}
