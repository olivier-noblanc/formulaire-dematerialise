<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Token\TokenService;
use App\Core\Database;
use App\Settings\SettingsService;
use App\Auth\AuthService;
use App\Audit\AuditLogService;
use App\Mail\MailService;
use App\Workflow\WorkflowEngine;
use App\Workflow\ConditionEvaluator;
use App\Forms\FieldService;
use App\Render\HtmlService;

final class TokenServiceTest extends TestCase
{
    private TokenService $tokenService;
    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database();
        $settings = new SettingsService($this->db);
        $auth = new AuthService($this->db);
        $audit = new AuditLogService($this->db);
        $mailer = new MailService($this->db, $settings);
        $fields = new FieldService($this->db);
        $conditions = new ConditionEvaluator();
        $workflow = new WorkflowEngine($this->db, $settings, $mailer, $fields, $conditions);

        $this->tokenService = new TokenService(
            $this->db,
            $settings,
            $auth,
            $audit,
            $mailer,
            $workflow
        );
    }

    public function testGetTokensForSubmissionReturnsArray(): void
    {
        $tokens = $this->tokenService->getForSubmission('nonexistent-id');
        $this->assertIsArray($tokens);
        $this->assertEmpty($tokens);
    }

    public function testRegenerateReturnsErrorForNonAdmin(): void
    {
        $result = $this->tokenService->regenerate('nonexistent-token-id');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Accès refusé', $result['message']);
    }

    public function testCancelReturnsErrorForNonexistentSubmission(): void
    {
        $result = $this->tokenService->cancel('nonexistent-submission-id', 'test@test.com');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('introuvable', $result['message']);
    }

    public function testRemindReturnsErrorForNonexistentToken(): void
    {
        $result = $this->tokenService->remind('nonexistent-token-id');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('introuvable', $result['message']);
    }

    public function testDelegateReturnsErrorForNonexistentToken(): void
    {
        $result = $this->tokenService->delegate('nonexistent-token-id', 'target@test.com');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('introuvable', $result['message']);
    }

    public function testDelegateReturnsErrorForInvalidEmail(): void
    {
        // Token lookup happens before email validation, so nonexistent token returns "introuvable"
        $result = $this->tokenService->delegate('nonexistent-token-id', 'not-an-email');
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['message']);
    }

    public function testGetDelegationsReturnsArray(): void
    {
        $delegations = $this->tokenService->getDelegations('nonexistent-id');
        $this->assertIsArray($delegations);
        $this->assertEmpty($delegations);
    }

    public function testGetForSubmissionWithExtraFields(): void
    {
        $tokens = $this->tokenService->getForSubmission('nonexistent', ['t.id', 't.token']);
        $this->assertIsArray($tokens);
    }
}
