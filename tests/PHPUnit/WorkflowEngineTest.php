<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Workflow\WorkflowEngine;
use App\Workflow\ConditionEvaluator;
use App\Core\Database;
use App\Settings\SettingsService;
use App\Mail\MailService;
use App\Forms\FieldService;

final class WorkflowEngineTest extends TestCase
{
    private WorkflowEngine $workflow;
    private Database $db;

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(\App\Core\Database::class);
        $settings = new SettingsService($this->db);
        $mail = new MailService($this->db, $settings);
        $fields = new FieldService($this->db);
        $conditions = new ConditionEvaluator();
        $this->workflow = new WorkflowEngine($this->db, $settings, $mail, $fields, $conditions);
    }

    public function testGetTokenWithContextReturnsNullForInvalidToken(): void
    {
        $result = $this->workflow->getTokenWithContext('nonexistent_token');
        $this->assertNull($result);
    }

    public function testGetTokenByIdWithContextReturnsNullForInvalidId(): void
    {
        $result = $this->workflow->getTokenByIdWithContext('nonexistent_id');
        $this->assertNull($result);
    }

    public function testGetWorkflowStepsReturnsArray(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $pdo->query("SELECT id FROM forms LIMIT 1")->fetchColumn();

        if ($formId) {
            $steps = $this->workflow->getWorkflowSteps($formId);
            $this->assertIsArray($steps);
        }
    }

    public function testGetSubmissionWithFormLabelReturnsNullForInvalidId(): void
    {
        $result = $this->workflow->getSubmissionWithFormLabel('nonexistent_id');
        $this->assertNull($result);
    }

    public function testResolveDynamicRecipientReturnsEmailForNonTemplate(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('user@example.com', []);
        $this->assertSame('user@example.com', $result);
    }

    public function testResolveDynamicRecipientResolvesTemplate(): void
    {
        $formData = ['manager_email' => 'manager@example.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{manager_email}}', $formData);
        $this->assertSame('manager@example.com', $result);
    }

    public function testResolveDynamicRecipientReturnsTemplateForMissingField(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{missing_field}}', []);
        $this->assertSame('{{missing_field}}', $result);
    }

    public function testResolveDynamicRecipientReturnsTemplateForInvalidEmail(): void
    {
        $formData = ['email' => 'not_an_email'];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        $this->assertSame('{{email}}', $result);
    }

    public function testValidateTokenReturnsInvalidForBadFormat(): void
    {
        $result = $this->workflow->validateToken('bad_format');
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenReturnsInvalidForNonexistentToken(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64));
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenReturnsInvalidForBadAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'bad_action');
        $this->assertSame('invalid', $result['status']);
    }

    public function testHasActiveSubmissionsReturnsInt(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $pdo->query("SELECT id FROM forms LIMIT 1")->fetchColumn();

        if ($formId) {
            $count = $this->workflow->hasActiveSubmissions($formId);
            $this->assertIsInt($count);
            $this->assertGreaterThanOrEqual(0, $count);
        }
    }

    public function testHasActiveStepSubmissionsReturnsInt(): void
    {
        $pdo = $this->db->getPdo();
        $stepId = $pdo->query("SELECT id FROM steps LIMIT 1")->fetchColumn();

        if ($stepId) {
            $count = $this->workflow->hasActiveStepSubmissions($stepId);
            $this->assertIsInt($count);
            $this->assertGreaterThanOrEqual(0, $count);
        }
    }
}
