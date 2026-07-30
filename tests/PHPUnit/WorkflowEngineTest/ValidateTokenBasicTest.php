<?php
declare(strict_types=1);

namespace App\Tests\WorkflowEngineTest;

final class ValidateTokenBasicTest extends Base
{
    public function testValidateTokenReturnsInvalidForBadFormat(): void
    {
        $result = $this->workflow->validateToken('bad_format');
        self::assertSame('invalid', $result['status']);
    }

    public function testValidateTokenReturnsInvalidForNonexistentToken(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64));
        self::assertSame('invalid', $result['status']);
    }

    public function testValidateTokenReturnsInvalidForBadAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'bad_action');
        self::assertSame('invalid', $result['status']);
        self::assertArrayHasKey('message', $result);
    }

    public function testValidateTokenReturnsAlreadyDoneForUsedToken(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $doneToken] = $this->createTestToken($subId, $stepId, doneAtOffset: '-1 minute');

        $result = $this->workflow->validateToken($doneToken);
        self::assertSame('already_done', $result['status']);
    }

    public function testValidateTokenSuccessForPendingToken(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider', 'Test validation', 'validator@test.com');
        self::assertSame('ok', $result['status']);
        self::assertArrayHasKey('data', $result);
    }

    public function testValidateTokenRefuse(): void
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

    public function testValidateTokenReturnsExpiredForExpiredToken(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId, expiresInOffset: '-1 day');

        $result = $this->workflow->validateToken($tokenVal);
        self::assertSame('expired', $result['status']);
    }

    public function testValidateTokenReturnsClosedForClosedSubmissionToken(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $this->closeSubmission($subId);

        $result = $this->workflow->validateToken($tokenVal);
        self::assertSame('closed', $result['status']);
    }

    public function testValidateTokenReturnsDataKeyInResult(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $doneToken] = $this->createTestToken($subId, $stepId, doneAtOffset: '-1 minute');

        $result = $this->workflow->validateToken($doneToken);
        self::assertArrayHasKey('data', $result);
        self::assertIsArray($result['data']);
    }

    public function testValidateTokenWithDefaultAction(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal);
        self::assertSame('ok', $result['status']);
    }

    public function testValidateTokenRefuseWithComment(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'refuser', 'Motif de refus détaillé');
        self::assertSame('ok', $result['status']);
    }

    public function testValidateTokenRefuseWithoutComment(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'refuser', '');
        self::assertSame('ok', $result['status']);
    }

    public function testValidateTokenWithEmptyComment(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider', '');
        self::assertSame('ok', $result['status']);
    }

    public function testValidateTokenReturnsInvalidForShortHex(): void
    {
        $result = $this->workflow->validateToken('abc123');
        self::assertSame('invalid', $result['status']);
    }

    public function testValidateTokenReturnsInvalidForUppercaseHex(): void
    {
        $result = $this->workflow->validateToken(strtoupper(str_repeat('a', 64)));
        self::assertSame('invalid', $result['status']);
    }

    public function testValidateTokenReturnsInvalidForHexWithSpecialChars(): void
    {
        $result = $this->workflow->validateToken(str_repeat('g', 64));
        self::assertSame('invalid', $result['status']);
    }

    public function testValidateTokenReturnsInvalidForMixedCaseHex(): void
    {
        $token = str_repeat('A', 32) . str_repeat('a', 32);
        $result = $this->workflow->validateToken($token);
        self::assertSame('invalid', $result['status']);
    }

    public function testValidateTokenAcceptsValiderAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'valider');
        self::assertSame('invalid', $result['status']);
    }

    public function testValidateTokenAcceptsRefuserAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'refuser');
        self::assertSame('invalid', $result['status']);
    }

    public function testValidateTokenRejectsInvalidAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'annuler');
        self::assertSame('invalid', $result['status']);
        self::assertArrayHasKey('message', $result);
    }

    public function testValidateTokenValiderWithEmptyDoneBy(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider', 'OK', '');
        self::assertSame('ok', $result['status']);
    }

    public function testValidateTokenRefuseReturnsOk(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'refuser');
        self::assertSame('ok', $result['status']);
    }

    public function testValidateTokenValiderWithDoneBy(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $doneBy = 'validator_' . uniqid() . '@test.com';
        $result = $this->workflow->validateToken($tokenVal, 'valider', 'Approuvé', $doneBy);
        self::assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $check = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $data = json_decode((string) $check->fetchColumn(), true);
        $validation = end($data['validations']);
        self::assertSame($doneBy, $validation['done_by']);
    }

    public function testValidateTokenRejectsNonHexToken(): void
    {
        $result = $this->workflow->validateToken('xyz123def456');
        self::assertSame('invalid', $result['status']);
    }

    public function testValidateTokenRejectsUppercaseHex(): void
    {
        $token = strtoupper(str_repeat('a', 64));
        $result = $this->workflow->validateToken($token);
        self::assertSame('invalid', $result['status']);
    }

    public function testValidateTokenRejectsSpecialCharacters(): void
    {
        $result = $this->workflow->validateToken(str_repeat('g', 64));
        self::assertSame('invalid', $result['status']);
    }

    public function testValidateTokenRejectsShortToken(): void
    {
        $result = $this->workflow->validateToken('abc123');
        self::assertSame('invalid', $result['status']);
    }

    public function testValidateTokenRejectsLongToken(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 128));
        self::assertSame('invalid', $result['status']);
    }

    public function testValidateTokenRejectsEmptyToken(): void
    {
        $result = $this->workflow->validateToken('');
        self::assertSame('invalid', $result['status']);
    }

    public function testValidateTokenRejectsNullLikeToken(): void
    {
        $result = $this->workflow->validateToken('0000000000000000000000000000000000000000000000000000000000000000');
        self::assertSame('invalid', $result['status']);
    }

    public function testValidateTokenRejectsMixedCaseHex(): void
    {
        $token = str_repeat('A', 32) . str_repeat('a', 32);
        $result = $this->workflow->validateToken($token);
        self::assertSame('invalid', $result['status']);
    }

    public function testValidateTokenRejectsDeleteAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'delete');
        self::assertSame('invalid', $result['status']);
        self::assertArrayHasKey('message', $result);
    }

    public function testValidateTokenRejectsCancelAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'cancel');
        self::assertSame('invalid', $result['status']);
        self::assertArrayHasKey('message', $result);
    }

    public function testValidateTokenRejectsApproveAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'approve');
        self::assertSame('invalid', $result['status']);
        self::assertArrayHasKey('message', $result);
    }

    public function testValidateTokenRejectsRejectAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'reject');
        self::assertSame('invalid', $result['status']);
        self::assertArrayHasKey('message', $result);
    }

    public function testValidateTokenReturnsOkStatus(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        self::assertSame('ok', $result['status']);
    }

    public function testValidateTokenReturnsDataKeyOnSuccess(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        self::assertArrayHasKey('data', $result);
        self::assertIsArray($result['data']);
    }
}
