<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * FIX-A (2026-09-03) — alert_check.php : tokens invalidés (invalidated_at) exclus.
 *
 * Un token invalidé (RGPD, délégation, régénération) n'a plus de validateur
 * actif derrière lui. Sur une soumission en_cours, il ne doit donc :
 *   1. pas compter comme étape en attente (has_incomplete_steps) ;
 *   2. pas recevoir d'alerte (resolve_recipients : validators / admin+validators) ;
 *   3. pas apparaître dans le rendu HTML d'avancement (build_alert_html).
 *
 * Tests comportementaux : alert_check.php est exécuté en subprocess
 * (APP_TEST_MODE=1 → TEST_MODE → DB de test) via un runner temporaire qui
 * dump les mails capturés ($GLOBALS['_test_mails'], cf. MailService::sendDetailed).
 * Pattern subprocess : tests/test_unit_wave5.php §13.3.
 */
final class AlertCheckInvalidatedTokensTest extends TestCase
{
    private const string MARKER = '===FIXA_MAILS===';

    private ?string $formId = null;
    private ?string $stepId = null;
    private ?string $submissionId = null;
    private ?string $ruleId = null;
    private string $formLabel = '';
    private string $emailInvalidated = '';
    private string $emailActive = '';

    protected function tearDown(): void
    {
        $this->cleanupFixture();
    }

    // ── FIX-A.1 : token invalidé non compté dans has_incomplete_steps ──

    public function testInvalidatedTokenNotCountedAsIncompleteSteps(): void
    {
        // Étape 1 : un token déjà validé (actif) + un token invalidé qui
        // ressemble à un pending (done_at NULL). Avant fix, le token invalidé
        // comptait comme pending → alerte envoyée. Après fix : étape complète,
        // aucune alerte.
        $this->seedFixture('fixa_custom_' . uniqid() . '@exemple.invalid', [
            ['email' => $this->newEmail('done'), 'done' => true, 'invalidated' => false],
            ['email' => $this->newEmail('invalide'), 'done' => false, 'invalidated' => true],
        ]);

        $result = $this->runAlertCheck();

        self::assertSame([], $result['recipients'], 'Aucune alerte ne doit partir : le token invalidé ne compte plus comme étape en attente.');
        self::assertSame(0, $this->alertLogCount(), 'alert_log ne doit contenir aucune entrée pour cette règle.');
    }

    // ── FIX-A.2 : destinataires validators — token invalidé exclu ──

    public function testInvalidatedValidatorExcludedFromRecipientList(): void
    {
        $this->seedFixture('validators', [
            ['email' => $this->newEmail('invalide'), 'done' => false, 'invalidated' => true],
            ['email' => $this->newEmail('actif'), 'done' => false, 'invalidated' => false],
        ]);

        $result = $this->runAlertCheck();

        self::assertSame([$this->emailActive], $result['recipients'], 'Seul le validateur actif doit recevoir l\'alerte.');
        self::assertSame(1, $this->alertLogCount(), 'Une seule entrée alert_log (un seul destinataire).');
    }

    // ── FIX-A.3 : destinataires admin+validators — token invalidé exclu ──

    public function testAdminPlusValidatorsExcludesInvalidatedRecipient(): void
    {
        $this->seedFixture('admin+validators', [
            ['email' => $this->newEmail('invalide'), 'done' => false, 'invalidated' => true],
            ['email' => $this->newEmail('actif'), 'done' => false, 'invalidated' => false],
        ]);

        $result = $this->runAlertCheck();

        self::assertContains($this->emailActive, $result['recipients'], 'Le validateur actif doit recevoir l\'alerte.');
        self::assertNotContains($this->emailInvalidated, $result['recipients'], 'Le token invalidé ne doit pas recevoir d\'alerte.');
        self::assertTrue(
            $result['recipients'] !== [] && count($result['recipients']) > 1,
            'Au moins un admin (testeur@e2e.test seedé) doit aussi recevoir l\'alerte.'
        );
    }

    // ── FIX-A.4 : rendu HTML d'avancement cohérent ──

    public function testAlertHtmlExcludesInvalidatedToken(): void
    {
        $this->seedFixture($this->newEmail('custom'), [
            ['email' => $this->newEmail('invalide'), 'done' => false, 'invalidated' => true],
            ['email' => $this->newEmail('actif'), 'done' => false, 'invalidated' => false],
        ]);

        $result = $this->runAlertCheck();

        self::assertCount(1, $result['mails'], 'Une seule alerte (destinataire email custom).');
        $body = (string) $result['mails'][0]['body'];
        self::assertStringContainsString('Avancement :', $body, 'Le rendu doit contenir la ligne Avancement.');
        self::assertStringContainsString('valid&eacute;(s) / 1 total', $body, 'Avancement : 1 token actif total (token invalidé exclu du comptage).');
        self::assertStringNotContainsString('valid&eacute;(s) / 2 total', $body, 'Le token invalidé ne doit pas compter dans le total d\'avancement.');
        self::assertStringContainsString($this->emailActive, $body, 'Le validateur actif apparaît dans le détail des étapes.');
        self::assertStringNotContainsString($this->emailInvalidated, $body, 'Le token invalidé ne doit pas apparaître dans le détail des étapes.');
    }

    // ── FIX-A.5 : soumission dont le seul token est invalidé — étape
    //    toujours incomplète (ordre démarré exclu), alerte part aux admins ──

