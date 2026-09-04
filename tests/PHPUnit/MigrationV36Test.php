<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * P0-7 (2026-09-03) — Migration v36 : marquage schema_version APRÈS validation FK.
 *
 * L'ancien ordre exécutait l'INSERT dans schema_version AVANT de tester le
 * résultat de PRAGMA foreign_key_check : en cas de violation FK, la version
 * 36 était déjà marquée et le RuntimeException remontait ensuite — au
 * prochain appel, apply_migration_v36 voyait `version = 36` et sautait la
 * migration malgré l'état cassé (jamais rejouée).
 *
 * Deux scénarios testés sur une base SQLite fichier temporaire :
 *  - chemin nominal : retour 36, colonnes relance ajoutées avec DEFAULT,
 *    clés settings globales supprimées, version 36 marquée ;
 *  - FK cassée (ligne orpheline dans une table enfant) : RuntimeException
 *    ET version 36 NON marquée (la migration reste à rejouer).
 *
 * Fichier : tests/PHPUnit/MigrationV36Test.php
 */
final class MigrationV36Test extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        // Base fichier (pas :memory:) : v36 lit le chemin via PRAGMA database_list
        // et rouvre une connexion dédiée pour le rebuild.
        $this->dbPath = tempnam(sys_get_temp_dir(), 'v36test_');
        self::assertNotFalse($this->dbPath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    /**
     * Schéma pré-v36 : forms sans colonnes relance, settings globales, tracker de version.
     */
    private function createBaseSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE schema_version (version INTEGER PRIMARY KEY, applied_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec("CREATE TABLE forms (
            id TEXT PRIMARY KEY NOT NULL,
            slug TEXT UNIQUE NOT NULL,
            label TEXT NOT NULL,
            description TEXT,
            actif INTEGER DEFAULT 1 CHECK (actif IN (0, 1)),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            deadline_field TEXT DEFAULT ''
        )");
        $pdo->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)');
        $pdo->exec("INSERT INTO settings (key, value) VALUES ('delai_relance_h', '24'), ('relance_max', '2')");
        $pdo->exec("INSERT INTO forms (id, slug, label) VALUES ('form-1', 'form-1', 'Form 1')");
    }

    private function openPdo(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    public function testHappyPathMarksVersionAndAddsRelanceColumns(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v36.php';
        $pdo = $this->openPdo();
        $this->createBaseSchema($pdo);

        $result = apply_migration_v36($pdo, 35);

        self::assertSame(36, $result);

        $stmt = $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 36');
        $marked = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
        $stmt = null;
        self::assertSame(1, $marked, 'La version 36 doit être marquée après une migration réussie');

        $cols = $pdo->query('PRAGMA table_info(forms)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        self::assertContains('relance_delai_h', $names);
        self::assertContains('relance_max', $names);

        $stmt = $pdo->query("SELECT relance_delai_h, relance_max FROM forms WHERE id = 'form-1'");
        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        $stmt = null;
        self::assertIsArray($row);
        self::assertSame(48, (int) $row['relance_delai_h'], 'Les lignes existantes reçoivent le DEFAULT 48');
        self::assertSame(3, (int) $row['relance_max'], 'Les lignes existantes reçoivent le DEFAULT 3');

        $stmt = $pdo->query("SELECT COUNT(*) FROM settings WHERE key IN ('delai_relance_h', 'relance_max')");
        $leftover = $stmt !== false ? (int) $stmt->fetchColumn() : -1;
        $stmt = null;
        self::assertSame(0, $leftover, 'Les clés settings globales doivent être supprimées');
    }

    public function testFkFailureThrowsAndDoesNotMarkVersion36(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v36.php';
        $pdo = $this->openPdo();
        $this->createBaseSchema($pdo);

        // FK cassée : ligne orpheline dans une table enfant référençant forms(id).
        // Insertion avec foreign_keys OFF (la corruption peut pré-exister).
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('CREATE TABLE child_ref (form_id TEXT REFERENCES forms(id))');
        $pdo->exec("INSERT INTO child_ref (form_id) VALUES ('form-orphan-inexistant')");

        $thrown = false;
        try {
            apply_migration_v36($pdo, 35);
        } catch (\RuntimeException $e) {
            $thrown = str_contains($e->getMessage(), 'FK integrity broken');
        }

        self::assertTrue($thrown, 'La migration doit échouer quand la validation FK détecte une violation');

        // P0-7 : le cœur du correctif — la version ne doit PAS être marquée,
        // sinon la migration sera sautée au prochain run malgré l'état cassé.
        $stmt = $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 36');
        $marked = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
        $stmt = null;
        self::assertSame(
            0,
            $marked,
            'P0-7 : schema_version 36 ne doit pas être marquée quand la validation FK échoue'
        );
    }
}
