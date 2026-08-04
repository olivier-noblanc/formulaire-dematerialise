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
        $GLOBALS['_test_mails'] = [];
        $GLOBALS['_test_captured_json'] = null;
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
        $output = $this->captureOutput(fn() => (new BackupController())->handle());

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

        $output = $this->captureOutput(fn() => (new BackupController())->handle());

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
            fn() => (new BackupController())->handle(),
            expectJsonCapture: true
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

        $output = $this->captureOutput(fn() => (new BackupController())->handle());

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

        $output = $this->captureOutput(fn() => (new BackupController())->handle());

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
            $output = $this->captureOutput(fn() => (new BackupController())->handle());
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
        // Créer un fichier .db SQLite valide
        $validDbPath = sys_get_temp_dir() . '/test_valid_' . uniqid() . '.db';
        $tmpPdo = new \PDO('sqlite:' . $validDbPath);
        $tmpPdo->exec('CREATE TABLE forms (id TEXT)');
        unset($tmpPdo);

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
            $output = $this->captureOutput(fn() => (new BackupController())->handle());
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

    // ── Tests POST purge_count ────────────────────────────────

    /**
     * POST action=purge_count avec une valeur de mois invalide doit
     * afficher « Valeur de mois invalide. »
     */
    public function testHandlePostPurgeCountWithInvalidMonthsReturnsError(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'purge_count', 'csrf_token' => 'test', 'purge_months' => '999'];

        $output = $this->captureOutput(fn() => (new BackupController())->handle());

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

        $output = $this->captureOutput(fn() => (new BackupController())->handle());

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

        $this->captureOutput(fn() => (new BackupController())->handle());

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

        $output = $this->captureOutput(fn() => (new BackupController())->handle());

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

        $output = $this->captureOutput(fn() => (new BackupController())->handle());

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

        $output = $this->captureOutput(fn() => (new BackupController())->handle());

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

        $output = $this->captureOutput(fn() => (new BackupController())->handle());

        self::assertStringContainsString('Sauvegarde et restauration', $output);
        // Ni message de succès, ni message d'erreur
        self::assertStringNotContainsString('msg-success', $output);
        self::assertStringNotContainsString('msg-error', $output);
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
     * Exécute un callable en capturant stdout. Attrape TestJsonCapturedException
     * levée par notre override de test_json_response.
     *
     * @param callable(): void $callable
     */
    private function captureOutput(callable $callable, bool $expectJsonCapture = false): string
    {
        ob_start();
        try {
            $callable();
        } catch (TestJsonCapturedException $e) {
            // JSON capturé — on continue
        } finally {
            $output = ob_get_clean();
        }
        return (string) $output;
    }
}

} // end namespace App\Tests\Controller
