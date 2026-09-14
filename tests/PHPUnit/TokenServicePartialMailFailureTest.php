<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Contract\MailInterface;
use App\Core\Database;
use App\Token\TokenService;
use App\Settings\SettingsService;
use App\Repository\SettingsRepository;
use App\Repository\SubmissionRepository;
use App\Repository\TokenRepository;
use App\Repository\DelegationRepository;
use App\Auth\AuthService;
use App\Audit\AuditLogService;

/**
 * B5 — quand l'action DB réussit mais que le mail échoue, le service doit
 * renvoyer un succès PARTIEL explicite (jamais un succès trompeur).
 */
final class TokenServicePartialMailFailureTest extends TestCase
{
    private Database $db;
    private TokenService $tokenService;
    private string $formId = '';
    private string $stepId = '';
    private string $submissionId = '';
    private string $tokenId = '';
    private string $originalUser = '';
    private bool $seededAdmin = false;

    /** Double de test dont send() échoue toujours. */
    private function makeFailingMailer(): MailInterface
    {
        return new class implements MailInterface {
            public function send(string $to, string $subject, string $body): bool
            {
                return false;
            }

            /** @param array<string, mixed> $submission */
            public function buildValidationEmail(array $submission, string $stepLabel, string $token): string
            {
                return '';
            }

            public function renderEmailTemplate(string $title, string $bodyHtml): string
            {
                return $bodyHtml;
            }
        };
    }

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(Database::class);
        $pdo = $this->db->getPdo();

        // regenerate()/cancel() exigent un admin. En exécution isolée
        // (--filter), 'admin@test.com' n'est pas encore présent dans la base
        // partagée : on le seede comme le fait PersonaServiceTest, sans le
        // supprimer s'il préexistait.
        $exists = $pdo->prepare("SELECT 1 FROM admins WHERE email = 'admin@test.com'");
        $exists->execute();
        if ($exists->fetchColumn() === false) {
            $pdo->prepare("INSERT INTO admins (id, email, added_at) VALUES (?, 'admin@test.com', datetime('now'))")
                ->execute([generate_uuid()]);
            $this->seededAdmin = true;
        }

        $settings = new SettingsService(new SettingsRepository($this->db));
        $auth = new AuthService($this->db);
        $audit = new AuditLogService(new \App\Repository\AuditRepository($this->db));

        $this->tokenService = new TokenService(
            $settings,
            $auth,
            $audit,
            $this->makeFailingMailer(),
            new SubmissionRepository($this->db),
            new TokenRepository($this->db),
            new DelegationRepository($this->db)
        );

        $this->originalUser = $_SERVER['HTTP_X_TEST_USER'] ?? '';
        $_SERVER['HTTP_X_TEST_USER'] = 'admin@test.com';

        $this->formId = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif) VALUES (?, ?, 'B5 Form', '', 1)")
            ->execute([$this->formId, 'b5-' . uniqid()]);
        $this->stepId = generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'Étape B5', 1, 1)")
            ->execute([$this->stepId, $this->formId]);
        $this->submissionId = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at, rgpd_consent) VALUES (?, ?, '{}', 'owner@test.com', 'en_cours', datetime('now'), 1)")
            ->execute([$this->submissionId, $this->formId]);
        $this->tokenId = generate_uuid();
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at, invalidated_at, expires_at) VALUES (?, ?, ?, 'validator@test.com', ?, datetime('now'), NULL, NULL, ?)")
            ->execute([$this->tokenId, $this->submissionId, $this->stepId, generate_token(), gmdate('Y-m-d H:i:s', strtotime('+30 days'))]);
    }

    protected function tearDown(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = $this->originalUser;
        $pdo = $this->db->getPdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->prepare("DELETE FROM delegations WHERE token_id IN (SELECT id FROM tokens WHERE step_id = ?)")->execute([$this->stepId]);
        $pdo->prepare("DELETE FROM tokens WHERE step_id = ?")->execute([$this->stepId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$this->submissionId]);
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$this->stepId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$this->formId]);
        if ($this->seededAdmin) {
            $pdo->prepare("DELETE FROM admins WHERE email = 'admin@test.com'")->execute();
            $this->seededAdmin = false;
        }
    }

    public function testRegenerateReturnsPartialSuccessWhenMailFails(): void
    {
        $result = $this->tokenService->regenerate($this->tokenId);
        self::assertTrue($result['success'], 'L\'action DB (nouveau token) a réussi.');
        self::assertStringContainsString("n'a pas pu être envoyé", $result['message']);
    }

    public function testDelegateReturnsPartialSuccessWhenMailFails(): void
    {
        $result = $this->tokenService->delegate($this->tokenId, 'delegue-' . uniqid() . '@test.com');
        self::assertTrue($result['success'], 'L\'action DB (délégation) a réussi.');
        self::assertStringContainsString("n'a pas pu être envoyé", $result['message']);
    }

    public function testCancelReturnsPartialSuccessWhenMailFails(): void
    {
        $result = $this->tokenService->cancel($this->submissionId, 'admin@test.com');
        self::assertTrue($result['success'], 'L\'action DB (annulation) a réussi.');
        self::assertStringContainsString("n'a pas pu être envoyé", $result['message']);
    }
}