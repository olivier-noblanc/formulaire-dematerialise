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

        $dbPath = defined('DB_PATH') ? DB_PATH : DEFAULT_DB_PATH;

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

                    if (strtolower(pathinfo((string) $origName, PATHINFO_EXTENSION)) !== 'db') {
                        $errorMsg = 'Seuls les fichiers .db sont acceptés. Fichier fourni : ' . App::html()->escape($origName);
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
                                $testCountStmt = $testPdo->query('SELECT COUNT(*) FROM sqlite_master');
                                if ($testCountStmt === false) {
                                    throw new \RuntimeException('Backup restore: COUNT query failed');
                                }
                                $testCountStmt->fetchColumn();
                                $testPdo = null;
                                App::audit()->log(
                                    'backup_restore',
                                    'database',
                                    'Base restaurée depuis le fichier : ' . App::html()->escape($origName)
                                    . ' (sauvegarde pré-restauration : ' . basename($backupBefore) . ')'
                                );
                                $successMsg = 'La base de données a été restaurée avec succès depuis « ' . App::html()->escape($origName) . ' ». '
                                               . 'Une copie de la base précédente a été conservée : ' . App::html()->escape(basename($backupBefore));
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
                $months = (int) ($_POST['purge_months'] ?? 0);
                if (!in_array($months, [6, 12, 18, 24], true)) {
                    $errorMsg = 'Valeur de mois invalide.';
                } else {
                    $cutoff = date('Y-m-d H:i:s', strtotime("-{$months} months"));
                    $purgePreview = [
                        'submissions'    => $this->submissionRepo->countPurgeableByCutoff($cutoff),
                        'tokens'         => $this->tokenRepo->countPurgeableByCutoff($cutoff),
                        'alert_logs'     => $this->alertRepo->countPurgeableByCutoff($cutoff),
                        'validator_data' => $this->submissionRepo->countValidatorDataPurgeable($cutoff),
                    ];
                    $purgePreview['months'] = $months;
                }
            }

            if ($action === 'purge_confirm') {
                $months = (int) ($_POST['purge_months'] ?? 0);
                if (!in_array($months, [6, 12, 18, 24], true)) {
                    $errorMsg = 'Valeur de mois invalide.';
                } else {
                    $cutoff = date('Y-m-d H:i:s', strtotime("-{$months} months"));
                    $preview = [
                        'submissions'    => $this->submissionRepo->countPurgeableByCutoff($cutoff),
                        'tokens'         => $this->tokenRepo->countPurgeableByCutoff($cutoff),
                        'alert_logs'     => $this->alertRepo->countPurgeableByCutoff($cutoff),
                        'validator_data' => $this->submissionRepo->countValidatorDataPurgeable($cutoff),
                    ];

                    if ($preview['submissions'] === 0) {
                        $infoMsg = 'Aucune soumission à purger pour la période de ' . $months . ' mois.';
                    } else {
                        try {
                            $this->db->enableForeignKeys();

                            $ids = $this->submissionRepo->findPurgeableIds($cutoff);

                            if (!empty($ids)) {
                                $validatorDataDeleted = $this->submissionRepo->deleteValidatorDataBySubmissionIds($ids);
                                $alertLogsDeleted = $this->alertRepo->deleteLogBySubmissionIds($ids);
                                $tokensDeleted = $this->tokenRepo->deleteBySubmissionIds($ids);
                                $submissionsDeleted = $this->submissionRepo->deleteByIds($ids);

                                $this->db->vacuum();

                                App::audit()->log(
                                    'purge_data',
                                    'database',
                                    "Purge effectuée : {$submissionsDeleted} soumissions, "
                                    . "{$tokensDeleted} tokens, {$alertLogsDeleted} alert_logs, "
                                    . "{$validatorDataDeleted} submission_validator_data "
                                    . "(soumissions clôturées depuis + de {$months} mois, avant le {$cutoff})"
                                );

                                $successMsg = 'Purge effectuée avec succès : '
                                    . "<strong>{$submissionsDeleted}</strong> soumission(s), "
                                    . "<strong>{$tokensDeleted}</strong> token(s), "
                                    . "<strong>{$alertLogsDeleted}</strong> alerte(s), "
                                    . "<strong>{$validatorDataDeleted}</strong> donnée(s) validateur "
                                    . "supprimée(s) (données clôturées depuis plus de {$months} mois).";
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
        $dbStats['file_size_readable'] = $this->formatBytes((int) $dbStats['file_size']);
        $dbStats['file_exists'] = file_exists($dbPath);
        $dbStats['file_modified'] = '—';
        if (file_exists($dbPath)) {
            $mtime = filemtime($dbPath);
            $dbStats['file_modified'] = $mtime !== false ? date('d/m/Y H:i:s', $mtime) : '—';
        }

        $dbStats['row_counts'] = [];
        try {
            $dbStats['row_counts'] = $this->submissionRepo->countByTableNames($dbTables);
        } catch (\Exception $e) {
            foreach ($dbTables as $dbTable) {
                $dbStats['row_counts'][$dbTable] = '—';
            }
            error_log('backup row count error: ' . $e->getMessage());
        }

        $oldestStr = $this->submissionRepo->getOldestSubmittedAt() ?? '';
        $newestStr = $this->submissionRepo->getNewestSubmittedAt() ?? '';
        $oldestTs = $oldestStr !== '' ? strtotime($oldestStr) : false;
        $newestTs = $newestStr !== '' ? strtotime($newestStr) : false;
        $dbStats['oldest_submission'] = ($oldestStr !== '' && $oldestTs !== false) ? date('d/m/Y H:i', $oldestTs) : '—';
        $dbStats['newest_submission'] = ($newestStr !== '' && $newestTs !== false) ? date('d/m/Y H:i', $newestTs) : '—';

        $pageCount = $this->db->getPageCount();
        $freelistCount = $this->db->getFreelistCount();
        $pageSize = $this->db->getPageSize();
        $dbStats['page_count']     = $pageCount;
        $dbStats['freelist_count'] = $freelistCount;
        $dbStats['page_size']      = $pageSize;
        $dbStats['db_size_pages']  = $this->formatBytes($pageCount * $pageSize);
        $dbStats['free_pages']     = $this->formatBytes($freelistCount * $pageSize);

        $purgePreview ??= null;

        new BackupRenderer()->renderPage($dbPath, $dbStats, $purgePreview, $successMsg, $errorMsg, $infoMsg);
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
        return $header !== false && str_starts_with($header, 'SQLite format 3');
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        if ($bytes <= 0) {
            return '0 o';
        }
        $units = ['o', 'Ko', 'Mo', 'Go'];
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);
        return round($bytes / 1024 ** $power, $precision) . ' ' . $units[$power];
    }
}