    public function testSubmissionWithOnlyInvalidatedTokenStillAlertsAdmins(): void
    {
        // Un seul token, invalidé, sur l'étape 1. Après exclusion des tokens
        // invalidés : ordre démarré = 0 < 1 ordre actif → étapes incomplètes
        // → l'alerte part quand même (aux admins, pas au token invalidé).
        // Avant fix, deux comportements fautifs possibles : alerte au token
        // invalidé, ou — si seul le comptage pending était corrigé — étape
        // vue "complète" et alerte perdue.
        $this->seedFixture('admin+validators', [
            ['email' => $this->newEmail('invalide'), 'done' => false, 'invalidated' => true],
        ]);

        $result = $this->runAlertCheck();

        self::assertNotSame([], $result['recipients'], 'L\'alerte doit partir (étape sans validateur actif = incomplète) : au moins un admin.');
        self::assertNotContains($this->emailInvalidated, $result['recipients'], 'Le token invalidé ne doit pas recevoir d\'alerte.');
        self::assertGreaterThanOrEqual(1, $this->alertLogCount(), 'alert_log doit tracer l\'alerte envoyée.');
        foreach ($result['mails'] as $mail) {
            self::assertStringNotContainsString($this->emailInvalidated, (string) $mail['body'], 'Le token invalidé ne doit pas apparaître dans le rendu.');
        }
    }

    // ── Fixtures ───────────────────────────────────────────────────

    /**
     * @param list<array{email: string, done?: bool, invalidated?: bool}> $tokens
     */
    private function seedFixture(string $notifyWho, array $tokens): void
    {
        $pdo = \App\Core\App::db()->getPdo();

        $this->formId = generate_uuid();
        $this->formLabel = 'FIX-A ' . uniqid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, deadline_field) VALUES (?, ?, ?, 'Fixture FIX-A tokens invalidés', 1, 'date_prise_poste')")
            ->execute([$this->formId, 'fixa-' . uniqid(), $this->formLabel]);

        $this->stepId = generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'Étape FIX-A', 1, 1)")
            ->execute([$this->stepId, $this->formId]);

        $this->submissionId = generate_uuid();
        $deadline = new \DateTimeImmutable('+3 days')->format('Y-m-d');
        $data = (string) json_encode(['prenom' => 'Fix', 'nom' => 'Ation', 'date_prise_poste' => $deadline]);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status, rgpd_consent) VALUES (?, ?, ?, ?, datetime('now'), 'en_cours', 1)")
            ->execute([$this->submissionId, $this->formId, $data, $this->newEmail('agent')]);

        $this->ruleId = generate_uuid();
        $pdo->prepare("INSERT INTO alert_rules (id, form_id, days_before, condition_type, notify_who, label, actif) VALUES (?, ?, 4, 'steps_incomplete', ?, 'Fixture FIX-A', 1)")
            ->execute([$this->ruleId, $this->formId, $notifyWho]);

        foreach ($tokens as $t) {
            $pdo->prepare(
                "INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at, invalidated_at, expires_at)
                 VALUES (?, ?, ?, ?, ?, datetime('now'), ?, ?, datetime('now', '+30 days'))"
            )->execute([
                generate_uuid(),
                $this->submissionId,
                $this->stepId,
                (string) ($t['email'] ?? ''),
                generate_token(),
                !empty($t['done']) ? gmdate('Y-m-d H:i:s') : null,
                !empty($t['invalidated']) ? gmdate('Y-m-d H:i:s') : null,
            ]);
        }
    }

    private function newEmail(string $role): string
    {
        $email = 'fixa_' . $role . '_' . uniqid() . '@exemple.invalid';
        if ($role === 'invalide') {
            $this->emailInvalidated = $email;
        } elseif ($role === 'actif') {
            $this->emailActive = $email;
        }
        return $email;
    }

    private function cleanupFixture(): void
    {
        if ($this->formId === null) {
            return;
        }
        $pdo = \App\Core\App::db()->getPdo();
        if ($this->submissionId !== null) {
            $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$this->submissionId]);
            $pdo->prepare("DELETE FROM alert_log WHERE submission_id = ?")->execute([$this->submissionId]);
            $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$this->submissionId]);
        }
        if ($this->ruleId !== null) {
            $pdo->prepare("DELETE FROM alert_rules WHERE id = ?")->execute([$this->ruleId]);
        }
        if ($this->stepId !== null) {
            $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$this->stepId]);
        }
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$this->formId]);
    }

    /**
     * Exécute alert_check.php en subprocess (TEST_MODE → DB test) et retourne
     * les mails capturés filtrés sur la fixture courante + les destinataires.
     *
     * @return array{mails: list<array{to: string, subject: string, body: string}>, recipients: list<string>}
     */
    private function runAlertCheck(): array
    {
        $alertCheckPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'alert_check.php';
        self::assertFileExists($alertCheckPath);

        $runner = (string) tempnam(sys_get_temp_dir(), 'fixa_run_');
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
            // Libérer la connexion PDO du parent avant le subprocess (SQLITE_LOCKED)
            release_pdo();
            $out = shell_exec('env APP_TEST_MODE=1 ' . $phpCmd . ' ' . escapeshellarg($runnerPhp) . ' ' . escapeshellarg($alertCheckPath) . ' 2>&1');
            $out = is_string($out) ? $out : '';

            $pos = strpos($out, self::MARKER);
            self::assertNotFalse($pos, 'Sortie runner inattendue : ' . substr($out, 0, 500));
            $decoded = json_decode(trim(substr($out, $pos + strlen(self::MARKER))), true);
            self::assertTrue(is_array($decoded), 'Dump mails illisible : ' . substr($out, $pos, 300));

            // Ne considérer que les mails de la règle de la fixture (sujet = "[ALERTE] {form_label} — …")
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
            $recipients = array_values(array_map(static fn(array $m): string => $m['to'], $mails));

            return ['mails' => $mails, 'recipients' => $recipients];
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
