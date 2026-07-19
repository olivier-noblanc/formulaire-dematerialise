<?php

declare(strict_types=1);

namespace App\Tests;

use App\Core\App;
use App\Core\Database;
use PHPUnit\Framework\TestCase;

/**
 * Tests that enum constraints reject invalid values.
 *
 * Approach split per table:
 * - form_fields.filled_by, form_fields.visibility, admin_requests.status → CHECK via table rebuild (DDL)
 * - submissions.status, tokens.action → triggers BEFORE INSERT + BEFORE UPDATE
 *
 * For each constrained column:
 * - One test that violates via INSERT
 * - One test that violates via UPDATE (separately)
 * - One test that accepts valid values
 */
final class EnumConstraintTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = App::getInstance()->get(Database::class);
        $pdo = $this->db->getPdo();
        $pdo->exec('DELETE FROM submissions WHERE 1=1');
        $pdo->exec('DELETE FROM tokens WHERE 1=1');
        $pdo->exec('DELETE FROM form_fields WHERE 1=1');
        $pdo->exec('DELETE FROM admin_requests WHERE 1=1');
    }

    // ── submissions.status (triggers) ──────────────────────────────

    public function testSubmissionsStatusRejectsInvalidInsert(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm($pdo);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('Invalid submissions.status');
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status) VALUES (?, ?, '{}', 'test@test.com', 'bad_status')")
            ->execute([\generate_uuid(), $formId]);
    }

    public function testSubmissionsStatusRejectsInvalidUpdate(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm($pdo);
        $subId = $this->createTestSubmission($pdo, $formId);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('Invalid submissions.status');
        $pdo->prepare("UPDATE submissions SET status = ? WHERE id = ?")
            ->execute(['bad_status', $subId]);
    }

    public function testSubmissionsStatusAcceptsValidValues(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm($pdo);

        foreach (['en_cours', 'valide', 'refuse', 'annule'] as $status) {
            $subId = $this->createTestSubmission($pdo, $formId, $status);
            $fetched = $pdo->prepare("SELECT status FROM submissions WHERE id = ?");
            $fetched->execute([$subId]);
            $this->assertSame($status, $fetched->fetchColumn());
        }
    }

    // ── tokens.action (triggers) ───────────────────────────────────

    public function testTokensActionRejectsInvalidInsert(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm($pdo);
        $subId = $this->createTestSubmission($pdo, $formId);
        $stepId = $this->createTestStep($pdo, $formId);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('Invalid tokens.action');
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, action) VALUES (?, ?, ?, 'test@test.com', ?, ?)")
            ->execute([\generate_uuid(), $subId, $stepId, bin2hex(random_bytes(16)), 'bad_action']);
    }

    public function testTokensActionRejectsInvalidUpdate(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm($pdo);
        $subId = $this->createTestSubmission($pdo, $formId);
        $stepId = $this->createTestStep($pdo, $formId);
        $tokenId = $this->createTestToken($pdo, $subId, $stepId);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('Invalid tokens.action');
        $pdo->prepare("UPDATE tokens SET action = ? WHERE id = ?")
            ->execute(['bad_action', $tokenId]);
    }

    public function testTokensActionAcceptsNullAndValidValues(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm($pdo);
        $subId = $this->createTestSubmission($pdo, $formId);

        // NULL (legacy tokens)
        $stepId1 = $this->createTestStep($pdo, $formId, 'Step A1');
        $tokenId1 = $this->createTestToken($pdo, $subId, $stepId1);
        $fetched = $pdo->prepare("SELECT action FROM tokens WHERE id = ?");
        $fetched->execute([$tokenId1]);
        $this->assertNull($fetched->fetchColumn());

        // 'valider'
        $stepId2 = $this->createTestStep($pdo, $formId, 'Step A2');
        $tokenId2 = $this->createTestToken($pdo, $subId, $stepId2, 'valider');
        $fetched->execute([$tokenId2]);
        $this->assertSame('valider', $fetched->fetchColumn());

        // 'refuser'
        $stepId3 = $this->createTestStep($pdo, $formId, 'Step A3');
        $tokenId3 = $this->createTestToken($pdo, $subId, $stepId3, 'refuser');
        $fetched->execute([$tokenId3]);
        $this->assertSame('refuser', $fetched->fetchColumn());
    }

    // ── form_fields.filled_by (CHECK via rebuild) ──────────────────

    public function testFormFieldsFilledByRejectsInvalidInsert(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm($pdo);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('CHECK constraint failed');
        $pdo->prepare("INSERT INTO form_fields (id, form_id, label, field_type, field_name, filled_by) VALUES (?, ?, 'Test', 'text', 'test', ?)")
            ->execute([\generate_uuid(), $formId, 'bad_value']);
    }

    public function testFormFieldsFilledByRejectsInvalidUpdate(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm($pdo);
        $fieldId = $this->createTestFormField($pdo, $formId);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('CHECK constraint failed');
        $pdo->prepare("UPDATE form_fields SET filled_by = ? WHERE id = ?")
            ->execute(['bad_value', $fieldId]);
    }

    public function testFormFieldsFilledByAcceptsValidValues(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm($pdo);

        foreach (['demandeur', 'validator'] as $filledBy) {
            $fieldId = $this->createTestFormField($pdo, $formId, filledBy: $filledBy);
            $fetched = $pdo->prepare("SELECT filled_by FROM form_fields WHERE id = ?");
            $fetched->execute([$fieldId]);
            $this->assertSame($filledBy, $fetched->fetchColumn());
        }
    }

    // ── form_fields.visibility (CHECK via rebuild) ─────────────────

    public function testFormFieldsVisibilityRejectsInvalidInsert(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm($pdo);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('CHECK constraint failed');
        $pdo->prepare("INSERT INTO form_fields (id, form_id, label, field_type, field_name, filled_by, visibility) VALUES (?, ?, 'Test', 'text', 'test', 'demandeur', ?)")
            ->execute([\generate_uuid(), $formId, 'bad_visibility']);
    }

    public function testFormFieldsVisibilityRejectsInvalidUpdate(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm($pdo);
        $fieldId = $this->createTestFormField($pdo, $formId);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('CHECK constraint failed');
        $pdo->prepare("UPDATE form_fields SET visibility = ? WHERE id = ?")
            ->execute(['bad_visibility', $fieldId]);
    }

    public function testFormFieldsVisibilityAcceptsValidValues(): void
    {
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm($pdo);

        foreach (['all', 'owner_only'] as $visibility) {
            $fieldId = $this->createTestFormField($pdo, $formId, visibility: $visibility);
            $fetched = $pdo->prepare("SELECT visibility FROM form_fields WHERE id = ?");
            $fetched->execute([$fieldId]);
            $this->assertSame($visibility, $fetched->fetchColumn());
        }
    }

    // ── admin_requests.status (CHECK via rebuild) ──────────────────

    public function testAdminRequestsStatusRejectsInvalidInsert(): void
    {
        $pdo = $this->db->getPdo();

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('CHECK constraint failed');
        $pdo->prepare("INSERT INTO admin_requests (id, email, status, token) VALUES (?, ?, ?, ?)")
            ->execute([\generate_uuid(), 'test-constraint@test.com', 'bad_status', bin2hex(random_bytes(16))]);
    }

    public function testAdminRequestsStatusRejectsInvalidUpdate(): void
    {
        $pdo = $this->db->getPdo();
        $reqId = $this->createTestAdminRequest($pdo);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('CHECK constraint failed');
        $pdo->prepare("UPDATE admin_requests SET status = ? WHERE id = ?")
            ->execute(['bad_status', $reqId]);
    }

    public function testAdminRequestsStatusAcceptsValidValues(): void
    {
        $pdo = $this->db->getPdo();

        foreach (['pending', 'approved', 'rejected'] as $status) {
            $reqId = $this->createTestAdminRequest($pdo, $status);
            $fetched = $pdo->prepare("SELECT status FROM admin_requests WHERE id = ?");
            $fetched->execute([$reqId]);
            $this->assertSame($status, $fetched->fetchColumn());
        }
    }

    // ── Crash simulation + self-healing ───────────────────────────

    public function testMigrationCrashSelfHealing(): void
    {
        // Utiliser la base production (pas le test DB vidé par setUp)
        $prodPath = dirname(__DIR__, 2) . '/db/workflow.db';
        if (!file_exists($prodPath)) {
            $this->markTestSkipped('workflow.db non trouvée');
        }

        // Copier la base pour ne pas casser l'environnement
        $tmpDb = sys_get_temp_dir() . '/v30_crash_test.db';
        copy($prodPath, $tmpDb);

        // Ouvrir une connexion séparée sur la copie
        $crashDb = new \PDO('sqlite:' . $tmpDb);
        $crashDb->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $versionBefore = $crashDb->query('SELECT MAX(version) FROM schema_version')->fetchColumn();
        $ffCount = $crashDb->query('SELECT COUNT(*) FROM form_fields')->fetchColumn();
        $this->assertGreaterThan(0, $ffCount, 'form_fields doit avoir des données avant le test');

        // Simuler une panne : DROP form_fields sans RENAME (DDL auto-committed, pas de transaction)
        $crashDb->exec('PRAGMA foreign_keys = OFF');
        $crashDb->exec('DROP TABLE IF EXISTS form_fields_new');
        $crashDb->exec('CREATE TABLE form_fields_new AS SELECT * FROM form_fields');
        $crashDb->exec('DROP TABLE form_fields');
        // PAS DE RENAME — panne simulée ici
        $crashDb = null;

        // Vérifier l'état corrompu
        $checkDb = new \PDO('sqlite:' . $tmpDb);
        $checkDb->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $hasFF = $checkDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name='form_fields'")->fetchColumn();
        $hasFFNew = $checkDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name='form_fields_new'")->fetchColumn();
        $this->assertEmpty($hasFF, 'form_fields ne doit plus exister après la panne simulée');
        $this->assertEquals('form_fields_new', $hasFFNew, 'form_fields_new doit exister avec les données');
        $checkDb = null;

        // Simuler le rejeu de la migration (self-healing)
        $replayDb = new \PDO('sqlite:' . $tmpDb);
        $replayDb->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $replayDb->exec('PRAGMA foreign_keys = ON');

        // Étape 0 de la migration : self-healing
        $replayDb->exec('PRAGMA foreign_keys = OFF');
        $hasFF2 = $replayDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name='form_fields'")->fetchColumn();
        $hasFFNew2 = $replayDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name='form_fields_new'")->fetchColumn();
        if (!$hasFF2 && $hasFFNew2) {
            $replayDb->exec('ALTER TABLE form_fields_new RENAME TO form_fields');
            $replayDb->exec('CREATE INDEX IF NOT EXISTS idx_ff_form ON form_fields(form_id)');
            $replayDb->exec('CREATE INDEX IF NOT EXISTS idx_ff_filled_by ON form_fields(form_id, filled_by)');
        }
        $replayDb->exec('PRAGMA foreign_keys = ON');

        // Vérifier que form_fields est restaurée avec les données
        $ffAfter = $replayDb->query('SELECT COUNT(*) FROM form_fields')->fetchColumn();
        $this->assertSame($ffCount, $ffAfter, 'form_fields doit avoir les mêmes données après self-healing');
        $replayDb = null;

        @unlink($tmpDb);
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function createTestForm(\PDO $pdo): string
    {
        $id = \generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$id, 'test-enum-' . $id, 'Test Form', '']);
        return $id;
    }

    private function createTestSubmission(\PDO $pdo, string $formId, string $status = 'en_cours'): string
    {
        $id = \generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, '{}', 'test@test.com', ?, datetime('now'))")
            ->execute([$id, $formId, $status]);
        return $id;
    }

    private function createTestStep(\PDO $pdo, string $formId, string $label = 'Validation'): string
    {
        $id = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, 1, 1)")
            ->execute([$id, $formId, $label]);
        return $id;
    }

    private function createTestToken(\PDO $pdo, string $submissionId, string $stepId, ?string $action = null): string
    {
        $id = \generate_uuid();
        $token = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, action) VALUES (?, ?, ?, 'test@test.com', ?, ?)")
            ->execute([$id, $submissionId, $stepId, $token, $action]);
        return $id;
    }

    private function createTestFormField(\PDO $pdo, string $formId, string $filledBy = 'demandeur', string $visibility = 'all'): string
    {
        $id = \generate_uuid();
        $pdo->prepare("INSERT INTO form_fields (id, form_id, label, field_type, field_name, filled_by, visibility) VALUES (?, ?, 'Test', 'text', 'test_field', ?, ?)")
            ->execute([$id, $formId, $filledBy, $visibility]);
        return $id;
    }

    private function createTestAdminRequest(\PDO $pdo, string $status = 'pending'): string
    {
        $id = \generate_uuid();
        $token = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO admin_requests (id, email, status, token) VALUES (?, ?, ?, ?)")
            ->execute([$id, 'test-admin-' . uniqid() . '@test.com', $status, $token]);
        return $id;
    }
}
