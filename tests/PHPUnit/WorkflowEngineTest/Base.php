<?php
declare(strict_types=1);

namespace App\Tests\WorkflowEngineTest;

use App\Core\Database;
use App\Forms\FieldService;
use App\Mail\MailService;
use App\Repository\SettingsRepository;
use App\Settings\SettingsService;
use App\Workflow\ConditionEvaluator;
use App\Workflow\WorkflowEngine;
use PHPUnit\Framework\TestCase;

/**
 * Base class for WorkflowEngineTest split files.
 * Contains setUp, tearDown, and test data helpers.
 */
abstract class Base extends TestCase
{
    protected WorkflowEngine $workflow;
    protected Database $db;
    /** @var array{forms: string[], steps: string[], step_recipients: string[], submissions: string[], tokens: string[], form_owners: string[]} */
    protected array $createdIds = ['forms' => [], 'steps' => [], 'step_recipients' => [], 'submissions' => [], 'tokens' => [], 'form_owners' => []];

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(\App\Core\Database::class);
        $settings = new SettingsService(new SettingsRepository($this->db));
        $mail = new MailService(new \App\Repository\MailRepository($this->db), $settings);
        $fields = new FieldService();
        $conditions = new ConditionEvaluator();
        $this->workflow = new WorkflowEngine($settings, $mail, $fields, $conditions, new \App\Repository\SubmissionRepository($this->db));
        $this->createdIds = ['forms' => [], 'steps' => [], 'step_recipients' => [], 'submissions' => [], 'tokens' => [], 'form_owners' => []];
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->getPdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($this->createdIds['tokens'] as $id) {
            try { $pdo->prepare("DELETE FROM tokens WHERE id = ?")->execute([$id]); } catch (\Throwable) {}
        }
        foreach ($this->createdIds['step_recipients'] as $id) {
            try { $pdo->prepare("DELETE FROM step_recipients WHERE id = ?")->execute([$id]); } catch (\Throwable) {}
        }
        foreach ($this->createdIds['submissions'] as $id) {
            try { $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$id]); } catch (\Throwable) {}
        }
        foreach ($this->createdIds['form_owners'] as $id) {
            try { $pdo->prepare("DELETE FROM form_owners WHERE id = ?")->execute([$id]); } catch (\Throwable) {}
        }
        foreach ($this->createdIds['steps'] as $id) {
            try { $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$id]); } catch (\Throwable) {}
        }
        foreach ($this->createdIds['forms'] as $id) {
            try { $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$id]); } catch (\Throwable) {}
        }
    }

    /** Create form + step + recipient. Returns [formId, stepId]. */
    protected function createTestForm(string $slug = 'test'): array
    {
        $pdo = $this->db->getPdo();
        $formId = \generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, '', 1, datetime('now'))")
            ->execute([$formId, $slug . '-' . uniqid(), 'Test Form']);
        $this->createdIds['forms'][] = $formId;

        $stepId = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation', 1, 1, '')")
            ->execute([$stepId, $formId]);
        $this->createdIds['steps'][] = $stepId;

        $srId = \generate_uuid();
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, 'validator@test.com')")
            ->execute([$srId, $stepId]);
        $this->createdIds['step_recipients'][] = $srId;

        return [$formId, $stepId];
    }

    /** Create submission. Returns submissionId. */
    protected function createTestSubmission(string $formId, string $data = '{}', string $status = 'en_cours', ?string $closedAtOffset = null): string
    {
        $pdo = $this->db->getPdo();
        $subId = \generate_uuid();
        $closedAt = $closedAtOffset !== null ? gmdate('Y-m-d H:i:s', strtotime($closedAtOffset) ?: time()) : null;
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at, closed_at) VALUES (?, ?, ?, 'agent@test.com', ?, datetime('now'), ?)")
            ->execute([$subId, $formId, $data, $status, $closedAt]);
        $this->createdIds['submissions'][] = $subId;
        return $subId;
    }

    /** Mark an existing submission as closed. */
    protected function closeSubmission(string $submissionId, string $status = 'valide'): void
    {
        $pdo = $this->db->getPdo();
        $pdo->prepare("UPDATE submissions SET closed_at = datetime('now'), status = ? WHERE id = ?")
            ->execute([$status, $submissionId]);
    }

    /** Create a form owner. Returns id. */
    protected function createFormOwner(string $formId, string $email): string
    {
        $pdo = $this->db->getPdo();
        $id = \generate_uuid();
        $pdo->prepare("INSERT INTO form_owners (id, form_id, email, added_at) VALUES (?, ?, ?, datetime('now'))")
            ->execute([$id, $formId, $email]);
        $this->createdIds['form_owners'][] = $id;
        return $id;
    }

    /**
     * Create token. Returns [tokenId, tokenValue].
     * $doneAtOffset: null = pending; strtotime offset = done.
     * $expiresInOffset: strtotime offset from now.
     */
    protected function createTestToken(string $submissionId, string $stepId, string $email = 'validator@test.com', ?string $doneAtOffset = null, string $expiresInOffset = '+7 days'): array
    {
        $pdo = $this->db->getPdo();
        $tokenId = \generate_uuid();
        $tokenVal = bin2hex(random_bytes(32));
        $doneAt = $doneAtOffset !== null ? gmdate('Y-m-d H:i:s', strtotime($doneAtOffset) ?: time()) : null;
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime($expiresInOffset) ?: time());
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), ?, ?)")
            ->execute([$tokenId, $submissionId, $stepId, $email, $tokenVal, $doneAt, $expiresAt]);
        $this->createdIds['tokens'][] = $tokenId;
        return [$tokenId, $tokenVal];
    }
}
