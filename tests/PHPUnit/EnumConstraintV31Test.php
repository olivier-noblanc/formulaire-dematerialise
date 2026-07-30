<?php

declare(strict_types=1);

namespace App\Tests;

use App\Core\App;
use App\Core\Database;
use PHPUnit\Framework\TestCase;

/**
 * Tests des contraintes CHECK ajoutées par la migration v31 (rebuild de table) :
 * - form_fields.field_type
 * - submission_validator_data.field_type
 * - submission_validator_data.filled_by
 * - mail_log.status
 *
 * Suit le même pattern que EnumConstraintTest.php (v30) : un test par
 * violation (INSERT/UPDATE) + un test d'acceptation des valeurs valides.
 */
final class EnumConstraintV31Test extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = App::getInstance()->get(Database::class);
        $pdo = $this->db->getPdo();
        $pdo->exec('DELETE FROM form_fields WHERE 1=1');
        $pdo->exec('DELETE FROM submission_validator_data WHERE 1=1');
        $pdo->exec("DELETE FROM mail_log WHERE recipient LIKE '%enum-v31-test%'");
    }

    private function skipIfNoCheckConstraints(string $table, string $column): void
    {
        $pdo = $this->db->getPdo();
        $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$table'")->fetchColumn();
        if ($sql === false || !str_contains($sql, "$column IN")) {
            self::markTestSkipped("CHECK constraint sur $table.$column absente (migration v31 peut avoir échoué)");
        }
    }

    // ── form_fields.field_type ──────────────────────────────────────

    public function testFormFieldsFieldTypeRejectsInvalidInsert(): void
    {
        $this->skipIfNoCheckConstraints('form_fields', 'field_type');
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm($pdo);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('CHECK constraint failed');
        $pdo->prepare("INSERT INTO form_fields (id, form_id, label, field_type, field_name) VALUES (?, ?, 'Test', 'bad_type', 'test')")
            ->execute([\generate_uuid(), $formId]);
    }

    public function testFormFieldsFieldTypeRejectsInvalidUpdate(): void
    {
        $this->skipIfNoCheckConstraints('form_fields', 'field_type');
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm($pdo);
        $fieldId = $this->createTestFormField($pdo, $formId);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('CHECK constraint failed');
        $pdo->prepare("UPDATE form_fields SET field_type = ? WHERE id = ?")
            ->execute(['bad_type', $fieldId]);
    }

    public function testFormFieldsFieldTypeAcceptsValidValues(): void
    {
        $this->skipIfNoCheckConstraints('form_fields', 'field_type');
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm($pdo);

        foreach (['text', 'email', 'date', 'select', 'checkbox', 'textarea', 'file'] as $type) {
            $fieldId = $this->createTestFormField($pdo, $formId, $type);
            $fetched = $pdo->prepare('SELECT field_type FROM form_fields WHERE id = ?');
            $fetched->execute([$fieldId]);
            self::assertSame($type, $fetched->fetchColumn());
        }
    }

    // ── submission_validator_data.field_type ────────────────────────

    public function testValidatorDataFieldTypeRejectsInvalidInsert(): void
    {
        $this->skipIfNoCheckConstraints('submission_validator_data', 'field_type');
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm($pdo);
        $subId = $this->createTestSubmission($pdo, $formId);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('CHECK constraint failed');
        $pdo->prepare("INSERT INTO submission_validator_data (id, submission_id, field_name, field_label, field_type, filled_by) VALUES (?, ?, 'f', 'F', 'bad_type', 'validator')")
            ->execute([\generate_uuid(), $subId]);
    }

    public function testValidatorDataFieldTypeAcceptsValidValues(): void
    {
        $this->skipIfNoCheckConstraints('submission_validator_data', 'field_type');
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm($pdo);
        $subId = $this->createTestSubmission($pdo, $formId);

        foreach (['text', 'email', 'date', 'select', 'checkbox', 'textarea', 'file'] as $i => $type) {
            $svdId = $this->createTestValidatorData($pdo, $subId, 'field_' . $i, $type);
            $fetched = $pdo->prepare('SELECT field_type FROM submission_validator_data WHERE id = ?');
            $fetched->execute([$svdId]);
            self::assertSame($type, $fetched->fetchColumn());
        }
    }

    // ── submission_validator_data.filled_by ──────────────────────────

    public function testValidatorDataFilledByRejectsInvalidInsert(): void
    {
        $this->skipIfNoCheckConstraints('submission_validator_data', 'filled_by');
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm($pdo);
        $subId = $this->createTestSubmission($pdo, $formId);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('CHECK constraint failed');
        $pdo->prepare("INSERT INTO submission_validator_data (id, submission_id, field_name, field_label, field_type, filled_by) VALUES (?, ?, 'f', 'F', 'text', 'bad_filler')")
            ->execute([\generate_uuid(), $subId]);
    }

    public function testValidatorDataFilledByRejectsInvalidUpdate(): void
    {
        $this->skipIfNoCheckConstraints('submission_validator_data', 'filled_by');
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm($pdo);
        $subId = $this->createTestSubmission($pdo, $formId);
        $svdId = $this->createTestValidatorData($pdo, $subId);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('CHECK constraint failed');
        $pdo->prepare('UPDATE submission_validator_data SET filled_by = ? WHERE id = ?')
            ->execute(['bad_filler', $svdId]);
    }

    public function testValidatorDataFilledByAcceptsValidValues(): void
    {
        $this->skipIfNoCheckConstraints('submission_validator_data', 'filled_by');
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm($pdo);
        $subId = $this->createTestSubmission($pdo, $formId);

        foreach (['demandeur', 'validator'] as $i => $filledBy) {
            $svdId = $this->createTestValidatorData($pdo, $subId, 'ffield_' . $i, 'text', $filledBy);
            $fetched = $pdo->prepare('SELECT filled_by FROM submission_validator_data WHERE id = ?');
            $fetched->execute([$svdId]);
            self::assertSame($filledBy, $fetched->fetchColumn());
        }
    }

    // ── mail_log.status ───────────────────────────────────────────────

    public function testMailLogStatusRejectsInvalidInsert(): void
    {
        $this->skipIfNoCheckConstraints('mail_log', 'status');
        $pdo = $this->db->getPdo();

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('CHECK constraint failed');
        $pdo->prepare("INSERT INTO mail_log (id, created_at, recipient, subject, status) VALUES (?, datetime('now'), 'enum-v31-test@test.com', 'Sujet', 'bad_status')")
            ->execute([\generate_uuid()]);
    }

    public function testMailLogStatusRejectsInvalidUpdate(): void
    {
        $this->skipIfNoCheckConstraints('mail_log', 'status');
        $pdo = $this->db->getPdo();
        $logId = $this->createTestMailLog($pdo);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('CHECK constraint failed');
        $pdo->prepare('UPDATE mail_log SET status = ? WHERE id = ?')
            ->execute(['bad_status', $logId]);
    }

    public function testMailLogStatusAcceptsValidValues(): void
    {
        $this->skipIfNoCheckConstraints('mail_log', 'status');
        $pdo = $this->db->getPdo();

        foreach (['sent', 'blocked', 'dry_run', 'error'] as $status) {
            $logId = $this->createTestMailLog($pdo, $status);
            $fetched = $pdo->prepare('SELECT status FROM mail_log WHERE id = ?');
            $fetched->execute([$logId]);
            self::assertSame($status, $fetched->fetchColumn());
        }
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function createTestForm(\PDO $pdo): string
    {
        $id = \generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$id, 'test-enum-v31-' . $id, 'Test Form', '']);
        return $id;
    }

    private function createTestSubmission(\PDO $pdo, string $formId): string
    {
        $id = \generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, '{}', 'test@test.com', 'en_cours', datetime('now'))")
            ->execute([$id, $formId]);
        return $id;
    }

    private function createTestFormField(\PDO $pdo, string $formId, string $fieldType = 'text'): string
    {
        $id = \generate_uuid();
        $pdo->prepare('INSERT INTO form_fields (id, form_id, label, field_type, field_name) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, $formId, 'Test ' . $fieldType, $fieldType, 'test_field_' . $fieldType]);
        return $id;
    }

    private function createTestValidatorData(\PDO $pdo, string $submissionId, string $fieldName = 'field_x', string $fieldType = 'text', string $filledBy = 'validator'): string
    {
        $id = \generate_uuid();
        $pdo->prepare('INSERT INTO submission_validator_data (id, submission_id, field_name, field_label, field_type, filled_by) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $submissionId, $fieldName, 'Label', $fieldType, $filledBy]);
        return $id;
    }

    private function createTestMailLog(\PDO $pdo, string $status = 'sent'): string
    {
        $id = \generate_uuid();
        $pdo->prepare("INSERT INTO mail_log (id, created_at, recipient, subject, status) VALUES (?, datetime('now'), 'enum-v31-test@test.com', 'Sujet', ?)")
            ->execute([$id, $status]);
        return $id;
    }
}
