<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;
use App\Render\BackupRenderer;

/**
 * Contrôleur de la page Sauvegarde et restauration de la base de données.
 */
final class BackupController extends BaseController
{
    public function handle(): void
    {
        App::auth()->requireAdmin();

        $successMsg = '';
        $errorMsg   = '';
        $infoMsg    = '';

        $dbPath = defined('DB_PATH') ? DB_PATH : dirname(__DIR__, 2) . '/db/workflow.db';

        $dbTables = ['forms', 'steps', 'step_recipients', 'submissions', 'tokens',
                      'admins', 'admin_requests', 'settings', 'form_fields',
                      'audit_log', 'alert_rules', 'alert_log',
                      'submission_validator_data'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->security->requireCsrf();
            $action = $_POST['action'] ?? '';

            if ($action === 'download_backup') {
                if (!file_exists($dbPath)) {
                    $errorMsg = 'Le fichier de base de données est introuvable.';
                } else {
                    $filename = 'workflow_backup_' . date('Ymd_His') . '.db';
                    App::audit()->log('backup_download', 'database', 'Téléchargement sauvegarde : ' . $filename);
                    header('Content-Type: application/x-sqlite3');
                    header('Content-Disposition: attachment; filename="' . $filename . '"');
                    header('Content-Length: ' . filesize($dbPath));
                    header('Cache-Control: no-cache, must-revalidate');
                    header('Pragma: no-cache');
                    readfile($dbPath);
                    exit;
                }
            }

            if ($action === 'restore_backup') {
                if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
                    $uploadErrors = [
                        UPLOAD_ERR_INI_SIZE   => 'Le fichier dépasse la taille maximale autorisée par le serveur (upload_max_filesize).',
                        UPLOAD_ERR_FORM_SIZE  => 'Le fichier dépasse la taille maximale autorisée par le formulaire.',
                        UPLOAD_ERR_PARTIAL    => 'Le fichier n\'a été que partiellement téléchargé.',
                        UPLOAD_ERR_NO_FILE    => 'Aucun fichier n\'a été téléchargé.',
                        UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant sur le serveur.',
                        UPLOAD_ERR_CANT_WRITE => 'Échec de l\'écriture du fichier sur le disque.',
                        UPLOAD_ERR_EXTENSION  => 'Téléchargement bloqué par une extension PHP.',
                    ];
                    $code = $_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE;
                    $errorMsg = $uploadErrors[$code] ?? 'Erreur inconnue lors du téléchargement.';
                } else {
                    $tmpPath  = $_FILES['backup_file']['tmp_name'];
                    $origName = $_FILES['backup_file']['name'];

                    if (strtolower(pathinfo($origName, PATHINFO_EXTENSION)) !== 'db') {
                        $errorMsg = 'Seuls les fichiers .db sont acceptés. Fichier fourni : ' . \App\Core\App::html()->escape($origName);
                    } elseif (!$this->isValidSqliteDb($tmpPath)) {
                        $errorMsg = 'Le fichier fourni n\'est pas une base de données SQLite valide. Vérifiez le fichier et réessayez.';
                    } else {
                        App::db()->release();
                        $backupBefore = $dbPath . '.before_restore_' . date('Ymd_His');
                        if (file_exists($dbPath)) {
                            copy($dbPath, $backupBefore);
                        }
                        if (move_uploaded_file($tmpPath, $dbPath)) {
                            try {
                                $testPdo = new \PDO('sqlite:' . $dbPath);
                                $testPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                                $testPdo->query('SELECT COUNT(*) FROM sqlite_master')->fetchColumn();
                                $testPdo = null;
                                App::audit()->log('backup_restore', 'database',
                                    'Base restaurée depuis le fichier : ' . \App\Core\App::html()->escape($origName) .
                                    ' (sauvegarde pré-restauration : ' . basename($backupBefore) . ')');
                                $successMsg = 'La base de données a été restaurée avec succès depuis « ' . \App\Core\App::html()->escape($origName) . ' ». ' .
                                               'Une copie de la base précédente a été conservée : ' . \App\Core\App::html()->escape(basename($backupBefore));
                            } catch (\Exception $e) {
                                if (file_exists($backupBefore)) {
                                    copy($backupBefore, $dbPath);
                                }
                                error_log('backup_restore error: ' . $e->getMessage());
                                $errorMsg = 'La base restaurée semble corrompue. La base d\'origine a été rétablie.';
                            }
                        } else {
                            $errorMsg = 'Impossible de remplacer le fichier de base de données. Vérifiez les permissions du dossier db/.';
                            if (file_exists($backupBefore)) {
                                @unlink($backupBefore);
                            }
                        }
                    }
                }
            }

            if ($action === 'purge_count') {
                $months = (int)($_POST['purge_months'] ?? 0);
                if (!in_array($months, [6, 12, 18, 24], true)) {
                    $errorMsg = 'Valeur de mois invalide.';
                } else {
                    $purgePreview = $this->countPurgeTargets($months);
                    $purgePreview['months'] = $months;
                }
            }

            if ($action === 'purge_confirm') {
                $months = (int)($_POST['purge_months'] ?? 0);
                if (!in_array($months, [6, 12, 18, 24], true)) {
                    $errorMsg = 'Valeur de mois invalide.';
                } else {
                    $preview = $this->countPurgeTargets($months);

                    if ($preview['submissions'] === 0) {
                        $infoMsg = 'Aucune soumission à purger pour la période de ' . $months . ' mois.';
                    } else {
                        try {
                            $pdo = $this->db->getPdo();
                            $pdo->exec('PRAGMA foreign_keys = ON');
                            $cutoff = date('Y-m-d H:i:s', strtotime("-{$months} months"));

                            $stmtIds = $pdo->prepare("
                                SELECT id FROM submissions
                                WHERE status IN ('valide', 'refuse')
                                  AND closed_at IS NOT NULL
                                  AND closed_at < ?
                            ");
                            $stmtIds->execute([$cutoff]);
                            $ids = $stmtIds->fetchAll(\PDO::FETCH_COLUMN);

                            if (!empty($ids)) {
                                $placeholders = implode(',', array_fill(0, count($ids), '?'));

                                $stmtDelVd = $pdo->prepare(
                                    "DELETE FROM submission_validator_data WHERE submission_id IN ($placeholders)"
                                );
                                $stmtDelVd->execute($ids);
                                $validatorDataDeleted = $stmtDelVd->rowCount();

                                $stmtDelAlerts = $pdo->prepare("DELETE FROM alert_log WHERE submission_id IN ($placeholders)");
                                $stmtDelAlerts->execute($ids);
                                $alertLogsDeleted = $stmtDelAlerts->rowCount();

                                $stmtDelTokens = $pdo->prepare("DELETE FROM tokens WHERE submission_id IN ($placeholders)");
                                $stmtDelTokens->execute($ids);
                                $tokensDeleted = $stmtDelTokens->rowCount();

                                $stmtDelSubs = $pdo->prepare("DELETE FROM submissions WHERE id IN ($placeholders)");
                                $stmtDelSubs->execute($ids);
                                $submissionsDeleted = $stmtDelSubs->rowCount();

                                $pdo->exec('VACUUM');

                                App::audit()->log('purge_data', 'database',
                                    "Purge effectuée : {$submissionsDeleted} soumissions, " .
                                    "{$tokensDeleted} tokens, {$alertLogsDeleted} alert_logs, " .
                                    "{$validatorDataDeleted} submission_validator_data " .
                                    "(soumissions clôturées depuis + de {$months} mois, avant le {$cutoff})");

                                $successMsg = "Purge effectuée avec succès : " .
                                    "<strong>{$submissionsDeleted}</strong> soumission(s), " .
                                    "<strong>{$tokensDeleted}</strong> token(s), " .
                                    "<strong>{$alertLogsDeleted}</strong> alerte(s), " .
                                    "<strong>{$validatorDataDeleted}</strong> donnée(s) validateur " .
                                    "supprimée(s) (données clôturées depuis plus de {$months} mois).";
                            } else {
                                $infoMsg = 'Aucune donnée à purger.';
                            }
                        } catch (\Exception $e) {
                            error_log('purge_confirm error: ' . $e->getMessage());
                            $errorMsg = 'Une erreur technique est survenue.';
                        }
                    }
                }
            }
        }

        $dbStats = [];
        $dbStats['file_size'] = filesize($dbPath);
        $dbStats['file_size_readable'] = $this->formatBytes($dbStats['file_size']);
        $dbStats['file_exists'] = file_exists($dbPath);
        $dbStats['file_modified'] = '—';
        if (file_exists($dbPath)) {
            $mtime = filemtime($dbPath);
            $dbStats['file_modified'] = $mtime !== false ? date('d/m/Y H:i:s', $mtime) : '—';
        }

        $dbStats['row_counts'] = [];
        try {
            $pdo = $this->db->getPdo();
            $unionParts = [];
            foreach ($dbTables as $table) {
                $unionParts[] = "SELECT '" . $table . "' AS tbl, COUNT(*) AS cnt FROM " . $table;
            }
            try {
                $countStmt = $pdo->query(implode(' UNION ALL ', $unionParts));
                while ($countStmt !== false && $row = $countStmt->fetch(\PDO::FETCH_ASSOC)) {
                    $dbStats['row_counts'][$row['tbl']] = (int)$row['cnt'];
                }
            } catch (\Exception $e) {
                foreach ($dbTables as $table) {
                    $dbStats['row_counts'][$table] = '—';
                }
                error_log('backup row count error: ' . $e->getMessage());
            }

            $oldest = $pdo->query("SELECT MIN(submitted_at) FROM submissions")->fetchColumn();
            $newest = $pdo->query("SELECT MAX(submitted_at) FROM submissions")->fetchColumn();
            $oldestStr = $oldest !== false ? (string)$oldest : '';
            $newestStr = $newest !== false ? (string)$newest : '';
            $oldestTs = $oldestStr !== '' ? strtotime($oldestStr) : false;
            $newestTs = $newestStr !== '' ? strtotime($newestStr) : false;
            $dbStats['oldest_submission'] = ($oldestStr !== '' && $oldestTs !== false) ? date('d/m/Y H:i', $oldestTs) : '—';
            $dbStats['newest_submission'] = ($newestStr !== '' && $newestTs !== false) ? date('d/m/Y H:i', $newestTs) : '—';

            $pageCount    = (int)$pdo->query("PRAGMA page_count")->fetchColumn();
            $freelistCount = (int)$pdo->query("PRAGMA freelist_count")->fetchColumn();
            $pageSize     = (int)$pdo->query("PRAGMA page_size")->fetchColumn();
            $dbStats['page_count']     = $pageCount;
            $dbStats['freelist_count'] = $freelistCount;
            $dbStats['page_size']      = $pageSize;
            $dbStats['db_size_pages']  = $this->formatBytes($pageCount * $pageSize);
            $dbStats['free_pages']     = $this->formatBytes($freelistCount * $pageSize);
        } catch (\Exception $e) {
            error_log('dbStats error: ' . $e->getMessage());
            $dbStats['error'] = 'Une erreur technique est survenue.';
        }

        $purgePreview = $purgePreview ?? null;

        (new BackupRenderer())->renderPage($dbPath, $dbStats, $purgePreview, $successMsg, $errorMsg, $infoMsg);
    }

