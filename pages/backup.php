<?php
declare(strict_types=1);

// backup.php — Sauvegarde et restauration de la base de données (admin uniquement)
//
// Le rendu HTML est extrait vers lib/render_backup.php pour garder ce
// fichier sous 600 lignes (refactor « all-under-600 »). Ce fichier garde
// la logique métier : handlers POST (download_backup / restore_backup /
// purge_count / purge_confirm), fonctions utilitaires (is_valid_sqlite_db,
// count_purge_targets), calcul des statistiques DB et format_bytes().
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/lib/render_backup.php';
use App\Core\App;

// Vérification des droits d'accès
require_admin();

$success_msg = '';
$error_msg   = '';
$info_msg    = '';

// ── Définition du chemin de la base ──
$db_path = defined('DB_PATH') ? DB_PATH : dirname(__DIR__) . '/db/workflow.db';

// ── Tables de référence pour les statistiques ──
$db_tables = ['forms', 'steps', 'step_recipients', 'submissions', 'tokens',
              'admins', 'admin_requests', 'settings', 'form_fields',
              'audit_log', 'alert_rules', 'alert_log',
              // P2-B : inclure submission_validator_data dans les stats (et donc le dump .db)
              'submission_validator_data'];

// ═══════════════════════════════════════════════════════════════
//  TRAITEMENT DES ACTIONS POST
// ═══════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';

    // ── 1. Téléchargement de la sauvegarde ──
    if ($action === 'download_backup') {
        if (!file_exists($db_path)) {
            $error_msg = 'Le fichier de base de données est introuvable.';
        } else {
            $filename = 'workflow_backup_' . date('Ymd_His') . '.db';

            // Journalisation avant le téléchargement
            App::audit()->log('backup_download', 'database', 'Téléchargement sauvegarde : ' . $filename);

            // Envoi du fichier en téléchargement
            header('Content-Type: application/x-sqlite3');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($db_path));
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');
            readfile($db_path);
            exit;
        }
    }

    // ── 2. Restauration de la base ──
    if ($action === 'restore_backup') {
        if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE   => 'Le fichier dépasse la taille maximale autorisée par le serveur (upload_max_filesize).',
                UPLOAD_ERR_FORM_SIZE  => 'Le fichier dépasse la taille maximale autorisée par le formulaire.',
                UPLOAD_ERR_PARTIAL    => 'Le fichier n\'a été que partiellement téléchargé.',
                UPLOAD_ERR_NO_FILE    => 'Aucun fichier n\'a été téléchargé.',
                UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant sur le serveur.',
                UPLOAD_ERR_CANT_WRITE => 'Échec de l\'écriture du fichier sur le disque.',
                UPLOAD_ERR_EXTENSION  => 'Téléchargement bloqué par une extension PHP.',
            ];
            $code = $_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $error_msg = $upload_errors[$code] ?? 'Erreur inconnue lors du téléchargement.';
        } else {
            $tmp_path  = $_FILES['backup_file']['tmp_name'];
            $orig_name = $_FILES['backup_file']['name'];

            // Vérifier l'extension .db
            if (strtolower(pathinfo($orig_name, PATHINFO_EXTENSION)) !== 'db') {
                $error_msg = 'Seuls les fichiers .db sont acceptés. Fichier fourni : ' . h($orig_name);
            }
            // Vérifier que le fichier est une base SQLite valide
            elseif (!is_valid_sqlite_db($tmp_path)) {
                $error_msg = 'Le fichier fourni n\'est pas une base de données SQLite valide. Vérifiez le fichier et réessayez.';
            }
            else {
                // Fermer la connexion PDO existante pour libérer le fichier SQLite
                // (release_pdo() met $GLOBALS['_pdo'] à null + rollback, T-19/O-05)
                release_pdo();

                // Copie de sécurité de la base actuelle
                $backup_before = $db_path . '.before_restore_' . date('Ymd_His');
                if (file_exists($db_path)) {
                    copy($db_path, $backup_before);
                }

                // Remplacement du fichier
                if (move_uploaded_file($tmp_path, $db_path)) {
                    // Vérifier que la base restaurée est fonctionnelle
                    try {
                        $test_pdo = new PDO('sqlite:' . $db_path);
                        $test_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        _dbm_q($test_pdo, 'SELECT COUNT(*) FROM sqlite_master')->fetchColumn();
                        $test_pdo = null; // Fermer

                        App::audit()->log('backup_restore', 'database',
                                'Base restaurée depuis le fichier : ' . h($orig_name) .
                                ' (sauvegarde pré-restauration : ' . basename($backup_before) . ')');

                        $success_msg = 'La base de données a été restaurée avec succès depuis « ' . h($orig_name) . ' ». ' .
                                       'Une copie de la base précédente a été conservée : ' . h(basename($backup_before));
                    } catch (Exception $e) {
                        // La base restaurée est corrompue — restaurer la sauvegarde
                        if (file_exists($backup_before)) {
                            copy($backup_before, $db_path);
                        }
                        $error_msg = 'La base restaurée semble corrompue. La base d\'origine a été rétablie. Erreur : ' . h($e->getMessage());
                    }
                } else {
                    $error_msg = 'Impossible de remplacer le fichier de base de données. Vérifiez les permissions du dossier db/.';
                    // Nettoyer la copie de sécurité si le move a échoué
                    if (file_exists($backup_before)) {
                        @unlink($backup_before);
                    }
                }
            }
        }
    }

    // ── 3. Purge — étape 1 : compter les éléments ──
    if ($action === 'purge_count') {
        $months = (int)($_POST['purge_months'] ?? 0);
        if (!in_array($months, [6, 12, 18, 24], true)) {
            $error_msg = 'Valeur de mois invalide.';
        } else {
            $purge_preview = count_purge_targets($months);
            $purge_preview['months'] = $months;
            // On ne valide pas encore la purge, on affiche juste le récapitulatif
        }
    }

    // ── 3. Purge — étape 2 : confirmer et exécuter ──
    if ($action === 'purge_confirm') {
        $months = (int)($_POST['purge_months'] ?? 0);
        if (!in_array($months, [6, 12, 18, 24], true)) {
            $error_msg = 'Valeur de mois invalide.';
        } else {
            $preview = count_purge_targets($months);

            if ($preview['submissions'] === 0) {
                $info_msg = 'Aucune soumission à purger pour la période de ' . $months . ' mois.';
            } else {
                try {
                    $pdo = get_pdo();
                    $pdo->exec('PRAGMA foreign_keys = ON');

                    $cutoff = date('Y-m-d H:i:s', strtotime("-{$months} months"));

                    // Récupérer les IDs des soumissions à purger
                    $stmt_ids = $pdo->prepare("
                        SELECT id FROM submissions
                        WHERE status IN ('valide', 'refuse')
                          AND closed_at IS NOT NULL
                          AND closed_at < ?
                    ");
                    $stmt_ids->execute([$cutoff]);
                    $ids = $stmt_ids->fetchAll(PDO::FETCH_COLUMN);

                    if (!empty($ids)) {
                        $placeholders = implode(',', array_fill(0, count($ids), '?'));

                        // P2-B : Supprimer les données validator associées (filled_by='validator').
                        // La FK est ON DELETE CASCADE mais PRAGMA foreign_keys peut être OFF
                        // selon la connexion — on supprime donc explicitement (ceinture + bretelles).
                        $stmt_del_vd = $pdo->prepare(
                            "DELETE FROM submission_validator_data WHERE submission_id IN ($placeholders)"
                        );
                        $stmt_del_vd->execute($ids);
                        $validator_data_deleted = $stmt_del_vd->rowCount();

                        // Supprimer les alert_logs associés
                        $stmt_del_alerts = $pdo->prepare("DELETE FROM alert_log WHERE submission_id IN ($placeholders)");
                        $stmt_del_alerts->execute($ids);
                        $alert_logs_deleted = $stmt_del_alerts->rowCount();

                        // Supprimer les tokens associés
                        $stmt_del_tokens = $pdo->prepare("DELETE FROM tokens WHERE submission_id IN ($placeholders)");
                        $stmt_del_tokens->execute($ids);
                        $tokens_deleted = $stmt_del_tokens->rowCount();

                        // Supprimer les soumissions
                        $stmt_del_subs = $pdo->prepare("DELETE FROM submissions WHERE id IN ($placeholders)");
                        $stmt_del_subs->execute($ids);
                        $submissions_deleted = $stmt_del_subs->rowCount();

                        // Optimiser la base
                        $pdo->exec('VACUUM');

                        App::audit()->log('purge_data', 'database',
                            "Purge effectuée : {$submissions_deleted} soumissions, " .
                            "{$tokens_deleted} tokens, {$alert_logs_deleted} alert_logs, " .
                            "{$validator_data_deleted} submission_validator_data " .
                            "(soumissions clôturées depuis + de {$months} mois, avant le {$cutoff})");

                        $success_msg = "Purge effectuée avec succès : " .
                            "<strong>{$submissions_deleted}</strong> soumission(s), " .
                            "<strong>{$tokens_deleted}</strong> token(s), " .
                            "<strong>{$alert_logs_deleted}</strong> alerte(s), " .
                            "<strong>{$validator_data_deleted}</strong> donnée(s) validateur " .
                            "supprimée(s) (données clôturées depuis plus de {$months} mois).";
                    } else {
                        $info_msg = 'Aucune donnée à purger.';
                    }
                } catch (Exception $e) {
                    $error_msg = 'Erreur lors de la purge : ' . h($e->getMessage());
                }
            }
        }
    }
}

