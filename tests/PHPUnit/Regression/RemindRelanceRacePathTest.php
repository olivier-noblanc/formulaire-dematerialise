<?php
declare(strict_types=1);

namespace App\Tests\Regression;

use PHPUnit\Framework\TestCase;

/**
 * R2 (audit 2026-09-14) — chemin remind.php de bout en bout.
 *
 * Complément du test repository (RelanceClaimTest) : exécute le vrai
 * remind.php en sous-processus (DB de test, mails interceptés) et vérifie que
 * la revendication CAS (TokenRepository::tryClaimRelance) se comporte
 * correctement côté script :
 *   - une relance due est enregistrée exactement une fois (relance_count=1) ;
 *   - une seconde exécution immédiate ne relance pas de nouveau (le CAS +
 *     le délai relance_at empêchent tout doublon) ;
 *   - un token invalidé n'est jamais relancé (filtre invalidated_at).
 *
 * Fichier : tests/PHPUnit/Regression/RemindRelanceRacePathTest.php
 */
final class RemindRelanceRacePathTest extends TestCase
{
    private string $root;
    private \PDO $pdo;
    /** @var array{tokens: string[], submissions: string[], steps: string[], forms: string[]} */
    private array $created = ['tokens' => [], 'submissions' => [], 'steps' => [], 'forms' => []];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
        $dbPath = $this->root . '/db/workflow_test.db';
        if (!is_file($dbPath)) {
            self::markTestSkipped('db/workflow_test.db introuvable (lancer PHPUnit au moins une fois).');
        }
        $this->pdo = new \PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->created = ['tokens' => [], 'submissions' => [], 'steps' => [], 'forms' => []];
    }

    protected function tearDown(): void
    {
        foreach ($this->created['tokens'] as $id) {
            $this->pdo->prepare('DELETE FROM tokens WHERE id = ?')->execute([$id]);
        }
        foreach ($this->created['submissions'] as $id) {
            $this->pdo->prepare('DELETE FROM submissions WHERE id = ?')->execute([$id]);
        }
        foreach ($this->created['steps'] as $id) {
            $this->pdo->prepare('DELETE FROM steps WHERE id = ?')->execute([$id]);
        }
        foreach ($this->created['forms'] as $id) {
            $this->pdo->prepare('DELETE FROM forms WHERE id = ?')->execute([$id]);
        }
    }

    /** Fixture token actif et "dû" (envoyé il y a 49h > délai 48h). */
    private function createDueToken(string $email, ?string $invalidatedAt): string
    {
        $formId = bin2hex(random_bytes(8));
        $stepId = bin2hex(random_bytes(8));
        $subId  = bin2hex(random_bytes(8));
        $tokenId = bin2hex(random_bytes(8));

        $this->pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at, relance_delai_h, relance_max) VALUES (?, ?, 'R2 Test', '', 1, datetime('now'), 48, 3)")
            ->execute([$formId, 'r2-remind-' . $formId]);
        $this->pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation', 1, 1, '')")
            ->execute([$stepId, $formId]);
        $this->pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at, closed_at) VALUES (?, ?, '{}', ?, 'en_cours', datetime('now'), NULL)")
            ->execute([$subId, $formId, 'r2_' . $subId . '@test.com']);
        $this->pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at, relance_at, relance_count, invalidated_at, expires_at)
                             VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, 0, ?, ?)")
            ->execute([
                $tokenId, $subId, $stepId, $email, bin2hex(random_bytes(16)),
                gmdate('Y-m-d H:i:s', strtotime('-49 hours')),
                $invalidatedAt,
                gmdate('Y-m-d H:i:s', strtotime('+7 days')),
            ]);

        $this->created['forms'][] = $formId;
        $this->created['steps'][] = $stepId;
        $this->created['submissions'][] = $subId;
        $this->created['tokens'][] = $tokenId;
        return $tokenId;
    }

    private function runRemind(): string
    {
        $previous = getenv('APP_TEST_MODE');
        putenv('APP_TEST_MODE=1');
        try {
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->root . '/remind.php');
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open($cmd, $descriptors, $pipes, $this->root);
            if (!is_resource($proc)) {
                self::fail('proc_open() a échoué pour lancer remind.php');
            }
            fclose($pipes[0]);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
            self::assertSame(0, $exit, 'remind.php doit sortir en 0. stderr: ' . trim($stderr));
            return $stdout;
        } finally {
            if ($previous === false || $previous === '') {
                putenv('APP_TEST_MODE');
            } else {
                putenv('APP_TEST_MODE=' . $previous);
            }
        }
    }

    /** @return array{relance_count: int|string|null, relance_at: string|null} */
    private function readRelanceState(string $tokenId): array
    {
        $stmt = $this->pdo->prepare('SELECT relance_count, relance_at FROM tokens WHERE id = ?');
        $stmt->execute([$tokenId]);
        /** @var array{relance_count: int|string|null, relance_at: string|null} $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row;
    }

    public function testDueTokenIsRelancedExactlyOnceAcrossTwoRuns(): void
    {
        $email = 'r2-once-' . uniqid() . '@test.com';
        $tokenId = $this->createDueToken($email, null);

        $first = $this->runRemind();
        self::assertStringContainsString('→ ' . $email, $first, 'la relance due doit partir au premier passage');
        $state = $this->readRelanceState($tokenId);
        self::assertSame(1, (int) $state['relance_count']);
        self::assertNotNull($state['relance_at']);

        // Deuxième passage immédiat : aucun doublon (claim CAS + délai).
        $second = $this->runRemind();
        self::assertStringNotContainsString('→ ' . $email, $second, 'une seconde exécution ne doit pas relancer le même token');
        self::assertSame(1, (int) $this->readRelanceState($tokenId)['relance_count']);
    }

    public function testInvalidatedTokenIsNeverRelanced(): void
    {
        $email = 'r2-invalid-' . uniqid() . '@test.com';
        $tokenId = $this->createDueToken($email, gmdate('Y-m-d H:i:s', strtotime('-1 hour')));

        $out = $this->runRemind();

        self::assertStringNotContainsString('→ ' . $email, $out, 'un token invalidé ne doit pas être relancé');
        $state = $this->readRelanceState($tokenId);
        self::assertSame(0, (int) $state['relance_count']);
        self::assertNull($state['relance_at']);
    }
}