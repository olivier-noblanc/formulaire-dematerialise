<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Core\Database;
use App\Token\TokenService;
use App\Repository\TokenRepository;
use App\Settings\SettingsService;
use App\Repository\SettingsRepository;
use App\Auth\AuthService;
use App\Audit\AuditLogService;
use App\Mail\MailService;
use App\Workflow\WorkflowEngine;
use App\Workflow\ConditionEvaluator;
use App\Forms\FieldService;
use App\Render\MyValidationsRenderer;

/**
 * Régressions Oracle P0-3 / P0-4 / S2 / D6 — tokens invalidés et expirés.
 *
 * P0-3 : les lecteurs "pending" (done_at IS NULL) doivent exclure les tokens
 *        invalidés (invalidated_at NOT NULL) — délégation, régénération, RGPD.
 * P0-4 : remind.php / TokenService::remind() ne relancent pas un token expiré ;
 *        les tokens expirés restent visibles dans "Mes validations" (badge Expiré).
 * S2   : les GROUP_CONCAT sources (dones/emails) excluent les tokens invalidés
 *        et rendent explicites les tokens en attente (chaîne vide).
 * D6   : MyValidationsRenderer — une étape partiellement validée (ou invalidée)
 *        n'est jamais rendue "done", et l'étape en attente du validateur non plus.
 */
final class TokenInvalidationRegressionTest extends TestCase
{
    private TokenService $tokenService;
    private TokenRepository $tokenRepo;
    private Database $db;

    private string $formId;
    private string $stepId;
    private string $submissionId;
    private string $validatorEmail;
    private string $ownerEmail;
    private string $originalUser;

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(Database::class);
        $this->tokenRepo = new TokenRepository($this->db);

        $settings = new SettingsService(new SettingsRepository($this->db));
        $auth = new AuthService($this->db);
        $audit = new AuditLogService(new \App\Repository\AuditRepository($this->db));
        $mailer = new MailService(new \App\Repository\MailRepository($this->db), $settings);
        $workflow = new WorkflowEngine(
            $settings,
            $mailer,
            new FieldService(),
            new ConditionEvaluator(),
            new \App\Repository\SubmissionRepository($this->db)
        );
        unset($workflow);

        $this->tokenService = new TokenService(
            $settings,
            $auth,
            $audit,
            $mailer,
            new \App\Repository\SubmissionRepository($this->db)
        );

