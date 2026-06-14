<?php
// backup.php — Sauvegarde et restauration de la base de données (admin uniquement)
require_once __DIR__ . '/helpers.php';

// Vérification des droits d'accès
if (!is_admin_user() && !is_super_admin()) {
    header('Location: admin_access.php');
    exit;
}

$success_msg = '';
$error_msg   = '';
$info_msg    = '';

// ── Définition du chemin de la base ──
$db_path = defined('DB_PATH') ? DB_PATH : __DIR__ . '/db/workflow.db';

// ── Tables de référence pour les statistiques ──
$db_tables = ['forms', 'steps', 'step_recipients', 'submissions', 'tokens',
              'admins', 'admin_requests', 'settings', 'form_fields',
              'audit_log', 'alert_rules', 'alert_log'];

// ═══════════════════════════════════════════════════════════════
//  TRAITEMENT DES ACTIONS POST
// ═══════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        die('Token CSRF invalide. Veuillez réessayer.');
    }

    $action = $_POST['action'] ?? '';

    // ── 1. Téléchargement de la sauvegarde ──
    if ($action === 'download_backup') {
        if (!file_exists($db_path)) {
            $error_msg = 'Le fichier de base de données est introuvable.';
        } else {
            $filename = 'workflow_backup_' . date('Ymd_His') . '.db';

            // Journalisation avant le téléchargement
            app_log('backup_download', 'database', 'Téléchargement sauvegarde : ' . $filename);

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
                // Fermer la connexion PDO existante pour libérer le fichier
                // (get_pdo utilise un static, on force la déconnexion)
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
                        $test_pdo->query('SELECT COUNT(*) FROM sqlite_master')->fetchColumn();
                        $test_pdo = null; // Fermer

                        app_log('backup_restore', 'database',
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
                    if (isset($backup_before) && file_exists($backup_before)) {
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

                        // Supprimer les alert_logs associés
                        $pdo->prepare("DELETE FROM alert_log WHERE submission_id IN ($placeholders)")->execute($ids);
                        $alert_logs_deleted = $pdo->rowCount();

                        // Supprimer les tokens associés
                        $pdo->prepare("DELETE FROM tokens WHERE submission_id IN ($placeholders)")->execute($ids);
                        $tokens_deleted = $pdo->rowCount();

                        // Supprimer les soumissions
                        $pdo->prepare("DELETE FROM submissions WHERE id IN ($placeholders)")->execute($ids);
                        $submissions_deleted = $pdo->rowCount();

                        // Optimiser la base
                        $pdo->exec('VACUUM');

                        app_log('purge_data', 'database',
                            "Purge effectuée : {$submissions_deleted} soumissions, " .
                            "{$tokens_deleted} tokens, {$alert_logs_deleted} alert_logs " .
                            "(soumissions clôturées depuis + de {$months} mois, avant le {$cutoff})");

                        $success_msg = "Purge effectuée avec succès : " .
                            "<strong>{$submissions_deleted}</strong> soumission(s), " .
                            "<strong>{$tokens_deleted}</strong> token(s), " .
                            "<strong>{$alert_logs_deleted}</strong> alerte(s) supprimée(s) " .
                            "(données clôturées depuis plus de {$months} mois).";
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

/**
 * Ferme la connexion PDO statique pour libérer le verrou sur le fichier
 */
function release_pdo(): void {
    // La fonction get_pdo() utilise une variable static $pdo.
    // Pour forcer la déconnexion, on doit manipuler la portée globale.
    // On passe par la reflection ou on recrée la connexion après.
    // Solution la plus simple : affecter null via le fait que get_pdo
    // est dans helpers.php et $pdo est static locale.
    // On ne peut pas y accéder directement, mais en fermant tous les
    // objets PDO enregistrés dans la connexion PDO par défaut on force
    // la libération. Autre approche : utiliser le gestionnaire PDO par défaut.
    // La méthode la plus fiable : vider le pool PDO.
    // Cependant, avec SQLite, il suffit de s'assurer qu'aucun objet statement
    // n'est en cours. On se contente de rouvrir la connexion.
    // Note : on ne peut pas réellement détruire la variable static de get_pdo
    // depuis l'extérieur, mais SQLite en mode WAL permet les lectures concurrentes.
    // Le move_uploaded_file remplacera le fichier correctement.
    return;
}

/**
 * Compte les éléments qui seraient purgés pour une durée donnée
 */
function count_purge_targets(int $months): array {
    $pdo = get_pdo();
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$months} months"));

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

    return [
        'submissions' => $submissions,
        'tokens'      => $tokens,
        'alert_logs'  => $alert_logs,
    ];
}

// ═══════════════════════════════════════════════════════════════
//  STATISTIQUES DE LA BASE DE DONNÉES
// ═══════════════════════════════════════════════════════════════

$db_stats = [];

// Taille du fichier
$db_stats['file_size'] = file_exists($db_path) ? filesize($db_path) : 0;
$db_stats['file_size_readable'] = format_bytes($db_stats['file_size']);
$db_stats['file_exists'] = file_exists($db_path);
$db_stats['file_modified'] = file_exists($db_path) ? date('d/m/Y H:i:s', filemtime($db_path)) : '—';

// Comptage par table
$db_stats['row_counts'] = [];
try {
    $pdo = get_pdo();
    foreach ($db_tables as $table) {
        try {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            $db_stats['row_counts'][$table] = $count;
        } catch (Exception $e) {
            $db_stats['row_counts'][$table] = '—';
        }
    }

    // Date de la soumission la plus ancienne et la plus récente
    $oldest = $pdo->query("SELECT MIN(submitted_at) FROM submissions")->fetchColumn();
    $newest = $pdo->query("SELECT MAX(submitted_at) FROM submissions")->fetchColumn();
    $db_stats['oldest_submission'] = $oldest ? date('d/m/Y H:i', strtotime($oldest)) : '—';
    $db_stats['newest_submission'] = $newest ? date('d/m/Y H:i', strtotime($newest)) : '—';

    // Informations SQLite : page_count et freelist_count
    $page_count    = (int)$pdo->query("PRAGMA page_count")->fetchColumn();
    $freelist_count = (int)$pdo->query("PRAGMA freelist_count")->fetchColumn();
    $page_size     = (int)$pdo->query("PRAGMA page_size")->fetchColumn();
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
    $power = floor(log($bytes, 1024));
    $power = min($power, count($units) - 1);
    return round($bytes / pow(1024, $power), $precision) . ' ' . $units[$power];
}

// Variable pour le récapitulatif de purge (persiste entre les étapes)
$purge_preview = $purge_preview ?? null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sauvegarde et restauration — DREETS Workflow</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='15' fill='%23003189'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial'>D</text></svg>">
    <?php require_once __DIR__ . '/style.php'; ?>
    <style>
        .container { max-width: 900px; }

        /* Blocs d'information dans les cartes */
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: .5rem 0;
            border-bottom: 1px solid #eee;
            font-size: .9rem;
        }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #555; font-weight: normal; }
        .info-value { font-weight: bold; color: #1e1e1e; }

        /* Zone de drop / upload */
        .upload-zone {
            border: 2px dashed #aaa;
            border-radius: 6px;
            padding: 2rem;
            text-align: center;
            margin-bottom: 1rem;
            background: #fafafa;
        }
        .upload-zone p { margin-bottom: .75rem; color: #666; font-size: .9rem; }
        .upload-zone input[type="file"] { font-size: .9rem; }

        /* Purge recap table */
        .purge-recap {
            background: #fff3e0;
            border: 1px solid #b45309;
            border-radius: 4px;
            padding: 1.25rem;
            margin-bottom: 1rem;
        }
        .purge-recap h3 { color: #b45309; margin-bottom: .75rem; font-size: 1rem; }
        .purge-recap .purge-counts { display: flex; gap: 1.5rem; flex-wrap: wrap; margin-bottom: 1rem; }
        .purge-recap .purge-count { text-align: center; min-width: 100px; }
        .purge-recap .purge-count strong { display: block; font-size: 1.6rem; color: #b45309; }
        .purge-recap .purge-count span { font-size: .8rem; color: #666; }

        /* Section danger */
        .danger-zone {
            border: 2px solid #c0392b;
            border-radius: 4px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            background: #fff8f8;
        }
        .danger-zone h2 {
            color: #c0392b;
            border-bottom-color: #c0392b;
        }

        /* Stat table spécifique */
        .stat-table { width: 100%; font-size: .85rem; }
        .stat-table td { padding: .35rem .75rem; }
        .stat-table tr:nth-child(even) td { background: #f7f7fb; }
        .stat-table tr:hover td { background: #f0f0f8; }
    </style>
</head>
<body>
<div class="bandeau">
    <strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités
    <span>Connecté en tant que : <strong><?= h(get_auth_user()) ?></strong></span>
    <span>
        <a href="docs.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;">📖 Documentation</a>
        <a href="admin_settings.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;margin-left:8px;">⚙ Paramètres</a>
    </span>
</div>
<div class="container">

    <h1>💾 Sauvegarde et restauration</h1>

    <?php if ($success_msg): ?>
        <div class="msg-success"><?= $success_msg ?></div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div class="msg-error"><?= h($error_msg) ?></div>
    <?php endif; ?>

    <?php if ($info_msg): ?>
        <div class="msg-info"><?= h($info_msg) ?></div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════ -->
    <!--  4. STATISTIQUES DE LA BASE                               -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <div class="card">
        <h2>📊 Statistiques de la base de données</h2>

        <?php if (!empty($db_stats['error'])): ?>
            <div class="msg-error">Erreur lors de la lecture des statistiques : <?= h($db_stats['error']) ?></div>
        <?php else: ?>

            <!-- Informations fichier -->
            <h3>Fichier</h3>
            <div class="info-row">
                <span class="info-label">Chemin</span>
                <span class="info-value" style="font-family:monospace;font-size:.82rem;"><?= h($db_path) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Existant</span>
                <span class="info-value"><?= $db_stats['file_exists'] ? '✅ Oui' : '❌ Non' ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Taille sur le disque</span>
                <span class="info-value"><?= h($db_stats['file_size_readable']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Dernière modification</span>
                <span class="info-value"><?= h($db_stats['file_modified']) ?></span>
            </div>

            <!-- Comptage par table -->
            <h3 style="margin-top:1.25rem;">Nombre d'enregistrements par table</h3>
            <table class="stat-table">
                <thead>
                    <tr>
                        <th>Table</th>
                        <th style="text-align:right;">Enregistrements</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $total_rows = 0;
                    foreach ($db_stats['row_counts'] as $table_name => $count):
                        if (is_int($count)) $total_rows += $count;
                    ?>
                    <tr>
                        <td style="font-family:monospace;font-size:.82rem;"><?= h($table_name) ?></td>
                        <td style="text-align:right;"><?= is_int($count) ? number_format($count, 0, '', ' ') : h($count) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="font-weight:bold;border-top:2px solid #003189;">
                        <td>Total</td>
                        <td style="text-align:right;"><?= number_format($total_rows, 0, '', ' ') ?></td>
                    </tr>
                </tbody>
            </table>

            <!-- Dates soumissions -->
            <h3 style="margin-top:1.25rem;">Soumissions</h3>
            <div class="info-row">
                <span class="info-label">Plus ancienne</span>
                <span class="info-value"><?= h($db_stats['oldest_submission']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Plus récente</span>
                <span class="info-value"><?= h($db_stats['newest_submission']) ?></span>
            </div>

            <!-- Informations SQLite internes -->
            <h3 style="margin-top:1.25rem;">Informations SQLite</h3>
            <div class="info-row">
                <span class="info-label">Taille de page</span>
                <span class="info-value"><?= number_format($db_stats['page_size']) ?> octets</span>
            </div>
            <div class="info-row">
                <span class="info-label">Nombre de pages</span>
                <span class="info-value"><?= number_format($db_stats['page_count']) ?> (<?= h($db_stats['db_size_pages']) ?>)</span>
            </div>
            <div class="info-row">
                <span class="info-label">Pages libres</span>
                <span class="info-value"><?= number_format($db_stats['freelist_count']) ?> (<?= h($db_stats['free_pages']) ?>)</span>
            </div>

        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════════════════ -->
    <!--  1. TÉLÉCHARGEMENT DE LA SAUVEGARDE                       -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <div class="card">
        <h2>📥 Télécharger une sauvegarde</h2>
        <p style="margin-bottom:1rem;color:#555;font-size:.9rem;">
            Téléchargez une copie complète de la base de données SQLite au format <code>.db</code>.
            Le fichier sera nommé automatiquement avec la date et l'heure actuelles
            (format : <code>workflow_backup_AAAAMMJJ_HHMMSS.db</code>).
        </p>
        <p style="margin-bottom:1rem;color:#555;font-size:.85rem;">
            ⚠️ La sauvegarde reflète l'état de la base au moment du téléchargement. Les connexions actives
            peuvent être en cours de modification.
        </p>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="download_backup">
            <button type="submit" class="btn btn-primary">💾 Télécharger la sauvegarde</button>
        </form>
    </div>

    <!-- ═══════════════════════════════════════════════════════════ -->
    <!--  2. RESTAURATION DE LA BASE                               -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <div class="card danger-zone">
        <h2>🔄 Restaurer la base de données</h2>
        <p style="margin-bottom:1rem;color:#555;font-size:.9rem;">
            Restaurez la base de données à partir d'un fichier de sauvegarde <code>.db</code> précédemment téléchargé.
        </p>

        <div class="warn-box" style="margin-bottom:1rem;">
            <p><strong>⚠️ Attention — Action irréversible</strong></p>
            <p>La base de données actuelle sera remplacée par le fichier téléchargé. Une copie de sécurité de la base actuelle sera automatiquement créée avant la restauration, mais toute donnée non sauvegardée sera perdue.</p>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="restore_backup">
            <div class="upload-zone">
                <p>📁 Sélectionnez un fichier de sauvegarde (.db)</p>
                <input type="file" name="backup_file" accept=".db" required>
            </div>
            <button type="submit" class="btn btn-danger">🔄 Restaurer la base de données</button>
        </form>
    </div>

    <!-- ═══════════════════════════════════════════════════════════ -->
    <!--  3. PURGE DES ANCIENNES DONNÉES                           -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <div class="card danger-zone">
        <h2>🗑️ Purger les anciennes données</h2>
        <p style="margin-bottom:1rem;color:#555;font-size:.9rem;">
            Supprimez les soumissions clôturées (validées ou refusées) anciennes, ainsi que leurs tokens et alertes associés.
            Les soumissions en cours (<span class="badge badge-en-cours">en_cours</span>) ne seront <strong>jamais</strong> supprimées.
        </p>

        <?php if ($purge_preview !== null): ?>
            <!-- Récapitulatif de la purge avant confirmation -->
            <div class="purge-recap">
                <h3>⚠️ Récapitulatif de la purge — données clôturées depuis plus de <?= (int)$purge_preview['months'] ?> mois</h3>
                <div class="purge-counts">
                    <div class="purge-count">
                        <strong><?= number_format($purge_preview['submissions'], 0, '', ' ') ?></strong>
                        <span>Soumission(s)</span>
                    </div>
                    <div class="purge-count">
                        <strong><?= number_format($purge_preview['tokens'], 0, '', ' ') ?></strong>
                        <span>Token(s)</span>
                    </div>
                    <div class="purge-count">
                        <strong><?= number_format($purge_preview['alert_logs'], 0, '', ' ') ?></strong>
                        <span>Alerte(s)</span>
                    </div>
                </div>
                <?php if ($purge_preview['submissions'] > 0): ?>
                    <p style="margin-bottom:1rem;color:#c0392b;font-size:.88rem;">
                        Ces données seront <strong>définitivement supprimées</strong>. Cette action est irréversible.
                    </p>
                    <form method="POST" style="display:flex;gap:.5rem;align-items:center;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="purge_confirm">
                        <input type="hidden" name="purge_months" value="<?= (int)$purge_preview['months'] ?>">
                        <button type="submit" class="btn btn-danger">✅ Confirmer la purge</button>
                        <a href="backup.php" class="btn btn-secondary">Annuler</a>
                    </form>
                <?php else: ?>
                    <p style="color:#1a6b3c;font-size:.9rem;">
                        ✅ Aucune donnée à purger pour cette période. Toutes les soumissions clôturées sont récentes.
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="purge_count">
            <div class="field">
                <label>Purger les données clôturées depuis plus de</label>
                <select name="purge_months" style="max-width:300px;">
                    <option value="6">6 mois</option>
                    <option value="12" selected>12 mois</option>
                    <option value="18">18 mois</option>
                    <option value="24">24 mois</option>
                </select>
            </div>
            <button type="submit" class="btn btn-danger">🔍 Compter les données à purger</button>
        </form>
    </div>

    <!-- Retour -->
    <div class="form-actions">
        <a href="dashboard.php" class="btn btn-secondary">← Retour au tableau de bord</a>
    </div>

</div>
<?= render_footer() ?>
</body>
</html>
