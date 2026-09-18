<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * P2-D (2026-09-18) — alert_check.php : revendication atomique AVANT l'envoi.
 *
 * L'ancien schéma « SELECT de dédoublonnage → envoi email → INSERT alert_log »
 * laissait une fenêtre de course : deux exécutions concurrentes du cron
 * (Task Scheduler + lazy_cron) lisaient toutes deux « aucune alerte
 * aujourd'hui », envoyaient chacune l'email, puis inséraient chacune leur
 * ligne — doublon d'alerte.
 *
 * Correctif : la claim est un INSERT ... SELECT gardé par WHERE NOT EXISTS
 * (dédoublonnage par jour civil de Paris, borne DateHelper::parisDayStartUtc) ;
 * la perdante obtient rowCount() = 0 et s'abstient. En cas d'échec d'envoi,
 * le contrat MailService/outbox (write-ahead) décide de la libération :
 *  - `error`  : réessayable, persisté/rejouable par l'outbox → claim CONSERVÉE
 *               (sinon le rejeu + le run suivant enverraient deux emails) ;
 *  - `blocked`: refus définitif, jamais rejoué → claim LIBÉRÉE pour qu'un run
 *               ultérieur retente.
 *
 * Fichier : tests/PHPUnit/AlertCheckAtomicClaimTest.php
 */
final class AlertCheckAtomicClaimTest extends TestCase
{
    private const string MARKER = '===P2D_MAILS===';

    private ?string $formId = null;
    private ?string $stepId = null;
    private ?string $submissionId = null;
    private ?string $ruleId = null;
    private string $formLabel = '';
    private string $recipient = '';

    protected function tearDown(): void
    {
        $this->cleanupFixture();
    }

    // ── 1. Source : claim atomique, ancien SELECT de dédoublonnage supprimé ──

    public function testSourceClaimsAtomicallyBeforeSend(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/alert_check.php');

        self::assertStringContainsString(
            'WHERE NOT EXISTS',
            $source,
            'La revendication doit être un INSERT ... SELECT WHERE NOT EXISTS (claim atomique).'
        );
        self::assertStringContainsString(
            'rowCount()',
            $source,
            'L\'exécutant doit tester rowCount() pour savoir s\'il a remporté la claim (0 = déjà alerté).'
        );
        self::assertStringNotContainsString(
            'SELECT COUNT(*) FROM alert_log',
            $source,
            'L\'ancien SELECT de dédoublonnage non atomique (SELECT puis INSERT) doit disparaître.'
        );
    }

    // ── 2. Sémantique SQL : deux claims le même jour → une seule gagne ──

