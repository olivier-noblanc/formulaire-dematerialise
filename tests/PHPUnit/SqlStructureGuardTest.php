<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Test de contrôle de `scripts/check_sql_structure.php` (garde CI SQL
 * structurelle). Le script est exécuté sur des fixtures temporaires — aucune
 * base de données, aucun bootstrap applicatif.
 */
final class SqlStructureGuardTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];
    }

    public function testCleanProjectPasses(): void
    {
        $root = $this->createProject([
            'src/Repository/FormFieldsTrait.php' => <<<'PHP'
                <?php
                $pdo->prepare('INSERT INTO form_fields (id, form_id, label) VALUES (?, ?, ?)');
                $pdo->prepare('UPDATE steps SET label = ? WHERE id = ?');
                $pdo->prepare('DELETE FROM step_recipients WHERE id = ?');
                PHP,
            'classes/migrations/v99.php' => <<<'PHP'
                <?php
                $pdo->exec('INSERT INTO form_fields_new (id, form_id, label) SELECT id, form_id, label FROM form_fields');
                PHP,
            'helpers.php' => <<<'PHP'
                <?php
                // SELECT * FROM forms — exemple documenté, doit être ignoré.
                /** INSERT INTO forms VALUES (?) — docblock ignoré. */
                $row = $pdo->query('SELECT id, label FROM forms')->fetch();
                PHP,
        ]);

        $result = $this->runGuard($root);

        self::assertSame(0, $result['code'], $result['output']);
        self::assertStringContainsString('OK garde SQL structurelle : 0 violation', $result['output']);
    }

    public function testViolationsAreReportedWithFileLineAndRule(): void
    {
        $root = $this->createProject([
            'src/Service/ReportService.php' => <<<'PHP'
                <?php
                $all = $pdo->query('SELECT * FROM forms');
                $pdo->prepare('INSERT INTO forms VALUES (?)');
                $pdo->prepare('UPDATE steps SET label = ? WHERE id = ?');
                PHP,
            'classes/migrations/v98.php' => <<<'PHP'
                <?php
                $pdo->exec('SELECT * FROM form_fields');
                PHP,
        ]);

        $result = $this->runGuard($root);

        self::assertSame(1, $result['code'], $result['output']);
        self::assertStringContainsString('src/Service/ReportService.php:2 [select_star]', $result['output']);
        self::assertStringContainsString('src/Service/ReportService.php:3 [insert_no_columns]', $result['output']);
        self::assertStringContainsString('src/Service/ReportService.php:4 [direct_write]', $result['output']);
        self::assertStringContainsString('classes/migrations/v98.php:2 [select_star]', $result['output']);
        self::assertStringContainsString('ÉCHEC garde SQL structurelle : 4 violation(s)', $result['output']);
    }

    public function testInsertWithExplicitColumnsFromAnyApplicationLayerPasses(): void
    {
        $root = $this->createProject([
            'src/Controller/AdminRecipientHandler.php' => <<<'PHP'
                <?php
                $pdo->prepare('INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)');
                PHP,
        ]);

        // INSERT valide (colonnes explicites) mais écriture interdite hors Repository.
        $result = $this->runGuard($root);

        self::assertSame(1, $result['code'], $result['output']);
        self::assertStringContainsString('[direct_write]', $result['output']);
        self::assertStringNotContainsString('[insert_no_columns]', $result['output']);
    }

    /** @param array<string, string> $files */
    private function createProject(array $files): string
    {
        $root = sys_get_temp_dir() . '/sql-guard-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        $this->tempDirs[] = $root;

        foreach ($files as $relative => $content) {
            $path = $root . '/' . $relative;
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($path, $content . "\n");
        }

        return $root;
    }

    /** @return array{code:int, output:string} */
    private function runGuard(string $root): array
    {
        $script = dirname(__DIR__, 2) . '/scripts/check_sql_structure.php';
        self::assertFileExists($script);

        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($script)
            . ' ' . escapeshellarg($root)
            . ' 2>&1';

        $output = [];
        $code = 0;
        exec($command, $output, $code);

        return ['code' => $code, 'output' => implode("\n", $output)];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }
            unlink($item->getPathname());
        }
        rmdir($dir);
    }
}