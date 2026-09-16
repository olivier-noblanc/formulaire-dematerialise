<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Contract\MailInterface;
use App\Core\Database;
use App\Forms\FieldService;
use App\Repository\FormRepository;
use App\Repository\SettingsRepository;
use App\Repository\SubmissionRepository;
use App\Repository\TokenRepository;
use App\Settings\SettingsService;
use App\Workflow\ConditionEvaluator;
use App\Workflow\RecipientResolver;
use App\Workflow\WorkflowAdvancer;

/**
 * Double de test : enregistre si une transaction était active au moment du send().
 */
final class MailTransactionProbe implements MailInterface
{
    public bool $sendCalled = false;
    public ?bool $inTransactionAtSend = null;

    public function __construct(private readonly \PDO $pdo) {}

    public function send(string $to, string $subject, string $body): bool
    {
        $this->sendCalled = true;
        $this->inTransactionAtSend = $this->pdo->inTransaction();
        return true;
    }

    /** @param array<string, mixed> $submission */
    public function buildValidationEmail(array $submission, string $stepLabel, string $token): string
    {
        return 'body';
    }

    public function renderEmailTemplate(string $title, string $bodyHtml): string
    {
        return $bodyHtml;
    }
}

/**
 * B3 — l'envoi SMTP doit avoir lieu APRÈS le commit de la transaction.
 */
final class WorkflowAdvancerMailTransactionTest extends TestCase
{
    private Database $db;
    private string $formId = '';
    private string $stepId = '';
    private string $submissionId = '';

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(Database::class);
        $pdo = $this->db->getPdo();

        $this->formId = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'B3 Form', '', 1, datetime('now'))")
            ->execute([$this->formId, 'b3-' . uniqid()]);
        $this->stepId = generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation', 1, 1, '')")
            ->execute([$this->stepId, $this->formId]);
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, 'b3-validator@test.com')")
            ->execute([generate_uuid(), $this->stepId]);
        $this->submissionId = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, '{}', 'agent@test.com', 'en_cours', datetime('now'))")
            ->execute([$this->submissionId, $this->formId]);
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->getPdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$this->submissionId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$this->submissionId]);
        $pdo->prepare("DELETE FROM step_recipients WHERE step_id = ?")->execute([$this->stepId]);
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$this->stepId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$this->formId]);
    }

    /**
     * Double de test : enregistre si une transaction était active au moment du send().
     */
    private function makeProbe(\PDO $pdo): MailTransactionProbe
    {
        return new MailTransactionProbe($pdo);
    }

    public function testTokenEmailIsSentAfterCommit(): void
    {
        $settings = new SettingsService(new SettingsRepository($this->db));
        $probe = $this->makeProbe($this->db->getPdo());

        $advancer = new WorkflowAdvancer(
            $settings,
            $probe,
            new FieldService(),
            new ConditionEvaluator(),
            new RecipientResolver($settings, new SubmissionRepository($this->db), new FormRepository($this->db)),
            new SubmissionRepository($this->db),
            new TokenRepository($this->db),
            new FormRepository($this->db)
        );

        $advancer->advance($this->submissionId);

        self::assertTrue($probe->sendCalled, 'Le lien doit être envoyé.');
        self::assertFalse(
            $probe->inTransactionAtSend,
            'B3 : send() ne doit pas être appelé pendant une transaction SQLite (verrou d\'écriture pendant l\'I/O).'
        );
    }
}