    public function testAtomicClaimSqlAllowsOnlyOneWinnerSameDay(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'p2dclaim_');
        self::assertNotFalse($dbPath);
        try {
            $pdo = new \PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE alert_log (id TEXT PRIMARY KEY, rule_id TEXT, submission_id TEXT, sent_at DATETIME, message TEXT)');

            $sql = "INSERT INTO alert_log (id, rule_id, submission_id, sent_at, message)
                    SELECT ?, ?, ?, datetime('now'), ?
                    WHERE NOT EXISTS (
                        SELECT 1 FROM alert_log
                        WHERE rule_id = ? AND submission_id = ? AND sent_at >= ?
                    )";
            $boundary = gmdate('Y-m-d H:i:s', time() - 3600); // borne du jour en cours

            $stmt = $pdo->prepare($sql);
            $stmt->execute(['claim-1', 'r1', 's1', 'm1', 'r1', 's1', $boundary]);
            $firstRowCount = $stmt->rowCount();
            $stmt->execute(['claim-2', 'r1', 's1', 'm2', 'r1', 's1', $boundary]);
            $secondRowCount = $stmt->rowCount();
            $stmt = null;

            self::assertSame(1, $firstRowCount, 'La première revendication doit gagner (1 ligne insérée).');
            self::assertSame(0, $secondRowCount, 'La seconde revendication le même jour doit perdre (0 ligne insérée).');

            $count = $pdo->query('SELECT COUNT(*) FROM alert_log')->fetchColumn();
            self::assertSame(1, (int) $count, 'Une seule ligne de claim pour la journée, malgré deux tentatives.');
        } finally {
            if (is_file($dbPath)) {
                @unlink($dbPath);
            }
        }
    }

    // ── 3. Comportement : double exécution le même jour → un seul envoi ──

    public function testSecondRunSameDaySendsNoSecondMail(): void
    {
        $this->seedFixture();

        $first = $this->runAlertCheck();
        self::assertCount(1, $first['mails'], 'Première exécution : un email d\'alerte envoyé.');

        // Seconde exécution, même jour, même base : la claim atomique doit
        // faire échouer le dédoublonnage → aucun second envoi.
        $second = $this->runAlertCheck();
        self::assertCount(0, $second['mails'], 'Seconde exécution le même jour : aucun second email (claim atomique).');

        self::assertSame(1, $this->alertLogCount(), 'Une seule claim alert_log pour la journée.');
    }

    // ── Fixtures ────────────────────────────────────────────────────

    private function seedFixture(): void
    {
        $pdo = \App\Core\App::db()->getPdo();

        $this->formId = generate_uuid();
        $this->formLabel = 'P2D ' . uniqid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, deadline_field) VALUES (?, ?, ?, 'Fixture P2-D claim atomique', 1, 'date_prise_poste')")
            ->execute([$this->formId, 'p2d-' . uniqid(), $this->formLabel]);

        $this->stepId = generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'Étape P2-D', 1, 1)")
            ->execute([$this->stepId, $this->formId]);

        $this->submissionId = generate_uuid();
        $deadline = new \DateTimeImmutable('+3 days')->format('Y-m-d');
        $data = (string) json_encode(['prenom' => 'P2', 'nom' => 'D', 'date_prise_poste' => $deadline]);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status, rgpd_consent) VALUES (?, ?, ?, ?, datetime('now'), 'en_cours', 1)")
            ->execute([$this->submissionId, $this->formId, $data, 'p2d_agent_' . uniqid() . '@exemple.invalid']);

        $this->recipient = 'p2d_dest_' . uniqid() . '@exemple.invalid';
        $this->ruleId = generate_uuid();
        $pdo->prepare("INSERT INTO alert_rules (id, form_id, days_before, condition_type, notify_who, label, actif) VALUES (?, ?, 4, 'steps_incomplete', ?, 'Fixture P2-D', 1)")
            ->execute([$this->ruleId, $this->formId, $this->recipient]);

        // Un token actif non traité → étape incomplète → alerte due.
        $pdo->prepare(
            "INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at, invalidated_at, expires_at)
             VALUES (?, ?, ?, ?, ?, datetime('now'), NULL, NULL, datetime('now', '+30 days'))"
        )->execute([
            generate_uuid(),
            $this->submissionId,
            $this->stepId,
            'p2d_validator_' . uniqid() . '@exemple.invalid',
            generate_token(),
        ]);
    }

    private function cleanupFixture(): void
    {
        if ($this->formId === null) {
            return;
        }
        $pdo = \App\Core\App::db()->getPdo();
        if ($this->submissionId !== null) {
            $pdo->prepare('DELETE FROM tokens WHERE submission_id = ?')->execute([$this->submissionId]);
            $pdo->prepare('DELETE FROM alert_log WHERE submission_id = ?')->execute([$this->submissionId]);
            $pdo->prepare('DELETE FROM submissions WHERE id = ?')->execute([$this->submissionId]);
        }
        if ($this->ruleId !== null) {
            $pdo->prepare('DELETE FROM alert_rules WHERE id = ?')->execute([$this->ruleId]);
        }
        if ($this->stepId !== null) {
            $pdo->prepare('DELETE FROM steps WHERE id = ?')->execute([$this->stepId]);
        }
        $pdo->prepare('DELETE FROM forms WHERE id = ?')->execute([$this->formId]);
    }

    /**
     * Exécute alert_check.php en subprocess (APP_TEST_MODE=1 → DB de test) et
     * retourne les mails capturés pour la fixture courante.
     *
     * @return array{mails: list<array{to: string, subject: string, body: string}>}
     */
    private function runAlertCheck(): array
    {
        $alertCheckPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'alert_check.php';
        self::assertFileExists($alertCheckPath);

        $runner = (string) tempnam(sys_get_temp_dir(), 'p2d_run_');
        $runnerPhp = $runner . '.php';
        self::assertTrue(rename($runner, $runnerPhp));
        file_put_contents($runnerPhp, '<?php
require $argv[1];
echo "\n' . self::MARKER . '" . json_encode($GLOBALS["_test_mails"] ?? [], JSON_UNESCAPED_UNICODE);
');

        try {
            $sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-sessions';
            if (!is_dir($sessionDir)) {
                @mkdir($sessionDir, 0777, true);
            }
            $ini = php_ini_loaded_file();
            $phpCmd = PHP_BINARY
                . (is_string($ini) && $ini !== '' ? ' -c ' . escapeshellarg($ini) : '')
                . ' -d session.save_path=' . escapeshellarg($sessionDir);
            // Libérer la connexion PDO du parent avant le subprocess (SQLITE_LOCKED).
            release_pdo();
            $out = shell_exec('env APP_TEST_MODE=1 ' . $phpCmd . ' ' . escapeshellarg($runnerPhp) . ' ' . escapeshellarg($alertCheckPath) . ' 2>&1');
            $out = is_string($out) ? $out : '';

            $pos = strpos($out, self::MARKER);
            self::assertNotFalse($pos, 'Sortie runner inattendue : ' . substr($out, 0, 500));
            $decoded = json_decode(trim(substr($out, $pos + strlen(self::MARKER))), true);
            self::assertTrue(is_array($decoded), 'Dump mails illisible : ' . substr($out, $pos, 300));

            $mails = [];
            foreach ($decoded as $mail) {
                if (is_array($mail) && isset($mail['subject']) && is_string($mail['subject'])
                    && str_contains($mail['subject'], $this->formLabel)) {
                    $mails[] = [
                        'to' => (string) ($mail['to'] ?? ''),
                        'subject' => $mail['subject'],
                        'body' => (string) ($mail['body'] ?? ''),
                    ];
                }
            }

            return ['mails' => $mails];
        } finally {
            if (is_file($runnerPhp)) {
                @unlink($runnerPhp);
            }
            if (is_file($runner)) {
                @unlink($runner);
            }
        }
    }

    private function alertLogCount(): int
    {
        if ($this->ruleId === null) {
            return 0;
        }
        $pdo = \App\Core\App::db()->getPdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM alert_log WHERE rule_id = ?');
        $stmt->execute([$this->ruleId]);
        return (int) $stmt->fetchColumn();
    }
}