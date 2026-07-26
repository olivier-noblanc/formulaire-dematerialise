<?php
declare(strict_types=1);

namespace App\Tests\WorkflowEngineTest;

/**
 * validateToken refuser tests extracted from WorkflowEngineTest.
 */
final class ValidateTokenRefuseTest extends Base
{
    public function testValidateTokenRefuseWithEmptyComment(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'refuser', '');
        $this->assertSame('ok', $result['status']);

        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame('', $validation['commentaire']);
    }

    public function testValidateTokenRefuseStoresRefuserAction(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'refuser', 'Motif');
        $this->assertSame('ok', $result['status']);

        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame('refuser', $validation['action']);
    }

    public function testValidateTokenRefuseStoresDoneBy(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $doneBy = 'refuser-' . uniqid() . '@test.com';
        $result = $this->workflow->validateToken($tokenVal, 'refuser', 'Motif', $doneBy);
        $this->assertSame('ok', $result['status']);

        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame($doneBy, $validation['done_by']);
    }

    public function testValidateTokenRefuseStoresDate(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $before = gmdate('Y-m-d H:i:s');
        $result = $this->workflow->validateToken($tokenVal, 'refuser', 'Motif');
        $after = gmdate('Y-m-d H:i:s');

        $this->assertSame('ok', $result['status']);

        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertGreaterThanOrEqual($before, $validation['date']);
        $this->assertLessThanOrEqual($after, $validation['date']);
    }

    public function testValidateTokenRefuseStoresEmail(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'refuser', 'Motif');
        $this->assertSame('ok', $result['status']);

        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame('validator@test.com', $validation['email']);
    }

    public function testValidateTokenRefuseStoresStepLabel(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'refuser', 'Motif');
        $this->assertSame('ok', $result['status']);

        $checkData = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $checkData->execute([$subId]);
        $data = json_decode((string) $checkData->fetchColumn(), true);
        $validation = end($data['validations']);
        $this->assertSame('Validation', $validation['step_label']);
    }

    public function testValidateTokenRefuseSetsDoneAtOnToken(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'refuser', 'Motif');
        $this->assertSame('ok', $result['status']);

        $check = $pdo->prepare("SELECT done_at FROM tokens WHERE token = ?");
        $check->execute([$tokenVal]);
        $this->assertNotEmpty($check->fetchColumn());
    }

    public function testValidateTokenValiderKeepsSubmissionOpenIfMoreSteps(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);
        $pdo = $this->db->getPdo();

        $result = $this->workflow->validateToken($tokenVal, 'valider');
        $this->assertSame('ok', $result['status']);

        $check = $pdo->prepare("SELECT status, closed_at FROM submissions WHERE id = ?");
        $check->execute([$subId]);
        $sub = $check->fetch(\PDO::FETCH_ASSOC);
        $this->assertContains($sub['status'], ['en_cours', 'valide']);
    }
}
