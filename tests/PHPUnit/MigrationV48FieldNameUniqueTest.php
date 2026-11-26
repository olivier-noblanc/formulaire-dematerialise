<?php
declare(strict_types=1);

namespace App\Tests;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * v48 — unicité de `form_fields.field_name` par formulaire.
 *
 * La migration est fail-closed : si des doublons existent déjà, elle bloque
 * avec les IDs concernés (pas de suppression ni de renommage silencieux).
 */
final class MigrationV48FieldNameUniqueTest extends TestCase
{
    /** @var list<string> */
    private array $dbPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->dbPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->dbPaths = [];
    }

    private function newDbPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'v48-contract-');
        self::assertNotFalse($path);
        $this->dbPaths[] = $path;
        return $path;
    }

    private function pdo(string $path): PDO
    {
        require_once dirname(__DIR__, 2) . '/classes/DatabaseMigrations.php';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }

    private function migratedPdo(): PDO
    {
        $pdo = $this->pdo($this->newDbPath());
        db_migrate($pdo);
        return $pdo;
    }

    private function v47Pdo(): PDO
    {
        $pdo = $this->pdo($this->newDbPath());
        $version = apply_schema_initial($pdo);
        $version = apply_migration_v43($pdo, $version);
        $version = apply_migration_v44($pdo, $version);
        $version = apply_migration_v45($pdo, $version);
        $version = apply_migration_v46($pdo, $version);
        $version = apply_migration_v47($pdo, $version);
        self::assertSame(47, $version);
        return $pdo;
    }

    private function createForm(PDO $pdo, string $id, string $slug): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO forms (id, slug, label, description, actif) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$id, $slug, 'Formulaire ' . $slug, '']);
    }

    private function insertField(PDO $pdo, string $id, string $formId, string $fieldName): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO form_fields (id, form_id, label, field_type, field_name, required, ordre) '
            . 'VALUES (?, ?, ?, ?, ?, 0, 1)'
        );
        $stmt->execute([$id, $formId, 'Champ ' . $id, 'text', $fieldName]);
    }

    public function testFreshDatabaseHasCaseInsensitiveUniqueIndex(): void
    {
        $pdo = $this->migratedPdo();

        self::assertSame(48, (int) $pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn());

        $index = $pdo->query(
            "SELECT sql FROM sqlite_master WHERE type='index' AND name='idx_ff_form_field_name'"
        );
        self::assertNotFalse($index);
        $sql = (string) $index->fetchColumn();
        $index = null;

        self::assertStringContainsString('UNIQUE', $sql);
        self::assertStringContainsString('lower(field_name)', $sql);
    }

    public function testDuplicateFieldNameInSameFormIsRejectedBySql(): void
    {
        $pdo = $this->migratedPdo();
        $this->createForm($pdo, 'form-1', 'form-1');
        $this->insertField($pdo, 'field-1', 'form-1', 'nom');

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('UNIQUE constraint failed');
        $this->insertField($pdo, 'field-2', 'form-1', 'nom');
    }

    public function testUniquenessIsCaseInsensitive(): void
    {
        $pdo = $this->migratedPdo();
        $this->createForm($pdo, 'form-2', 'form-2');
        $this->insertField($pdo, 'field-3', 'form-2', 'nom');

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('UNIQUE constraint failed');
        $this->insertField($pdo, 'field-4', 'form-2', 'Nom');
    }

    public function testSameFieldNameAllowedInTwoDifferentForms(): void
    {
        $pdo = $this->migratedPdo();
        $this->createForm($pdo, 'form-3', 'form-3');
        $this->createForm($pdo, 'form-4', 'form-4');
        $this->insertField($pdo, 'field-5', 'form-3', 'nom');
        $this->insertField($pdo, 'field-6', 'form-4', 'nom');

        self::assertSame(
            2,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM form_fields "
                . "WHERE lower(field_name) = 'nom' AND form_id IN ('form-3', 'form-4')"
            )->fetchColumn()
        );
    }

    public function testMigrationBlocksWhenDuplicatesAlreadyExist(): void
    {
        $pdo = $this->v47Pdo();
        $this->createForm($pdo, 'form-dup', 'form-dup');
        $this->insertField($pdo, 'field-dup-1', 'form-dup', 'nom');
        $this->insertField($pdo, 'field-dup-2', 'form-dup', 'nom');

        try {
            apply_migration_v48($pdo, 47);
            self::fail('v48 doit bloquer quand des doublons existent déjà.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('v48 blocked', $e->getMessage());
            self::assertStringContainsString('form-dup', $e->getMessage());
            self::assertStringContainsString('nom', $e->getMessage());
        }

        self::assertSame(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 48')->fetchColumn(),
            'La version ne doit pas être marquée appliquée après un blocage'
        );
        self::assertSame(
            0,
            (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='idx_ff_form_field_name'")->fetchColumn(),
            'Aucun index UNIQUE ne doit être créé après un blocage'
        );
    }

    public function testMigrationIsIdempotent(): void
    {
        $pdo = $this->migratedPdo();
        db_migrate($pdo);

        self::assertSame(48, apply_migration_v48($pdo, 48));
        self::assertSame(
            1,
            (int) $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 48')->fetchColumn()
        );
        self::assertSame(
            1,
            (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='idx_ff_form_field_name'")->fetchColumn()
        );
    }
}