    private function isValidSqliteDb(string $path): bool
    {
        if (!file_exists($path) || filesize($path) < 16) {
            return false;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $header = fread($handle, 16);
        fclose($handle);
        return $header !== false && strpos($header, 'SQLite format 3') === 0;
    }

    private function countPurgeTargets(int $months): array
    {
        $pdo = $this->db->getPdo();
        $cutoffTs = strtotime("-{$months} months");
        $cutoff = date('Y-m-d H:i:s', $cutoffTs !== false ? $cutoffTs : 0);

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM submissions
            WHERE status IN ('valide', 'refuse')
              AND closed_at IS NOT NULL
              AND closed_at < ?
        ");
        $stmt->execute([$cutoff]);
        $submissions = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM tokens t
            JOIN submissions s ON s.id = t.submission_id
            WHERE s.status IN ('valide', 'refuse')
              AND s.closed_at IS NOT NULL
              AND s.closed_at < ?
        ");
        $stmt->execute([$cutoff]);
        $tokens = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM alert_log al
            JOIN submissions s ON s.id = al.submission_id
            WHERE s.status IN ('valide', 'refuse')
              AND s.closed_at IS NOT NULL
              AND s.closed_at < ?
        ");
        $stmt->execute([$cutoff]);
        $alertLogs = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM submission_validator_data svd
            JOIN submissions s ON s.id = svd.submission_id
            WHERE s.status IN ('valide', 'refuse')
              AND s.closed_at IS NOT NULL
              AND s.closed_at < ?
        ");
        $stmt->execute([$cutoff]);
        $validatorData = (int)$stmt->fetchColumn();

        return [
            'submissions'       => $submissions,
            'tokens'            => $tokens,
            'alert_logs'        => $alertLogs,
            'validator_data'    => $validatorData,
        ];
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        if ($bytes <= 0) return '0 o';
        $units = ['o', 'Ko', 'Mo', 'Go'];
        $power = (int)floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);
        return round($bytes / pow(1024, $power), $precision) . ' ' . $units[$power];
    }
}
