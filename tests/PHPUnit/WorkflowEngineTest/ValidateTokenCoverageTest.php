<?php
declare(strict_types=1);

namespace App\Tests\WorkflowEngineTest;

/**
 * validateToken valider coverage tests extracted from WorkflowEngineTest.
 */
final class ValidateTokenCoverageTest extends Base
{
    public function testValidateTokenTruncatesComment(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $longComment = str_repeat('x', 1500);
        $result = $this->workflow->validateToken($tokenVal, 'valider', $longComment);
        $this->assertSame('ok', $result['status']);
        $pdo = $this->db->getPdo();
        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertLessThanOrEqual(1000, strlen($validation['commentaire']));
    }

    public function testValidateTokenStoresDoneByField(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $doneBy = 'verifier-' . uniqid() . '@test.com';
        $result = $this->workflow->validateToken($tokenVal, 'valider', 'Test', $doneBy);
        $this->assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $check = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $data = json_decode((string) $check->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame($doneBy, $validation['done_by']);
    }

    public function testValidateTokenStoresStepLabel(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider', 'Test');
        $this->assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertArrayHasKey('step_label', $validation);
        $this->assertArrayHasKey('email', $validation);
        $this->assertArrayHasKey('action', $validation);
        $this->assertArrayHasKey('date', $validation);
    }

    public function testValidateTokenStoresDateTimestamp(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $before = gmdate('Y-m-d H:i:s');
        $result = $this->workflow->validateToken($tokenVal, 'valider', 'Test');
        $after = gmdate('Y-m-d H:i:s');

        $this->assertSame('ok', $result['status']);
        $pdo = $this->db->getPdo();
        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertGreaterThanOrEqual($before, $validation['date']);
        $this->assertLessThanOrEqual($after, $validation['date']);
    }

    public function testValidateTokenRefuseWithCommentZero(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'refuser', '0');
        $this->assertSame('ok', $result['status']);

        $check = $pdo->prepare("SELECT status FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $this->assertSame('refuse', $check->fetchColumn());
    }

    public function testValidateTokenValiderAppendsToExistingValidations(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $existingData = json_encode(['validations' => [['step_label' => 'Old', 'email' => 'old@test.com', 'action' => 'valider']]]);
        $pdo->prepare("UPDATE submissions SET data = ? WHERE id = ?")->execute([$existingData, $subId]);

        $result = $this->workflow->validateToken($tokenVal, 'valider', 'New validation', 'new@test.com');
        $this->assertSame('ok', $result['status']);

        $check = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $data = json_decode((string) $check->fetchColumn(), true);
        $this->assertArrayHasKey('validations', $data);
        $this->assertGreaterThanOrEqual(2, count($data['validations']));
        $last = end($data['validations']);
        $this->assertSame('valider', $last['action']);
        $this->assertSame('new@test.com', $last['done_by']);
    }

    public function testValidateTokenRefuseStoresRefuseData(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'refuser', 'Motif refus', 'refuser@test.com');
        $this->assertSame('ok', $result['status']);

        $check = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $data = json_decode((string) $check->fetchColumn(), true);
        $validations = $data['validations'] ?? [];
        $last = end($validations);
        $this->assertSame('refuser', $last['action']);
        $this->assertSame('Motif refus', $last['commentaire']);
        $this->assertSame('refuser@test.com', $last['done_by']);
    }

    public function testValidateTokenSetsDoneAtOnToken(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertSame('ok', $result['status']);

        $check = $pdo->prepare("SELECT done_at FROM tokens WHERE token = ?");
        $check->execute([$tokenVal]);
        $doneAt = $check->fetchColumn();
        $this->assertNotEmpty($doneAt);
    }

    public function testValidateTokenValiderWithEmptyDoneByString(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider', 'Approved', '');
        $this->assertSame('ok', $result['status']);
        $this->assertArrayHasKey('done_at', $result['data']);
        $this->assertNotEmpty($result['data']['done_at']);
    }

    public function testValidateTokenRefuseNotifiesAgent(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'refuser', 'Motif de refus');
        $this->assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $check = $pdo->prepare("SELECT status FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $this->assertSame('refuse', $check->fetchColumn());
    }

    public function testValidateTokenRefuseSetsSubmissionClosedAt(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $this->workflow->validateToken($tokenVal, 'refuser', 'Refus');

        $check = $pdo->prepare("SELECT closed_at FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $this->assertNotEmpty($check->fetchColumn());
    }

    public function testValidateTokenCommentTruncatedAt1000Chars(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $longComment = str_repeat('x', 1500);
        $result = $this->workflow->validateToken($tokenVal, 'valider', $longComment);
        $this->assertSame('ok', $result['status']);

        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertLessThanOrEqual(1000, strlen($validation['commentaire']));
    }

    public function testValidateTokenCommentExactly1000Chars(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $comment = str_repeat('x', 1000);
        $result = $this->workflow->validateToken($tokenVal, 'valider', $comment);
        $this->assertSame('ok', $result['status']);

        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame(1000, strlen($validation['commentaire']));
    }

    public function testValidateTokenCommentUnder1000Chars(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $comment = str_repeat('y', 500);
        $result = $this->workflow->validateToken($tokenVal, 'valider', $comment);
        $this->assertSame('ok', $result['status']);

        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame(500, strlen($validation['commentaire']));
    }

    public function testValidateTokenStoresEmailInValidation(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertSame('ok', $result['status']);

        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame('validator@test.com', $validation['email']);
    }

    public function testValidateTokenStoresStepLabelInValidation(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertSame('ok', $result['status']);

        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame('Validation', $validation['step_label']);
    }

    public function testValidateTokenStoresActionInValidation(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertSame('ok', $result['status']);

        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame('valider', $validation['action']);
    }

    public function testValidateTokenStoresDateInValidation(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $before = gmdate('Y-m-d H:i:s');
        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $after = gmdate('Y-m-d H:i:s');

        $this->assertSame('ok', $result['status']);

        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertGreaterThanOrEqual($before, $validation['date']);
        $this->assertLessThanOrEqual($after, $validation['date']);
    }

    public function testValidateTokenDataContainsDoneAt(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertArrayHasKey('done_at', $result['data']);
        $this->assertNotEmpty($result['data']['done_at']);
    }

    public function testValidateTokenDataContainsToken(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertSame($tokenVal, $result['data']['token']);
    }

    public function testValidateTokenDataContainsEmail(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertSame('validator@test.com', $result['data']['email']);
    }

    public function testValidateTokenDataContainsStepId(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertSame($stepId, $result['data']['step_id']);
    }

    public function testValidateTokenDataContainsSubmissionId(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertSame($subId, $result['data']['submission_id']);
    }

    public function testValidateTokenDataContainsSentAt(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertArrayHasKey('sent_at', $result['data']);
    }

    public function testValidateTokenDataContainsExpiresAt(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertArrayHasKey('expires_at', $result['data']);
    }
}
