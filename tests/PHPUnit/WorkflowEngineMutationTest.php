<?php

declare(strict_types=1);

namespace App\Tests;

use App\Core\App;
use App\Core\Database;
use App\Enum\SubmissionStatus;
use App\Enum\ValidationAction;
use App\Forms\FieldService;
use App\Mail\MailService;
use App\Repository\MailRepository;
use App\Repository\SettingsRepository;
use App\Repository\SubmissionRepository;
use App\Settings\SettingsService;
use App\Workflow\ConditionEvaluator;
use App\Workflow\WorkflowEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests ciblés sur les 8 mutants Infection critiques identifiés sur WorkflowEngine.
 *
 * Référence : worklog.md → section `infection-mutants` (Top 10, mutants #1 à #8).
 *
 * Patterns utilisés (documentés dans le rapport précédent) :
 *  - Pattern A : capture de $GLOBALS['_test_mails'] (mutants #6 et #7).
 *  - Pattern B : assertion sur $pdo->inTransaction() après chemins d'erreur (mutants #3, #4, #5).
 *  - Pattern C : assertSame sur comptes exacts de tokens (mutants #1 et #2).
 *  - Pattern E : pré-remplir la DB pour chemins "déjà peuplés" (mutants #1 et #8 —
 *    ce dernier nécessite de contourner le fallback case-insensitive qui masque la mutation).
 *
 * @package App\Tests
 */
final class WorkflowEngineMutationTest extends TestCase
{
    private Database $db;
    private WorkflowEngine $workflow;

    /** @var array{forms: string[], steps: string[], step_recipients: string[], submissions: string[], tokens: string[], form_owners: string[]} */
    private array $createdIds = [
        'forms' => [], 'steps' => [], 'step_recipients' => [],
        'submissions' => [], 'tokens' => [], 'form_owners' => [],
    ];

    protected function setUp(): void
    {
        $this->db = App::getInstance()->get(Database::class);
        $settings = new SettingsService(new SettingsRepository($this->db));
        $mail = new MailService(new MailRepository($this->db), $settings);
        $fields = new FieldService($this->db);
        $conditions = new ConditionEvaluator();
        $this->workflow = new WorkflowEngine(
            $this->db,
            $settings,
            $mail,
            $fields,
            $conditions,
            new SubmissionRepository($this->db),
        );
        // Pattern A — reset test email queue before each test
        $GLOBALS['_test_mails'] = [];
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->getPdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($this->createdIds['tokens'] as $id) {
            try { $pdo->prepare('DELETE FROM tokens WHERE id = ?')->execute([$id]); } catch (\Throwable) {}
        }
        foreach ($this->createdIds['step_recipients'] as $id) {
            try { $pdo->prepare('DELETE FROM step_recipients WHERE id = ?')->execute([$id]); } catch (\Throwable) {}
        }
        foreach ($this->createdIds['submissions'] as $id) {
            try { $pdo->prepare('DELETE FROM submissions WHERE id = ?')->execute([$id]); } catch (\Throwable) {}
        }
        foreach ($this->createdIds['form_owners'] as $id) {
            try { $pdo->prepare('DELETE FROM form_owners WHERE id = ?')->execute([$id]); } catch (\Throwable) {}
        }
        foreach ($this->createdIds['steps'] as $id) {
            try { $pdo->prepare('DELETE FROM steps WHERE id = ?')->execute([$id]); } catch (\Throwable) {}
        }
        foreach ($this->createdIds['forms'] as $id) {
            try { $pdo->prepare('DELETE FROM forms WHERE id = ?')->execute([$id]); } catch (\Throwable) {}
        }
        $GLOBALS['_test_mails'] = [];
    }

    // ── Mutant #1 — WorkflowEngine.php:388 ArrayItemRemoval sur $dupCheck->execute() ──

    /**
     * Mutant #1 : ArrayItemRemoval retire $submissionId des paramètres de
     * `$dupCheck->execute([$submissionId, $step['step_id'], $rawEmail])`.
     * Le SQL attend 3 placeholders ; avec 2 paramètres, PDO lance une
     * PDOException (ERRMODE_EXCEPTION), attrapée par le catch (\Throwable)
     * de advanceWorkflow → rollback + re-throw → aucun token créé.
     *
     * Ce test crée 2 step_recipients (emails distincts) sur la même étape et
     * vérifie qu'un token est créé pour chaque recipient. Sur le code muté,
     * advanceWorkflow jette une exception ou ne crée aucun token.
     */
    public function testAdvanceWorkflowWithMultipleRecipientsOnSameStepCreatesTokenForEach(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $pdo = $this->db->getPdo();

        // Ajoute un 2e recipient sur la même étape (le 1er 'validator@test.com' est déjà créé par createTestForm)
        $sr2 = \generate_uuid();
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, 'second.mutant@test.com')")
            ->execute([$sr2, $stepId]);
        $this->createdIds['step_recipients'][] = $sr2;

        $subId = $this->createTestSubmission($formId);
        $this->workflow->advanceWorkflow($subId);

        // Vérifie qu'un token a été créé pour chaque recipient distinct.
        // Pattern C : assertSame (pas assertGreaterThanOrEqual) pour tuer
        // tout mutant qui crasherait silencieusement le createTokensForGroup.
        $count = $pdo->prepare(
            "SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND email IN ('validator@test.com', 'second.mutant@test.com')"
        );
        $count->execute([$subId]);
        self::assertSame(
            2,
            (int) $count->fetchColumn(),
            'Mutant #1 ArrayItemRemoval: chaque recipient doit recevoir son token (dupCheck doit recevoir ses 3 params).'
        );
    }

    // ── Mutant #2 — WorkflowEngine.php:304 IfNegation sur `if ($tokenCreated)` ──

    /**
     * Mutant #2 : IfNegation transforme `if ($tokenCreated)` en
     * `if (!$tokenCreated)`. Sur le chemin normal (tokens créés), le mutant
     * skip le `commit + return` et tombe dans le bloc de fallback qui log
     * `workflow_stalled` (audit_log) puis return. Résultat observable :
     *  - Original : aucun audit_log "workflow_stalled" créé (tokens créés normalement)
     *  - Muté     : audit_log "workflow_stalled" créé ALORS QUE des tokens existent
     *
     * Ce test crée 2 groupes (étapes d'ordre 1 et 2) et vérifie :
     *  - que seul le groupe 1 a un token (comportement nominal)
     *  - qu'AUCUN audit_log "workflow_stalled" n'a été créé pour cette soumission
     *    (l'assertion qui tue réellement le mutant — sans elle, le mutant reste
     *    "escaped" car il return au même endroit que l'original).
     */
    public function testAdvanceWorkflowStopsAfterFirstGroupWithTokensCreated(): void
    {
        [$formId, $step1Id] = $this->createTestForm();
        $pdo = $this->db->getPdo();

        // 2e étape, ordre 2, recipient distinct → groupe indépendant
        $step2Id = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation 2', 2, 1, '')")
            ->execute([$step2Id, $formId]);
        $this->createdIds['steps'][] = $step2Id;
        $sr2 = \generate_uuid();
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, 'groupe2.mutant@test.com')")
            ->execute([$sr2, $step2Id]);
        $this->createdIds['step_recipients'][] = $sr2;

        $subId = $this->createTestSubmission($formId);

        // 1er appel : doit créer UNIQUEMENT les tokens du groupe 1, pas du groupe 2
        $this->workflow->advanceWorkflow($subId);

        $countG2 = $pdo->prepare('SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND step_id = ?');
        $countG2->execute([$subId, $step2Id]);
        self::assertSame(
            0,
            (int) $countG2->fetchColumn(),
            'Mutant #2 IfNegation: aucun token ne doit être créé pour le groupe 2 tant que le groupe 1 n\'est pas validé.'
        );

        $countG1 = $pdo->prepare('SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND step_id = ?');
        $countG1->execute([$subId, $step1Id]);
        self::assertSame(
            1,
            (int) $countG1->fetchColumn(),
            'Mutant #2 IfNegation: un token doit être créé pour le groupe 1 (return après création).'
        );

        // Assertion qui tue réellement le mutant : aucun audit_log "workflow_stalled"
        // ne doit exister pour cette soumission (les tokens du groupe 1 ont bien été créés).
        // Sur code muté, le fallback "Si aucun token créé" s'exécute et log workflow_stalled.
        $auditStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE action = 'workflow_stalled' AND target = ?");
        $auditStmt->execute(['submission:' . $subId]);
        self::assertSame(
            0,
            (int) $auditStmt->fetchColumn(),
            'Mutant #2 IfNegation: aucun audit_log workflow_stalled ne doit être créé quand des tokens ont effectivement été créés pour le groupe 1.'
        );
    }

    // ── Mutant #3 — WorkflowEngine.php:313 MethodCallRemoval sur `$pdo->commit()` ──

    /**
     * Mutant #3 : MethodCallRemoval supprime l'appel `$pdo->commit()` quand
     * `isGroupComplete` retourne false. La transaction reste ouverte.
     *
     * Pattern B : assertion sur `! inTransaction()` après retour de
     * advanceWorkflow. Sur code muté, la transaction reste ouverte.
     */
    public function testAdvanceWorkflowCommitsTransactionWhenGroupIncomplete(): void
    {
        // Scénario : un groupe avec un token done_at=NULL (isGroupComplete=false)
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        $this->createTestToken($subId, $stepId); // done_at = NULL → groupe incomplet

        $pdo = $this->db->getPdo();
        while ($pdo->inTransaction()) {
            $pdo->rollBack(); // état propre avant l'appel
        }

        $this->workflow->advanceWorkflow($subId);

        self::assertFalse(
            $pdo->inTransaction(),
            'Mutant #3 MethodCallRemoval: advanceWorkflow doit committer (et fermer la transaction) quand le groupe est incomplet.'
        );
    }

    // ── Mutant #4 — WorkflowEngine.php:513 MethodCallRemoval sur `$pdo->rollBack()` (token inconnu) ──

    /**
     * Mutant #4 : MethodCallRemoval supprime `$pdo->rollBack()` quand le token
     * est introuvable. La transaction reste ouverte → lock SQLite exclusif
     * maintenu → deadlock potentiel en production.
     */
    public function testValidateTokenWithUnknownTokenRollsBackTransaction(): void
    {
        $pdo = $this->db->getPdo();
        while ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // Token inexistant (64 hex chars valides mais absent de la DB)
        $result = $this->workflow->validateToken(str_repeat('a', 64));

        self::assertSame('invalid', $result['status']);
        self::assertFalse(
            $pdo->inTransaction(),
            'Mutant #4 MethodCallRemoval: validateToken doit rollback (et fermer la transaction) quand le token est introuvable.'
        );
    }

    // ── Mutant #5 — WorkflowEngine.php:536 MethodCallRemoval sur `$pdo->rollBack()` (token expiré) ──

    /**
     * Mutant #5 : idem mutant #4 mais sur le chemin "token expiré".
     */
    public function testValidateTokenWithExpiredTokenRollsBackTransaction(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId, expiresInOffset: '-1 day');

        $pdo = $this->db->getPdo();
        while ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $result = $this->workflow->validateToken($tokenVal);

        self::assertSame('expired', $result['status']);
        self::assertFalse(
            $pdo->inTransaction(),
            'Mutant #5 MethodCallRemoval: validateToken doit rollback quand le token est expiré.'
        );
    }

    // ── Mutant #6 — WorkflowEngine.php:594 Identical `===` → `!==` ──

    /**
     * Mutant #6 : Identical inverse la comparaison `$action === Refuser->value`.
     * Sur action='valider', le mutant envoie le mail "Demande refusée" à
     * l'agent (alors que la demande est validée) ET n'appelle pas advanceWorkflow.
     *
     * Test A (valider) : aucun mail "Demande refusée" ne doit partir, ET
     * le workflow doit avancer (advanceWorkflow appelé → si étape unique,
     * soumission clôturée à 'valide').
     */
    public function testValidateTokenValiderDoesNotSendRefusedEmailAndAdvances(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $this->workflow->validateToken($tokenVal, ValidationAction::Valider->value, 'OK');

        // Aucun mail "Demande refusée" ne doit partir sur une validation
        foreach ($GLOBALS['_test_mails'] ?? [] as $mail) {
            self::assertStringNotContainsString(
                'Demande refusée',
                $mail['subject'],
                'Mutant #6 Identical: aucun mail de refus ne doit partir sur action=valider.'
            );
        }

        // Sur une étape unique, valider → clôture à 'valide' (advanceWorkflow appelé)
        $check = $this->db->getPdo()->prepare('SELECT status FROM submissions WHERE id = ?');
        $check->execute([$subId]);
        self::assertSame(
            SubmissionStatus::Valide->value,
            $check->fetchColumn(),
            'Mutant #6 Identical: valider doit faire avancer le workflow jusqu\'à clôture (pas de blocage).'
        );
    }

    /**
     * Mutant #6 (suite) : sur action='refuser', le mutant appellerait
     * advanceWorkflow (au lieu d'envoyer le mail de refus). Résultat : la
     * soumission serait marquée 'valide' au lieu de 'refuse', et des tokens
     * seraient créés pour l'étape suivante.
     *
     * Test B (refuser) : statut doit être 'refuse' + aucun token pour étape 2.
     */
    public function testValidateTokenRefuserDoesNotAdvanceWorkflow(): void
    {
        [$formId, $step1Id] = $this->createTestForm();
        $pdo = $this->db->getPdo();

        // 2e étape d'ordre 2 — ne doit pas être activée par refuser
        $step2Id = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Etape 2', 2, 1, '')")
            ->execute([$step2Id, $formId]);
        $this->createdIds['steps'][] = $step2Id;
        $sr2 = \generate_uuid();
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, 'etape2.refuser@test.com')")
            ->execute([$sr2, $step2Id]);
        $this->createdIds['step_recipients'][] = $sr2;

        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $step1Id);

        $this->workflow->validateToken($tokenVal, ValidationAction::Refuser->value, 'Motif');

        // Statut doit être 'refuse' (pas 'valide' ni 'en_cours')
        $check = $pdo->prepare('SELECT status FROM submissions WHERE id = ?');
        $check->execute([$subId]);
        self::assertSame(
            SubmissionStatus::Refuse->value,
            $check->fetchColumn(),
            'Mutant #6 Identical: refuser doit marquer la soumission comme refuse, pas valide.'
        );

        // Aucun token ne doit être créé pour l'étape 2 (pas d'advanceWorkflow sur refuser)
        $count2 = $pdo->prepare('SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND step_id = ?');
        $count2->execute([$subId, $step2Id]);
        self::assertSame(
            0,
            (int) $count2->fetchColumn(),
            'Mutant #6 Identical: refuser ne doit pas déclencher advanceWorkflow (pas de token pour étape 2).'
        );
    }

    // ── Mutant #7 — WorkflowEngine.php:601 MethodCallRemoval sur `$this->mailService->send()` ──

    /**
     * Mutant #7 : MethodCallRemoval supprime l'envoi du mail "Demande refusée"
     * à l'agent. L'agent n'est plus notifié du refus → mauvaise UX.
     *
     * Pattern A : capture dans $GLOBALS['_test_mails'] et assertion sur
     * sujet + body + destinataire.
     */
    public function testValidateTokenRefuserSendsRefusedEmailToAgent(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        // createTestSubmission met submitted_by='agent@test.com' par défaut
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $this->workflow->validateToken($tokenVal, ValidationAction::Refuser->value, 'Motif détaillé mutant #7');

        // Un mail "Demande refusée" doit être envoyé à l'agent avec le motif dans le body
        $found = false;
        foreach ($GLOBALS['_test_mails'] ?? [] as $mail) {
            if (($mail['to'] ?? '') === 'agent@test.com'
                && str_contains($mail['subject'] ?? '', 'Demande refusée')
                && str_contains($mail['body'] ?? '', 'Motif détaillé mutant #7')) {
                $found = true;
                break;
            }
        }
        self::assertTrue(
            $found,
            'Mutant #7 MethodCallRemoval: un mail "Demande refusée" avec le motif doit être envoyé à l\'agent.'
        );
    }

    // ── Mutant #8 — WorkflowEngine.php:216 LogicalNot `!empty()` → `empty()` ──

    /**
     * Mutant #8 : LogicalNot transforme `!empty($formData[$fieldName])` en
     * `empty($formData[$fieldName])`. Le premier `if (isset && !empty)` ne
     * s'exécute plus → le fallback `foreach ($formData as $key => $val)` qui
     * matche case-insensitive prend le relais.
     *
     * Pour tuer ce mutant, il faut un jeu de données où le premier `if` et le
     * fallback produisent des résultats DIFFÉRENTS. C'est le cas quand le
     * formData contient 2 clés différant uniquement par la casse, et où la
     * clé exacte (lowercase) a été insérée APRÈS la clé case-variant :
     *  - Code original : premier `if` accède à `$formData['email']` (accès
     *    direct par clé exacte) → retourne la valeur de la clé exacte.
     *  - Code muté : premier `if` skipé, le fallback foreach itère et
     *    retourne la première clé dont strtolower matche — soit la clé
     *    case-variant insérée en premier.
     *
     * Sans cette astuce d'insertion ordonnée, le fallback masque la mutation
     * et le mutant reste "escaped".
     */
    public function testResolveDynamicRecipientPrefersExactCaseKeyOverCaseInsensitiveFallback(): void
    {
        // Insertion ordonnée : 'Email' (case-variant) en premier, 'email' (exact) en second.
        // PHP préserve l'ordre d'insertion dans les arrays associatifs.
        $formData = [
            'Email' => 'fallback.mutant8@example.com',
            'email' => 'expected.exact@example.com',
        ];

        $resolved = $this->workflow->resolveDynamicRecipient('{{email}}', $formData);

        self::assertSame(
            'expected.exact@example.com',
            $resolved,
            'Mutant #8 LogicalNot: le premier if (isset && !empty) doit résoudre via la clé exact-case (email), pas via le fallback case-insensitive (Email).'
        );
    }

    /**
     * Mutant #8 (variante end-to-end) : vérifie qu'un recipient dynamique
     * `{{manager_email}}` est bien résolu quand le formData contient la clé
     * exacte. Ce test complète le précédent en exerçant le chemin via
     * advanceWorkflow (création effective d'un token pour l'email résolu).
     */
    public function testAdvanceWorkflowResolvesDynamicRecipientField(): void
    {
        $pdo = $this->db->getPdo();
        $formId = \generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'Dyn Recip Mutant8', 'test', 1, datetime('now'))")
            ->execute([$formId, 'dyn-mutant8-' . uniqid()]);
        $this->createdIds['forms'][] = $formId;

        $stepId = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation Manager', 1, 1, '')")
            ->execute([$stepId, $formId]);
        $this->createdIds['steps'][] = $stepId;

        $srId = \generate_uuid();
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, '{{manager_email}}')")
            ->execute([$srId, $stepId]);
        $this->createdIds['step_recipients'][] = $srId;

        $resolvedEmail = 'manager.mutant8.' . uniqid() . '@test.com';
        $subId = $this->createTestSubmission(
            $formId,
            json_encode(['manager_email' => $resolvedEmail], JSON_UNESCAPED_UNICODE),
        );

        $this->workflow->advanceWorkflow($subId);

        // Un token doit avoir été créé avec l'email résolu (pas le placeholder littéral)
        $check = $pdo->prepare('SELECT email FROM tokens WHERE submission_id = ? AND step_id = ?');
        $check->execute([$subId, $stepId]);
        $tokenEmail = $check->fetchColumn();
        self::assertNotFalse(
            $tokenEmail,
            'Mutant #8: un token doit être créé pour le recipient dynamique résolu.'
        );
        self::assertSame(
            $resolvedEmail,
            $tokenEmail,
            'Mutant #8: l\'email du token doit être la valeur résolue (pas le placeholder {{manager_email}}).'
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** Crée un form + étape + recipient. Retourne [formId, stepId]. */
    private function createTestForm(string $slug = 'mut'): array
    {
        $pdo = $this->db->getPdo();
        $formId = \generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, '', 1, datetime('now'))")
            ->execute([$formId, $slug . '-' . uniqid(), 'Test Mutant Form']);
        $this->createdIds['forms'][] = $formId;

        $stepId = \generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation', 1, 1, '')")
            ->execute([$stepId, $formId]);
        $this->createdIds['steps'][] = $stepId;

        $srId = \generate_uuid();
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, 'validator@test.com')")
            ->execute([$srId, $stepId]);
        $this->createdIds['step_recipients'][] = $srId;

        return [$formId, $stepId];
    }

    /** Crée une soumission. Retourne submissionId. */
    private function createTestSubmission(string $formId, string $data = '{}', string $status = 'en_cours'): string
    {
        $pdo = $this->db->getPdo();
        $subId = \generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at, closed_at) VALUES (?, ?, ?, 'agent@test.com', ?, datetime('now'), NULL)")
            ->execute([$subId, $formId, $data, $status]);
        $this->createdIds['submissions'][] = $subId;
        return $subId;
    }

    /**
     * Crée un token. Retourne [tokenId, tokenValue].
     * $doneAtOffset : null = pending ; strtotime offset = done.
     * $expiresInOffset : strtotime offset depuis maintenant.
     */
    private function createTestToken(
        string $submissionId,
        string $stepId,
        string $email = 'validator@test.com',
        ?string $doneAtOffset = null,
        string $expiresInOffset = '+7 days',
    ): array {
        $pdo = $this->db->getPdo();
        $tokenId = \generate_uuid();
        $tokenVal = bin2hex(random_bytes(32));
        $doneAt = $doneAtOffset !== null ? gmdate('Y-m-d H:i:s', strtotime($doneAtOffset) ?: time()) : null;
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime($expiresInOffset) ?: time());
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), ?, ?)")
            ->execute([$tokenId, $submissionId, $stepId, $email, $tokenVal, $doneAt, $expiresAt]);
        $this->createdIds['tokens'][] = $tokenId;
        return [$tokenId, $tokenVal];
    }
}
