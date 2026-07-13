<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Scan la codebase pour détecter les require_once/require/include
 * vers des fichiers qui n'existent pas.
 *
 * Ce test aurait évité les bugs suivants en prod :
 * - lib/render_monitoring_audit.php (MonitoringController)
 * - lib/render_submission_view.php (SubmissionViewController)
 * - lib/render_form_tracking.php (FormTrackingController)
 *
 * Il vérifie TOUTE la codebase src/ + helpers.php + index.php.
 */
final class RequireOnceIntegrityTest extends TestCase
{
    private const SRC_DIR = __DIR__ . '/../../src';
    private const ROOT_DIR = __DIR__ . '/../..';

    /**
     * Fichiers PHP racine à scanner (pas vendor/ ni tests/).
     */
    private static function getScannedFiles(): array
    {
        $files = [];

        foreach (['helpers.php', 'index.php', 'router.php'] as $f) {
            $path = self::ROOT_DIR . '/' . $f;
            if (file_exists($path)) {
                $files[] = $path;
            }
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::SRC_DIR, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Extrait les chemins référencés par require/require_once/include/include_once.
     * Gère les deux cas :
     * - require('chemin/fixe.php')
     * - require_once dirname(__DIR__, 2) . '/lib/quelquechose.php'
     *
     * @return array<int, array{file: string, line: int, referenced: string, type: string, raw: string}>
     */
    private static function extractRequires(array $files): array
    {
        $requires = [];
        // Pattern 1: require('string')
        $simplePattern = '/require(?:_once)?\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/';
        // Pattern 2: require dirname(...) . '/path/file.php' OR require __DIR__ . '/path/file.php'
        $concatPattern = '/require(?:_once)?\s+(?:dirname\([^)]+\)|__DIR__)\s*\.\s*[\'"]([^\'"]+)[\'"]/';

        foreach ($files as $filePath) {
            $content = file_get_contents($filePath);
            if ($content === false) continue;

            $lines = explode("\n", $content);
            foreach ($lines as $lineNum => $line) {
                $trimmed = ltrim($line);

                // Ignorer les commentaires
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                    continue;
                }

                $referenced = null;
                $type = 'require_once';

                if (preg_match($concatPattern, $line, $m)) {
                    $referenced = $m[1];
                    $type = str_contains($line, 'require_once') ? 'require_once' : 'require';
                } elseif (preg_match($simplePattern, $line, $m)) {
                    $referenced = $m[1];
                    $type = (str_contains($line, 'include_once') ? 'include_once'
                          : (str_contains($line, 'include') ? 'include'
                          : (str_contains($line, 'require_once') ? 'require_once'
                          : 'require')));
                }

                if ($referenced !== null) {
                    $requires[] = [
                        'file' => $filePath,
                        'line' => $lineNum + 1,
                        'referenced' => $referenced,
                        'type' => $type,
                        'raw' => trim($line),
                    ];
                }
            }
        }

        return $requires;
    }

    /**
     * Résout le chemin référencé par rapport au fichier qui le référence.
     * Gère __DIR__, dirname(), et les chemins relatifs/absolus.
     */
    private static function resolvePath(string $referencingFile, string $referenced): string
    {
        // Chemin absolu Windows (C:\...) — tel quel
        if (preg_match('/^[A-Z]:\\\\/i', $referenced)) {
            return $referenced;
        }

        // Nettoyer les / et \ initiaux (relatifs au projet, pas absolus système)
        $cleanRef = preg_replace('#^[\\/]+#', '', $referenced);

        // __DIR__ → le répertoire du fichier qui contient le require
        if (str_contains($referenced, '__DIR__')) {
            $dir = dirname($referencingFile);
            $path = str_replace('__DIR__', $dir, $cleanRef);
            $parts = explode(DIRECTORY_SEPARATOR, $path);
            $resolved = [];
            foreach ($parts as $part) {
                if ($part === '.' || $part === '') continue;
                if ($part === '..') { array_pop($resolved); continue; }
                $resolved[] = $part;
            }
            return implode(DIRECTORY_SEPARATOR, $resolved);
        }

        // Chemin relatif : résoudre par rapport au répertoire du fichier
        return dirname($referencingFile) . DIRECTORY_SEPARATOR . $cleanRef;
    }

    /**
     * Vérifie qu'aucun require_once/require/include ne pointe vers un fichier inexistant.
     */
    #[Test]
    public function testAllRequirePathsResolveToExistingFiles(): void
    {
        $files = self::getScannedFiles();
        $this->assertNotEmpty($files, 'Aucun fichier PHP trouvé à scanner');

        $requires = self::extractRequires($files);
        $this->assertNotEmpty($requires, 'Aucun require/include trouvé');

        $missing = [];
        $skipped = 0;

        foreach ($requires as $req) {
            $ref = $req['referenced'];

            // Ignorer les references dynamiques (variables PHP)
            if (str_starts_with($ref, '$') || str_starts_with($ref, '<?')) {
                $skipped++;
                continue;
            }

            // Ignorer vendor/autoload.php
            if (str_contains($ref, 'vendor/autoload.php')) {
                $skipped++;
                continue;
            }

            $resolved = self::resolvePath($req['file'], $ref);

            if (!file_exists($resolved)) {
                $relativeFile = str_replace(self::ROOT_DIR . '/', '', $req['file']);
                $missing[] = sprintf(
                    '  %s:%d → %s (%s) [resolved: %s]',
                    $relativeFile,
                    $req['line'],
                    $ref,
                    $req['type'],
                    $resolved
                );
            }
        }

        $this->assertEmpty(
            $missing,
            count($missing) . ' referenced file(s) by require/include DO NOT EXIST:\n'
            . implode("\n", $missing)
            . '\n\n(' . $skipped . ' dynamic/vendor references skipped)'
        );
    }

    /**
     * Vérifie qu'aucun controller ne référence un fichier lib/ obsolète.
     * Les fichiers migrés vers src/Render/ ne doivent plus être chargés via require_once.
     */
    #[Test]
    public function testNoStaleLibRequireInControllers(): void
    {
        $controllerDir = self::SRC_DIR . '/Controller';
        if (!is_dir($controllerDir)) {
            $this->markTestSkipped('src/Controller/ directory not found');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($controllerDir, \FilesystemIterator::SKIP_DOTS)
        );

        $stale = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') continue;

            $content = file_get_contents($file->getPathname());
            if ($content === false) continue;

            if (preg_match_all('/require(?:_once)?\s*(?:\(|(?:dirname\([^)]+\)|__DIR__)\s*\.\s*)[\'"]?[^)\']*lib\//', $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($m as $match) {
                    $lines = explode("\n", substr($content, 0, $match[0][1]));
                    $lineNum = count($lines);

                    $relativeFile = str_replace(self::ROOT_DIR . '/', '', $file->getPathname());
                    // Extraire le chemin lib/ du match
                    preg_match('/lib\/[^\s\'")]+/', $match[0][0], $pathMatch);
                    $referenced = $pathMatch[0] ?? 'lib/...';

                    $stale[] = sprintf(
                        '  %s:%d → %s',
                        $relativeFile,
                        $lineNum,
                        $referenced
                    );
                }
            }
        }

        $this->assertEmpty(
            $stale,
            count($stale) . ' stale require_once to lib/ in controllers:\n'
            . implode("\n", $stale)
            . '\n\nThese files were migrated to src/Render/. Remove the require_once (autoloader handles it).'
        );
    }
}
