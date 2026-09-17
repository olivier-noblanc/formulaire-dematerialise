<?php
declare(strict_types=1);

namespace App\Tests\Repository;

use App\Core\Database;
use App\Enum\SubmissionStatus;
use App\Repository\FormRepository;
use PHPUnit\Framework\TestCase;

/**
 * TOCTOU (audit 2026-09-17) — handleDeleteForm vérifiait les soumissions en
 * cours AVANT d'ouvrir la transaction de suppression. Une soumission créée
 * entre ce check et le DELETE était supprimée silencieusement avec le
 * formulaire.
 *
 * Le correctif refait le comptage DANS la transaction `BEGIN IMMEDIATE` de
 * FormRepository::deleteCascade() : le verrou d'écriture est tenu dès
 * l'ouverture, donc plus aucun écrivain ne peut insérer entre le comptage et
 * le DELETE. Si une soumission en cours est détectée, la suppression est
 * refusée par rollback (aucune perte).
 *
 * Ces tests tournent sur une base SQLite temporaire isolée (schéma migré).
 * `HookPdo` (défini plus bas) déclenche un callback au moment précis où
 * `BEGIN IMMEDIATE` est émis, ce qui simule de façon déterministe la
 * soumission créée pile dans la fenêtre de course.
 */
final class FormRepositoryDeleteCascadeGuardTest extends TestCase
{
    private string $dbPath;
    private ?string $savedTestDbPath = null;
    private Database $db;
    private FormRepository $repo;

    protected function setUp(): void
    {
        $this->savedTestDbPath = $GLOBALS['_test_db_path'] ?? null;
        $dbPath = tempnam(sys_get_temp_dir(), 'cascade_guard_');
        self::assertNotFalse($dbPath);
        $this->dbPath = $dbPath;
        $GLOBALS['_test_db_path'] = $this->dbPath;

        $this->db = new Database();
        $hookPdo = new HookPdo('sqlite:' . $this->dbPath);
        $this->db->applyConnectionPragmas($hookPdo);
        // Migre le schéma une fois pour toutes avant de swap la connexion.
        \db_migrate($hookPdo);

        $pdoTestProp = new \ReflectionProperty(Database::class, 'pdoTest');
        $pdoTestProp->setValue($this->db, $hookPdo);

        $this->repo = new FormRepository($this->db);
    }

    protected function tearDown(): void
    {
        while ($this->repo->inTransaction()) {
            $this->repo->rollBack();
        }
        $this->db->release();
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if ($this->savedTestDbPath === null) {
            unset($GLOBALS['_test_db_path']);
        } else {
            $GLOBALS['_test_db_path'] = $this->savedTestDbPath;
        }
    }

    /**
     * Course déterministe : le pré-check hors transaction voit 0 soumission,
     * puis une soumission en cours est créée exactement au moment où la
     * transaction IMMEDIATE s'ouvre. Le re-check atomique doit la détecter et
     * refuser la suppression sans rien effacer.
     */
    public function testDeleteCascadeRefusesActiveSubmissionCreatedDuringRaceWindow(): void
    {
        $formId = $this->createForm('race');

        // Pré-check hors transaction (comme handleDeleteForm) : 0 soumission.
        self::assertSame(0, $this->countActive($formId));

        $pdo = $this->db->getPdo();
        self::assertInstanceOf(HookPdo::class, $pdo);

        $raceSubmissionId = '';
        $raceValidatorDataId = '';
        $pdo->onBeginImmediate = function () use ($pdo, $formId, &$raceSubmissionId, &$raceValidatorDataId): void {
            $raceSubmissionId = \generate_uuid();
            $pdo->prepare(
                "INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) "
                . "VALUES (?, ?, '{}', 'race@test.invalid', datetime('now'), ?)"
            )->execute([$raceSubmissionId, $formId, SubmissionStatus::EnCours->value]);

            $raceValidatorDataId = \generate_uuid();
            $pdo->prepare(
                "INSERT INTO submission_validator_data (id, submission_id, field_name, field_label, field_type, value, filled_by, filled_at) "
                . "VALUES (?, ?, 'decision', 'Décision', 'text', 'ok', 'validator', datetime('now'))"
            )->execute([$raceValidatorDataId, $raceSubmissionId]);
        };

        // La soumission est créée pendant l'ouverture de la transaction
        // IMMEDIATE ; le comptage interne doit la voir.
        $blocked = $this->repo->deleteCascade($formId);

        self::assertSame(1, $blocked, 'deleteCascade doit retourner le nombre de soumissions en cours détectées.');
        self::assertNotNull($this->repo->findById($formId), 'le formulaire ne doit pas être supprimé.');
        self::assertSame(1, $this->countSubmissions($formId), 'la soumission créée pendant la course doit survivre.');
        self::assertSame(
            1,
            $this->countValidatorData($raceSubmissionId),
            'TOCTOU : aucune donnée enfant ne doit être perdue (rollback).'
        );
    }

