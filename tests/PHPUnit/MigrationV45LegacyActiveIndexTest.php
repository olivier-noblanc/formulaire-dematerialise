<?php
declare(strict_types=1);

namespace App\Tests;

use App\Enum\SubmissionStatus;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * v45 — suppression de l'index unique legacy des soumissions actives.
 *
 * Régression réelle : la v34 avait supprimé
 * `idx_submissions_active_per_form_user`, mais la v43 d'avant squash l'a
 * recréé, puis le squash a effacé la v34. Une base ayant déjà appliqué
 * l'ancienne v43 conserve `schema_version = 43` : la v43 corrigée ne rejoue
 * jamais et l'index survit, ce qui interdit plusieurs soumissions actives
 * pour un même couple form_id/submitted_by. Ces tests reproduisent cette base
 * et vérifient que la v45 la répare.
 */
final class MigrationV45LegacyActiveIndexTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'legacy-active-index-');
        self::assertNotFalse($path);
        $this->dbPath = $path;
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) unlink($this->dbPath);
    }

    private function migratedPdo(): PDO
    {
        require_once dirname(__DIR__, 2) . '/classes/DatabaseMigrations.php';
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        db_migrate($pdo);
        return $pdo;
    }

    private function legacyIndexCount(PDO $pdo): int
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='idx_submissions_active_per_form_user'")->fetchColumn();
    }

    public function testV45DropsLegacyUniqueIndexAndRestoresSeveralActiveSubmissions(): void
    {
        $pdo = $this->migratedPdo();
        $formId = (string) $pdo->query("SELECT id FROM forms WHERE slug = 'onboarding'")->fetchColumn();

        // Reproduit l'état d'une base migrée par l'ancienne v43 : index unique
        // globale présente alors que schema_version vaut déjà 44.
        $pdo->exec("CREATE UNIQUE INDEX idx_submissions_active_per_form_user ON submissions(form_id, submitted_by) WHERE status = 'en_cours' AND closed_at IS NULL");
        $pdo->exec('DELETE FROM schema_version WHERE version = 45');
        self::assertSame(1, $this->legacyIndexCount($pdo));

        $insert = $pdo->prepare('INSERT INTO submissions (id, form_id, data, submitted_by, status) VALUES (?, ?, ?, ?, ?)');
        $insert->execute(['legacy-sub-1', $formId, '{}', 'agent', SubmissionStatus::EnCours->value]);

        $blocked = false;
        try {
            $insert->execute(['legacy-sub-2', $formId, '{}', 'agent', SubmissionStatus::EnCours->value]);
        } catch (\PDOException) {
            $blocked = true;
        }
        self::assertTrue($blocked, 'Précondition : l\'index legacy doit interdire la 2e soumission active.');

        self::assertSame(45, apply_migration_v45($pdo, 44));
        self::assertSame(0, $this->legacyIndexCount($pdo));
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 45')->fetchColumn());

        $insert->execute(['legacy-sub-2', $formId, '{}', 'agent', SubmissionStatus::EnCours->value]);
        self::assertSame(
            2,
            (int) $pdo->query("SELECT COUNT(*) FROM submissions WHERE submitted_by = 'agent' AND status = 'en_cours'")->fetchColumn()
        );
    }

    public function testV45IsIdempotentOnFreshAndMigratedDatabases(): void
    {
        $pdo = $this->migratedPdo();
        self::assertSame(0, $this->legacyIndexCount($pdo), 'Une base fraîche ne doit jamais porter l\'index legacy.');

        db_migrate($pdo);
        self::assertSame(45, apply_migration_v45($pdo, 45));
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 45')->fetchColumn());
        self::assertSame(0, $this->legacyIndexCount($pdo));
    }
}