        $this->originalUser = $_SERVER['HTTP_X_TEST_USER'] ?? '';
        $this->seedBase();
    }

    protected function tearDown(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = $this->originalUser;
        $this->cleanupBase();
    }

    // ── Fixtures ────────────────────────────────────────────────

    private function seedBase(): void
    {
        $pdo = $this->db->getPdo();

        $this->formId = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif) VALUES (?, ?, ?, 'Régressions tokens invalidés/expirés', 1)")
            ->execute([$this->formId, 'reg-token-' . uniqid(), 'Formulaire régression tokens']);

        $this->stepId = generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'Étape régression', 1, 1)")
            ->execute([$this->stepId, $this->formId]);

        $this->ownerEmail = 'owner_' . uniqid() . '@test.com';
        $this->submissionId = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status, rgpd_consent) VALUES (?, ?, '{}', ?, datetime('now'), 'en_cours', 1)")
            ->execute([$this->submissionId, $this->formId, $this->ownerEmail]);

        $this->validatorEmail = 'validator_' . uniqid() . '@test.com';
    }

    private function cleanupBase(): void
    {
        $pdo = $this->db->getPdo();
        // delegations cascade sur tokens, mais on nettoie explicitement par step
        $pdo->prepare("DELETE FROM delegations WHERE token_id IN (SELECT id FROM tokens WHERE step_id = ?)")
            ->execute([$this->stepId]);
        $pdo->prepare("DELETE FROM tokens WHERE step_id = ?")->execute([$this->stepId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$this->submissionId]);
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$this->stepId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$this->formId]);
    }

    /**
     * Insère un token sur la soumission/étape de test et retourne son id.
     */
    private function insertToken(
        ?string $doneAt = null,
        ?string $invalidatedAt = null,
        ?string $expiresAt = null,
        ?string $email = null
    ): string {
        $pdo = $this->db->getPdo();
        $id = generate_uuid();
        $pdo->prepare(
            "INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at, invalidated_at, expires_at)
             VALUES (?, ?, ?, ?, ?, datetime('now'), ?, ?, ?)"
        )->execute([
            $id,
            $this->submissionId,
            $this->stepId,
            $email ?? $this->validatorEmail,
            generate_token(),
            $doneAt,
            $invalidatedAt,
            $expiresAt ?? gmdate('Y-m-d H:i:s', strtotime('+30 days')),
        ]);
        return $id;
    }

    private function pastExpiry(): string
    {
        return gmdate('Y-m-d H:i:s', time() - 3600);
    }

    private function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Carte de token "en attente" au format attendu par MyValidationsRenderer.
     *
     * @return array{token_id: string, token: string, sent_at: string|null, expires_at: string|null, relance_count: int, step_id: string, email: string, step_label: string, ordre: int, submission_id: string, data: string|null, submitted_at: string|null, sub_status: string, form_label: string, form_slug: string}
     */
    private function makePendingCard(?string $expiresAt = null, ?string $stepId = null, int $ordre = 2): array
    {
        return [
            'token_id' => generate_uuid(),
            'token' => generate_token(),
            'sent_at' => $this->nowUtc(),
            'expires_at' => $expiresAt ?? gmdate('Y-m-d H:i:s', strtotime('+30 days')),
            'relance_count' => 0,
            'step_id' => $stepId ?? 'step-pending',
            'email' => 'me@test.com',
            'step_label' => 'Étape en attente',
            'ordre' => $ordre,
            'submission_id' => 'sub-1',
            'data' => '{}',
            'submitted_at' => $this->nowUtc(),
            'sub_status' => 'en_cours',
            'form_label' => 'Formulaire test',
            'form_slug' => 'formulaire-test',
        ];
    }

    /**
     * @param array<string, list<array{submission_id: string, id: string, label: string, ordre: int, dones: string|null}>> $allStepsBySub
     */
    private function renderPending(array $allStepsBySub, array $pendingCards): string
    {
        return MyValidationsRenderer::content(
            $pendingCards,
            [],
            'pending',
            count($pendingCards),
            0,
            '',
            '',
            $allStepsBySub,
            [],
            'me@test.com'
        );
    }

    // ── P0-3 : tokens invalidés exclus des lecteurs pending ─────

    public function testRgpdInvalidatedTokenExcludedFromRemindableIds(): void
    {
        $id = $this->insertToken(invalidatedAt: $this->nowUtc());

        $ids = $this->tokenRepo->findRemindableTokenIds($this->nowUtc());

        self::assertNotContains($id, $ids, 'Un token invalidé (RGPD) ne doit pas être relancé par remind.php.');
    }

    public function testDelegatedTokenExcludedFromRemindableIds(): void
    {
        $oldId = $this->insertToken();
        $result = $this->tokenService->delegate($oldId, 'delegue_' . uniqid() . '@test.com');
        self::assertTrue($result['success'], 'La délégation doit réussir : ' . $result['message']);

        $ids = $this->tokenRepo->findRemindableTokenIds($this->nowUtc());

        self::assertNotContains($oldId, $ids, 'Le token d\'origine délégué ne doit plus être relancé.');
    }

    public function testDelegatedNewTokenStillRemindable(): void
    {
        // Contrôle positif : le nouveau token du délégataire reste relançable.
        $oldId = $this->insertToken();
        $delegatee = 'delegue_' . uniqid() . '@test.com';
        $result = $this->tokenService->delegate($oldId, $delegatee);
        self::assertTrue($result['success'], 'La délégation doit réussir : ' . $result['message']);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT id FROM tokens WHERE submission_id = ? AND email = ? AND done_at IS NULL");
        $stmt->execute([$this->submissionId, $delegatee]);
        $newId = $stmt->fetchColumn();
        self::assertNotFalse($newId, 'Le token du délégataire doit exister.');

        $ids = $this->tokenRepo->findRemindableTokenIds($this->nowUtc());
        self::assertContains((string) $newId, $ids, 'Le token du délégataire reste relançable.');
    }

    public function testRgpdInvalidatedTokenHiddenFromPendingReaders(): void
    {
        $this->insertToken(invalidatedAt: $this->nowUtc());

        $pending = $this->tokenRepo->findPendingByEmail($this->validatorEmail);
        self::assertSame([], $pending, 'Un token invalidé (RGPD) ne doit plus apparaître dans Mes validations.');

        self::assertSame(0, $this->tokenRepo->countPendingForEmail($this->validatorEmail));

        self::assertFalse(
            $this->tokenRepo->hasPendingDuplicate($this->submissionId, $this->stepId, $this->validatorEmail),
            'Un token invalidé (RGPD) ne doit pas bloquer la création d\'un nouveau token.'
        );
    }

    public function testCountPendingExcludesInvalidatedTokens(): void
    {
        $before = $this->tokenRepo->countPending();
        $id = $this->insertToken();

        self::assertSame($before + 1, $this->tokenRepo->countPending(), 'Un token actif compte comme pending.');

        $this->tokenRepo->invalidateActiveByEmail($this->validatorEmail, $this->nowUtc());

        self::assertSame($before, $this->tokenRepo->countPending(), 'Un token invalidé (RGPD) ne doit plus compter comme pending.');
        unset($id);
    }

    public function testValidatorStatsPendingExcludesInvalidatedTokens(): void
    {
        $this->insertToken();
        $this->tokenRepo->invalidateActiveByEmail($this->validatorEmail, $this->nowUtc());

        $stats = $this->tokenRepo->getValidatorStats('en_cours');
        foreach ($stats as $row) {
            if ($row['email'] === $this->validatorEmail) {
                self::assertSame(0, (int) $row['pending'], 'Un token invalidé (RGPD) ne doit pas compter en pending dans les stats.');
                return;
            }
        }
        $this->addToAssertionCount(1); // ligne absente des stats = exclu aussi
    }

    public function testRegenerateRefusesInvalidatedToken(): void
    {
        // Admin seedé par phpunit_bootstrap (INSERT OR IGNORE) — identité
        // déterministe quel que soit l'ordre des tests. NB : 'admin@test.com'
        // n'est admin que par la ligne insérée sans cleanup par
        // PersonaServiceTest::setUp() (pollution d'ordre, à suivre en P0) — ne pas s'y fier.
        $_SERVER['HTTP_X_TEST_USER'] = 'testeur@e2e.test';
        $id = $this->insertToken(invalidatedAt: $this->nowUtc());

        $result = $this->tokenService->regenerate($id);

        self::assertFalse($result['success'], 'Un token invalidé (RGPD) ne doit pas pouvoir être régénéré.');
        self::assertStringContainsString('invalidé', $result['message']);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE step_id = ? AND done_at IS NULL AND invalidated_at IS NULL");
        $stmt->execute([$this->stepId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'Aucun nouveau token actif ne doit être créé.');
    }

    // ── P0-4 : tokens expirés — relance interdite, visibilité conservée ──

    public function testExpiredTokenExcludedFromRemindableIds(): void
    {
        $id = $this->insertToken(expiresAt: $this->pastExpiry());

        $ids = $this->tokenRepo->findRemindableTokenIds($this->nowUtc());

        self::assertNotContains($id, $ids, 'Un token expiré (lien mort) ne doit pas être relancé par remind.php.');
    }

    public function testHealthyTokenStillRemindable(): void
    {
        // Contrôle positif : un token actif et valide reste relançable.
        $id = $this->insertToken();

        $ids = $this->tokenRepo->findRemindableTokenIds($this->nowUtc());

        self::assertContains($id, $ids);
    }

    public function testExpiredTokenStillListedInPendingTab(): void
    {
        $id = $this->insertToken(expiresAt: $this->pastExpiry());

        $pending = $this->tokenRepo->findPendingByEmail($this->validatorEmail);

        $pendingIds = array_column($pending, 'token_id');
        self::assertContains($id, $pendingIds, 'Un token expiré doit rester visible dans Mes validations (badge Expiré, régénération possible).');
    }

    public function testRemindRefusesExpiredToken(): void
    {
        $id = $this->insertToken(expiresAt: $this->pastExpiry());

        $result = $this->tokenService->remind($id);

        self::assertFalse($result['success'], 'Pas de rappel manuel sur un token expiré.');
        self::assertStringContainsString('expiré', $result['message']);
    }

    public function testRemindRefusesInvalidatedToken(): void
    {
        $id = $this->insertToken(invalidatedAt: $this->nowUtc());

        $result = $this->tokenService->remind($id);

        self::assertFalse($result['success'], 'Pas de rappel manuel sur un token invalidé.');
        self::assertStringContainsString('invalidé', $result['message']);
    }

    public function testRemindSucceedsForHealthyPendingToken(): void
    {
        // Contrôle positif : le rappel manuel fonctionne toujours sur un token sain.
        $id = $this->insertToken();

        $result = $this->tokenService->remind($id);

        self::assertTrue($result['success'], 'Le rappel doit réussir sur un token actif et valide : ' . $result['message']);
    }

    // ── S2 : source GROUP_CONCAT — tokens invalidés exclus ──────

    public function testFindStepsBySubmissionIdsDonesIgnoresInvalidatedTokens(): void
    {
        $oldId = $this->insertToken();
        $result = $this->tokenService->delegate($oldId, 'delegue_' . uniqid() . '@test.com');
        self::assertTrue($result['success'], 'La délégation doit réussir : ' . $result['message']);

        $steps = $this->tokenRepo->findStepsBySubmissionIds([$this->submissionId]);
        $dones = $steps[$this->submissionId][0]['dones'] ?? null;

        self::assertSame(
            '',
            (string) $dones,
            'dones ne doit pas contenir le done_at du token invalidé par la délégation (étape toujours en attente).'
        );
    }

    public function testFindStepsBySubmissionIdsDonesMarksPendingExplicitly(): void
    {
        // 2 recipients sur la même étape : l'un validé, l'autre en attente.
        $doneAt = $this->nowUtc();
        $this->insertToken(doneAt: $doneAt);
        $this->insertToken(email: 'autre_' . uniqid() . '@test.com');

        $steps = $this->tokenRepo->findStepsBySubmissionIds([$this->submissionId]);
        $dones = (string) ($steps[$this->submissionId][0]['dones'] ?? '');
        $parts = explode('|', $dones);

        self::assertContains($doneAt, $parts, 'Le token validé apparaît dans dones.');
        self::assertContains('', $parts, 'Le token en attente doit apparaître explicitement (chaîne vide) pour que l\'étape ne soit pas "done".');
    }

    public function testGetWorkflowStepsWithTokensExcludesInvalidatedTokens(): void
    {
        $oldId = $this->insertToken();
        $delegatee = 'delegue_' . uniqid() . '@test.com';
        $result = $this->tokenService->delegate($oldId, $delegatee);
        self::assertTrue($result['success'], 'La délégation doit réussir : ' . $result['message']);

        $rows = \App\Core\App::getInstance()->get(\App\Repository\FormRepository::class)->getWorkflowStepsWithTokens(
            $this->formId,
            $this->submissionId
        );

        self::assertNotSame([], $rows);
        $row = $rows[0];
        self::assertSame(
            '',
            (string) $row['dones'],
            'dones ne doit pas inclure le token invalidé par la délégation.'
        );
        self::assertSame(
            $delegatee,
            (string) $row['emails'],
            'emails ne doit lister que les tokens actifs (non invalidés) de l\'étape.'
        );
    }

    // ── D6 : garde MyValidationsRenderer ────────────────────────

    public function testRendererPartialStepNotDone(): void
    {
        $allStepsBySub = [
            'sub-1' => [
                ['submission_id' => 'sub-1', 'id' => 'step-1', 'label' => 'Étape 1', 'ordre' => 1, 'dones' => '2026-01-01 10:00:00|'],
                ['submission_id' => 'sub-1', 'id' => 'step-2', 'label' => 'Étape 2', 'ordre' => 2, 'dones' => null],
            ],
        ];
        $html = $this->renderPending($allStepsBySub, [$this->makePendingCard(stepId: 'step-2')]);

        self::assertStringNotContainsString('wf-step-done', $html, 'Une étape partiellement validée ne doit pas être rendue "done".');
        self::assertStringContainsString('wf-step-current', $html);
    }

    public function testRendererOwnPendingStepNeverDone(): void
    {
        // Garde : même si dones prétend tout validé, l'étape en attente du
        // validateur lui-même ne doit jamais être rendue "done".
        $allStepsBySub = [
            'sub-1' => [
                ['submission_id' => 'sub-1', 'id' => 'step-pending', 'label' => 'Étape en attente', 'ordre' => 2, 'dones' => '2026-01-01 10:00:00'],
            ],
        ];
        $html = $this->renderPending($allStepsBySub, [$this->makePendingCard(stepId: 'step-pending')]);

        self::assertStringNotContainsString('wf-step-done', $html, 'L\'étape du token en attente du validateur ne doit jamais être "done".');
        self::assertStringContainsString('wf-step-current', $html);
    }

    public function testRendererFullyDoneStepShownDone(): void
    {
        // Contrôle positif : une étape avec tous ses tokens validés est "done".
        $allStepsBySub = [
            'sub-1' => [
                ['submission_id' => 'sub-1', 'id' => 'step-1', 'label' => 'Étape 1', 'ordre' => 1, 'dones' => '2026-01-01 10:00:00|2026-01-02 10:00:00'],
                ['submission_id' => 'sub-1', 'id' => 'step-2', 'label' => 'Étape 2', 'ordre' => 2, 'dones' => null],
            ],
        ];
        $html = $this->renderPending($allStepsBySub, [$this->makePendingCard(stepId: 'step-2')]);

        self::assertStringContainsString('wf-step-done', $html);
    }

    public function testRendererExpiredCardShowsExpiredState(): void
    {
        $html = $this->renderPending([], [$this->makePendingCard(expiresAt: gmdate('Y-m-d H:i:s', time() - 3600))]);

        self::assertStringContainsString('expired-badge', $html, 'Le badge Expiré doit être rendu pour un token expiré.');
        self::assertStringContainsString('contactez un administrateur', $html);
        self::assertStringNotContainsString('Valider / Refuser', $html, 'Pas d\'action de validation sur un token expiré.');
    }

    public function testRendererActiveCardOffersValidate(): void
    {
        $html = $this->renderPending([], [$this->makePendingCard()]);

        self::assertStringContainsString('Valider / Refuser', $html, 'Un token actif doit proposer la validation.');
        self::assertStringNotContainsString('expired-badge', $html);
    }
}
