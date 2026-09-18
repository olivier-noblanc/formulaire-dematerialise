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
    /**
     * Tables pivot dont la présence identifie une base CircuitDémat. Une base
     * SQLite étrangère (autre application, autre schéma) n'a aucune raison de
     * les contenir toutes.
     *
     * @var list<string>
     */
    private const array REQUIRED_PIVOT_TABLES = ['forms', 'submissions', 'tokens', 'steps', 'settings'];

    public function handle(): void
    {
        App::auth()->requireAdminEffective();

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
                    // F3 : un readfile() brut du .db ignore les transactions
                    // encore présentes dans le WAL non checkpointé → sauvegarde
                    // potentiellement incohérente. On sert un instantané cohérent.
                    $snapshotPath = self::createConsistentSnapshot($dbPath);
                    if ($snapshotPath === null) {
                        $errorMsg = 'Impossible de préparer un instantané cohérent de la base. Téléchargement annulé.';
                    } else {
                        $filename = 'workflow_backup_' . date('Ymd_His') . '.db';
                        App::audit()->log('backup_download', 'database', 'Téléchargement sauvegarde : ' . $filename);
                        header('Content-Type: application/x-sqlite3');
                        header('Content-Disposition: attachment; filename="' . $filename . '"');
                        header('Content-Length: ' . filesize($snapshotPath));
                        header('Cache-Control: no-cache, must-revalidate');
                        header('Pragma: no-cache');
                        readfile($snapshotPath);
                        @unlink($snapshotPath);
                        exit;
                    }
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
                    $code = (int) ($_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE);
                    $errorMsg = $uploadErrors[$code] ?? 'Erreur inconnue lors du téléchargement.';
                } else {
                    $tmpPath  = $_FILES['backup_file']['tmp_name'];
                    $origName = $_FILES['backup_file']['name'];

                    if (strtolower(pathinfo((string) $origName, PATHINFO_EXTENSION)) !== 'db') {
                        $errorMsg = 'Seuls les fichiers .db sont acceptés. Fichier fourni : ' . App::html()->escape($origName);
                    } elseif (!$this->isValidSqliteDb($tmpPath)) {
                        $errorMsg = 'Le fichier fourni n\'est pas une base de données SQLite valide. Vérifiez le fichier et réessayez.';
                    } elseif (!$this->isCircuitDematDatabase($tmpPath)) {
                        // BUG5 (audit 2026-09-17) : refus AVANT tout remplacement.
                        // L'en-tête SQLite seul ne prouve pas que le fichier est
                        // une base CircuitDémat — une base étrangère valide
                        // écraserait la base applicative et toutes ses données.
                        $errorMsg = 'Le fichier fourni n\'est pas une base de données CircuitDémat : '
                            . 'les tables requises (forms, submissions, tokens, steps, settings) sont absentes. '
                            . 'Restauration refusée pour protéger la base actuelle.';
                    } else {
                        // P1-C : identité AVANT remplacement. On fixe l'empreinte
                        // SHA-256 du fichier réellement validé (upload) avant que
                        // move_uploaded_file() ne le déplace ; elle sera comparée à
                        // l'empreinte de la base en place après le move.
                        $expectedHash = hash_file('sha256', $tmpPath);
                        App::db()->release();
                        $backupBefore = $dbPath . '.before_restore_' . date('Ymd_His');
                        // B-02-2 fix (audit 2026-07-26) : copy() retournait false silencieusement
                        // si le disque était plein ou permissions insuffisantes, puis move_uploaded_file
                        // écrasait quand même la DB originale → perte de données silencieuse.
                        // Maintenant on vérifie le retour de la sauvegarde et on abort si échec.
                        // F3 (audit 2026-09-16) : la copie pré-restauration est un instantané
                        // cohérent (VACUUM INTO, WAL inclus) et non un copy() brut — sinon le
                        // rollback rejouerait une base incomplète.
                        $backupOk = true;
                        if (file_exists($dbPath)) {
                            $backupOk = self::createConsistentSnapshot($dbPath, $backupBefore) !== null;
                            if (!$backupOk) {
                                $errorMsg = 'Impossible de créer la sauvegarde pré-restauration (permissions disque ?). Restauration annulée pour protéger la base actuelle.';
                                error_log('backup_restore: snapshot failed to create ' . $backupBefore);
                            }
                        }
                        if ($backupOk && move_uploaded_file($tmpPath, $dbPath)) {
                            // F4 (audit 2026-09-16) : supprimer les -wal/-shm de
                            // l'ancienne base AVANT toute ouverture. Sinon SQLite
                            // rejouerait un journal étranger sur la base restaurée.
                            // P1-C : removeWalSidecars() retourne false si un sidecar
                            // survit (verrou Windows) — impossible d'annoncer un succès.
                            $restoreFailed = false;
                            $failureCause = 'La base restaurée semble corrompue';
                            $failureLog = '';

                            if (!self::removeWalSidecars($dbPath)) {
                                $restoreFailed = true;
                                $failureCause = 'Un journal WAL de l\'ancienne base n\'a pas pu être supprimé';
                                $failureLog = 'WAL sidecar(s) survived removal';
                            } elseif ($expectedHash === false) {
                                // Empreinte du fichier validé indisponible : impossible
                                // de prouver l'identité de la base désormais en place.
                                $restoreFailed = true;
                                $failureLog = 'uploaded db hash unavailable';
                            } else {
                                // P1-C : identité APRÈS remplacement. L'empreinte
                                // SHA-256 de la base en place doit correspondre à
                                // celle du fichier validé avant le move — sinon le
                                // contenu restauré n'est pas celui qui a passé les
                                // pré-contrôles (troncature, écriture partielle).
                                $actualHash = @hash_file('sha256', $dbPath);
                                if ($actualHash === false || !hash_equals($expectedHash, $actualHash)) {
                                    $restoreFailed = true;
                                    $failureLog = 'restored db hash mismatch';
                                } elseif (!$this->isCircuitDematDatabase($dbPath)) {
                                    // Sanity-check de la DB réellement en place : elle
                                    // doit toujours contenir les tables pivot CircuitDémat.
                                    // Un simple COUNT(*) sur sqlite_master était insuffisant :
                                    // SQLite ouvre un fichier vide/tronqué comme une base
                                    // valide et renvoie 0 sans exception → « restaurée avec
                                    // succès » sur une base vide, puis erreurs "no such
                                    // table" à la première requête. Source unique de la
                                    // liste des tables pivot (BUG5). allowIn:
                                    // disallowed-calls.neon → PDO::query().
                                    $restoreFailed = true;
                                    $failureLog = 'restored db is not a CircuitDemat database';
                                }
                            }

                            if (!$restoreFailed) {
                                App::audit()->log(
                                    'backup_restore',
                                    'database',
                                    'Base restaurée depuis le fichier : ' . App::html()->escape($origName)
                                    . ' (sauvegarde pré-restauration : ' . basename($backupBefore) . ')'
                                );
                                $successMsg = 'La base de données a été restaurée avec succès depuis « ' . App::html()->escape($origName) . ' ». '
                                               . 'Une copie de la base précédente a été conservée : ' . App::html()->escape(basename($backupBefore));
                            } else {
                                // B-02-3 fix : copy() de secours non vérifié — si échec, message
                                // disait 'rétablie' alors que la DB était vide/corrompue.
                                // F4 : retirer aussi les -wal/-shm de la base restaurée
                                // corrompue avant de recopier la sauvegarde d'origine.
                                // P1-C : si un sidecar résiste encore, le rollback n'est
                                // pas fiable → échec explicite (jamais de faux 'rétablie').
                                $sidecarsOk = self::removeWalSidecars($dbPath);
                                $rollbackOk = false;
                                if (file_exists($backupBefore)) {
                                    $rollbackOk = @copy($backupBefore, $dbPath) && $sidecarsOk;
                                }
                                error_log('backup_restore error: ' . $failureLog
                                    . ' | sidecar removal: ' . ($sidecarsOk ? 'ok' : 'FAILED')
                                    . ' | rollback copy: ' . ($rollbackOk ? 'ok' : 'FAILED'));
                                if ($rollbackOk) {
                                    $errorMsg = $failureCause . '. La base d\'origine a été rétablie.';
                                } else {
                                    $errorMsg = $failureCause . ' ET la restauration de secours a échoué. La sauvegarde manuelle est disponible : ' . App::html()->escape(basename($backupBefore)) . '. Contactez l\'administrateur.';
                                }
                            }
                        } elseif ($backupOk) {
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
                    $cutoff = self::purgeCutoffUtc($months);
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
                    $cutoff = self::purgeCutoffUtc($months);
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

                            if ($ids !== []) {
                                // BUG6 (audit 2026-09-17) : les 4 suppressions
                                // doivent être atomiques. BEGIN IMMEDIATE (BUG3)
                                // acquiert le verrou d'écriture dès l'ouverture ;
                                // toute exception (même un \Error, pas seulement
                                // \Exception) rollback puis remonte, laissant la
                                // base intacte.
                                $this->submissionRepo->beginImmediateTransaction();
                                try {
                                    $validatorDataDeleted = $this->submissionRepo->deleteValidatorDataBySubmissionIds($ids);
                                    $alertLogsDeleted = $this->alertRepo->deleteLogBySubmissionIds($ids);
                                    $tokensDeleted = $this->tokenRepo->deleteBySubmissionIds($ids);
                                    $submissionsDeleted = $this->submissionRepo->deleteByIds($ids);
                                    $this->submissionRepo->commit();
                                } catch (\Throwable $e) {
                                    if ($this->submissionRepo->inTransaction()) {
                                        $this->submissionRepo->rollBack();
                                    }
                                    throw $e;
                                }

                                // Post-commit uniquement : VACUUM ne peut pas
                                // s'exécuter dans une transaction, et l'audit
                                // doit tracer une purge réellement committée.
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
                        } catch (\Throwable $e) {
                            // @silent-ok: fallback sets user-facing error
                            // règle 9 catégorie 2 : panne DB attendue → message
                            // utilisateur + trace. La transaction est déjà fermée
                            // (rollback dans le catch interne ci-dessus).
                            error_log('purge_confirm error: ' . $e->getMessage());
                            $errorMsg = 'Une erreur technique est survenue.';
                        }
                    }
                }
            }
        }

        $dbStats = [];
        // B-02-7 fix (audit 2026-07-26) : filesize() était appelé AVANT file_exists()
        // → warning PHP si le fichier n'existe pas. On inverse l'ordre.
        $dbStats['file_exists'] = file_exists($dbPath);
        $dbStats['file_size'] = $dbStats['file_exists'] ? filesize($dbPath) : false;
        $dbStats['file_size_readable'] = $this->formatBytes((int) $dbStats['file_size']);
        $dbStats['file_modified'] = '—';
        if ($dbStats['file_exists']) {
            $mtime = filemtime($dbPath);
            $dbStats['file_modified'] = $mtime !== false ? date('d/m/Y H:i:s', $mtime) : '—';
        }

        $dbStats['row_counts'] = [];
        try {
            $dbStats['row_counts'] = $this->submissionRepo->countByTableNames($dbTables);
        } catch (\Exception $e) {
            // @silent-ok: fallback sets placeholder values
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

    /**
     * Crée un instantané cohérent de la base (WAL inclus) via VACUUM INTO.
     *
     * F3 (audit 2026-09-16) : un readfile()/copy() brut du fichier .db ignore
     * les transactions encore présentes dans le journal WAL non checkpointé —
     * la sauvegarde téléchargée ou la copie pré-restauration pouvait donc être
     * incohérente (données récentes absentes, voire base illisible). VACUUM INTO
     * produit un fichier unique et cohérent intégrant le WAL.
     *
     * @param string      $sourceDbPath Base à instantanéiser.
     * @param string|null $destination  Chemin cible. Par défaut un fichier unique
     *                                  sous sys_get_temp_dir().
     *
     * @return string|null Chemin du snapshot, ou null si la création a échoué.
     */
    public static function createConsistentSnapshot(string $sourceDbPath, ?string $destination = null): ?string
    {
        $destination ??= sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'workflow_backup_snapshot_' . getmypid() . '_' . uniqid('', true) . '.db';

        try {
            // VACUUM INTO refuse une destination préexistante.
            if (file_exists($destination)) {
                @unlink($destination);
            }
            // Connexion dédiée (pas App::db(), déjà libéré côté restore) sur le
            // fichier à sauvegarder. allowIn: disallowed-calls.neon → PDO::query().
            $source = new \PDO('sqlite:' . $sourceDbPath);
            $source->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $source->query('VACUUM INTO ' . $source->quote($destination));
            $source = null;
        } catch (\Throwable $e) {
            // @silent-ok: échec retourné à l'appelant (null) puis surfacé en message d'erreur utilisateur.
            error_log('backup snapshot error: ' . $e->getMessage());
            return null;
        }

        return $destination;
    }

    /**
     * Supprime les fichiers annexes WAL/SHM d'une base SQLite.
     *
     * F4 (audit 2026-09-16) : après remplacement du fichier .db (restauration
     * ou rollback), un -wal/-shm résiduel de l'ANCIENNE base ne doit pas
     * subsister à côté de la nouvelle — SQLite le rejouerait et corromprait
     * la base restaurée.
     *
     * P1-C : la suppression est vérifiée. Sous Windows un sidecar peut rester
     * verrouillé (handle ouvert) : `@unlink()` échoue alors silencieusement.
     * On relit l'existence après suppression et on retourne false si un sidecar
     * survit, pour que l'appelant refuse/rollback au lieu de poursuivre sur une
     * base que SQLite corromprait à la réouverture.
     *
     * @return bool true si aucun sidecar ne subsiste, false sinon.
     */
    public static function removeWalSidecars(string $dbPath): bool
    {
        $sidecars = [$dbPath . '-wal', $dbPath . '-shm'];
        foreach ($sidecars as $sidecar) {
            if (file_exists($sidecar)) {
                @unlink($sidecar);
            }
        }
        return array_all($sidecars, fn(string $sidecar): bool => !file_exists($sidecar));
    }

    /**
     * Calcule le cutoff de purge en UTC.
     *
     * F5 (audit 2026-09-16) : closed_at est stocké en UTC (datetime('now') en
     * SQLite, gmdate côté PHP). Un cutoff calculé avec date() (fuseau serveur,
     * Europe/Paris en prod) décalait la fenêtre de 1-2h et faussait le
     * comptage comme la purge réelle.
     */
    public static function purgeCutoffUtc(int $months): string
    {
        $cutoffTs = strtotime("-{$months} months");
        return gmdate('Y-m-d H:i:s', $cutoffTs !== false ? $cutoffTs : time());
    }

    /**
     * Vérifie que le fichier est bien une base CircuitDémat et non une base
     * SQLite étrangère, en exigeant la présence de toutes les tables pivot.
     *
     * BUG5 (audit 2026-09-17) : isValidSqliteDb() ne contrôlait que l'en-tête
     * « SQLite format 3 » — n'importe quel .db SQLite (autre application, autre
     * schéma) passait la validation et remplaçait la base applicative, soit une
     * perte totale des données. On refuse AVANT tout remplacement.
     */
    private function isCircuitDematDatabase(string $path): bool
    {
        try {
            // Connexion dédiée en lecture sur le fichier uploadé (pas App::db()).
            // allowIn: disallowed-calls.neon → PDO::query() pour BackupController.
            $pdo = new \PDO('sqlite:' . $path);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            // Noms issus d'une constante interne (jamais d'entrée utilisateur) :
            // interpolation sûre, pas de placeholder à faire correspondre.
            $names = "'" . implode("', '", self::REQUIRED_PIVOT_TABLES) . "'";
            $stmt = $pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ({$names})"
            );
            if ($stmt === false) {
                return false;
            }
            $found = (int) $stmt->fetchColumn();
            $stmt = null;
            $pdo = null;
            return $found === count(self::REQUIRED_PIVOT_TABLES);
        } catch (\Throwable $e) {
            // @silent-ok: fichier illisible/étranger → refus de restauration (retour false surfacé en message d'erreur utilisateur)
            error_log('backup restore: rejected non-CircuitDemat db: ' . $e->getMessage());
            return false;
        }
    }

    private function isValidSqliteDb(string $path): bool
    {
        if (!file_exists($path)) {
            return false;
        }
        $size = @filesize($path);
        if ($size === false || $size < 16) {
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