// ═══════════════════════════════════════════════════════════════
//  FONCTIONS UTILITAIRES
// ═══════════════════════════════════════════════════════════════

/**
 * Vérifie qu'un fichier est une base SQLite valide
 * en lisant l'en-tête (les 16 premiers octets doivent contenir "SQLite format 3")
 */
function is_valid_sqlite_db(string $path): bool {
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

// release_pdo() est désormais définie dans helpers.php (T-19/O-05) — implémentation
// réelle : rollback + null de la connexion PDO globale pour libérer le fichier SQLite.
// Conservée comme fonction globale publique (signature inchangée : void ()).

/**
 * Compte les éléments qui seraient purgés pour une durée donnée
 *
 * @return array<string, mixed>
 */
function count_purge_targets(int $months): array {
    $pdo = get_pdo();
    $cutoff_ts = strtotime("-{$months} months");
    $cutoff = date('Y-m-d H:i:s', $cutoff_ts !== false ? $cutoff_ts : 0);

    // Soumissions clôturées (valide ou refusé) depuis plus de X mois
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM submissions
        WHERE status IN ('valide', 'refuse')
          AND closed_at IS NOT NULL
          AND closed_at < ?
    ");
    $stmt->execute([$cutoff]);
    $submissions = (int)$stmt->fetchColumn();

    // Tokens associés
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM tokens t
        JOIN submissions s ON s.id = t.submission_id
        WHERE s.status IN ('valide', 'refuse')
          AND s.closed_at IS NOT NULL
          AND s.closed_at < ?
    ");
    $stmt->execute([$cutoff]);
    $tokens = (int)$stmt->fetchColumn();

    // Alert logs associés
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM alert_log al
        JOIN submissions s ON s.id = al.submission_id
        WHERE s.status IN ('valide', 'refuse')
          AND s.closed_at IS NOT NULL
          AND s.closed_at < ?
    ");
    $stmt->execute([$cutoff]);
    $alert_logs = (int)$stmt->fetchColumn();

    // P2-B : Données validator associées (filled_by='validator')
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM submission_validator_data svd
        JOIN submissions s ON s.id = svd.submission_id
        WHERE s.status IN ('valide', 'refuse')
          AND s.closed_at IS NOT NULL
          AND s.closed_at < ?
    ");
    $stmt->execute([$cutoff]);
    $validator_data = (int)$stmt->fetchColumn();

    return [
        'submissions'       => $submissions,
        'tokens'            => $tokens,
        'alert_logs'        => $alert_logs,
        'validator_data'    => $validator_data,
    ];
}

// ═══════════════════════════════════════════════════════════════
//  STATISTIQUES DE LA BASE DE DONNÉES
// ═══════════════════════════════════════════════════════════════

$db_stats = [];

// Taille du fichier
$db_stats['file_size'] = get_db_size();
$db_stats['file_size_readable'] = format_bytes($db_stats['file_size']);
$db_stats['file_exists'] = file_exists($db_path);
$db_stats['file_modified'] = '—';
if (file_exists($db_path)) {
    $mtime = filemtime($db_path);
    $db_stats['file_modified'] = $mtime !== false ? date('d/m/Y H:i:s', $mtime) : '—';
}

// Comptage par table
$db_stats['row_counts'] = [];
try {
    $pdo = get_pdo();
    foreach ($db_tables as $table) {
        try {
            $count = (int)_dbm_q($pdo, "SELECT COUNT(*) FROM {$table}")->fetchColumn();
            $db_stats['row_counts'][$table] = $count;
        } catch (Exception $e) {
            $db_stats['row_counts'][$table] = '—';
            error_log('backup row count error for ' . $table . ': ' . $e->getMessage());
        }
    }

    // Date de la soumission la plus ancienne et la plus récente
    $oldest = _dbm_q($pdo, "SELECT MIN(submitted_at) FROM submissions")->fetchColumn();
    $newest = _dbm_q($pdo, "SELECT MAX(submitted_at) FROM submissions")->fetchColumn();
    $oldest_str = $oldest !== false ? (string)$oldest : '';
    $newest_str = $newest !== false ? (string)$newest : '';
    $oldest_ts = $oldest_str !== '' ? strtotime($oldest_str) : false;
    $newest_ts = $newest_str !== '' ? strtotime($newest_str) : false;
    $db_stats['oldest_submission'] = ($oldest_str !== '' && $oldest_ts !== false) ? date('d/m/Y H:i', $oldest_ts) : '—';
    $db_stats['newest_submission'] = ($newest_str !== '' && $newest_ts !== false) ? date('d/m/Y H:i', $newest_ts) : '—';

    // Informations SQLite : page_count et freelist_count
    $page_count    = (int)_dbm_q($pdo, "PRAGMA page_count")->fetchColumn();
    $freelist_count = (int)_dbm_q($pdo, "PRAGMA freelist_count")->fetchColumn();
    $page_size     = (int)_dbm_q($pdo, "PRAGMA page_size")->fetchColumn();
    $db_stats['page_count']     = $page_count;
    $db_stats['freelist_count'] = $freelist_count;
    $db_stats['page_size']      = $page_size;
    $db_stats['db_size_pages']  = format_bytes($page_count * $page_size);
    $db_stats['free_pages']     = format_bytes($freelist_count * $page_size);

} catch (Exception $e) {
    $db_stats['error'] = $e->getMessage();
}

/**
 * Formate une taille en octets en unité lisible
 */
function format_bytes(int $bytes, int $precision = 2): string {
    if ($bytes <= 0) return '0 o';
    $units = ['o', 'Ko', 'Mo', 'Go'];
    $power = (int)floor(log($bytes, 1024));
    $power = min($power, count($units) - 1);
    return round($bytes / pow(1024, $power), $precision) . ' ' . $units[$power];
}

// Variable pour le récapitulatif de purge (persiste entre les étapes)
$purge_preview = $purge_preview ?? null;

// ═══════════════════════════════════════════════════════════════
//  RENDU HTML (délégué à lib/render_backup.php)
// ═══════════════════════════════════════════════════════════════
//
// Toute la production HTML (CSS, breadcrumb, cartes statistiques,
// formulaires de téléchargement / restauration / purge, appel final
// à render_page) est déléguée à render_backup_page() afin de garder
// ce fichier sous la barre des 600 lignes (refactor « all-under-600 »).
render_backup_page(
    $db_path,
    $db_stats,
    $purge_preview,
    $success_msg,
    $error_msg,
    $info_msg
);
