<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Mail\MailerService;
use App\Core\Database;
use App\Settings\SettingsService;
use App\Repository\SettingsRepository;

final class MailerServiceTest extends TestCase
{
    private MailerService $mailer;
    private Database $db;
    private SettingsService $settings;

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(\App\Core\Database::class);
        $this->settings = new SettingsService(new SettingsRepository($this->db));
        $this->mailer = new MailerService($this->db, $this->settings);
    }

    // ── send ────────────────────────────────────────────────────

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

    public function testSendReturnsFalseForInvalidEmail(): void
    {
        $result = $this->mailer->send('not-an-email', 'Test Subject', '<p>Test body</p>');
        $this->assertFalse($result);
    }

    // ── sendDetailed ────────────────────────────────────────────

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

    public function testSendDetailedCapturesMultipleMails(): void
    {
        $GLOBALS['_test_mails'] = [];
        $this->mailer->sendDetailed('user1@test.com', 'Subject 1', '<p>Body 1</p>');
        $this->mailer->sendDetailed('user2@test.com', 'Subject 2', '<p>Body 2</p>');
        $this->assertCount(2, $GLOBALS['_test_mails']);
        $this->assertSame('user1@test.com', $GLOBALS['_test_mails'][0]['to']);
        $this->assertSame('user2@test.com', $GLOBALS['_test_mails'][1]['to']);
    }

    public function testSendDetailedStoresTimeForEachMail(): void
    {
        $GLOBALS['_test_mails'] = [];
        $this->mailer->sendDetailed('test@test.com', 'Subject', '<p>Body</p>');
        $this->assertArrayHasKey('time', $GLOBALS['_test_mails'][0]);
        $this->assertNotEmpty($GLOBALS['_test_mails'][0]['time']);
    }

    // ── logAttempt ──────────────────────────────────────────────

    public function testLogAttemptDoesNotThrow(): void
    {
        $this->mailer->logAttempt('test@example.com', 'Test', 'sent', '', '', 'test_actor', '127.0.0.1');
        $this->assertTrue(true);
    }

    public function testLogAttemptWithLongSubjectTruncates(): void
    {
        $longSubject = str_repeat('A', 600);
        // Should not throw — subject is truncated internally
        $this->mailer->logAttempt('test@example.com', $longSubject, 'sent', '', '', 'actor', '127.0.0.1');
        $this->assertTrue(true);
    }

    public function testLogAttemptWithLongErrorTruncates(): void
    {
        $longError = str_repeat('E', 3000);
        // Should not throw — error is truncated internally
        $this->mailer->logAttempt('test@example.com', 'Subject', 'error', $longError, '', 'actor', '127.0.0.1');
        $this->assertTrue(true);
    }

    public function testLogAttemptWithLongSmtpLogTruncates(): void
    {
        $longLog = str_repeat('L', 40000);
        // Should not throw — smtp_log is truncated to 32000
        $this->mailer->logAttempt('test@example.com', 'Subject', 'sent', '', $longLog, 'actor', '127.0.0.1');
        $this->assertTrue(true);
    }

    public function testLogAttemptWithEmptyValues(): void
    {
        $this->mailer->logAttempt('', '', '', '', '', '', '');
        $this->assertTrue(true);
    }

    // ── getRecentLogs ───────────────────────────────────────────

    public function testGetRecentLogsReturnsArray(): void
    {
        $logs = $this->mailer->getRecentLogs(10);
        $this->assertIsArray($logs);
    }

    public function testGetRecentLogsRespectsLimit(): void
    {
        $logs = $this->mailer->getRecentLogs(3);
        $this->assertIsArray($logs);
        $this->assertLessThanOrEqual(3, count($logs));
    }

    public function testGetRecentLogsWithZeroLimit(): void
    {
        $logs = $this->mailer->getRecentLogs(0);
        $this->assertIsArray($logs);
        $this->assertEmpty($logs);
    }

    // ── renderEmailTemplate ─────────────────────────────────────

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

    public function testRenderEmailTemplateContainsLanguageAttribute(): void
    {
        $html = $this->mailer->renderEmailTemplate('Title', 'Body');
        $this->assertStringContainsString('lang="fr"', $html);
    }

    public function testRenderEmailTemplateContainsMetaCharset(): void
    {
        $html = $this->mailer->renderEmailTemplate('Title', 'Body');
        $this->assertStringContainsString('charset="UTF-8"', $html);
    }

    public function testRenderEmailTemplateBodyIsUnescaped(): void
    {
        $body = '<p style="color:red;">Hello</p>';
        $html = $this->mailer->renderEmailTemplate('Title', $body);
        $this->assertStringContainsString($body, $html);
    }

    // ── buildMailHtml ───────────────────────────────────────────

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

    public function testBuildMailHtmlRendersArrayValues(): void
    {
        $submission = [
            'form_label' => 'Form',
            'data' => json_encode(['items' => ['a', 'b', 'c']]),
        ];
        $html = $this->mailer->buildMailHtml($submission, 'Step', 'tok');
        $this->assertIsString($html);
        // Array values get json_encoded
        $this->assertStringContainsString('Items', $html);
    }

    public function testBuildMailHtmlRendersCheckmarkForValueOne(): void
    {
        $submission = [
            'form_label' => 'Form',
            'data' => json_encode(['validated' => '1']),
        ];
        $html = $this->mailer->buildMailHtml($submission, 'Step', 'tok');
        $this->assertStringContainsString('✓', $html);
    }

    public function testBuildMailHtmlWithEmptyFormLabel(): void
    {
        $submission = [
            'form_label' => '',
            'data' => json_encode(['nom' => 'Test']),
        ];
        $html = $this->mailer->buildMailHtml($submission, 'Step', 'tok');
        $this->assertIsString($html);
        $this->assertStringContainsString('Step', $html);
    }

    public function testBuildMailHtmlStripsFieldPrefixInLabel(): void
    {
        // Field names like 'agent_nom' should render as 'Nom' (strip prefix before first underscore)
        $submission = [
            'form_label' => 'Form',
            'data' => json_encode(['agent_nom' => 'Dupont']),
        ];
        $html = $this->mailer->buildMailHtml($submission, 'Step', 'tok');
        $this->assertStringContainsString('Nom', $html);
    }

    public function testBuildMailHtmlContainsValidationUrl(): void
    {
        $submission = [
            'form_label' => 'Form',
            'data' => json_encode(['nom' => 'Test']),
        ];
        $html = $this->mailer->buildMailHtml($submission, 'Step', 'mytoken123');
        $this->assertStringContainsString('validate&token=mytoken123', $html);
    }

    public function testBuildMailHtmlWithNullFormLabel(): void
    {
        $submission = [
            'data' => json_encode(['nom' => 'Test']),
        ];
        $html = $this->mailer->buildMailHtml($submission, 'Step', 'tok');
        $this->assertIsString($html);
    }

    // ── sendDetailed() additional paths ─────────────────────────

    public function testSendDetailedSuccessAndDryRunInTestMode(): void
    {
        $result = $this->mailer->sendDetailed('test@example.com', 'Subject', '<p>Body</p>');
        $this->assertTrue($result['success']);
        $this->assertSame('dry_run', $result['status']);
    }

    public function testSendDetailedWithValidEmailInTestMode(): void
    {
        $GLOBALS['_test_mails'] = [];
        $result = $this->mailer->sendDetailed('valid@test.com', 'Test', '<p>Body</p>');
        $this->assertTrue($result['success']);
        $this->assertNotEmpty($GLOBALS['_test_mails']);
    }

    public function testSendDetailedStoresSubjectInMail(): void
    {
        $GLOBALS['_test_mails'] = [];
        $this->mailer->sendDetailed('test@test.com', 'My Subject', '<p>Body</p>');
        $this->assertSame('My Subject', $GLOBALS['_test_mails'][0]['subject']);
    }

    public function testSendDetailedStoresBodyInMail(): void
    {
        $GLOBALS['_test_mails'] = [];
        $this->mailer->sendDetailed('test@test.com', 'Subject', '<p>My Body</p>');
        $this->assertSame('<p>My Body</p>', $GLOBALS['_test_mails'][0]['body']);
    }

    public function testSendDetailedTrimsAndLowercasesEmail(): void
    {
        $GLOBALS['_test_mails'] = [];
        $this->mailer->sendDetailed('  TEST@EXAMPLE.COM  ', 'Subject', '<p>Body</p>');
        $this->assertSame('test@example.com', $GLOBALS['_test_mails'][0]['to']);
    }

    // ── logAttempt() additional cases ───────────────────────────

    public function testLogAttemptWithAllStatuses(): void
    {
        $statuses = ['sent', 'error', 'blocked', 'dry_run', 'cli_blocked', 'rate_limited'];
        foreach ($statuses as $status) {
            $this->mailer->logAttempt('test@test.com', 'Subj', $status, 'err', 'log', 'actor', '127.0.0.1');
        }
        $this->assertTrue(true);
    }

    public function testLogAttemptWithUtf8Subject(): void
    {
        $this->mailer->logAttempt('test@test.com', 'Sujet avec accents éèà', 'sent', '', '', 'actor', '127.0.0.1');
        $this->assertTrue(true);
    }

    // ── getRecentLogs() additional cases ────────────────────────

    public function testGetRecentLogsDefaultLimit(): void
    {
        $logs = $this->mailer->getRecentLogs();
        $this->assertIsArray($logs);
    }

    public function testGetRecentLogsLargeLimit(): void
    {
        $logs = $this->mailer->getRecentLogs(1000);
        $this->assertIsArray($logs);
    }

    // ── buildMailHtml() edge cases ──────────────────────────────

    public function testBuildMailHtmlWithBooleanValues(): void
    {
        $submission = [
            'form_label' => 'Form',
            'data' => json_encode(['active' => '1', 'deleted' => '0']),
        ];
        $html = $this->mailer->buildMailHtml($submission, 'Step', 'tok');
        $this->assertStringContainsString('Active', $html);
    }

    public function testBuildMailHtmlWithSpecialCharsInValues(): void
    {
        $submission = [
            'form_label' => 'Form',
            'data' => json_encode(['comment' => 'Line1\nLine2']),
        ];
        $html = $this->mailer->buildMailHtml($submission, 'Step', 'tok');
        $this->assertIsString($html);
    }

    // ── renderEmailTemplate() additional cases ──────────────────

    public function testRenderEmailTemplateWithEmptyTitle(): void
    {
        $html = $this->mailer->renderEmailTemplate('', '<p>Body</p>');
        $this->assertIsString($html);
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
    }

    public function testRenderEmailTemplateWithEmptyBody(): void
    {
        $html = $this->mailer->renderEmailTemplate('Title', '');
        $this->assertIsString($html);
        $this->assertStringContainsString('Title', $html);
    }

    public function testRenderEmailTemplateWithSpecialCharsInBody(): void
    {
        $html = $this->mailer->renderEmailTemplate('Title', '<p style="color:red;">Test & "quotes"</p>');
        $this->assertStringContainsString('color:red', $html);
    }
}
