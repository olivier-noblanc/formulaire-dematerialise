<?php
declare(strict_types=1);

namespace App\Tests;

use App\Audit\AuditLogService;
use App\Auth\AuthService;
use App\Contract\MailInterface;
use App\Core\Database;
use App\Repository\AuditRepository;
use App\Repository\DelegationRepository;
use App\Repository\SettingsRepository;
use App\Repository\SubmissionRepository;
use App\Repository\TokenRepository;
use App\Settings\SettingsService;
use App\Token\TokenService;
use PHPUnit\Framework\TestCase;

/**
 * R2 (audit 2026-09-14) — chemin manuel TokenService::remind().
 *
 * Vérifie la sémantique claim-first introduite pour tuer la race des rappels :
 * le créneau (relance_count) est revendiqué atomiquement AVANT l'envoi SMTP.
 *   - un rappel réussi a déjà incrémenté relance_count au moment de l'envoi ;
 *   - un envoi échoué libère le créneau revendiqué (relance_count restauré),
 *     pour ne pas compter un rappel non envoyé ni bloquer le suivant.
 *
 * La perte de claim "stale count" (deux workers lisant le même relance_count)
 * est couverte au niveau repository par RelanceClaimTest.
 *
 * Fichier : tests/PHPUnit/TokenServiceRemindClaimTest.php
 */
final class TokenServiceRemindClaimTest extends TestCase
{
    private Database $db;
    private TokenService $tokenService;
    private RemindClaimProbeMailer $mailer;

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
        $auth = new AuthService($this->db);
        $audit = new AuditLogService(new AuditRepository($this->db));

        $this->formId = generate_uuid();
        $this->stepId = generate_uuid();
        $this->submissionId = generate_uuid();
        $this->tokenId = generate_uuid();

        $this->mailer = new RemindClaimProbeMailer($pdo, $this->tokenId);
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
        $_SERVER['HTTP_X_TEST_USER'] = 'testeur@e2e.test';

        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'R2 Claim Form', '', 1, datetime('now'))")
            ->execute([$this->formId, 'r2-claim-' . uniqid()]);
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation', 1, 1, '')")
            ->execute([$this->stepId, $this->formId]);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at, closed_at) VALUES (?, ?, '{}', ?, 'en_cours', datetime('now'), NULL)")
            ->execute([$this->submissionId, $this->formId, 'r2-' . $this->submissionId . '@test.com']);
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at, relance_at, relance_count, invalidated_at, expires_at)
                       VALUES (?, ?, ?, ?, ?, datetime('now'), NULL, NULL, 0, NULL, ?)")
            ->execute([$this->tokenId, $this->submissionId, $this->stepId, 'validator@test.com', generate_token(), gmdate('Y-m-d H:i:s', strtotime('+30 days'))]);
    }

    protected function tearDown(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = $this->originalUser;
        $pdo = $this->db->getPdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->prepare('DELETE FROM tokens WHERE id = ?')->execute([$this->tokenId]);
        $pdo->prepare('DELETE FROM submissions WHERE id = ?')->execute([$this->submissionId]);
        $pdo->prepare('DELETE FROM steps WHERE id = ?')->execute([$this->stepId]);
        $pdo->prepare('DELETE FROM forms WHERE id = ?')->execute([$this->formId]);
    }

    /** @return array{relance_count: int|null, relance_at: string|null} */
    private function readRelanceState(): array
    {
        $stmt = $this->db->getPdo()->prepare('SELECT relance_count, relance_at FROM tokens WHERE id = ?');
        $stmt->execute([$this->tokenId]);
        /** @var array{relance_count: int|string|null, relance_at: string|null}|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            self::fail('token introuvable');
        }
        return [
            'relance_count' => $row['relance_count'] !== null ? (int) $row['relance_count'] : null,
            'relance_at' => $row['relance_at'],
        ];
    }

    public function testRemindClaimsRelanceBeforeSendingMail(): void
    {
        $result = $this->tokenService->remind($this->tokenId);

        self::assertTrue($result['success'], 'Le rappel doit réussir : ' . $result['message']);
        self::assertCount(1, $this->mailer->observed, 'un seul envoi attendu');

        // Au moment de l'envoi, le créneau est DÉJÀ revendiqué : c'est
        // l'invariant anti-doublon (l'ancien code envoyait avant d'incrémenter).
        self::assertSame(1, $this->mailer->observed[0]['relance_count'], 'le créneau doit être revendiqué avant l\'envoi');
        self::assertNotNull($this->mailer->observed[0]['relance_at']);

        $state = $this->readRelanceState();
        self::assertSame(1, $state['relance_count']);
        self::assertNotNull($state['relance_at']);
    }

    public function testRemindSendsTwoSuccessiveRelancesWithIncreasingCount(): void
    {
        self::assertTrue($this->tokenService->remind($this->tokenId)['success']);
        self::assertTrue($this->tokenService->remind($this->tokenId)['success']);

        self::assertSame([1, 2], array_column($this->mailer->observed, 'relance_count'));
        self::assertSame(2, $this->readRelanceState()['relance_count']);
    }

    public function testRemindReleasesClaimWhenMailSendFails(): void
    {
        $this->mailer->fail = true;

        $result = $this->tokenService->remind($this->tokenId);

        self::assertFalse($result['success']);
        self::assertStringContainsString("Erreur lors de l'envoi", $result['message']);

        // Le créneau a bien été revendiqué (count=1 lors de l'envoi)...
        self::assertCount(1, $this->mailer->observed);
        self::assertSame(1, $this->mailer->observed[0]['relance_count']);

        // ... puis libéré : relance_count restauré à 0, relance_at remis à NULL.
        $state = $this->readRelanceState();
        self::assertSame(0, $state['relance_count'], 'un rappel non envoyé ne doit pas être compté');
        self::assertNull($state['relance_at'], 'relance_at doit être restauré sur échec d\'envoi');
    }
}

/**
 * Double de test qui, à chaque send(), capture l'état relance du token tel
 * qu'il est en base au moment de l'envoi — preuve que la revendication CAS a
 * lieu AVANT l'I/O mail. Peut forcer l'échec d'envoi.
 */
final class RemindClaimProbeMailer implements MailInterface
{
    /** @var list<array{relance_count: int|null, relance_at: string|null}> */
    public array $observed = [];

    public bool $fail = false;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $tokenId
    ) {}

    public function send(string $to, string $subject, string $body): bool
    {
        $stmt = $this->pdo->prepare('SELECT relance_count, relance_at FROM tokens WHERE id = ?');
        $stmt->execute([$this->tokenId]);
        /** @var array{relance_count: int|string|null, relance_at: string|null}|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            $row = ['relance_count' => null, 'relance_at' => null];
        }
        $this->observed[] = [
            'relance_count' => $row['relance_count'] !== null ? (int) $row['relance_count'] : null,
            'relance_at' => $row['relance_at'],
        ];
        return !$this->fail;
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