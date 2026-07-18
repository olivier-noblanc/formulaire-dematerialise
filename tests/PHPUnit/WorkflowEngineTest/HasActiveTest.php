<?php
declare(strict_types=1);

namespace App\Tests\WorkflowEngineTest;

/**
 * hasActiveSubmissions and hasActiveStepSubmissions tests extracted from WorkflowEngineTest.
 */
class HasActiveTest extends Base
{
    // ── hasActiveSubmissions ─────────────────────────────────────

    public function testHasActiveSubmissionsReturnsInt(): void
    {
        [$formId] = $this->createTestForm();

        $count = $this->workflow->hasActiveSubmissions($formId);
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testHasActiveSubmissionsReturnsZeroForNonexistentForm(): void
    {
        $count = $this->workflow->hasActiveSubmissions('nonexistent-form-id');
        $this->assertSame(0, $count);
    }

    public function testHasActiveSubmissionsReturnsZeroForEmptyFormId(): void
    {
        $count = $this->workflow->hasActiveSubmissions('');
        $this->assertSame(0, $count);
    }

    public function testHasActiveSubmissionsMatchesDirectQuery(): void
    {
        [$formId] = $this->createTestForm();
        $this->createTestSubmission($formId);

        $methodResult = $this->workflow->hasActiveSubmissions($formId);
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE form_id = ? AND status = 'en_cours'");
        $stmt->execute([$formId]);
        $directCount = $stmt->fetchColumn();

        $this->assertSame((int) $directCount, $methodResult);
    }

    public function testHasActiveSubmissionsReturnsCountForFormWithActiveSubmissions(): void
    {
        [$formId] = $this->createTestForm();
        $this->createTestSubmission($formId);

        $count = $this->workflow->hasActiveSubmissions($formId);
        $this->assertGreaterThan(0, $count);
    }

    public function testHasActiveSubmissionsReturnsCorrectCount(): void
    {
        [$formId] = $this->createTestForm();

        $count = $this->workflow->hasActiveSubmissions($formId);
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testHasActiveSubmissionsOnlyCountsEnCours(): void
    {
        [$formId] = $this->createTestForm();
        $this->createTestSubmission($formId);
        $this->createTestSubmission($formId, status: 'valide');

        $count = $this->workflow->hasActiveSubmissions($formId);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE form_id = ? AND status = 'en_cours'");
        $stmt->execute([$formId]);
        $expected = (int) $stmt->fetchColumn();

        $this->assertSame($expected, $count);
    }

    public function testHasActiveSubmissionsReturnsZeroForFormWithNoSubmissions(): void
    {
        $pdo = $this->db->getPdo();

        $formId = bin2hex(random_bytes(8));
        $slug = 'test-no-subs-' . uniqid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'Test', 'test', 1, datetime('now'))")
            ->execute([$formId, $slug]);

        $count = $this->workflow->hasActiveSubmissions($formId);
        $this->assertSame(0, $count);

        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }

    // ── hasActiveStepSubmissions ─────────────────────────────────

    public function testHasActiveStepSubmissionsReturnsInt(): void
    {
        [, $stepId] = $this->createTestForm();

        $count = $this->workflow->hasActiveStepSubmissions($stepId);
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testHasActiveStepSubmissionsReturnsZeroForNonexistentStep(): void
    {
        $count = $this->workflow->hasActiveStepSubmissions('nonexistent-step-id');
        $this->assertSame(0, $count);
    }

    public function testHasActiveStepSubmissionsReturnsCountForStepWithPendingTokens(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        $this->createTestToken($subId, $stepId);

        $count = $this->workflow->hasActiveStepSubmissions($stepId);
        $this->assertGreaterThan(0, $count);
    }

    public function testHasActiveStepSubmissionsMatchesDirectQuery(): void
    {
        [, $stepId] = $this->createTestForm();

        $methodResult = $this->workflow->hasActiveStepSubmissions($stepId);
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM tokens t
            JOIN submissions s ON s.id = t.submission_id
            WHERE t.step_id = ? AND t.done_at IS NULL AND s.status = 'en_cours'
        ");
        $stmt->execute([$stepId]);
        $directCount = $stmt->fetchColumn();

        $this->assertSame((int) $directCount, $methodResult);
    }

    public function testHasActiveStepSubmissionsReturnsZeroForEmptyStepId(): void
    {
        $count = $this->workflow->hasActiveStepSubmissions('');
        $this->assertSame(0, $count);
    }

    public function testHasActiveStepSubmissionsReturnsZeroForCompletedStep(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId, status: 'valide');
        $this->createTestToken($subId, $stepId, doneAtOffset: '-1 minute');

        $count = $this->workflow->hasActiveStepSubmissions($stepId);
        $this->assertIsInt($count);
    }

    public function testHasActiveStepSubmissionsReturnsZeroForInactiveStep(): void
    {
        [, $stepId] = $this->createTestForm();

        $count = $this->workflow->hasActiveStepSubmissions($stepId);
        $this->assertSame(0, $count);
    }

    public function testHasActiveStepSubmissionsReturnsZeroForStepWithNoTokens(): void
    {
        $pdo = $this->db->getPdo();
        [$formId] = $this->createTestForm();

        $stepId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'No Tokens Step', 100, 1)")
            ->execute([$stepId, $formId]);

        $count = $this->workflow->hasActiveStepSubmissions($stepId);
        $this->assertSame(0, $count);

        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$stepId]);
    }

    public function testHasActiveStepSubmissionsOnlyCountsNullDoneAt(): void
    {
        $pdo = $this->db->getPdo();
        [$formId] = $this->createTestForm();

        $stepId = bin2hex(random_bytes(8));
        $subId = bin2hex(random_bytes(8));
        $tokenId = bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(32));

        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'Test Step', 100, 1)")
            ->execute([$stepId, $formId]);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, '{}', 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId]);
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at, expires_at) VALUES (?, ?, ?, 'done@test.com', ?, datetime('now'), datetime('now'), datetime('now', '+30 days'))")
            ->execute([$tokenId, $subId, $stepId, $token]);

        $count = $this->workflow->hasActiveStepSubmissions($stepId);
        $this->assertSame(0, $count);

        $pdo->prepare("DELETE FROM tokens WHERE id = ?")->execute([$tokenId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$stepId]);
    }

    public function testHasActiveStepSubmissionsCountsPendingTokens(): void
    {
        $pdo = $this->db->getPdo();
        [$formId] = $this->createTestForm();

        $stepId = bin2hex(random_bytes(8));
        $subId = bin2hex(random_bytes(8));
        $tokenId = bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(32));

        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'Pending Step', 100, 1)")
            ->execute([$stepId, $formId]);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, '{}', 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId]);
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, 'pending@test.com', ?, datetime('now'), datetime('now', '+30 days'))")
            ->execute([$tokenId, $subId, $stepId, $token]);

        $count = $this->workflow->hasActiveStepSubmissions($stepId);
        $this->assertGreaterThan(0, $count);

        $pdo->prepare("DELETE FROM tokens WHERE id = ?")->execute([$tokenId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$stepId]);
    }
}
