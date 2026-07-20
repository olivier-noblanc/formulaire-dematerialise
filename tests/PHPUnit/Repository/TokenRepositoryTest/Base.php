<?php
declare(strict_types=1);

namespace App\Tests\Repository\TokenRepositoryTest;

use App\Core\Database;
use App\Repository\TokenRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for TokenRepositoryTest split files.
 * Contains setUp, tearDown, and test data helpers.
 */
abstract class Base extends TestCase
{
    protected TokenRepository $repo;
    protected PDO $pdo;
    /** @var array{forms: string[], steps: string[], submissions: string[], tokens: string[]} */
    protected array $createdIds = ['forms' => [], 'steps' => [], 'submissions' => [], 'tokens' => []];

    protected function setUp(): void
    {
        $db = \App\Core\App::getInstance()->get(Database::class);
        $this->repo = new TokenRepository($db);
        $this->pdo = $db->getPdo();
        $this->createdIds = ['forms' => [], 'steps' => [], 'submissions' => [], 'tokens' => []];
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds['tokens'] as $id) {
            try { $this->pdo->prepare("DELETE FROM tokens WHERE id = ?")->execute([$id]); } catch (\Throwable) {}
        }
        foreach ($this->createdIds['submissions'] as $id) {
            try { $this->pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$id]); } catch (\Throwable) {}
        }
        foreach ($this->createdIds['steps'] as $id) {
            try { $this->pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$id]); } catch (\Throwable) {}
        }
        foreach ($this->createdIds['forms'] as $id) {
            try { $this->pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$id]); } catch (\Throwable) {}
        }
    }

    /** Create form + step. Returns [formId, stepId]. */
    protected function createFormAndStep(string $slug = 'test', int $ordre = 1, string $stepLabel = 'Validation', string $formLabel = 'Test Form'): array
    {
        $formId = \generate_uuid();
        $this->pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, '', 1, datetime('now'))")
            ->execute([$formId, $slug . '-' . uniqid(), $formLabel]);
        $this->createdIds['forms'][] = $formId;

        $stepId = \generate_uuid();
        $this->pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, ?, ?, 1, '')")
            ->execute([$stepId, $formId, $stepLabel, $ordre]);
        $this->createdIds['steps'][] = $stepId;

        return [$formId, $stepId];
    }

    /** Create submission. Returns submissionId. */
    protected function createSubmission(string $formId, string $data = '{}', string $status = 'en_cours', ?string $closedAtOffset = null): string
    {
        $subId = \generate_uuid();
        $closedAt = $closedAtOffset !== null ? gmdate('Y-m-d H:i:s', strtotime($closedAtOffset) ?: time()) : null;
        $this->pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at, closed_at) VALUES (?, ?, ?, 'agent@test.com', ?, datetime('now'), ?)")
            ->execute([$subId, $formId, $data, $status, $closedAt]);
        $this->createdIds['submissions'][] = $subId;
        return $subId;
    }

    /**
     * Create token. Returns tokenId.
     * $doneAtOffset / $sentAtOffset / $expiresInOffset: strtotime offsets, null = colonne NULL (sauf expires_at).
     */
    protected function createToken(
        string $submissionId,
        string $stepId,
        string $email = 'validator@test.com',
        ?string $doneAtOffset = null,
        string $expiresInOffset = '+7 days',
        string $sentAtOffset = 'now',
        ?string $invalidatedAtOffset = null
    ): string {
        $tokenId = \generate_uuid();
        $tokenVal = bin2hex(random_bytes(32));
        $sentAt = gmdate('Y-m-d H:i:s', strtotime($sentAtOffset) ?: time());
        $doneAt = $doneAtOffset !== null ? gmdate('Y-m-d H:i:s', strtotime($doneAtOffset) ?: time()) : null;
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime($expiresInOffset) ?: time());
        $invalidatedAt = $invalidatedAtOffset !== null ? gmdate('Y-m-d H:i:s', strtotime($invalidatedAtOffset) ?: time()) : null;
        $this->pdo->prepare(
            "INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at, expires_at, invalidated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$tokenId, $submissionId, $stepId, $email, $tokenVal, $sentAt, $doneAt, $expiresAt, $invalidatedAt]);
        $this->createdIds['tokens'][] = $tokenId;
        return $tokenId;
    }
}