    /**
     * Cas nominal : aucune soumission en cours → suppression en cascade
     * complète (formulaire + soumissions clôturées + données enfants).
     */
    public function testDeleteCascadeRemovesEverythingWhenNoActiveSubmission(): void
    {
        $formId = $this->createForm('nominal');
        $closedId = $this->createSubmission($formId, SubmissionStatus::Valide->value, '2026-01-01 00:00:00');
        $validatorDataId = $this->createValidatorData($closedId);

        $blocked = $this->repo->deleteCascade($formId);

        self::assertSame(0, $blocked, 'aucune soumission en cours → suppression autorisée (0)');
        self::assertNull($this->repo->findById($formId), 'le formulaire doit être supprimé.');
        self::assertSame(0, $this->countSubmissions($formId), 'les soumissions clôturées doivent être supprimées.');
        self::assertSame(0, $this->countValidatorData($closedId), 'les données enfants doivent être supprimées.');
        self::assertSame(0, $this->countValidatorDataById($validatorDataId));
    }

    /**
     * Le re-check protège aussi le cas déjà connu : une soumission en cours
     * présente avant l'appel bloque la suppression sans rien supprimer.
     */
    public function testDeleteCascadeRefusesWhenActiveSubmissionAlreadyExists(): void
    {
        $formId = $this->createForm('existing');
        $activeId = $this->createSubmission($formId, SubmissionStatus::EnCours->value, null);

        $blocked = $this->repo->deleteCascade($formId);

        self::assertSame(1, $blocked);
        self::assertNotNull($this->repo->findById($formId));
        self::assertSame(1, $this->countSubmissions($formId));
        self::assertSame($activeId, (string) ($this->repo->fetchOne('SELECT id FROM submissions WHERE form_id = ?', [$formId])['id'] ?? ''));
    }

    // ── Helpers ───────────────────────────────────────────────

    private function createForm(string $suffix): string
    {
        return $this->repo->create([
            'label' => 'Cascade Guard ' . $suffix,
            'slug' => 'cascade-guard-' . $suffix . '-' . bin2hex(random_bytes(4)),
            'description' => '',
        ]);
    }

    private function createSubmission(string $formId, string $status, ?string $closedAt): string
    {
        $id = \generate_uuid();
        $this->repo->execute(
            "INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, closed_at, status) "
            . "VALUES (?, ?, '{}', 'guard@test.invalid', datetime('now'), ?, ?)",
            [$id, $formId, $closedAt, $status]
        );

        return $id;
    }

    private function createValidatorData(string $submissionId): string
    {
        $id = \generate_uuid();
        $this->repo->execute(
            "INSERT INTO submission_validator_data (id, submission_id, field_name, field_label, field_type, value, filled_by, filled_at) "
            . "VALUES (?, ?, 'decision', 'Décision', 'text', 'ok', 'validator', datetime('now'))",
            [$id, $submissionId]
        );

        return $id;
    }

    private function countActive(string $formId): int
    {
        return (int) ($this->repo->fetchOne(
            'SELECT COUNT(*) AS cnt FROM submissions WHERE form_id = ? AND status = ?',
            [$formId, SubmissionStatus::EnCours->value]
        )['cnt'] ?? 0);
    }

    private function countSubmissions(string $formId): int
    {
        return (int) ($this->repo->fetchOne(
            'SELECT COUNT(*) AS cnt FROM submissions WHERE form_id = ?',
            [$formId]
        )['cnt'] ?? 0);
    }

    private function countValidatorData(string $submissionId): int
    {
        return (int) ($this->repo->fetchOne(
            'SELECT COUNT(*) AS cnt FROM submission_validator_data WHERE submission_id = ?',
            [$submissionId]
        )['cnt'] ?? 0);
    }

    private function countValidatorDataById(string $id): int
    {
        return (int) ($this->repo->fetchOne(
            'SELECT COUNT(*) AS cnt FROM submission_validator_data WHERE id = ?',
            [$id]
        )['cnt'] ?? 0);
    }
}

/**
 * PDO dont le `exec('BEGIN IMMEDIATE')` déclenche un callback unique avant
 * d'ouvrir réellement la transaction. Permet de simuler de façon déterministe
 * un écrivain concurrent qui committe juste avant l'acquisition du verrou.
 */
final class HookPdo extends \PDO
{
    /** @var (\Closure(): void)|null */
    public ?\Closure $onBeginImmediate = null;

    public function exec(string $statement): int|false
    {
        if ($this->onBeginImmediate instanceof \Closure && stripos($statement, 'BEGIN IMMEDIATE') !== false) {
            $callback = $this->onBeginImmediate;
            $this->onBeginImmediate = null;
            $callback();
        }

        return parent::exec($statement);
    }
}