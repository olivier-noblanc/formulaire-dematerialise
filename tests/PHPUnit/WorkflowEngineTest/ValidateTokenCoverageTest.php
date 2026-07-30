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
        self::assertSame('ok', $result['status']);
        $pdo = $this->db->getPdo();
        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        self::assertLessThanOrEqual(1000, strlen($validation['commentaire']));
    }

    public function testValidateTokenStoresDoneByField(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $doneBy = 'verifier-' . uniqid() . '@test.com';
        $result = $this->workflow->validateToken($tokenVal, 'valider', 'Test', $doneBy);
        self::assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $check = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $data = json_decode((string) $check->fetchColumn(), true);
        $validation = end($data['validations']);
        self::assertSame($doneBy, $validation['done_by']);
    }

    public function testValidateTokenStoresStepLabel(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider', 'Test');
        self::assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        self::assertArrayHasKey('step_label', $validation);
        self::assertArrayHasKey('email', $validation);
        self::assertArrayHasKey('action', $validation);
        self::assertArrayHasKey('date', $validation);
    }

    public function testValidateTokenStoresDateTimestamp(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $before = gmdate('Y-m-d H:i:s');
        $result = $this->workflow->validateToken($tokenVal, 'valider', 'Test');
        $after = gmdate('Y-m-d H:i:s');

        self::assertSame('ok', $result['status']);
        $pdo = $this->db->getPdo();
        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        self::assertGreaterThanOrEqual($before, $validation['date']);
        self::assertLessThanOrEqual($after, $validation['date']);
    }

    public function testValidateTokenRefuseWithCommentZero(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'refuser', '0');
        self::assertSame('ok', $result['status']);

        $check = $pdo->prepare("SELECT status FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        self::assertSame('refuse', $check->fetchColumn());
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
        self::assertSame('ok', $result['status']);

        $check = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $data = json_decode((string) $check->fetchColumn(), true);
        self::assertArrayHasKey('validations', $data);
        self::assertGreaterThanOrEqual(2, count($data['validations']));
        $last = end($data['validations']);
        self::assertSame('valider', $last['action']);
        self::assertSame('new@test.com', $last['done_by']);
    }

    public function testValidateTokenRefuseStoresRefuseData(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'refuser', 'Motif refus', 'refuser@test.com');
        self::assertSame('ok', $result['status']);

        $check = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $data = json_decode((string) $check->fetchColumn(), true);
        $validations = $data['validations'] ?? [];
        $last = end($validations);
        self::assertSame('refuser', $last['action']);
        self::assertSame('Motif refus', $last['commentaire']);
        self::assertSame('refuser@test.com', $last['done_by']);
    }

    public function testValidateTokenSetsDoneAtOnToken(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        self::assertSame('ok', $result['status']);

        $check = $pdo->prepare("SELECT done_at FROM tokens WHERE token = ?");
        $check->execute([$tokenVal]);
        $doneAt = $check->fetchColumn();
        self::assertNotEmpty($doneAt);
    }

    public function testValidateTokenValiderWithEmptyDoneByString(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider', 'Approved', '');
        self::assertSame('ok', $result['status']);
        self::assertArrayHasKey('done_at', $result['data']);
        self::assertNotEmpty($result['data']['done_at']);
    }

    public function testValidateTokenRefuseNotifiesAgent(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'refuser', 'Motif de refus');
        self::assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $check = $pdo->prepare("SELECT status FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        self::assertSame('refuse', $check->fetchColumn());
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
        self::assertNotEmpty($check->fetchColumn());
    }

    public function testValidateTokenCommentTruncatedAt1000Chars(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $longComment = str_repeat('x', 1500);
        $result = $this->workflow->validateToken($tokenVal, 'valider', $longComment);
        self::assertSame('ok', $result['status']);

        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        self::assertLessThanOrEqual(1000, strlen($validation['commentaire']));
    }

    public function testValidateTokenCommentExactly1000Chars(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $comment = str_repeat('x', 1000);
        $result = $this->workflow->validateToken($tokenVal, 'valider', $comment);
        self::assertSame('ok', $result['status']);

        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        self::assertSame(1000, strlen($validation['commentaire']));
    }

    public function testValidateTokenCommentUnder1000Chars(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $comment = str_repeat('y', 500);
        $result = $this->workflow->validateToken($tokenVal, 'valider', $comment);
        self::assertSame('ok', $result['status']);

        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        self::assertSame(500, strlen($validation['commentaire']));
    }

    public function testValidateTokenStoresEmailInValidation(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        self::assertSame('ok', $result['status']);

        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        self::assertSame('validator@test.com', $validation['email']);
    }

    public function testValidateTokenStoresStepLabelInValidation(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        self::assertSame('ok', $result['status']);

        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        self::assertSame('Validation', $validation['step_label']);
    }

    public function testValidateTokenStoresActionInValidation(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        self::assertSame('ok', $result['status']);

        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        self::assertSame('valider', $validation['action']);
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

        self::assertSame('ok', $result['status']);

        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        self::assertGreaterThanOrEqual($before, $validation['date']);
        self::assertLessThanOrEqual($after, $validation['date']);
    }

    public function testValidateTokenDataContainsDoneAt(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        self::assertArrayHasKey('done_at', $result['data']);
        self::assertNotEmpty($result['data']['done_at']);
    }

    public function testValidateTokenDataContainsToken(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        self::assertSame($tokenVal, $result['data']['token']);
    }

    public function testValidateTokenDataContainsEmail(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        self::assertSame('validator@test.com', $result['data']['email']);
    }

    public function testValidateTokenDataContainsStepId(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        self::assertSame($stepId, $result['data']['step_id']);
    }

    public function testValidateTokenDataContainsSubmissionId(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        self::assertSame($subId, $result['data']['submission_id']);
    }

    public function testValidateTokenDataContainsSentAt(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        self::assertArrayHasKey('sent_at', $result['data']);
    }

    public function testValidateTokenDataContainsExpiresAt(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        self::assertArrayHasKey('expires_at', $result['data']);
    }
}
