<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Core\Database;
use App\Repository\TokenRepository;
use App\Render\SubmissionViewRenderer;

/**
 * Régressions Oracle FIX-B (2026-09-03) — tokens invalidés non affichés ni
 * comptés comme validés dans les vues détail / dashboard / délégation.
 *
 * Sémantique d'écriture (TokenWriteQueriesTrait) :
 * - validation réelle via le lien   : done_at seul, invalidated_at NULL ;
 * - délégation / régénération       : done_at + invalidated_at (faux "done") ;
 * - RGPD / annulation               : invalidated_at seul (faux "pending").
 *
 * Conséquence : tout token avec invalidated_at NOT NULL n'est ni validé ni
 * en attente — il ne doit apparaître dans aucune vue d'état, et son done_at
 * éventuel ne doit jamais être affiché/compté comme une validation.
 *
 * FIX-B couvre :
 * - les requêtes sources TokenReadSubmissionTrait (détail, dashboard, my
 *   submissions, tokens générés) ;
 * - SubmissionViewRenderer::buildValidationHistory() (all_tokens est un
 *   list<array> publiquement affectable — défense en profondeur) ;
 * - les filtres "pending" des templates délégation / relances / actions admin
 *   (defense-in-depth si les templates sont ravivés).
 *
 * Fichier : tests/PHPUnit/TokenInvalidatedDisplayTest.php
 */
final class TokenInvalidatedDisplayTest extends TestCase
{
    private Database $db;
    private TokenRepository $tokenRepo;

