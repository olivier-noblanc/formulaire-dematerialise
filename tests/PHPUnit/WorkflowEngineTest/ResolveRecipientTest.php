<?php
declare(strict_types=1);

namespace App\Tests\WorkflowEngineTest;

/**
 * @covers \App\Workflow\WorkflowEngine::resolveDynamicRecipient
 */
final class ResolveRecipientTest extends Base
{
    // ── Basic resolve ────────────────────────────────────────────

    public function testResolveDynamicRecipientReturnsEmailForNonTemplate(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('user@example.com', []);
        self::assertSame('user@example.com', $result);
    }

    public function testResolveDynamicRecipientResolvesTemplate(): void
    {
        $formData = ['manager_email' => 'manager@example.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{manager_email}}', $formData);
        self::assertSame('manager@example.com', $result);
    }

    public function testResolveDynamicRecipientReturnsTemplateForMissingField(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{missing_field}}', []);
        self::assertSame('{{missing_field}}', $result);
    }

    public function testResolveDynamicRecipientReturnsTemplateForInvalidEmail(): void
    {
        $formData = ['email' => 'not_an_email'];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        self::assertSame('{{email}}', $result);
    }

    public function testResolveDynamicRecipientResolvesCaseInsensitive(): void
    {
        $formData = ['Manager_Email' => 'manager@example.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{manager_email}}', $formData);
        self::assertSame('manager@example.com', $result);
    }

    public function testResolveDynamicRecipientReturnsEmptyEmailUnchanged(): void
    {
        $formData = ['email' => ''];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        self::assertSame('{{email}}', $result);
    }

    public function testResolveDynamicRecipientResolvesExactMatchFirst(): void
    {
        $formData = ['email' => 'exact@example.com', 'Email' => 'case@example.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        self::assertSame('exact@example.com', $result);
    }

    public function testResolveDynamicRecipientResolvesCaseInsensitiveFallback(): void
    {
        $formData = ['Email' => 'fallback@example.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        self::assertSame('fallback@example.com', $result);
    }

    public function testResolveDynamicRecipientReturnsTemplateForNullFormDataValue(): void
    {
        $formData = ['email' => null];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        self::assertSame('{{email}}', $result);
    }

    public function testResolveDynamicRecipientReturnsTemplateForWhitespaceOnlyEmail(): void
    {
        $formData = ['email' => '   '];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        self::assertSame('{{email}}', $result);
    }

    public function testResolveDynamicRecipientReturnsStaticEmailUnchanged(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('fixed@example.com', ['key' => 'value']);
        self::assertSame('fixed@example.com', $result);
    }

    public function testResolveDynamicRecipientWithEmptyFormData(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{anything}}', []);
        self::assertSame('{{anything}}', $result);
    }

    // ── Template syntax edge cases ───────────────────────────────

    public function testResolveDynamicRecipientIgnoresNonLowercaseStart(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{ManagerEmail}}', ['ManagerEmail' => 'test@example.com']);
        self::assertSame('{{ManagerEmail}}', $result);
    }

    public function testResolveDynamicRecipientIgnoresNumericStart(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{1field}}', ['1field' => 'test@example.com']);
        self::assertSame('{{1field}}', $result);
    }

    public function testResolveDynamicRecipientWithPartialTemplateSyntax(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{email', ['email' => 'test@example.com']);
        self::assertSame('{{email', $result);
    }

    public function testResolveDynamicRecipientWithTripleBraces(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{{email}}}', ['email' => 'test@example.com']);
        self::assertSame('{{{email}}}', $result);
    }

    public function testResolveDynamicRecipientEmptyBraces(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{}}', ['email' => 'test@example.com']);
        self::assertSame('{{}}', $result);
    }

    public function testResolveDynamicRecipientFieldNameStartingWithNumber(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{1field}}', ['1field' => 'test@example.com']);
        self::assertSame('{{1field}}', $result);
    }

    public function testResolveDynamicRecipientFieldNameWithUnderscore(): void
    {
        $formData = ['user_email' => 'user@example.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{user_email}}', $formData);
        self::assertSame('user@example.com', $result);
    }

    public function testResolveDynamicRecipientFieldNameWithDigits(): void
    {
        $formData = ['email2' => 'test@example.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{email2}}', $formData);
        self::assertSame('test@example.com', $result);
    }

    public function testResolveDynamicRecipientEmptyRecipient(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('', ['email' => 'test@example.com']);
        self::assertSame('', $result);
    }

    public function testResolveDynamicRecipientRecipientWithSpaces(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('  user@example.com  ', []);
        self::assertSame('  user@example.com  ', $result);
    }

    // ── Owner resolution ─────────────────────────────────────────

    public function testResolveDynamicRecipientWithOwnerTemplate(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{owner}}', [], 'nonexistent-submission');
        self::assertSame('{{owner}}', $result);
    }

    public function testResolveDynamicRecipientWithOwnerAndNoSubmissionId(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{owner}}', []);
        self::assertSame('{{owner}}', $result);
    }

    public function testResolveDynamicRecipientWithOwnerAndNonexistentSubmission(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{owner}}', [], '00000000-0000-0000-0000-000000000000');
        self::assertSame('{{owner}}', $result);
    }

    public function testResolveDynamicRecipientWithOwnerAndRealSubmission(): void
    {
        [$formId] = $this->createTestForm();
        $this->createFormOwner($formId, 'owner-' . uniqid() . '@test.com');
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->resolveDynamicRecipient('{{owner}}', [], $subId);
        self::assertNotSame('{{owner}}', $result);
        self::assertStringContainsString('@', $result);
    }

    public function testResolveDynamicRecipientWithOwnerFallbackToAdmin(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->resolveDynamicRecipient('{{owner}}', [], $subId);
        self::assertNotSame('{{owner}}', $result);
        self::assertStringContainsString('@', $result);
    }

    public function testResolveDynamicRecipientOwnerInvalidEmailFallsBackToAdmin(): void
    {
        $pdo = $this->db->getPdo();

        $formId = bin2hex(random_bytes(8));
        $slug = 'test-owner-invalid-' . uniqid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'Test', 'test', 1, datetime('now'))")
            ->execute([$formId, $slug]);

        $ownerId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO form_owners (id, form_id, email, added_at) VALUES (?, ?, 'not-an-email', datetime('now'))")
            ->execute([$ownerId, $formId]);

        $subId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, '{}', 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId]);

        $result = $this->workflow->resolveDynamicRecipient('{{owner}}', [], $subId);

        self::assertNotSame('{{owner}}', $result);
        self::assertStringContainsString('@', $result);

        $pdo->prepare("DELETE FROM form_owners WHERE id = ?")->execute([$ownerId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }

    public function testResolveDynamicRecipientNoOwnersFallsBackToAdminEmail(): void
    {
        $pdo = $this->db->getPdo();

        $formId = bin2hex(random_bytes(8));
        $slug = 'test-no-owners-' . uniqid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'Test', 'test', 1, datetime('now'))")
            ->execute([$formId, $slug]);

        $subId = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, '{}', 'test@test.com', datetime('now'), 'en_cours')")
            ->execute([$subId, $formId]);

        $result = $this->workflow->resolveDynamicRecipient('{{owner}}', [], $subId);

        self::assertIsString($result);

        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }

    public function testResolveDynamicRecipientOwnerWithNoSubmission(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{owner}}', []);
        self::assertSame('{{owner}}', $result);
    }

    public function testResolveDynamicRecipientOwnerWithNullSubmission(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{owner}}', [], null);
        self::assertSame('{{owner}}', $result);
    }

    // ── Field resolution ─────────────────────────────────────────

    public function testResolveDynamicRecipientFieldWithSpecialChars(): void
    {
        $formData = ['email' => 'user+tag@domain.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        self::assertSame('user+tag@domain.com', $result);
    }

    public function testResolveDynamicRecipientFieldWithMultipleAtSigns(): void
    {
        $formData = ['email' => 'invalid@@email.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        self::assertSame('{{email}}', $result);
    }

    public function testResolveDynamicRecipientFieldWithNumericValue(): void
    {
        $formData = ['phone' => '1234567890'];
        $result = $this->workflow->resolveDynamicRecipient('{{phone}}', $formData);
        self::assertSame('{{phone}}', $result);
    }

    public function testResolveDynamicRecipientFieldWithUrlValue(): void
    {
        $formData = ['website' => 'https://example.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{website}}', $formData);
        self::assertSame('{{website}}', $result);
    }

    public function testResolveDynamicRecipientFieldWithBooleanValue(): void
    {
        $formData = ['active' => true];
        $result = $this->workflow->resolveDynamicRecipient('{{active}}', $formData);
        self::assertSame('{{active}}', $result);
    }

    public function testResolveDynamicRecipientFieldWithNullValue(): void
    {
        $formData = ['email' => null];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        self::assertSame('{{email}}', $result);
    }

    public function testResolveDynamicRecipientFieldWithEmptyString(): void
    {
        $formData = ['email' => ''];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        self::assertSame('{{email}}', $result);
    }

    public function testResolveDynamicRecipientFieldWithWhitespaceEmail(): void
    {
        $formData = ['email' => '   '];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        self::assertSame('{{email}}', $result);
    }

    public function testResolveDynamicRecipientFieldWithValidEmail(): void
    {
        $formData = ['contact' => 'contact@example.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{contact}}', $formData);
        self::assertSame('contact@example.com', $result);
    }

    public function testResolveDynamicRecipientFieldWithEmailNameFormat(): void
    {
        $formData = ['email' => 'John Doe <john@example.com>'];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        self::assertSame('{{email}}', $result);
    }

    public function testResolveDynamicRecipientCaseInsensitiveKeyLookup(): void
    {
        $formData = ['Email' => 'test@example.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        self::assertSame('test@example.com', $result);
    }

    public function testResolveDynamicRecipientExactKeyMatchFirst(): void
    {
        $formData = ['email' => 'exact@example.com', 'Email' => 'case@example.com'];
        $result = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);
        self::assertSame('exact@example.com', $result);
    }

    public function testResolveDynamicRecipientPartialTemplateSyntax(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{email', ['email' => 'test@example.com']);
        self::assertSame('{{email', $result);
    }

    public function testResolveDynamicRecipientTripleBraces(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('{{{email}}}', ['email' => 'test@example.com']);
        self::assertSame('{{{email}}}', $result);
    }

    public function testResolveDynamicRecipientStaticEmailReturnedUnchanged(): void
    {
        $result = $this->workflow->resolveDynamicRecipient('admin@example.com', ['key' => 'value']);
        self::assertSame('admin@example.com', $result);
    }
}
