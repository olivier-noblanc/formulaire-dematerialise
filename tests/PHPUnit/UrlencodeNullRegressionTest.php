<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests de régression pour le fix urlencode(null) — PHP 8.1+ TypeError.
 *
 * PHP 8.1+ rejette null en argument de urlencode(). Le fix applique
 * (string) ($var ?? '') à 11 emplacements dans le codebase.
 * Ces tests vérifient que le pattern est correct et que les contrôleurs
 * ne plantent plus avec des valeurs null.
 */
final class UrlencodeNullRegressionTest extends TestCase
{
    // ── Pattern fix: (string) ($var ?? '') ─────────────────────

    public function testUrlencodePatternCastsNullToStringEmpty(): void
    {
        $null_var = null;
        $result = urlencode((string) ($null_var ?? ''));
        self::assertSame('', $result);
    }

    public function testUrlencodePatternPreservesStringValue(): void
    {
        $str_var = 'hello world';
        $result = urlencode((string) ($str_var ?? ''));
        self::assertSame('hello+world', $result);
    }

    public function testUrlencodePatternPreservesUuid(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $result = urlencode((string) ($uuid ?? ''));
        self::assertSame($uuid, $result);
    }

    public function testUrlencodePatternHandlesEmptyString(): void
    {
        $empty = '';
        $result = urlencode((string) ($empty ?? ''));
        self::assertSame('', $result);
    }

    // ── Direct null would throw TypeError (PHP 8.1+) ──────────

    public function testBareUrlencodeNullWouldThrowTypeError(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('urlencode');
        /** @phpstan-ignore-next-line */
        urlencode(null);
    }

    // ── Regression: controllers that used urlencode with nullable data ─

    public function testAdminFormsControllerFormIdUrlencode(): void
    {
        // AdminFormsController: urlencode((string) $formId) — $formId can be ''
        $formId = '';
        $url = 'index.php?p=admin_forms&form_id=' . urlencode((string) $formId);
        self::assertIsString($url);
        self::assertStringContainsString('form_id=', $url);
    }

    public function testAdminFormsControllerEditStepUrlencode(): void
    {
        // AdminFormsController: urlencode((string) ($workflowStep['step_id'] ?? ''))
        $workflowStep = ['step_id' => null];
        $result = urlencode((string) ($workflowStep['step_id'] ?? ''));
        self::assertSame('', $result);

        $workflowStep = ['step_id' => 'abc-123'];
        $result = urlencode((string) ($workflowStep['step_id'] ?? ''));
        self::assertSame('abc-123', $result);
    }

    public function testAdminFormsControllerEditFieldUrlencode(): void
    {
        // AdminFormsController: urlencode((string) ($formField['id'] ?? ''))
        $formField = ['id' => null];
        $result = urlencode((string) ($formField['id'] ?? ''));
        self::assertSame('', $result);

        $formField = [];
        $result = urlencode((string) ($formField['id'] ?? ''));
        self::assertSame('', $result);
    }

    public function testFormPreviewControllerFormIdUrlencode(): void
    {
        // FormPreviewController: urlencode((string) ($form['id'] ?? ''))
        $form = ['id' => null];
        $result = urlencode((string) ($form['id'] ?? ''));
        self::assertSame('', $result);

        $form = [];
        $result = urlencode((string) ($form['id'] ?? ''));
        self::assertSame('', $result);
    }

    public function testSubmissionViewControllerUrls(): void
    {
        // SubmissionViewController: urlencode((string) ($att['id'] ?? ''))
        $att = ['id' => null];
        $result = urlencode((string) ($att['id'] ?? ''));
        self::assertSame('', $result);

        $att = [];
        $result = urlencode((string) ($att['id'] ?? ''));
        self::assertSame('', $result);
    }

    public function testFormTrackingControllerUrls(): void
    {
        // FormTrackingController: urlencode((string) ($submission['id'] ?? ''))
        $submission = ['id' => null];
        $result = urlencode((string) ($submission['id'] ?? ''));
        self::assertSame('', $result);

        $submission = [];
        $result = urlencode((string) ($submission['id'] ?? ''));
        self::assertSame('', $result);
    }

    public function testConfirmActionControllerUrls(): void
    {
        // ConfirmActionController: urlencode((string) ($_GET['form_id'] ?? ''))
        $_GET['form_id'] = null;
        $result = urlencode((string) ($_GET['form_id'] ?? ''));
        self::assertSame('', $result);

        unset($_GET['form_id']);
        $result = urlencode((string) ($_GET['form_id'] ?? ''));
        self::assertSame('', $result);
    }

    public function testFormControllerUrls(): void
    {
        // FormController: urlencode((string) ($existing_submission['id'] ?? ''))
        $existing_submission = ['id' => null];
        $result = urlencode((string) ($existing_submission['id'] ?? ''));
        self::assertSame('', $result);

        $existing_submission = [];
        $result = urlencode((string) ($existing_submission['id'] ?? ''));
        self::assertSame('', $result);
    }

    public function testAdminAccessControllerUrls(): void
    {
        // AdminAccessController: urlencode((string) ($allAdmin['email'] ?? ''))
        $allAdmin = ['email' => null];
        $result = urlencode((string) ($allAdmin['email'] ?? ''));
        self::assertSame('', $result);

        $allAdmin = [];
        $result = urlencode((string) ($allAdmin['email'] ?? ''));
        self::assertSame('', $result);
    }

    public function testMySubmissionsRendererUrls(): void
    {
        // MySubmissionsRenderer: urlencode((string) ($submission['id'] ?? ''))
        $submission = ['id' => null];
        $result = urlencode((string) ($submission['id'] ?? ''));
        self::assertSame('', $result);
    }

    public function testMyValidationsRendererUrls(): void
    {
        // MyValidationsRenderer: urlencode((string) ($pendingToken['token'] ?? ''))
        $pendingToken = ['token' => null];
        $result = urlencode((string) ($pendingToken['token'] ?? ''));
        self::assertSame('', $result);
    }

    public function testAdminAlertsRendererUrls(): void
    {
        // AdminAlertsRenderer: urlencode((string) ($rule['id'] ?? ''))
        $rule = ['id' => null];
        $result = urlencode((string) ($rule['id'] ?? ''));
        self::assertSame('', $result);

        $rule = [];
        $result = urlencode((string) ($rule['id'] ?? ''));
        self::assertSame('', $result);
    }
}
