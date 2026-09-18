<?php
declare(strict_types=1);

namespace App\Controller {
    /**
     * Override file_exists for BackupController tests. When the global flag
     * $_test_force_db_missing is set, returns false for the configured DB_PATH
     * (simulating a missing database file for the download_backup error branch).
     * All other paths fall back to the built-in file_exists.
     */
    function file_exists(string $filename): bool
    {
        if (!empty($GLOBALS['_test_force_db_missing'])) {
            $dbPath = defined('DB_PATH') ? DB_PATH : DEFAULT_DB_PATH;
            if ($filename === $dbPath) {
                return false;
            }
        }
        return \file_exists($filename);
    }

    /**
     * Override filesize for BackupController tests — when DB_PATH is "missing",
     * filesize() would emit a deprecation warning on PHP 8.4+ and return false.
     * Return 0 in that case to avoid noisy test output.
     */
    function filesize(string $filename): int|false
    {
        if (!empty($GLOBALS['_test_force_db_missing'])) {
            $dbPath = defined('DB_PATH') ? DB_PATH : DEFAULT_DB_PATH;
            if ($filename === $dbPath) {
                return 0;
            }
        }
        return \filesize($filename);
    }

    /**
     * Override move_uploaded_file for BackupController tests. In CLI there is no
     * real HTTP upload, so the built-in always returns false. When the global
     * flag $_test_force_move_uploaded is set, perform a real rename to simulate
     * a successful upload. At that exact instant (right after the swap):
     *   - $_test_plant_foreign_wal plants a valid -wal coming from another DB —
     *     SQLite would silently apply that foreign journal over the restored DB
     *     if it is not removed;
     *   - $_test_corrupt_after_move overwrites the destination with an unreadable
     *     file — simulates a write error that only materialises after the pivot
     *     pre-check passed on the source file, so the post-move sanity check
     *     triggers the rollback (F4).
     *   - $_test_empty_after_move truncates the destination to 0 byte — SQLite
     *     treats an empty file as a valid empty database (COUNT sqlite_master = 0,
     *     no exception), so a sanity check limited to readability would report a
     *     false success. The pivot-table check must reject it and roll back.
     *   - $_test_swap_after_move replaces the destination with ANOTHER valid
     *     CircuitDémat database after the rename. The pivot-table check would
     *     accept it (all pivot tables present), so only the SHA-256 identity
     *     check (before/after move) can detect the substitution.
     */
    function move_uploaded_file(string $from, string $to): bool
    {
        if (empty($GLOBALS['_test_force_move_uploaded'])) {
            return \move_uploaded_file($from, $to);
        }
        $ok = \rename($from, $to);
        if ($ok && !empty($GLOBALS['_test_corrupt_after_move'])) {
            \file_put_contents($to, "SQLite format 3\0" . str_repeat('X', 256));
        }
        if ($ok && !empty($GLOBALS['_test_empty_after_move'])) {
            \file_put_contents($to, '');
        }
        if ($ok && !empty($GLOBALS['_test_swap_after_move'])) {
            \copy($GLOBALS['_test_swap_after_move'], $to);
        }
        if ($ok && !empty($GLOBALS['_test_plant_foreign_wal'])) {
            \file_put_contents($to . '-wal', $GLOBALS['_test_plant_foreign_wal']);
        }
        return $ok;
    }

    /**
     * Override unlink for BackupController tests. When $_test_force_unlink_fail
     * is set, every deletion in the App\Controller namespace fails silently —
     * a portable simulation of a locked sidecar (Windows keeps an open handle,
     * Linux would honour the unlink). BackupController::removeWalSidecars() must
     * therefore re-check survivors and report failure instead of claiming success.
     */
    function unlink(string $filename): bool
    {
        if (!empty($GLOBALS['_test_force_unlink_fail'])) {
            return false;
        }
        return \unlink($filename);
    }
}

namespace App\Tests\Controller {

use App\Controller\BackupController;
use App\Core\App;
use App\Core\Database;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/_controller_overrides.php';

/**
 * Tests PHPUnit pour App\Controller\BackupController.
 *
 * BackupController gère la page ?p=backup (admin-only) :
 *   - GET → rendu HTML avec stats DB (taille fichier, nb lignes/tables,
 *     oldest/newest submission, page_count/freelist SQLite) + 3 forms
 *     (download_backup, restore_backup, purge_count/purge_confirm)
 *   - POST action=download_backup → envoie le .db via readfile+exit
 *   - POST action=restore_backup → valide l'upload (.db extension, magic
 *     header SQLite), appelle move_uploaded_file, teste l'ouverture PDO,
 *     rollback en cas d'échec
 *   - POST action=purge_count → calcule un preview des données purgeables
 *   - POST action=purge_confirm → supprime les submissions clôturées
 *     anciennes + cascade (tokens, alert_log, submission_validator_data)
 *
 * Stratégie de test :
 *   - requireAdmin() est bypassé en ajoutant 'testeur@e2e.test' à la table
 *     admins dans setUp (ou en le supprimant pour tester l'accès refusé)
 *   - test_json_response (appelé par requireAdmin en TEST_MODE sur accès
 *     refusé) est capturé par notre override namespaced App\Auth\test_json_response
 *     → l'exit est évité
 *   - Le path download_backup exit-prone (readfile+exit) est testé via la
 *     branche d'erreur (file_exists retourne false grâce à l'override
 *     App\Controller\file_exists + flag $_test_force_db_missing)
 *   - Le path restore_backup est testé via les branches d'erreur (extension,
 *     header SQLite, move_uploaded_file échec) — la branche succès n'est
 *     pas testée car elle écraserait db/workflow.db
 */
final class BackupControllerTest extends TestCase
{
    private Database $db;
    private string $dbPath;

    /** @var list<string> UUIDs de submissions créées (pour cleanup) */
    private array $createdSubmissionIds = [];
    /** @var list<string> UUIDs de forms créés */
    private array $createdFormIds = [];