    private string $formId;
    private string $stepId;
    private string $submissionId;

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(Database::class);
        $this->tokenRepo = new TokenRepository($this->db);
        $this->seedBase();
    }

    protected function tearDown(): void
    {
        $this->cleanupBase();
    }

    // ── Fixtures ────────────────────────────────────────────────

    private function seedBase(): void
    {
        $pdo = $this->db->getPdo();

        $this->formId = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif) VALUES (?, ?, ?, 'Régressions FIX-B tokens invalidés', 1)")
            ->execute([$this->formId, 'reg-fixb-' . uniqid(), 'Formulaire régression FIX-B']);

        $this->stepId = generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, 'Étape FIX-B', 1, 1)")
            ->execute([$this->stepId, $this->formId]);

        $this->submissionId = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status, rgpd_consent) VALUES (?, ?, '{}', ?, datetime('now'), 'en_cours', 1)")
            ->execute([$this->submissionId, $this->formId, 'owner_' . uniqid() . '@test.com']);
    }

    private function cleanupBase(): void
    {
        $pdo = $this->db->getPdo();
        $pdo->prepare("DELETE FROM delegations WHERE token_id IN (SELECT id FROM tokens WHERE step_id = ?)")
            ->execute([$this->stepId]);
        $pdo->prepare("DELETE FROM tokens WHERE step_id = ?")->execute([$this->stepId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$this->submissionId]);
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$this->stepId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$this->formId]);
    }

    /**
     * Insère un token avec un état arbitraire et retourne son id.
     */
    private function insertToken(
        ?string $doneAt = null,
        ?string $invalidatedAt = null,
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
            $email ?? 'validator_' . uniqid() . '@test.com',
            generate_token(),
            $doneAt,
            $invalidatedAt,
            gmdate('Y-m-d H:i:s', strtotime('+30 days')),
        ]);
        return $id;
    }

    /**
     * Rend un template src/Render/templates/ avec les variables fournies
     * (même mécanique que SubmissionViewRenderer::loadTemplate).
     *
     * @param array<string, mixed> $vars
     */
    private function renderTemplateFile(string $filename, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        /** @var string $result */
        $result = include dirname(__DIR__, 2) . '/src/Render/templates/' . $filename;
        return is_string($result) ? $result : '';
    }

    // ── FIX-B : requêtes sources ────────────────────────────────

    public function testFindDetailedWithStepsBySubmissionExcludesInvalidatedTokens(): void
    {
        // faux "done" (délégation/régénération) + faux "pending" (RGPD)
        $this->insertToken('2026-09-01 10:00:00', '2026-09-01 10:00:00');
        $this->insertToken(null, '2026-09-02 10:00:00');

        $rows = $this->tokenRepo->findDetailedWithStepsBySubmission($this->submissionId);
        self::assertSame([], $rows, 'Un token invalidé (avec ou sans done_at) ne doit pas apparaître dans la vue détaillée.');
    }

    public function testFindDetailedWithStepsBySubmissionKeepsGenuineValidation(): void
    {
        // validation réelle via le lien : done_at seul
        $this->insertToken('2026-09-01 10:00:00');

        $rows = $this->tokenRepo->findDetailedWithStepsBySubmission($this->submissionId);
        self::assertCount(1, $rows, 'Une validation réelle (done_at sans invalidated_at) doit rester visible.');
        self::assertSame('2026-09-01 10:00:00', $rows[0]['done_at']);
    }

    public function testFindBySubmissionIdsExcludesInvalidatedTokens(): void
    {
        $this->insertToken('2026-09-01 10:00:00');              // validation réelle
        $this->insertToken('2026-09-01 11:00:00', '2026-09-01 11:00:00'); // délégué (faux done)
        $this->insertToken(null, '2026-09-02 11:00:00');              // RGPD (faux pending)

        $result = $this->tokenRepo->findBySubmissionIds([$this->submissionId]);
        self::assertArrayHasKey($this->submissionId, $result);
        self::assertCount(1, $result[$this->submissionId], 'Seul le token réellement validé doit être compté/affiché côté dashboard.');

        // cohérence : plus aucun pending pour cette soumission
        $pending = $this->tokenRepo->countPendingBySubmissionIds([$this->submissionId]);
        self::assertArrayNotHasKey($this->submissionId, $pending, 'Un token invalidé ne doit pas compter comme pending.');
    }

    public function testFindWithStepsBySubmissionExcludesInvalidatedTokens(): void
    {
        $this->insertToken('2026-09-01 10:00:00', '2026-09-01 10:00:00');

        $rows = $this->tokenRepo->findWithStepsBySubmission($this->submissionId);
        self::assertSame([], $rows, 'Un token invalidé ne doit pas apparaître dans la liste des tokens générés.');
    }

    // ── FIX-B : historique des validations (renderer) ───────────

    /**
     * @param list<array<string, mixed>> $allTokens
     * @return list<array<string, mixed>>
     */
    private function buildHistory(array $allTokens): array
    {
        // ReflectionMethod::setAccessible() déprécié (no-op depuis PHP 8.1)
        $m = new \ReflectionMethod(SubmissionViewRenderer::class, 'buildValidationHistory');
        /** @var list<array<string, mixed>> $history */
        $history = $m->invoke(new SubmissionViewRenderer(), $allTokens);
        return $history;
    }

    public function testValidationHistoryExcludesInvalidatedTokens(): void
    {
        $history = $this->buildHistory([
            [
                'id' => 't1',
                'email' => 'delegated@test.com',
                'step_label' => 'Étape 1',
                'done_at' => '2026-09-01 10:00:00',
                'invalidated_at' => '2026-09-01 10:00:00', // faux done (délégation/régénération)
            ],
            [
                'id' => 't2',
                'email' => 'genuine@test.com',
                'step_label' => 'Étape 1',
                'done_at' => '2026-09-01 12:00:00',
                'invalidated_at' => null,
            ],
        ]);

        self::assertCount(1, $history, 'Un token invalidé (done_at + invalidated_at) ne doit pas apparaître comme validation dans l\'historique.');
        self::assertSame('genuine@test.com', $history[0]['email']);
    }

    public function testValidationHistorySkipsInvalidatedPendingTokens(): void
    {
        $history = $this->buildHistory([
            [
                'id' => 't3',
                'email' => 'rgpd@test.com',
                'step_label' => 'Étape 2',
                'done_at' => null,
                'invalidated_at' => '2026-09-02 09:00:00',
            ],
        ]);

        self::assertSame([], $history, 'Un token invalidé sans done_at ne doit pas produire d\'entrée d\'historique.');
    }

    // ── FIX-B : templates délégation / actions / relances ───────

    public function testDelegationFormTemplateExcludesInvalidatedTokens(): void
    {
        $validPendingId = $this->insertToken(null, null, 'valid-pending@test.com');
        $this->insertToken(null, '2026-09-02 09:00:00', 'rgpd-pending@test.com');
        $delegatedId = $this->insertToken('2026-09-01 10:00:00', '2026-09-01 10:00:00', 'delegated@test.com');

        $html = $this->renderTemplateFile('renderDelegationForm.php', [
            'status' => 'en_cours',
            'is_admin' => false,
            'user' => 'valid-pending@test.com',
            'all_tokens' => $this->tokenRepo->findWithStepsBySubmission($this->submissionId),
        ]);

        self::assertStringContainsString($validPendingId, $html, 'Le token en attente valide doit être délégable.');
        self::assertStringNotContainsString($delegatedId, $html, 'Un token invalidé (même avec done_at) ne doit pas être délégable.');
    }

    public function testWorkflowActionsTemplateExcludesInvalidatedTokens(): void
    {
        $validPendingId = $this->insertToken(null, null, 'valid-pending@test.com');
        $this->insertToken(null, '2026-09-02 09:00:00', 'rgpd-pending@test.com');
        $this->insertToken('2026-09-01 10:00:00', '2026-09-01 10:00:00', 'delegated@test.com');

        $html = $this->renderTemplateFile('renderWorkflowActions.php', [
            'is_admin' => true,
            'status' => 'en_cours',
            'all_tokens' => $this->tokenRepo->findWithStepsBySubmission($this->submissionId),
        ]);

        self::assertStringContainsString($validPendingId, $html, 'Les actions admin (relancer/régénérer) doivent viser le token valide.');
        self::assertStringNotContainsString('rgpd-pending@test.com', $html, 'Un token invalidé (RGPD) ne doit recevoir aucune action admin.');
    }

    public function testRemindHistoryTemplateExcludesInvalidatedTokens(): void
    {
        $this->insertToken(null, null, 'valid-pending@test.com');
        $this->insertToken(null, '2026-09-02 09:00:00', 'rgpd-pending@test.com');

        $html = $this->renderTemplateFile('renderRemindHistory.php', [
            'pending_with_relance' => [],
            'status' => 'en_cours',
            'is_admin' => false,
            'all_tokens' => $this->tokenRepo->findWithStepsBySubmission($this->submissionId),
            'submission_reminds' => [['detail' => 'Relance envoyée', 'created_at' => '2026-09-01 08:00:00', 'actor' => 'admin@test.com']],
        ]);

        self::assertStringContainsString('valid-pending@test.com', $html, 'Le token en attente valide doit apparaître dans les relances.');
        self::assertStringNotContainsString('rgpd-pending@test.com', $html, 'Un token invalidé (RGPD) ne doit pas apparaître comme en attente de relance.');
    }
}
