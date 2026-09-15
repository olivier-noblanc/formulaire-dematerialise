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
 * Double de test mail configurable : enregistre les envois réellement effectués
 * et permet de faire échouer soit tous les envois (`failAll`), soit un
 * destinataire précis (`failTo`). Sert à distinguer l'échec du mail principal
 * au délégataire de l'échec du mail de confirmation.
 */
final class TokenServicePartialMailFailureStub implements MailInterface
{
    public bool $failAll = true;
    public ?string $failTo = null;

    /** @var list<array{to: string, subject: string}> */
    public array $sent = [];

    public function send(string $to, string $subject, string $body): bool
    {
        if ($this->failAll || ($this->failTo !== null && strtolower($to) === strtolower($this->failTo))) {
            return false;
        }
        $this->sent[] = ['to' => $to, 'subject' => $subject];
        return true;
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
}

/**
 * B5 — quand l'action DB réussit mais que le mail échoue, le service doit
 * renvoyer un succès PARTIEL explicite (jamais un succès trompeur).
 * Audit Oracle : l'échec du mail principal (délégataire) et celui du mail de
 * confirmation (validateur d'origine) doivent être distingués explicitement.
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
    private TokenServicePartialMailFailureStub $mailer;

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

        // Par défaut tous les envois échouent (comportement B5). Les tests de
        // délégation désactivent `failAll` et ciblent éventuellement `failTo`.
        $this->mailer = new TokenServicePartialMailFailureStub();

        $this->tokenService = new TokenService(
            $settings,
            $auth,
            $audit,
            $this->mailer,
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
        // Tous les envois échouent : la délégation DB reste faite et l'échec du
        // mail principal (délégataire) est signalé explicitement.
        $delegatee = 'delegue-' . uniqid() . '@test.com';
        $result = $this->tokenService->delegate($this->tokenId, $delegatee);
        self::assertTrue($result['success'], 'L\'action DB (délégation) a réussi.');
        self::assertStringContainsString('délégataire', $result['message']);
        self::assertStringContainsString("n'a pas pu être envoyé", $result['message']);

        $detail = $this->lastDelegateAuditDetail();
        self::assertStringContainsString('mail_sent=0', $detail);
        self::assertStringContainsString('confirm_mail_sent=0', $detail);
    }

    public function testDelegatePrimaryMailFailureSignalsDelegateeNotNotified(): void
    {
        // Seul le mail principal (délégataire) échoue ; la confirmation part.
        $delegatee = 'delegue-' . uniqid() . '@test.com';
        $this->mailer->failAll = false;
        $this->mailer->failTo = $delegatee;

        $result = $this->tokenService->delegate($this->tokenId, $delegatee);

        self::assertTrue($result['success'], 'La délégation DB est commitée : pas d\'échec global.');
        self::assertStringContainsString('délégataire', $result['message']);
        self::assertStringContainsString("il ne l'a pas reçu", $result['message']);
        self::assertStringContainsString("n'a pas pu être envoyé", $result['message']);

        // Le délégataire n'a rien reçu, le validateur d'origine a été confirmé.
        self::assertEmpty($this->recipientsFor($delegatee));
        self::assertNotEmpty($this->recipientsFor('validator@test.com'));

        $this->assertDelegationCommitted($this->tokenId, $delegatee);

        $detail = $this->lastDelegateAuditDetail();
        self::assertStringContainsString('mail_sent=0', $detail);
        self::assertStringContainsString('confirm_mail_sent=1', $detail);
    }

    public function testDelegateConfirmationMailFailureSignalsOriginalValidatorNotNotified(): void
    {
        // Le lien part au délégataire, seule la confirmation échoue.
        $delegatee = 'delegue-' . uniqid() . '@test.com';
        $this->mailer->failAll = false;
        $this->mailer->failTo = 'validator@test.com';

        $result = $this->tokenService->delegate($this->tokenId, $delegatee);

        self::assertTrue($result['success'], 'La délégation DB est commitée : pas d\'échec global.');
        self::assertStringContainsString('Un email lui a été envoyé', $result['message']);
        self::assertStringContainsString('confirmation', $result['message']);
        self::assertStringContainsString("n'a pas pu être envoyé", $result['message']);

        self::assertNotEmpty($this->recipientsFor($delegatee));
        self::assertEmpty($this->recipientsFor('validator@test.com'));

        $this->assertDelegationCommitted($this->tokenId, $delegatee);

        $detail = $this->lastDelegateAuditDetail();
        self::assertStringContainsString('mail_sent=1', $detail);
        self::assertStringContainsString('confirm_mail_sent=0', $detail);
    }

    public function testDelegateNominalSuccessNotifiesBothParties(): void
    {
        // Succès nominal : les deux emails partent.
        $delegatee = 'delegue-' . uniqid() . '@test.com';
        $this->mailer->failAll = false;

        $result = $this->tokenService->delegate($this->tokenId, $delegatee);

        self::assertTrue($result['success']);
        self::assertStringContainsString('Un email lui a été envoyé', $result['message']);
        self::assertStringNotContainsString("n'a pas pu être envoyé", $result['message']);
        self::assertStringNotContainsString('Attention', $result['message']);

        self::assertNotEmpty($this->recipientsFor($delegatee));
        self::assertNotEmpty($this->recipientsFor('validator@test.com'));

        $this->assertDelegationCommitted($this->tokenId, $delegatee);

        $detail = $this->lastDelegateAuditDetail();
        self::assertStringContainsString('mail_sent=1', $detail);
        self::assertStringContainsString('confirm_mail_sent=1', $detail);
    }

    public function testCancelReturnsPartialSuccessWhenMailFails(): void
    {
        $result = $this->tokenService->cancel($this->submissionId, 'admin@test.com');
        self::assertTrue($result['success'], 'L\'action DB (annulation) a réussi.');
        self::assertStringContainsString("n'a pas pu être envoyé", $result['message']);
    }

    /**
     * Envois réellement effectués (non échoués) vers un destinataire donné.
     *
     * @return list<string>
     */
    private function recipientsFor(string $email): array
    {
        $recipients = [];
        foreach ($this->mailer->sent as $m) {
            if (strtolower($m['to']) === strtolower($email)) {
                $recipients[] = strtolower($m['to']);
            }
        }
        return $recipients;
    }

    private function assertDelegationCommitted(string $tokenId, string $delegatee): void
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM delegations WHERE token_id = ? AND to_email = ?");
        $stmt->execute([$tokenId, $delegatee]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'La délégation DB doit rester commitée même si un mail échoue.');
    }

    /** Dernier détail d'audit `token_delegate` écrit (vérifie mail_sent=0/1). */
    private function lastDelegateAuditDetail(): string
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->query("SELECT detail FROM audit_log WHERE action = 'token_delegate' ORDER BY rowid DESC LIMIT 1");
        $detail = $stmt !== false ? $stmt->fetchColumn() : false;
        return is_string($detail) ? $detail : '';
    }
}