    protected function setUp(): void
    {
        $this->db = App::getInstance()->get(Database::class);
        $this->dbPath = defined('DB_PATH') ? DB_PATH : DEFAULT_DB_PATH;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_X_TEST_MODE'] = '1';
        $_SERVER['HTTP_X_TEST_USER'] = 'testeur@e2e.test';
        $_SERVER['AUTH_USER'] = 'DREETS\testeur';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['HTTPS'] = '';
        $_SERVER['REQUEST_URI'] = '/';
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $GLOBALS['_test_mails'] = [];
        $GLOBALS['_test_captured_json'] = null;
        $GLOBALS['_test_force_db_missing'] = false;
        $GLOBALS['_test_force_move_uploaded'] = false;
        $GLOBALS['_test_plant_foreign_wal'] = '';
        $GLOBALS['_test_corrupt_after_move'] = false;
        $GLOBALS['_test_empty_after_move'] = false;
        $GLOBALS['_test_swap_after_move'] = '';
        $GLOBALS['_test_force_unlink_fail'] = false;

        // S'assurer que db/workflow.db existe (pour la plupart des tests)
        if (!\file_exists($this->dbPath)) {
            $initPdo = new \PDO('sqlite:' . $this->dbPath);
            $initPdo->exec('CREATE TABLE IF NOT EXISTS forms (id TEXT)');
            unset($initPdo);
        }

        // Par défaut, ajouter testeur@e2e.test à admins pour passer requireAdmin
        $this->addAdmin('testeur@e2e.test');

        // Nettoyer les reliquats de tests précédents
        $pdo = $this->db->getPdo();
        $pdo->exec("DELETE FROM submission_validator_data WHERE submission_id IN (SELECT id FROM submissions WHERE submitted_by LIKE 'test-bc-%')");
        $pdo->exec("DELETE FROM tokens WHERE submission_id IN (SELECT id FROM submissions WHERE submitted_by LIKE 'test-bc-%')");
        $pdo->exec("DELETE FROM submissions WHERE submitted_by LIKE 'test-bc-%'");
        $pdo->exec("DELETE FROM forms WHERE slug LIKE 'test-bc-%'");
        $pdo->exec("DELETE FROM audit_log WHERE action IN ('backup_download', 'backup_restore', 'purge_data')");
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->getPdo();
        foreach ($this->createdSubmissionIds as $id) {
            try {
                $pdo->prepare("DELETE FROM submission_validator_data WHERE submission_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$id]);
            } catch (\Throwable) {}
        }
        foreach ($this->createdFormIds as $id) {
            try { $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$id]); } catch (\Throwable) {}
        }
        // Ne PAS retirer testeur@e2e.test des admins ici : c'est l'admin
        // seedé par phpunit_bootstrap.php pour toute la suite de tests.
        // Le supprimer contaminerait les autres tests qui dépendent de
        // sa présence (notamment les tests e2e).
        $pdo->exec("DELETE FROM audit_log WHERE action IN ('backup_download', 'backup_restore', 'purge_data')");
        $this->createdSubmissionIds = [];
        $this->createdFormIds = [];
        $GLOBALS['_test_force_db_missing'] = false;
        $GLOBALS['_test_force_move_uploaded'] = false;
        $GLOBALS['_test_plant_foreign_wal'] = '';
        $GLOBALS['_test_corrupt_after_move'] = false;
        $GLOBALS['_test_empty_after_move'] = false;
        $GLOBALS['_test_swap_after_move'] = '';
        $GLOBALS['_test_force_unlink_fail'] = false;
        $GLOBALS['_test_mails'] = [];
        $GLOBALS['_test_captured_json'] = null;
    }

    /**
     * Crée une base SQLite « CircuitDémat » minimale : les 5 tables pivot dont
     * la présence est exigée par le contrôle anti-base-étrangère (BUG5), plus
     * toute table supplémentaire passée en argument.
     *
     * @param list<string> $extraTables
     */
    private function createPivotTablesDb(string $path, array $extraTables = []): void
    {
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE forms (id TEXT)');
        $pdo->exec('CREATE TABLE submissions (id TEXT)');
        $pdo->exec('CREATE TABLE tokens (id TEXT)');
        $pdo->exec('CREATE TABLE steps (id TEXT)');
        $pdo->exec('CREATE TABLE settings (key TEXT)');
        foreach ($extraTables as $table) {
            $pdo->exec('CREATE TABLE ' . $table . ' (v TEXT)');
        }
        $pdo = null;
    }

    // ── Tests GET ─────────────────────────────────────────────

    /**
     * GET (admin connecté) doit rendre la page sauvegarde avec :
     *   - le titre « Sauvegarde et restauration »
     *   - la section « Statistiques de la base de données »
     *   - la section « Télécharger une sauvegarde »
     *   - la section « Restaurer la base de données »
     *   - la section « Purger les anciennes données »
     */
    public function testHandleGetRendersBackupPageWithAllSections(): void
    {
        $output = $this->captureOutput(fn() => new BackupController()->handle());

        self::assertStringContainsString('Sauvegarde et restauration', $output);
        self::assertStringContainsString('Statistiques de la base de données', $output);
        self::assertStringContainsString('Télécharger une sauvegarde', $output);
        self::assertStringContainsString('Restaurer la base de données', $output);
        self::assertStringContainsString('Purger les anciennes données', $output);
        // Les forms ont les bons inputs hidden pour les actions
        self::assertStringContainsString('name="action" value="download_backup"', $output);
        self::assertStringContainsString('name="action" value="restore_backup"', $output);
        self::assertStringContainsString('name="action" value="purge_count"', $output);
        // Le select de purge_months propose 6/12/18/24 mois
        self::assertStringContainsString('<option value="6">6 mois</option>', $output);
        self::assertStringContainsString('<option value="12" selected>12 mois</option>', $output);
        self::assertStringContainsString('<option value="18">18 mois</option>', $output);
        self::assertStringContainsString('<option value="24">24 mois</option>', $output);
    }

    /**
     * GET doit afficher les row counts par table dans la section stats.
     * Vérifie que les tables principales sont listées.
     */
    public function testHandleGetDisplaysTableRowCountStatistics(): void
    {
        // Insérer quelques forms pour que les stats soient non triviales
        $this->createTestForm('test-bc-stats');

        $output = $this->captureOutput(fn() => new BackupController()->handle());

        self::assertStringContainsString('Nombre d\'enregistrements par table', $output);
        self::assertStringContainsString('class="u-fon-fon-3">forms', $output);
        self::assertStringContainsString('class="u-fon-fon-3">submissions', $output);
        self::assertStringContainsString('class="u-fon-fon-3">tokens', $output);
        self::assertStringContainsString('class="u-fon-fon-3">audit_log', $output);
        // Ligne Total
        self::assertStringContainsString('<td>Total</td>', $output);
    }

    // ── Tests POST access control ─────────────────────────────

    /**
     * POST sans privilèges admin doit déclencher test_json_response
     * (via AuthService::requireAdmin) avec error='Accès refusé'. Notre
     * override App\Auth\test_json_response capture le payload + lève
     * TestJsonCapturedException (donc ErrorRenderer::errorPage n'est
     * jamais atteint).
     */
    public function testHandlePostWithoutAdminReturnsAccessDeniedJson(): void
    {
        // Retirer testeur@e2e.test des admins
        $this->removeAdmin('testeur@e2e.test');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'download_backup', 'csrf_token' => 'test'];

        $this->captureOutput(
            fn() => new BackupController()->handle()
        );

