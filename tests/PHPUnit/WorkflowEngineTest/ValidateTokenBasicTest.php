<?php
declare(strict_types=1);

namespace App\Tests\WorkflowEngineTest;

final class ValidateTokenBasicTest extends Base
{
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
        $this->assertArrayHasKey('message', $result);
    }

    public function testValidateTokenReturnsAlreadyDoneForUsedToken(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $doneToken] = $this->createTestToken($subId, $stepId, doneAtOffset: '-1 minute');

        $result = $this->workflow->validateToken($doneToken);
        $this->assertSame('already_done', $result['status']);
    }

    public function testValidateTokenSuccessForPendingToken(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider', 'Test validation', 'validator@test.com');
        $this->assertSame('ok', $result['status']);
        $this->assertArrayHasKey('data', $result);
    }

    public function testValidateTokenRefuse(): void
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

    public function testValidateTokenReturnsExpiredForExpiredToken(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId, expiresInOffset: '-1 day');

        $result = $this->workflow->validateToken($tokenVal);
        $this->assertSame('expired', $result['status']);
    }

    public function testValidateTokenReturnsClosedForClosedSubmissionToken(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $this->closeSubmission($subId);

        $result = $this->workflow->validateToken($tokenVal);
        $this->assertSame('closed', $result['status']);
    }

    public function testValidateTokenReturnsDataKeyInResult(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $doneToken] = $this->createTestToken($subId, $stepId, doneAtOffset: '-1 minute');

        $result = $this->workflow->validateToken($doneToken);
        $this->assertArrayHasKey('data', $result);
        $this->assertIsArray($result['data']);
    }

    public function testValidateTokenWithDefaultAction(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal);
        $this->assertSame('ok', $result['status']);
    }

    public function testValidateTokenRefuseWithComment(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'refuser', 'Motif de refus détaillé');
        $this->assertSame('ok', $result['status']);
    }

    public function testValidateTokenRefuseWithoutComment(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'refuser', '');
        $this->assertSame('ok', $result['status']);
    }

    public function testValidateTokenWithEmptyComment(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider', '');
        $this->assertSame('ok', $result['status']);
    }

    public function testValidateTokenReturnsInvalidForShortHex(): void
    {
        $result = $this->workflow->validateToken('abc123');
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenReturnsInvalidForUppercaseHex(): void
    {
        $result = $this->workflow->validateToken(strtoupper(str_repeat('a', 64)));
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenReturnsInvalidForHexWithSpecialChars(): void
    {
        $result = $this->workflow->validateToken(str_repeat('g', 64));
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenReturnsInvalidForMixedCaseHex(): void
    {
        $token = str_repeat('A', 32) . str_repeat('a', 32);
        $result = $this->workflow->validateToken($token);
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenAcceptsValiderAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'valider');
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenAcceptsRefuserAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'refuser');
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenRejectsInvalidAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'annuler');
        $this->assertSame('invalid', $result['status']);
        $this->assertArrayHasKey('message', $result);
    }

    public function testValidateTokenValiderWithEmptyDoneBy(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider', 'OK', '');
        $this->assertSame('ok', $result['status']);
    }

    public function testValidateTokenRefuseReturnsOk(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'refuser');
        $this->assertSame('ok', $result['status']);
    }

    public function testValidateTokenValiderWithDoneBy(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $doneBy = 'validator_' . uniqid() . '@test.com';
        $result = $this->workflow->validateToken($tokenVal, 'valider', 'Approuvé', $doneBy);
        $this->assertSame('ok', $result['status']);

        $pdo = $this->db->getPdo();
        $check = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $data = json_decode((string) $check->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame($doneBy, $validation['done_by']);
    }

    public function testValidateTokenRejectsNonHexToken(): void
    {
        $result = $this->workflow->validateToken('xyz123def456');
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenRejectsUppercaseHex(): void
    {
        $token = strtoupper(str_repeat('a', 64));
        $result = $this->workflow->validateToken($token);
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenRejectsSpecialCharacters(): void
    {
        $result = $this->workflow->validateToken(str_repeat('g', 64));
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenRejectsShortToken(): void
    {
        $result = $this->workflow->validateToken('abc123');
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenRejectsLongToken(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 128));
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenRejectsEmptyToken(): void
    {
        $result = $this->workflow->validateToken('');
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenRejectsNullLikeToken(): void
    {
        $result = $this->workflow->validateToken('0000000000000000000000000000000000000000000000000000000000000000');
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenRejectsMixedCaseHex(): void
    {
        $token = str_repeat('A', 32) . str_repeat('a', 32);
        $result = $this->workflow->validateToken($token);
        $this->assertSame('invalid', $result['status']);
    }

    public function testValidateTokenRejectsDeleteAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'delete');
        $this->assertSame('invalid', $result['status']);
        $this->assertArrayHasKey('message', $result);
    }

    public function testValidateTokenRejectsCancelAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'cancel');
        $this->assertSame('invalid', $result['status']);
        $this->assertArrayHasKey('message', $result);
    }

    public function testValidateTokenRejectsApproveAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'approve');
        $this->assertSame('invalid', $result['status']);
        $this->assertArrayHasKey('message', $result);
    }

    public function testValidateTokenRejectsRejectAction(): void
    {
        $result = $this->workflow->validateToken(str_repeat('a', 64), 'reject');
        $this->assertSame('invalid', $result['status']);
        $this->assertArrayHasKey('message', $result);
    }

    public function testValidateTokenReturnsOkStatus(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertSame('ok', $result['status']);
    }

    public function testValidateTokenReturnsDataKeyOnSuccess(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertArrayHasKey('data', $result);
        $this->assertIsArray($result['data']);
    }
}
