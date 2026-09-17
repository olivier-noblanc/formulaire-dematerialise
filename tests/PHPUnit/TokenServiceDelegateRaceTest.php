<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Core\Database;
use App\Token\TokenService;
use App\Settings\SettingsService;
use App\Repository\SettingsRepository;
use App\Repository\SubmissionRepository;
use App\Repository\AuditRepository;
use App\Repository\MailRepository;
use App\Auth\AuthService;
use App\Audit\AuditLogService;
use App\Mail\MailService;

/**
 * Course dans TokenService::delegate() : un token actif pour l'email cible peut
 * apparaître APRÈS la vérification hasPendingDuplicate() mais AVANT l'INSERT.
 * L'index unique partiel idx_tokens_active_per_step_email (v37) fait alors
 * échouer l'INSERT en 23000. Le service doit convertir cette violation en une
 * erreur métier propre (« déjà actif »), pas laisser remonter la PDOException.
 *
 * La fenêtre de course est reproduite de façon déterministe par un trigger
 * SQLite qui insère le token concurrent au moment où delegate() invalide le
 * token d'origine (UPDATE) — soit exactement ce qu'un second process ferait
 * entre la lecture hasPendingDuplicate() et l'INSERT.
 */
final class TokenServiceDelegateRaceTest extends TestCase
{
    private Database $db;
    private TokenService $tokenService;
    private string $formId = '';
    private string $stepId = '';
    private string $submissionId = '';
    private string $tokenId = '';
    private string $originalUser = '';

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(Database::class);
        $pdo = $this->db->getPdo();

        $settings = new SettingsService(new SettingsRepository($this->db));
        $this->tokenService = new TokenService(
            $settings,
            new AuthService($this->db),
            new AuditLogService(new AuditRepository($this->db)),
            new MailService(new MailRepository($this->db), $settings),
            new SubmissionRepository($this->db)
        );

        $this->originalUser = $_SERVER['HTTP_X_TEST_USER'] ?? '';

        $this->formId = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif) VALUES (?, ?, 'Race Form', '', 1)")
            ->execute([$this->formId, 'race-' . uniqid()]);
        $this->stepId = generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'Validation', 1, 1)")
            ->execute([$this->stepId, $this->formId]);
        $this->submissionId = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status, rgpd_consent) VALUES (?, ?, '{}', ?, datetime('now'), 'en_cours', 1)")
            ->execute([$this->submissionId, $this->formId, 'owner-' . uniqid() . '@test.com']);
        $this->tokenId = generate_uuid();
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), ?)")
            ->execute([
                $this->tokenId,
                $this->submissionId,
                $this->stepId,
                'orig-' . uniqid() . '@test.com',
                generate_token(),
                gmdate('Y-m-d H:i:s', strtotime('+30 days')),
            ]);
    }

    protected function tearDown(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = $this->originalUser;
        $pdo = $this->db->getPdo();
        $pdo->exec('DROP TRIGGER IF EXISTS test_delegate_race');
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->prepare("DELETE FROM delegations WHERE token_id IN (SELECT id FROM tokens WHERE submission_id = ?)")->execute([$this->submissionId]);
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$this->submissionId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$this->submissionId]);
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$this->stepId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$this->formId]);
    }

    public function testDelegateReturnsCleanErrorWhenDuplicateAppearsAfterCheck(): void
    {
        $pdo = $this->db->getPdo();
        $targetEmail = 'race-' . uniqid() . '@test.com';
        $concurrentTokenId = generate_uuid();
        $concurrentToken = generate_token();

        $pdo->exec(
            "CREATE TRIGGER test_delegate_race AFTER UPDATE ON tokens
             WHEN NEW.id = '" . $this->tokenId . "'
             BEGIN
                 INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at)
                 VALUES ('" . $concurrentTokenId . "', '" . $this->submissionId . "', '" . $this->stepId . "',
                         '" . $targetEmail . "', '" . $concurrentToken . "', datetime('now'), datetime('now', '+30 days'));
             END"
        );

        try {
            $result = $this->tokenService->delegate($this->tokenId, $targetEmail);
        } finally {
            $pdo->exec('DROP TRIGGER IF EXISTS test_delegate_race');
        }

        self::assertFalse($result['success'], 'La course doit produire un échec propre, pas une exception non gérée.');
        self::assertStringContainsString('déjà actif', $result['message']);

        // Rollback : le token d'origine redevient actif (non invalidé).
        $stmt = $pdo->prepare('SELECT invalidated_at FROM tokens WHERE id = ?');
        $stmt->execute([$this->tokenId]);
        self::assertNull($stmt->fetchColumn(), 'Le rollback doit restaurer le token d\'origine.');
    }
}