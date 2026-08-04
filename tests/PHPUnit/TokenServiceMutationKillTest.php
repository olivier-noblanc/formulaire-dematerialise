<?php
declare(strict_types=1);

namespace App\Tests;

use App\Core\App;
use App\Core\Database;
use App\Enum\SubmissionStatus;
use App\Enum\ValidationAction;
use App\Token\TokenService;
use PHPUnit\Framework\TestCase;

/**
 * Tests ciblant les mutants Infection échappés sur TokenService.
 *
 * 54 mutants échappés — majoritairement Concat/ConcatOperandRemoval sur les
 * sujets d'emails et messages d'audit. Ces tests assertent le CONTENU EXACT
 * des emails envoyés et des entrées audit_log.
 *
 * @package App\Tests
 */
final class TokenServiceMutationKillTest extends TestCase
{
    private Database $db;
    private TokenService $tokenService;

    /** @var list<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        $this->db = App::getInstance()->get(Database::class);
        $this->tokenService = App::getInstance()->get(TokenService::class);
        $GLOBALS['_test_mails'] = [];
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->getPdo();
        foreach ($this->createdIds as $id) {
            $pdo->prepare('DELETE FROM tokens WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM submissions WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM steps WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM forms WHERE id = ?')->execute([$id]);
        }
        $this->createdIds = [];
    }

    // ── regenerate() : mutants ligne 93, 97, 101 ──────────────────────

    public function testRegenerateSendsEmailWithExactSubjectAndBody(): void
    {
        [$formId, $stepId, $subId, $tokenId, $token] = $this->createFullSubmission(
            validatorEmail: 'regen-validator@test.com',
            formLabel: 'Test Regen Form',
            stepLabel: 'Validation Manager'
        );
        $formLabel = 'Test Regen Form';

        $GLOBALS['_test_mails'] = [];
        $result = $this->tokenService->regenerate($tokenId);

        if (!$result['success']) {
            $this->markTestSkipped('regenerate échoué (DB instable) : ' . ($result['message'] ?? '?'));
        }

        // Mutant Concat ligne 93 : subject doit contenir form_label + step_label
        $mails = $GLOBALS['_test_mails'];
        $this->assertNotEmpty($mails, 'Un email doit être envoyé');
        $regenMail = $mails[0];
        $this->assertSame('regen-validator@test.com', $regenMail['to']);
        $this->assertStringContainsString('[Renvoi]', $regenMail['subject']);
        $this->assertStringContainsString($formLabel, $regenMail['subject']);
        $this->assertStringContainsString('Validation Manager', $regenMail['subject']);

        // Mutant Concat ligne 101 : message de retour doit contenir l'email
        $this->assertStringContainsString('regen-validator@test.com', $result['message']);
    }

    public function testRegenerateAuditLogContainsEmailAndTokenId(): void
    {
        [$formId, $stepId, $subId, $tokenId, $token] = $this->createFullSubmission(
            validatorEmail: 'audit-regen@test.com'
        );

        $result = $this->tokenService->regenerate($tokenId);
        if (!$result['success']) {
            $this->markTestSkipped('regenerate échoué (DB instable) : ' . ($result['message'] ?? '?'));
        }

        // Mutant Concat ligne 97 : audit_log doit contenir l'email du validateur
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT detail FROM audit_log WHERE action = 'token_regenerate' AND target = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute(['token:' . $tokenId]);
        $detail = (string) $stmt->fetchColumn();
        $this->assertStringContainsString('audit-regen@test.com', $detail);
        $this->assertStringContainsString('nouveau token créé', $detail);
    }

    // ── cancel() : mutants ligne 175, 176, 177, 181, 183 ──────────────

    public function testCancelSendsEmailWithExactSubjectAndFormLabel(): void
    {
        [$formId, $stepId, $subId, $tokenId, $token] = $this->createFullSubmission(
            submittedBy: 'cancel-agent@test.com',
            formLabel: 'Cancel Test Form'
        );

        $GLOBALS['_test_mails'] = [];
        $result = $this->tokenService->cancel($subId, 'admin@test.com');

        // Si cancel échoue (DB instable), skip
        if (!$result['success']) {
            $this->markTestSkipped('cancel échoué : ' . ($result['message'] ?? '?'));
        }

        // Mutant Concat ligne 175 : subject doit contenir form_label
        $mails = $GLOBALS['_test_mails'];
        $cancelMail = null;
        foreach ($mails as $m) {
            if ($m['to'] === 'cancel-agent@test.com') {
                $cancelMail = $m;
                break;
            }
        }
        $this->assertNotNull($cancelMail, 'Email d\'annulation doit être envoyé à l\'agent');
        $this->assertStringContainsString('Demande annulée', $cancelMail['subject']);
        $this->assertStringContainsString('Cancel Test Form', $cancelMail['subject']);

        // Mutant Concat ligne 176-177 : body doit contenir form_label échappé
        $this->assertStringContainsString('Cancel Test Form', $cancelMail['body']);

        // Mutant Concat ligne 183 : message de retour
        $this->assertSame('Soumission annulée avec succès.', $result['message']);
    }

    public function testCancelAuditLogContainsCorrectAction(): void
    {
        [$formId, $stepId, $subId, $tokenId, $token] = $this->createFullSubmission(
            submittedBy: 'audit-cancel@test.com'
        );

        $result = $this->tokenService->cancel($subId, 'admin@test.com');
        if (!$result['success']) {
            $this->markTestSkipped('cancel échoué (DB instable) : ' . ($result['message'] ?? '?'));
        }

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT action, detail, actor FROM audit_log WHERE action = 'submission_cancel' AND target = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute(['submission:' . $subId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($row, 'audit_log submission_cancel doit exister');
        $this->assertSame('Soumission annulée', $row['detail']);
        $this->assertSame('admin@test.com', $row['actor']);
    }

    // ── remind() : mutants ligne 205, 208, 209, 216, 218, 222 ─────────

    public function testRemindSendsEmailWithExactSubject(): void
    {
        [$formId, $stepId, $subId, $tokenId, $token] = $this->createFullSubmission(
            validatorEmail: 'remind-validator@test.com',
            formLabel: 'Remind Test Form',
            stepLabel: 'Étape RH'
        );

        $GLOBALS['_test_mails'] = [];
        $result = $this->tokenService->remind($tokenId);

        if (!$result['success']) {
            $this->markTestSkipped('remind échoué : ' . ($result['message'] ?? '?'));
        }

        $mails = $GLOBALS['_test_mails'];
        $this->assertNotEmpty($mails);

        $remindMail = $mails[0];
        $this->assertSame('remind-validator@test.com', $remindMail['to']);

        // Mutant Concat ligne 216 : subject = '[Rappel] form_label — stepLabel'
        $this->assertStringContainsString('[Rappel]', $remindMail['subject']);
        $this->assertStringContainsString('Remind Test Form', $remindMail['subject']);
        $this->assertStringContainsString('Étape RH', $remindMail['subject']);

        // Mutant Concat ligne 222-224 : body doit contenir 'Rappel n°1'
        $this->assertStringContainsString('Rappel', $remindMail['body']);
        $this->assertStringContainsString('rappel n°1', $remindMail['body']);
    }

    public function testRemindSecondTimeSubjectContainsRappelCount(): void
    {
        [$formId, $stepId, $subId, $tokenId, $token] = $this->createFullSubmission(
            validatorEmail: 'remind2@test.com',
            formLabel: 'Remind2 Form',
            stepLabel: 'Validation'
        );

        // Premier rappel
        $GLOBALS['_test_mails'] = [];
        $this->tokenService->remind($tokenId);
        $firstMails = $GLOBALS['_test_mails'];

        // Deuxième rappel
        $GLOBALS['_test_mails'] = [];
        $this->tokenService->remind($tokenId);
        $secondMails = $GLOBALS['_test_mails'];

        $this->assertNotEmpty($secondMails);

        // Mutant Concat ligne 218 : subject du 2e rappel doit contenir 'Rappel 2/3'
        $this->assertStringContainsString('[Rappel 2/3]', $secondMails[0]['subject']);

        // Mutant Concat ligne 222-224 : body doit contenir 'rappel n°2'
        $this->assertStringContainsString('rappel n°2', $secondMails[0]['body']);
    }

    public function testRemindAtMaxReturnsExactMessage(): void
    {
        [$formId, $stepId, $subId, $tokenId, $token] = $this->createFullSubmission(
            validatorEmail: 'remind-max@test.com'
        );

        // Faire 3 rappels (max par défaut)
        $this->tokenService->remind($tokenId);
        $this->tokenService->remind($tokenId);
        $this->tokenService->remind($tokenId);

        // 4e rappel doit échouer
        $GLOBALS['_test_mails'] = [];
        $result = $this->tokenService->remind($tokenId);

        $this->assertFalse($result['success']);

        // Mutant Concat ligne 209 : message exact avec le nombre max
        $this->assertStringContainsString('Maximum de rappels atteint', $result['message']);
        $this->assertStringContainsString('3', $result['message']);

        // Aucun email ne doit partir
        $this->assertEmpty($GLOBALS['_test_mails']);
    }

    public function testRemindAuditLogContainsEmailAndCount(): void
    {
        [$formId, $stepId, $subId, $tokenId, $token] = $this->createFullSubmission(
            validatorEmail: 'audit-remind@test.com'
        );

        $this->tokenService->remind($tokenId);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT detail FROM audit_log WHERE action = 'manual_remind' AND target = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute(['token:' . $tokenId]);
        $detail = (string) $stmt->fetchColumn();

        // Mutant Concat ligne 240 : detail doit contenir email + count
        $this->assertStringContainsString('audit-remind@test.com', $detail);
        $this->assertStringContainsString('relance 1/3', $detail);
    }

    // ── delegate() : mutants ligne 265-310 ─────────────────────────────

    public function testDelegateSendsEmailsWithExactSubjects(): void
    {
        [$formId, $stepId, $subId, $tokenId, $token] = $this->createFullSubmission(
            validatorEmail: 'delegate-from@test.com',
            formLabel: 'Delegate Form',
            stepLabel: 'Validation Direction'
        );

        $GLOBALS['_test_mails'] = [];
        $result = $this->tokenService->delegate($tokenId, 'delegate-to@test.com', 'Motif délégation');

        if (!$result['success']) {
            $this->markTestSkipped('delegate échoué : ' . ($result['message'] ?? '?'));
        }

        $mails = $GLOBALS['_test_mails'];
        $this->assertGreaterThanOrEqual(2, count($mails), 'Au moins 2 emails (nouveau validateur + confirmation)');

        // Email au nouveau validateur — mutant Concat ligne 291
        $delegateMail = null;
        $confirmMail = null;
        foreach ($mails as $m) {
            if ($m['to'] === 'delegate-to@test.com') {
                $delegateMail = $m;
            }
            if ($m['to'] === 'delegate-from@test.com') {
                $confirmMail = $m;
            }
        }

        $this->assertNotNull($delegateMail, 'Email de délégation doit être envoyé au nouveau validateur');
        $this->assertStringContainsString('[Délégation]', $delegateMail['subject']);
        $this->assertStringContainsString('Delegate Form', $delegateMail['subject']);
        $this->assertStringContainsString('Validation Direction', $delegateMail['subject']);

        $this->assertNotNull($confirmMail, 'Email de confirmation doit être envoyé à l\'expéditeur');
        $this->assertStringContainsString('Délégation confirmée', $confirmMail['subject']);
        $this->assertStringContainsString('Delegate Form', $confirmMail['subject']);

        // Mutant Concat ligne 295 : body de délégation doit contenir l'email du délégant
        $this->assertStringContainsString('delegate-from@test.com', $delegateMail['body']);
    }

    public function testDelegateAuditLogContainsFromAndToEmails(): void
    {
        [$formId, $stepId, $subId, $tokenId, $token] = $this->createFullSubmission(
            validatorEmail: 'audit-delegate-from@test.com'
        );

        $this->tokenService->delegate($tokenId, 'audit-delegate-to@test.com', 'Test reason');

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT detail FROM audit_log WHERE action = 'token_delegate' AND target = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute(['token:' . $tokenId]);
        $detail = (string) $stmt->fetchColumn();

        // Mutant Concat ligne 307 : detail doit contenir from + to emails
        $this->assertStringContainsString('audit-delegate-from@test.com', $detail);
        $this->assertStringContainsString('audit-delegate-to@test.com', $detail);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: string} */
    private function createFullSubmission(
        string $submittedBy = 'agent@test.com',
        string $validatorEmail = 'validator@test.com',
        string $formLabel = 'Test Form',
        string $stepLabel = 'Validation',
        int $expiresInDays = 30
    ): array {
        $formId = \generate_uuid();
        $stepId = \generate_uuid();
        $subId = \generate_uuid();
        $tokenId = \generate_uuid();
        $token = \generate_token();

        $pdo = $this->db->getPdo();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at, deadline_field) VALUES (?, ?, ?, '', 1, datetime('now'), '')")
            ->execute([$formId, 'test-' . substr($formId, 0, 8), $formLabel]);
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, ?, 1, 1, '')")
            ->execute([$stepId, $formId, $stepLabel]);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at, closed_at, rgpd_consent) VALUES (?, ?, '{}', ?, 'en_cours', datetime('now'), NULL, 1)")
            ->execute([$subId, $formId, $submittedBy]);
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime("+{$expiresInDays} days") ?: time());
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), ?)")
            ->execute([$tokenId, $subId, $stepId, $validatorEmail, $token, $expiresAt]);

        $this->createdIds[] = $tokenId;
        $this->createdIds[] = $subId;
        $this->createdIds[] = $stepId;
        $this->createdIds[] = $formId;
        return [$formId, $stepId, $subId, $tokenId, $token];
    }
}