        self::assertNotNull($GLOBALS['_test_captured_json'], 'requireAdmin doit appeler test_json_response');
        self::assertSame('Accès refusé', $GLOBALS['_test_captured_json']['error']);
        self::assertSame('index.php?p=admin_access', $GLOBALS['_test_captured_json']['redirect']);
    }

    // ── Tests POST restore_backup ─────────────────────────────

    /**
     * POST action=restore_backup sans $_FILES doit afficher l'erreur
     * « Aucun fichier n'a été téléchargé. »
     */
    public function testHandlePostRestoreBackupWithNoFileReturnsUploadError(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'restore_backup', 'csrf_token' => 'test'];
        // Pas de $_FILES

        $output = $this->captureOutput(fn() => new BackupController()->handle());

        self::assertStringContainsString('Sauvegarde et restauration', $output);
        // Le message d'erreur est HTML-escaped (apostrophe → &apos;)
        self::assertStringContainsString('Aucun fichier n&apos;a été téléchargé', $output);
    }

    /**
     * POST restore_backup avec un fichier non-.db doit afficher
     * « Seuls les fichiers .db sont acceptés. »
     */
    public function testHandlePostRestoreBackupWithNonDbExtensionReturnsError(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'restore_backup', 'csrf_token' => 'test'];
        $_FILES = [
            'backup_file' => [
                'name'     => 'malicious.exe',
                'type'     => 'application/octet-stream',
                'tmp_name' => '/tmp/nonexistent',
                'error'    => UPLOAD_ERR_OK,
                'size'     => 0,
            ],
        ];

        $output = $this->captureOutput(fn() => new BackupController()->handle());

        self::assertStringContainsString('Seuls les fichiers .db sont acceptés', $output);
        self::assertStringContainsString('malicious.exe', $output);
    }

    /**
     * POST restore_backup avec un fichier .db mais corrompu (magic header
     * SQLite absent) doit afficher « Le fichier fourni n'est pas une base
     * de données SQLite valide. »
     */
    public function testHandlePostRestoreBackupWithCorruptDbFileReturnsError(): void
    {
        // Créer un fichier .db corrompu (contenu textuel, pas SQLite)
        $corruptPath = sys_get_temp_dir() . '/test_corrupt_' . uniqid() . '.db';
        file_put_contents($corruptPath, 'NOT A SQLITE DATABASE FILE');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'restore_backup', 'csrf_token' => 'test'];
        $_FILES = [
            'backup_file' => [
                'name'     => 'corrupt.db',
                'type'     => 'application/x-sqlite3',
                'tmp_name' => $corruptPath,
                'error'    => UPLOAD_ERR_OK,
                'size'     => filesize($corruptPath),
            ],
        ];

        try {
            $output = $this->captureOutput(fn() => new BackupController()->handle());
            // HTML-escaped : « n'est » → « n&apos;est »
            self::assertStringContainsString('n&apos;est pas une base de données SQLite valide', $output);
        } finally {
            @unlink($corruptPath);
        }
    }

    /**
     * POST restore_backup avec un fichier .db valide (header SQLite correct)
     * mais move_uploaded_file() qui échoue (par défaut en CLI) doit afficher
     * « Impossible de remplacer le fichier de base de données. »
     *
     * Note : en CLI, move_uploaded_file retourne toujours false car il n'y a
     * pas de réel upload HTTP. Ce test vérifie donc le chemin d'erreur par
     * défaut sans aucune override.
     */
    public function testHandlePostRestoreBackupWithMoveFailureReturnsError(): void
    {
        // Créer un fichier .db CircuitDémat valide (tables pivot présentes,
        // requises par le contrôle anti-base-étrangère BUG5).
        $validDbPath = sys_get_temp_dir() . '/test_valid_' . uniqid() . '.db';
        $this->createPivotTablesDb($validDbPath);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'restore_backup', 'csrf_token' => 'test'];
        $_FILES = [
            'backup_file' => [
                'name'     => 'workflow_backup_20260101_120000.db',
                'type'     => 'application/x-sqlite3',
                'tmp_name' => $validDbPath,
                'error'    => UPLOAD_ERR_OK,
                'size'     => filesize($validDbPath),
            ],
        ];

        try {
            $output = $this->captureOutput(fn() => new BackupController()->handle());
            // Le controller doit soit dire "Impossible de remplacer" (move_uploaded_file = false en CLI),
            // soit restaurer avec succès si l'environnement simule l'upload. On accepte les deux.
            self::assertTrue(
                str_contains($output, 'Impossible de remplacer le fichier de base de données')
                || str_contains($output, 'a été restaurée avec succès'),
                'Le controller doit soit signaler un échec de move, soit un succès de restauration. Output: ' . substr($output, 0, 500)
            );
        } finally {
            @unlink($validDbPath);
        }
    }

    /**
     * BUG5 (audit 2026-09-17) — une base SQLite étrangère (en-tête valide mais
     * sans les tables pivot forms/submissions/tokens/steps/settings) ne doit
     * JAMAIS remplacer la base applicative. Le contrôle refuse AVANT tout
     * remplacement (pas de snapshot pré-restauration, pas de move) et la base
     * actuelle reste strictement inchangée.
     */
    public function testRestoreBackupRefusesForeignSqliteAndLeavesDatabaseUnchanged(): void
    {
        $original = $this->captureDbSnapshot();
        $this->cleanupPreRestoreBackups();

        // Base SQLite valide mais étrangère : aucune table pivot CircuitDémat.
        $foreign = sys_get_temp_dir() . '/bc_foreign_db_' . uniqid() . '.db';
        $foreignPdo = new \PDO('sqlite:' . $foreign);
        $foreignPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $foreignPdo->exec('CREATE TABLE foreign_only (v TEXT)');
        $foreignPdo->prepare('INSERT INTO foreign_only (v) VALUES (?)')->execute(['FOREIGN']);
        $foreignPdo = null;

        // Sentinelle dans la base réelle : doit survivre si la restauration est refusée.
        $sentinel = 'bc_sentinel_' . uniqid();
        $seed = new \PDO('sqlite:' . $this->dbPath);
        $seed->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $seed->exec('CREATE TABLE IF NOT EXISTS bc_sentinel (v TEXT)');
        $seed->prepare('INSERT INTO bc_sentinel (v) VALUES (?)')->execute([$sentinel]);
        $seed = null;

        // Forcer le move : sans le contrôle BUG5, la base étrangère écraserait
        // la base réelle (le test échouerait alors sur les assertions ci-dessous).
        $GLOBALS['_test_force_move_uploaded'] = true;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'restore_backup', 'csrf_token' => 'test'];
        $_FILES = [
            'backup_file' => [
                'name'     => 'foreign.db',
                'type'     => 'application/x-sqlite3',
                'tmp_name' => $foreign,
                'error'    => UPLOAD_ERR_OK,
                'size'     => filesize($foreign),
            ],
        ];

        try {
            try {
                $output = $this->captureOutput(fn() => new BackupController()->handle());
            } catch (\Throwable) {
                // Sans le contrôle, la base réelle est remplacée par le schéma
                // étranger : le rendu échoue (tables attendues absentes). Les
                // assertions sur la base ci-dessous constatent alors la corruption.
                $output = '';
            }

            self::assertStringNotContainsString('a été restaurée avec succès', $output, 'une base étrangère ne doit pas être restaurée');
            self::assertStringContainsString(
                'les tables requises (forms, submissions, tokens, steps, settings) sont absentes',
                $output,
                'le refus doit nommer les tables pivot manquantes'
            );

            // Base inchangée : marqueur étranger absent, sentinelle toujours là.
            $check = new \PDO('sqlite:' . $this->dbPath);
            $check->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            self::assertSame(
                0,
                (int) $check->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'foreign_only'")->fetchColumn(),
                'la table étrangère ne doit pas avoir remplacé la base'
            );
            self::assertSame(
                $sentinel,
                (string) $check->query('SELECT v FROM bc_sentinel ORDER BY rowid DESC LIMIT 1')->fetchColumn(),
                'la base applicative doit rester intacte'
            );
            $check = null;

            // Refus avant tout remplacement : aucune copie pré-restauration créée.
            self::assertSame([], \glob($this->dbPath . '.before_restore_*') ?: [], 'refus avant tout remplacement');
        } finally {
            $GLOBALS['_test_force_move_uploaded'] = false;
            $this->restoreDbFile($original);
            $this->cleanupPreRestoreBackups();
            @unlink($foreign);
        }
    }

    /**
     * BUG5 (complément) — le contrôle exige la présence de TOUTES les tables
     * pivot, pas d'une partie : une base SQLite valide qui n'en contient que
     * 4 sur 5 (ici `settings` absente) doit être refusée comme une base
     * étrangère, sans toucher à la base en place.
     */
    public function testRestoreBackupRefusesDbMissingOnePivotTable(): void
    {
        $original = $this->captureDbSnapshot();
        $this->cleanupPreRestoreBackups();

        // Base SQLite valide, 4 tables pivot sur 5 (settings manquante) + une
        // table « étrangère » qui ne doit jamais remplacer la base applicative.
        $partial = sys_get_temp_dir() . '/bc_partial_db_' . uniqid() . '.db';
        $partialPdo = new \PDO('sqlite:' . $partial);
        $partialPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        foreach (['forms', 'submissions', 'tokens', 'steps'] as $table) {
            $partialPdo->exec('CREATE TABLE ' . $table . ' (id TEXT)');
        }
        $partialPdo->exec('CREATE TABLE foreign_only (v TEXT)');
        $partialPdo = null;

        $sentinel = 'bc_partial_sentinel_' . uniqid();
        $seed = new \PDO('sqlite:' . $this->dbPath);
        $seed->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $seed->exec('CREATE TABLE IF NOT EXISTS bc_partial_marker (v TEXT)');
        $seed->prepare('INSERT INTO bc_partial_marker (v) VALUES (?)')->execute([$sentinel]);
        $seed = null;

        $GLOBALS['_test_force_move_uploaded'] = true;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'restore_backup', 'csrf_token' => 'test'];
        $_FILES = [
            'backup_file' => [
                'name'     => 'partial.db',
                'type'     => 'application/x-sqlite3',
                'tmp_name' => $partial,
                'error'    => UPLOAD_ERR_OK,
                'size'     => filesize($partial),
            ],
        ];

        try {
            $output = $this->captureOutput(fn() => new BackupController()->handle());

            self::assertStringNotContainsString('a été restaurée avec succès', $output, '4/5 tables pivot ne doivent pas être restaurées');
            self::assertStringContainsString(
                'les tables requises (forms, submissions, tokens, steps, settings) sont absentes',
                $output,
                'le refus doit nommer les tables pivot requises'
            );

            $check = new \PDO('sqlite:' . $this->dbPath);
            $check->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            self::assertSame(
                0,
                (int) $check->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'foreign_only'")->fetchColumn(),
                'la table étrangère ne doit pas avoir remplacé la base'
            );
            self::assertSame(
                $sentinel,
                (string) $check->query('SELECT v FROM bc_partial_marker ORDER BY rowid DESC LIMIT 1')->fetchColumn(),
                'la base applicative doit rester intacte'
            );
            $check = null;

            self::assertSame([], \glob($this->dbPath . '.before_restore_*') ?: [], 'refus avant tout remplacement');
        } finally {
            $GLOBALS['_test_force_move_uploaded'] = false;
            $this->restoreDbFile($original);
            $this->cleanupPreRestoreBackups();
            @unlink($partial);
        }
    }

    /**
     * Le sanity-check post-restauration doit exiger que la base réellement en
     * place soit une base CircuitDémat, pas seulement qu'elle soit lisible : un
     * fichier vide/0 octet est une base SQLite valide pour SQLite (`COUNT(*)`
     * sur `sqlite_master` = 0, aucune exception). Un contrôle limité à la
     * lisibilité affichait donc « restaurée avec succès » sur une base vide,
     * puis l'application échouait en « no such table ». Ici le fichier restauré
     * est vidé pendant le move → le contrôle pivot échoue → rollback.
     */
    public function testRestoreBackupEmptyAfterMoveTriggersRollback(): void
    {
        $original = $this->captureDbSnapshot();
        $this->cleanupPreRestoreBackups();

        $sentinel = 'bc_empty_sentinel_' . uniqid();
        $seed = new \PDO('sqlite:' . $this->dbPath);
        $seed->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $seed->exec('CREATE TABLE IF NOT EXISTS bc_empty_marker (v TEXT)');
        $seed->prepare('INSERT INTO bc_empty_marker (v) VALUES (?)')->execute([$sentinel]);
        $seed = null;

        // L'upload passe le pré-contrôle (tables pivot) puis est vidé pendant le
        // remplacement : seule la vérification post-move peut détecter le problème.
        $uploaded = sys_get_temp_dir() . '/bc_empty_after_move_' . uniqid() . '.db';
        $this->createPivotTablesDb($uploaded);

        $GLOBALS['_test_force_move_uploaded'] = true;
        $GLOBALS['_test_empty_after_move'] = true;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'restore_backup', 'csrf_token' => 'test'];
        $_FILES = [
            'backup_file' => [
                'name'     => 'empty_restore.db',
                'type'     => 'application/x-sqlite3',
                'tmp_name' => $uploaded,
                'error'    => UPLOAD_ERR_OK,
                'size'     => filesize($uploaded),
            ],
        ];

        try {
            $output = $this->captureOutput(fn() => new BackupController()->handle());

            self::assertStringNotContainsString('a été restaurée avec succès', $output, 'une base vide ne doit pas être annoncée comme restaurée');
            self::assertStringContainsString('semble corrompue', $output);
            self::assertStringContainsString('a été rétablie', $output);

            // La sauvegarde pré-restauration (instantané) a été remise en place.
            $check = new \PDO('sqlite:' . $this->dbPath);
            $check->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            self::assertSame(
                $sentinel,
                (string) $check->query('SELECT v FROM bc_empty_marker ORDER BY rowid DESC LIMIT 1')->fetchColumn(),
                'le rollback doit rétablir la base d\'origine'
            );
            $check = null;
        } finally {
            $GLOBALS['_test_force_move_uploaded'] = false;
            $GLOBALS['_test_empty_after_move'] = false;
            $this->restoreDbFile($original);
            $this->cleanupPreRestoreBackups();
            @unlink($uploaded);
        }
    }

    /**
     * P1-C — removeWalSidecars() doit signaler qu'une suppression a échoué :
     * un sidecar verrouillé (unlink qui échoue, cas Windows) doit être détecté
     * par relecture de son existence et retourner false, jamais un faux succès.
     * Le test est portable : l'échec d'unlink est simulé via l'override
     * namespaced $_test_force_unlink_fail (indépendant du système de fichiers).
     */
    public function testRemoveWalSidecarsReportsFailureWhenSidecarSurvives(): void
    {
        $db = sys_get_temp_dir() . '/bc_sidecar_lock_' . uniqid() . '.db';
        \file_put_contents($db, "SQLite format 3\0" . str_repeat(' ', 128));
        \file_put_contents($db . '-wal', 'LOCKED');

        $GLOBALS['_test_force_unlink_fail'] = true;
        $refused = BackupController::removeWalSidecars($db);
        $survived = \file_exists($db . '-wal');
        $GLOBALS['_test_force_unlink_fail'] = false;
        $removed = BackupController::removeWalSidecars($db);

        // Nettoyage avant assertions (aucune fuite si une assertion échoue).
        @\unlink($db);

        self::assertFalse($refused, 'un sidecar survivant ne doit pas être signalé comme supprimé');
        self::assertTrue($survived, 'le sidecar verrouillé doit toujours exister après l\'échec d\'unlink');
        self::assertTrue($removed, 'une fois déverrouillé, le sidecar doit être supprimé');
        self::assertFileDoesNotExist($db . '-wal');
    }

    /**
     * P1-C — identité post-remplacement (hash SHA-256 avant/après). Une AUTRE
     * base CircuitDémat valide (toutes les tables pivot présentes) est
     * substituée pendant le move : le contrôle « tables pivot » la validerait,
     * seule la comparaison d'empreinte détecte que le contenu restauré n'est
     * pas celui qui a passé les pré-contrôles. Le rollback rétablit l'original.
     */
    public function testRestoreBackupHashMismatchAfterMoveTriggersRollback(): void
    {
        $original = $this->captureDbSnapshot();
        $this->cleanupPreRestoreBackups();

        $sentinel = 'bc_hash_sentinel_' . uniqid();
        $seed = new \PDO('sqlite:' . $this->dbPath);
        $seed->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $seed->exec('CREATE TABLE IF NOT EXISTS bc_hash_marker (v TEXT)');
        $seed->prepare('INSERT INTO bc_hash_marker (v) VALUES (?)')->execute([$sentinel]);
        $seed = null;

        // Base uploadée valide ; une base valide différente est copiée par-dessus
        // pendant le move (tables pivot identiques → le pré-contrôle passe).
        $uploaded = sys_get_temp_dir() . '/bc_hash_upload_' . uniqid() . '.db';
        $this->createPivotTablesDb($uploaded);
        $alternate = sys_get_temp_dir() . '/bc_hash_swap_' . uniqid() . '.db';
        $this->createPivotTablesDb($alternate, ['swapped_marker']);
        $swapPdo = new \PDO('sqlite:' . $alternate);
        $swapPdo->prepare('INSERT INTO swapped_marker (v) VALUES (?)')->execute(['SWAPPED']);
        $swapPdo = null;

        $GLOBALS['_test_force_move_uploaded'] = true;
        $GLOBALS['_test_swap_after_move'] = $alternate;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'restore_backup', 'csrf_token' => 'test'];
        $_FILES = [
            'backup_file' => [
                'name'     => 'hash_mismatch.db',
                'type'     => 'application/x-sqlite3',
                'tmp_name' => $uploaded,
                'error'    => UPLOAD_ERR_OK,
                'size'     => filesize($uploaded),
            ],
        ];

        try {
            $output = $this->captureOutput(fn() => new BackupController()->handle());

            self::assertStringNotContainsString(
                'a été restaurée avec succès',
                $output,
                'un contenu dont le hash diffère ne doit pas être annoncé comme restauré'
            );
            self::assertStringContainsString('semble corrompue', $output);
            self::assertStringContainsString('a été rétablie', $output);

            $check = new \PDO('sqlite:' . $this->dbPath);
            $check->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            self::assertSame(
                $sentinel,
                (string) $check->query('SELECT v FROM bc_hash_marker ORDER BY rowid DESC LIMIT 1')->fetchColumn(),
                'le rollback doit rétablir la base d\'origine'
            );
            self::assertSame(
                0,
                (int) $check->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'swapped_marker'")->fetchColumn(),
                'la base substituée ne doit pas rester en place'
            );
            $check = null;
        } finally {
            $GLOBALS['_test_force_move_uploaded'] = false;
            $GLOBALS['_test_swap_after_move'] = '';
            $this->restoreDbFile($original);
            $this->cleanupPreRestoreBackups();
            @\unlink($uploaded);
            @\unlink($alternate);
        }
    }

    /**
     * P1-C — un sidecar WAL verrouillé pendant la restauration doit être refusé
     * et rollbacké : laisser un -wal étranger à côté de la base restaurée
     * corromprait celle-ci à la réouverture. Comme le sidecar résiste aussi au
     * rollback, le contrôleur doit l'indiquer explicitement (rollback non
     * présenté comme fiable) plutôt que d'annoncer un succès ou un « rétablie ».
     */
    public function testRestoreBackupRefusesAndRollsBackWhenWalSidecarLocked(): void
    {
        $original = $this->captureDbSnapshot();
        $this->cleanupPreRestoreBackups();

        $sentinel = 'bc_locked_sentinel_' . uniqid();
        $seed = new \PDO('sqlite:' . $this->dbPath);
        $seed->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $seed->exec('CREATE TABLE IF NOT EXISTS bc_locked_marker (v TEXT)');
        $seed->prepare('INSERT INTO bc_locked_marker (v) VALUES (?)')->execute([$sentinel]);
        $seed = null;

        $uploaded = sys_get_temp_dir() . '/bc_locked_upload_' . uniqid() . '.db';
        $this->createPivotTablesDb($uploaded, ['restored_marker']);

        // WAL valide planté pendant le move ; l'unlink verrouillé l'empêche de
        // partir, y compris lors du rollback.
        $foreign = $this->startForeignWalDb('locked');

        $GLOBALS['_test_force_move_uploaded'] = true;
        $GLOBALS['_test_plant_foreign_wal'] = $foreign['wal'];
        $GLOBALS['_test_force_unlink_fail'] = true;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'restore_backup', 'csrf_token' => 'test'];
        $_FILES = [
            'backup_file' => [
                'name'     => 'locked_restore.db',
                'type'     => 'application/x-sqlite3',
                'tmp_name' => $uploaded,
                'error'    => UPLOAD_ERR_OK,
                'size'     => filesize($uploaded),
            ],
        ];

        try {
            $output = $this->captureOutput(fn() => new BackupController()->handle());

            self::assertStringNotContainsString(
                'a été restaurée avec succès',
                $output,
                'un sidecar non supprimable ne doit pas aboutir à un succès'
            );
            self::assertStringContainsString('n&apos;a pas pu être supprimé', $output);
            self::assertStringContainsString('a échoué', $output);

            // Déverrouillage + purge du -wal avant lecture (il est encore là).
            $GLOBALS['_test_force_unlink_fail'] = false;
            BackupController::removeWalSidecars($this->dbPath);

            $check = new \PDO('sqlite:' . $this->dbPath);
            $check->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            self::assertSame(
                $sentinel,
                (string) $check->query('SELECT v FROM bc_locked_marker ORDER BY rowid DESC LIMIT 1')->fetchColumn(),
                'la copie pré-restauration doit être remise en place'
            );
            $check = null;
        } finally {
            $GLOBALS['_test_force_move_uploaded'] = false;
            $GLOBALS['_test_plant_foreign_wal'] = '';
            $GLOBALS['_test_force_unlink_fail'] = false;
            $this->disposeForeignWalDb($foreign);
            $this->restoreDbFile($original);
            $this->cleanupPreRestoreBackups();
            @\unlink($uploaded);
        }
    }

    // ── Tests POST purge_count ───────────────────────────────

    /**
     * POST action=purge_count avec une valeur de mois invalide doit
     * afficher « Valeur de mois invalide. »
     */
    public function testHandlePostPurgeCountWithInvalidMonthsReturnsError(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'purge_count', 'csrf_token' => 'test', 'purge_months' => '999'];

        $output = $this->captureOutput(fn() => new BackupController()->handle());

        self::assertStringContainsString('Valeur de mois invalide', $output);
    }

    /**
     * POST action=purge_count avec purge_months=12 doit calculer le preview
     * et afficher la section « Récapitulatif de la purge ».
     *
     * Sans soumissions anciennes, la section recap affiche aussi le message
     * « Aucune donnée à purger pour cette période » (rendu par BackupRenderer).
     */
    public function testHandlePostPurgeCountWithValidMonthsDisplaysPreview(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'purge_count', 'csrf_token' => 'test', 'purge_months' => '12'];

        $output = $this->captureOutput(fn() => new BackupController()->handle());

        self::assertStringContainsString('Récapitulatif de la purge', $output);
        self::assertStringContainsString('données clôturées depuis plus de 12 mois', $output);
        // Le recap doit contenir les 4 compteurs (avec valeurs à 0 si pas de vieilles données)
        self::assertStringContainsString('Soumission(s)', $output);
        self::assertStringContainsString('Token(s)', $output);
        self::assertStringContainsString('Alerte(s)', $output);
        self::assertStringContainsString('Donnée(s) validateur', $output);
        // Sans données purgeables : message « Aucune donnée à purger » (rendu par BackupRenderer)
        self::assertStringContainsString('Aucune donnée à purger pour cette période', $output);
    }

    /**
     * POST action=purge_count doit loguer dans audit_log (l'action n'est pas
     * loguée, mais purge_confirm l'est — test séparé). On vérifie ici que
     * purge_count ne crée PAS d'entrée audit_log (pas d'effet de bord).
     */
    public function testHandlePostPurgeCountDoesNotCreateAuditLogEntry(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'purge_count', 'csrf_token' => 'test', 'purge_months' => '6'];

        $this->captureOutput(fn() => new BackupController()->handle());

        $pdo = $this->db->getPdo();
        $count = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'purge_data'")->fetchColumn();
        self::assertSame(0, $count, 'purge_count ne doit pas loguer dans audit_log');
    }

    // ── Tests POST purge_confirm ──────────────────────────────

    /**
     * POST action=purge_confirm avec purge_months=12 mais aucune soumission
     * ancienne clôturée doit afficher l'info « Aucune soumission à purger ».
     */
    public function testHandlePostPurgeConfirmWithNoOldDataReturnsInfoMessage(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'purge_confirm', 'csrf_token' => 'test', 'purge_months' => '12'];

        $output = $this->captureOutput(fn() => new BackupController()->handle());

        self::assertStringContainsString('Aucune soumission à purger pour la période de 12 mois', $output);
    }

    /**
     * POST action=purge_confirm avec purge_months=12 ET une vieille soumission
     * clôturée doit :
     *   - supprimer la soumission + tokens + validator_data + alert_logs liés
     *   - afficher « Purge effectuée avec succès »
     *   - loguer dans audit_log (action='purge_data')
     */
    public function testHandlePostPurgeConfirmWithOldDataPurgesAndLogs(): void
    {
        // Créer une soumission clôturée il y a 13 mois (donc purgeable avec months=12)
        $oldSubId = $this->createOldClosedSubmission(13);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'purge_confirm', 'csrf_token' => 'test', 'purge_months' => '12'];

        $output = $this->captureOutput(fn() => new BackupController()->handle());

        self::assertStringContainsString('Purge effectuée avec succès', $output);
        // Le successMsg contient <strong>N</strong> pour chaque compteur, mais
        // ErrorRenderer::messages() HTML-escape le texte → on cherche la version escaped.
        self::assertStringContainsString('&lt;strong&gt;1&lt;/strong&gt; soumission(s)', $output);
        // Et le détail des compteurs
        self::assertStringContainsString('token(s)', $output);
        self::assertStringContainsString('alerte(s)', $output);
        self::assertStringContainsString('donnée(s) validateur', $output);

        // Side-effect DB : la soumission est supprimée
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE id = ?");
        $stmt->execute([$oldSubId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'La soumission purgeable doit être supprimée');

        // audit_log : entrée purge_data créée
        $auditStmt = $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'purge_data'");
        self::assertGreaterThanOrEqual(1, (int) $auditStmt->fetchColumn(), 'Une entrée audit_log purge_data doit être créée');

        // Retirer l'ID de la liste de cleanup (déjà supprimé par le controller)
        $this->createdSubmissionIds = array_diff($this->createdSubmissionIds, [$oldSubId]);
    }

    // ── Tests POST download_backup ───────────────────────────

    /**
     * POST action=download_backup quand db/workflow.db n'existe pas doit
     * afficher « Le fichier de base de données est introuvable. »
     *
     * Utilise l'override App\Controller\file_exists + flag $_test_force_db_missing
     * pour simuler l'absence du fichier sans réellement le supprimer.
     */
    public function testHandlePostDownloadBackupWithMissingFileReturnsError(): void
    {
        $GLOBALS['_test_force_db_missing'] = true;

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'download_backup', 'csrf_token' => 'test'];

        $output = $this->captureOutput(fn() => new BackupController()->handle());

        self::assertStringContainsString('Le fichier de base de données est introuvable', $output);
    }

    /**
     * POST avec une action inconnue doit juste rendre la page normalement
     * (pas d'erreur, pas de succès — simplement le formulaire).
     */
    public function testHandlePostWithUnknownActionRendersPageNormally(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'unknown_action', 'csrf_token' => 'test'];

        $output = $this->captureOutput(fn() => new BackupController()->handle());

        self::assertStringContainsString('Sauvegarde et restauration', $output);
        // Ni message de succès, ni message d'erreur
        self::assertStringNotContainsString('msg-success', $output);
        self::assertStringNotContainsString('msg-error', $output);
    }

    // ── Tests F3 : instantané cohérent WAL (VACUUM INTO) ─────

    /**
     * F3 — un commit encore présent dans le WAL non checkpointé doit se
     * retrouver dans l'instantané de sauvegarde (VACUUM INTO), même s'il est
     * absent du fichier .db principal. Reproduit le défaut d'un readfile()/
     * copy() brut qui perdrait cette transaction.
     */
    public function testCreateConsistentSnapshotIncludesUncheckpointedWalCommit(): void
    {
        $scratchDb = sys_get_temp_dir() . '/bc_wal_' . uniqid() . '.db';
        $sentinel = 'WAL_ONLY_' . uniqid();
        $writer = null;
        $snapshot = null;
        try {
            $writer = new \PDO('sqlite:' . $scratchDb);
            $writer->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $writer->exec('PRAGMA journal_mode = WAL');
            $writer->exec('PRAGMA wal_autocheckpoint = 0');
            $writer->exec('CREATE TABLE marker (val TEXT)');
            $writer->prepare('INSERT INTO marker (val) VALUES (?)')->execute([$sentinel]);

            // Précondition : la transaction n'a pas été checkpointée → le
            // sentinel est absent du fichier principal tant que le WAL existe.
            self::assertFalse(
                str_contains((string) \file_get_contents($scratchDb), $sentinel),
                'précondition : le commit doit être uniquement dans le WAL'
            );

            $snapshot = BackupController::createConsistentSnapshot($scratchDb);
            self::assertNotNull($snapshot, 'VACUUM INTO doit produire un instantané');
            self::assertFileExists($snapshot);

            $reader = new \PDO('sqlite:' . $snapshot);
            self::assertSame(
                1,
                (int) $reader->query('SELECT COUNT(*) FROM marker')->fetchColumn(),
                'le commit non checkpointé doit être présent dans l\'instantané'
            );
            $reader = null;
        } finally {
            $writer = null;
            if ($snapshot !== null) {
                @unlink($snapshot);
            }
            $this->removeSidecars($scratchDb, $scratchDb . '-wal', $scratchDb . '-shm');
        }
    }

    // ── Tests F3/F4 : restauration + sidecars WAL ─────────────

    /**
     * F3 + F4 — la restauration doit supprimer un -wal (valide) orphelin de
     * l'ancienne base après le remplacement du fichier. Sans F4, SQLite
     * applique ce journal étranger par-dessus la base restaurée : la table
     * restaurée disparaît, remplacée par celle de l'ancienne base.
     */
    public function testRestoreBackupRemovesOrphanWalSidecars(): void
    {
        $original = $this->captureDbSnapshot();

        // Base "uploadée" (restaurée) CircuitDémat valide (tables pivot) avec
        // une table témoin — sinon le contrôle BUG5 refuserait la restauration.
        $uploaded = sys_get_temp_dir() . '/bc_restore_' . uniqid() . '.db';
        $this->createPivotTablesDb($uploaded, ['restored_marker']);
        $uploadPdo = new \PDO('sqlite:' . $uploaded);
        $uploadPdo->prepare('INSERT INTO restored_marker (v) VALUES (?)')->execute(['RESTORED']);
        $uploadPdo = null;

        // WAL valide issu d'une AUTRE base (écrit mais non checkpointé).
        $foreign = $this->startForeignWalDb('restore');

        $GLOBALS['_test_force_move_uploaded'] = true;
        $GLOBALS['_test_plant_foreign_wal'] = $foreign['wal'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'restore_backup', 'csrf_token' => 'test'];
        $_FILES = [
            'backup_file' => [
                'name'     => 'restore.db',
                'type'     => 'application/x-sqlite3',
                'tmp_name' => $uploaded,
                'error'    => UPLOAD_ERR_OK,
                'size'     => filesize($uploaded),
            ],
        ];

        try {
            $output = $this->captureOutput(fn() => new BackupController()->handle());

            self::assertStringContainsString('a été restaurée avec succès', $output);

            // La base restaurée est intacte : le WAL orphelin n'a pas été appliqué.
            $check = new \PDO('sqlite:' . $this->dbPath);
            self::assertSame(
                1,
                (int) $check->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'restored_marker'")->fetchColumn(),
                'la table restaurée doit être conservée'
            );
            self::assertSame(
                0,
                (int) $check->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'foreign_tbl'")->fetchColumn(),
                'le WAL orphelin ne doit pas remplacer la base restaurée'
            );
            $check = null;
        } finally {
            $GLOBALS['_test_force_move_uploaded'] = false;
            $GLOBALS['_test_plant_foreign_wal'] = '';
            $this->disposeForeignWalDb($foreign);
            $this->restoreDbFile($original);
            $this->cleanupPreRestoreBackups();
            @unlink($uploaded);
        }
    }

    /**
     * F4 — si la base restaurée est corrompue, le rollback doit retirer le
     * -wal orphelin avant de recopier la sauvegarde d'origine. Sans F4, SQLite
     * récupérerait le journal étranger et « réparerait » la base corrompue
     * (donc pas de rollback : la base restaurée serait en réalité l'ancienne).
     */
    public function testRestoreBackupRollbackRemovesWalSidecars(): void
    {
        $original = $this->captureDbSnapshot();

        // Marqueur inséré AVANT la restauration : la copie pré-restauration
        // (VACUUM INTO) doit le contenir, et le rollback doit le rétablir. Rendre
        // l'assertion déterministe plutôt que de compter les tables d'une base
        // dont l'état initial dépend d'autres tests (l'échec CI Linux venait d'un
        // « 0 table » après qu'un test voisin ait vidé db/workflow.db).
        $seed = new \PDO('sqlite:' . $this->dbPath);
        $seed->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $seed->exec('CREATE TABLE IF NOT EXISTS bc_rollback_marker (v TEXT)');
        $seed->prepare('INSERT INTO bc_rollback_marker (v) VALUES (?)')->execute(['RESTORED']);
        $seed = null;

        // L'upload passe les contrôles (CircuitDémat + tables pivot) mais le
        // fichier est corrompu PENDANT le remplacement (simulé par l'override) :
        // l'ouverture PDO post-move échoue → déclenche le rollback.
        $uploaded = sys_get_temp_dir() . '/bc_corrupt_after_move_' . uniqid() . '.db';
        $this->createPivotTablesDb($uploaded);

        $foreign = $this->startForeignWalDb('rollback');

        $GLOBALS['_test_force_move_uploaded'] = true;
        $GLOBALS['_test_corrupt_after_move'] = true;
        $GLOBALS['_test_plant_foreign_wal'] = $foreign['wal'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'restore_backup', 'csrf_token' => 'test'];
        $_FILES = [
            'backup_file' => [
                'name'     => 'corrupt_restore.db',
                'type'     => 'application/x-sqlite3',
                'tmp_name' => $uploaded,
                'error'    => UPLOAD_ERR_OK,
                'size'     => filesize($uploaded),
            ],
        ];

        try {
            $output = $this->captureOutput(fn() => new BackupController()->handle());

            self::assertStringContainsString('semble corrompue', $output);
            self::assertStringContainsString('a été rétablie', $output);
            self::assertFileDoesNotExist($this->dbPath . '-wal');
            self::assertFileDoesNotExist($this->dbPath . '-shm');

            // La sauvegarde d'origine (instantané pré-restauration) est en place,
            // avec le marqueur inséré juste avant la restauration.
            $check = new \PDO('sqlite:' . $this->dbPath);
            $check->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            self::assertSame(
                'RESTORED',
                (string) $check->query('SELECT v FROM bc_rollback_marker ORDER BY rowid DESC LIMIT 1')->fetchColumn(),
                'le rollback doit rétablir la base d\'origine (marqueur présent avant la restauration)'
            );
            $check = null;
        } finally {
            $GLOBALS['_test_force_move_uploaded'] = false;
            $GLOBALS['_test_corrupt_after_move'] = false;
            $GLOBALS['_test_plant_foreign_wal'] = '';
            $this->disposeForeignWalDb($foreign);
            $this->restoreDbFile($original);
            $this->cleanupPreRestoreBackups();
            @unlink($uploaded);
        }
    }

    // ── Tests F5 : cutoffs de purge en UTC (gmdate) ──────────

    /**
     * F5 — le cutoff de purge doit être rendu en UTC, pas dans le fuseau
     * serveur (Europe/Paris en prod), sinon la fenêtre glisse de 1-2h.
     */
    public function testPurgeCutoffUtcIgnoresServerTimezone(): void
    {
        $previousTz = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati'); // UTC+14, sans DST
        try {
            $cutoffUtc = BackupController::purgeCutoffUtc(12);
            $sameInstant = strtotime('-12 months');
            $localRendering = date('Y-m-d H:i:s', $sameInstant);
        } finally {
            date_default_timezone_set($previousTz);
        }

        $cutoffTs = strtotime($cutoffUtc . ' UTC');
        self::assertNotFalse($cutoffTs, 'cutoff non parsable : ' . $cutoffUtc);
        self::assertLessThanOrEqual(
            5,
            abs($cutoffTs - $sameInstant),
            'le cutoff doit correspondre à maintenant - 12 mois'
        );
        self::assertNotSame(
            $localRendering,
            $cutoffUtc,
            'le cutoff ne doit pas être rendu dans le fuseau serveur (UTC+14)'
        );
    }

    /**
     * F5 — purge_count sous un fuseau serveur UTC+14 ne doit PAS compter une
     * soumission clôturée 7h après le cutoff UTC. Avec l'ancien date(), le
     * cutoff glissait de +14h et la rendait purgeable à tort.
     */
    public function testPurgeCountUsesUtcCutoffUnderServerTimezone(): void
    {
        $previousTz = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati');
        try {
            // 7h après le cutoff UTC → non purgeable en UTC, purgeable si le
            // cutoff est décalé de +14h (bug date()).
            $this->createClosedSubmissionAt(gmdate('Y-m-d H:i:s', strtotime('-12 months') + 7 * 3600));

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['action' => 'purge_count', 'csrf_token' => 'test', 'purge_months' => '12'];
            $output = $this->captureOutput(fn() => new BackupController()->handle());
        } finally {
            date_default_timezone_set($previousTz);
        }

        self::assertStringContainsString('Aucune donnée à purger pour cette période', $output);
    }

    /**
     * F5 — purge_confirm sous un fuseau serveur UTC+14 ne doit PAS supprimer
     * une soumission clôturée 7h après le cutoff UTC.
     */
    public function testPurgeConfirmUsesUtcCutoffUnderServerTimezone(): void
    {
        $previousTz = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati');
        try {
            $subId = $this->createClosedSubmissionAt(gmdate('Y-m-d H:i:s', strtotime('-12 months') + 7 * 3600));

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['action' => 'purge_confirm', 'csrf_token' => 'test', 'purge_months' => '12'];
            $output = $this->captureOutput(fn() => new BackupController()->handle());
        } finally {
            date_default_timezone_set($previousTz);
        }

        self::assertStringContainsString('Aucune soumission à purger pour la période de 12 mois', $output);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM submissions WHERE id = ?');
        $stmt->execute([$subId]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'la soumission post-cutoff UTC ne doit pas être purgée');
    }

    // ── Helpers ───────────────────────────────────────────────

    private function addAdmin(string $email): void
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO admins (id, email, added_at) VALUES (?, ?, datetime('now'))");
        $stmt->execute([\generate_uuid(), $email]);
    }

    private function removeAdmin(string $email): void
    {
        $pdo = $this->db->getPdo();
        $pdo->prepare("DELETE FROM admins WHERE email = ?")->execute([$email]);
    }

    private function createTestForm(string $slug): string
    {
        $pdo = $this->db->getPdo();
        $formId = \generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'BC Test Form', '', 1, datetime('now'))")
            ->execute([$formId, $slug]);
        $this->createdFormIds[] = $formId;
        return $formId;
    }

    /**
     * Crée une soumission clôturée il y a N mois (donc purgeable avec
     * purge_months=N ou moins).
     */
    private function createOldClosedSubmission(int $monthsAgo): string
    {
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm('test-bc-old-' . uniqid());

        $subId = \generate_uuid();
        $submittedAt = gmdate('Y-m-d H:i:s', strtotime("-{$monthsAgo} months"));
        $closedAt = gmdate('Y-m-d H:i:s', strtotime("-{$monthsAgo} months +1 hour"));
        $pdo->prepare(
            "INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, closed_at, status, rgpd_consent) "
            . "VALUES (?, ?, '{}', 'test-bc-agent@e2e.test', ?, ?, 'valide', 1)"
        )->execute([$subId, $formId, $submittedAt, $closedAt]);
        $this->createdSubmissionIds[] = $subId;
        return $subId;
    }

    /**
     * Crée une soumission clôturée à un instant UTC explicite (status=valide).
     */
    private function createClosedSubmissionAt(string $closedAt): string
    {
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm('test-bc-boundary-' . uniqid());

        $subId = \generate_uuid();
        $submittedAt = gmdate('Y-m-d H:i:s', strtotime('-13 months'));
        $pdo->prepare(
            "INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, closed_at, status, rgpd_consent) "
            . "VALUES (?, ?, '{}', 'test-bc-agent@e2e.test', ?, ?, 'valide', 1)"
        )->execute([$subId, $formId, $submittedAt, $closedAt]);
        $this->createdSubmissionIds[] = $subId;
        return $subId;
    }

    /**
     * Supprime les fichiers SQLite annexes (base, -wal, -shm) s'ils existent.
     */
    private function removeSidecars(string ...$paths): void
    {
        foreach ($paths as $path) {
            if (\file_exists($path)) {
                @\unlink($path);
            }
        }
    }

    /**
     * Capture un instantané cohérent de db/workflow.db (WAL inclus) via
     * VACUUM INTO. Un simple file_get_contents() du fichier principal perdrait
     * les pages non checkpointées : en mode WAL, les tables créées vivent dans
     * le journal -wal et le fichier .db peut ne contenir que l'en-tête (0 table).
     *
     * @return string Chemin du snapshot (jamais null — échec = test en erreur).
     */
    private function captureDbSnapshot(): string
    {
        $snapshot = BackupController::createConsistentSnapshot($this->dbPath);
        if ($snapshot === null) {
            self::fail('Impossible de capturer un instantané cohérent de db/workflow.db');
        }
        return $snapshot;
    }

    /**
     * Restaure db/workflow.db depuis un instantané cohérent et purge ses sidecars.
     *
     * Remplace l'ancienne recopie du seul fichier principal (file_get_contents +
     * file_put_contents) qui perdait le -wal non checkpointé et laissait la base
     * partagée sans aucune table pour les tests suivants (échec CI Linux).
     */
    private function restoreDbFile(string $snapshot): void
    {
        $this->db->release();
        if (\file_exists($snapshot)) {
            @\copy($snapshot, $this->dbPath);
            @\unlink($snapshot);
        }
        $this->removeSidecars($this->dbPath . '-wal', $this->dbPath . '-shm');
    }

    /**
     * Supprime les copies pré-restauration (workflow.db.before_restore_*) créées
     * par les tests de restauration.
     */
    private function cleanupPreRestoreBackups(): void
    {
        foreach (\glob($this->dbPath . '.before_restore_*') ?: [] as $backup) {
            @\unlink($backup);
        }
    }

    /**
     * Crée une base « étrangère » en WAL et retourne son journal -wal (valide)
     * ainsi que la connexion à garder ouverte (un checkpoint au close viderait
     * le WAL). Sert à reproduire un -wal orphelin de l'ancienne base.
     *
     * @return array{writer: \PDO, wal: string, db: string}
     */
    private function startForeignWalDb(string $tag): array
    {
        $db = sys_get_temp_dir() . '/bc_foreign_' . $tag . '_' . uniqid() . '.db';
        $writer = new \PDO('sqlite:' . $db);
        $writer->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $writer->exec('PRAGMA journal_mode = WAL');
        $writer->exec('PRAGMA wal_autocheckpoint = 0');
        $writer->exec('CREATE TABLE foreign_tbl (v TEXT)');
        $writer->prepare('INSERT INTO foreign_tbl (v) VALUES (?)')->execute(['FOREIGN']);
        $wal = (string) \file_get_contents($db . '-wal');

        return ['writer' => $writer, 'wal' => $wal, 'db' => $db];
    }

    /**
     * Ferme la connexion « étrangère » puis supprime ses fichiers.
     *
     * @param array{writer: \PDO|null, wal: string, db: string} $foreign
     */
    private function disposeForeignWalDb(array &$foreign): void
    {
        // Fermer AVANT de supprimer : sous Windows le handle ouvert bloque unlink.
        $foreign['writer'] = null;
        $this->removeSidecars($foreign['db'], $foreign['db'] . '-wal', $foreign['db'] . '-shm');
    }

    /**
     * Exécute un callable en capturant stdout. Attrape TestJsonCapturedException
     * levée par notre override de test_json_response.
     *
     * @param callable(): void $callable
     */
    private function captureOutput(callable $callable): string
    {
        ob_start();
        try {
            $callable();
        } catch (TestJsonCapturedException) {
            // JSON capturé — on continue
        } finally {
            $output = ob_get_clean();
        }
        return (string) $output;
    }
}

} // end namespace App\Tests\Controller
