<?php
declare(strict_types=1);

namespace App\Render;

/**
 * Rendu HTML de la page sauvegarde / restauration (backup.php).
 *
 * Extrait du rendu inline de backup.php pour garder ce fichier sous
 * 600 lignes (refactor « all-under-600 »). Contient :
 *  - pageCss()         : CSS spécifique à la page (nowdoc statique)
 *  - renderContent()   : contenu HTML (cartes statistiques,
 *                                téléchargement, restauration, purge)
 *  - renderPage()      : compose et affiche la page complète
 *                                (CSS + breadcrumb + contenu + render_page)
 *
 * Les comportements (backup .db, restauration, purge) restent gérés dans
 * backup.php ; ce module ne fait que du rendu.
 */
final class BackupRenderer
{
    /**
     * CSS spécifique à la page sauvegarde (chargé depuis le template).
     */
    public function pageCss(): string
    {
        $css = '';
        require __DIR__ . '/templates/backup_page_css.php';
        return $css;
    }

    /**
     * Rend le contenu HTML principal de la page sauvegarde.
     *
     * @param array{file_size?: int|false, file_exists: bool, file_size_readable: string, file_modified: string, row_counts: array<string, '—'|int>, oldest_submission?: string, newest_submission?: string, page_size?: int, page_count?: int, db_size_pages?: string, freelist_count?: int, free_pages?: string, error?: string} $db_stats
     * @param array{months: int, submissions: int, tokens: int, alert_logs: int, validator_data?: int}|null $purge_preview
     */
    public function renderContent(
        string $db_path,
        array $db_stats,
        ?array $purge_preview,
        string $success_msg,
        string $error_msg,
        string $info_msg
    ): string {
        ob_start();
        ?>

    <h1><span aria-hidden="true">💾</span> Sauvegarde et restauration</h1>

    <?= new ErrorRenderer()->messages(['success' => $success_msg, 'error' => $error_msg, 'info' => $info_msg]) ?>

    <!-- ═══════════════════════════════════════════════════════════ -->
    <!--  4. STATISTIQUES DE LA BASE                               -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <div class="card">
        <h2><span aria-hidden="true">📊</span> Statistiques de la base de données</h2>

        <?php if ((bool)($db_stats['error'])): ?>
            <div class="msg-error" role="alert" aria-live="assertive">Erreur lors de la lecture des statistiques : <?= \App\Core\App::html()->escape($db_stats['error']) ?></div>
        <?php else: ?>

            <!-- Informations fichier -->
            <h3>Fichier</h3>
            <div class="info-row">
                <span class="info-label">Chemin</span>
                <span class="info-value u-fon-fon-3"><?= \App\Core\App::html()->escape($db_path) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Existant</span>
                <span class="info-value"><?= $db_stats['file_exists'] ? '<span aria-hidden="true">✅</span> Oui' : '<span aria-hidden="true">❌</span> Non' ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Taille sur le disque</span>
                <span class="info-value"><?= \App\Core\App::html()->escape($db_stats['file_size_readable']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Dernière modification</span>
                <span class="info-value"><?= \App\Core\App::html()->escape($db_stats['file_modified']) ?></span>
            </div>

            <!-- Comptage par table -->
            <h3 class="mt-125">Nombre d'enregistrements par table</h3>
            <table class="stat-table">
                <thead>
                    <tr>
                        <th>Table</th>
                        <th class="ta-right">Enregistrements</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $total_rows = 0;
            foreach ($db_stats['row_counts'] as $table_name => $count):
                if (is_int($count)) {
                    $total_rows += $count;
                }
                ?>
                    <tr>
                        <td class="u-fon-fon-3"><?= \App\Core\App::html()->escape($table_name) ?></td>
                        <td class="ta-right"><?= is_int($count) ? number_format($count, 0, '', ' ') : \App\Core\App::html()->escape($count) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="u-bor-fon">
                        <td>Total</td>
                        <td class="ta-right"><?= number_format($total_rows, 0, '', ' ') ?></td>
                    </tr>
                </tbody>
            </table>

            <!-- Dates soumissions -->
            <h3 class="mt-125">Soumissions</h3>
            <div class="info-row">
                <span class="info-label">Plus ancienne</span>
                <span class="info-value"><?= \App\Core\App::html()->escape($db_stats['oldest_submission'] ?? '') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Plus récente</span>
                <span class="info-value"><?= \App\Core\App::html()->escape($db_stats['newest_submission'] ?? '') ?></span>
            </div>

            <!-- Informations SQLite internes -->
            <h3 class="mt-125">Informations SQLite</h3>
            <div class="info-row">
                <span class="info-label">Taille de page</span>
                <span class="info-value"><?= number_format($db_stats['page_size'] ?? 0) ?> octets</span>
            </div>
            <div class="info-row">
                <span class="info-label">Nombre de pages</span>
                <span class="info-value"><?= number_format($db_stats['page_count'] ?? 0) ?> (<?= \App\Core\App::html()->escape($db_stats['db_size_pages'] ?? '') ?>)</span>
            </div>
            <div class="info-row">
                <span class="info-label">Pages libres</span>
                <span class="info-value"><?= number_format($db_stats['freelist_count'] ?? 0) ?> (<?= \App\Core\App::html()->escape($db_stats['free_pages'] ?? '') ?>)</span>
            </div>

        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════════════════ -->
    <!--  1. TÉLÉCHARGEMENT DE LA SAUVEGARDE                       -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <div class="card">
        <h2><span aria-hidden="true">📥</span> Télécharger une sauvegarde</h2>
        <p class="caption-2">
            Téléchargez une copie complète de la base de données SQLite au format <code>.db</code>.
            Le fichier sera nommé automatiquement avec la date et l'heure actuelles
            (format : <code>workflow_backup_AAAAMMJJ_HHMMSS.db</code>).
        </p>
        <p class="caption-10">
            <span aria-hidden="true">⚠️</span> La sauvegarde reflète l'état de la base au moment du téléchargement. Les connexions actives
            peuvent être en cours de modification.
        </p>
        <form method="POST">
            <?= \App\Core\App::security()->csrfField() ?>
            <input type="hidden" name="action" value="download_backup">
            <button type="submit" class="btn btn-primary"><span aria-hidden="true">💾</span> Télécharger la sauvegarde</button>
        </form>
    </div>

    <!-- ═══════════════════════════════════════════════════════════ -->
    <!--  2. RESTAURATION DE LA BASE                               -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <div class="card danger-zone">
        <h2><span aria-hidden="true">🔄</span> Restaurer la base de données</h2>
        <p class="caption-2">
            Restaurez la base de données à partir d'un fichier de sauvegarde <code>.db</code> précédemment téléchargé.
        </p>

        <div class="warn-box mb-1">
            <p><strong><span aria-hidden="true">⚠️</span> Attention — Action irréversible</strong></p>
            <p>La base de données actuelle sera remplacée par le fichier téléchargé. Une copie de sécurité de la base actuelle sera automatiquement créée avant la restauration, mais toute donnée non sauvegardée sera perdue.</p>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <?= \App\Core\App::security()->csrfField() ?>
            <input type="hidden" name="action" value="restore_backup">
            <div class="upload-zone">
                <p><span aria-hidden="true">📁</span> Sélectionnez un fichier de sauvegarde (.db)</p>
                <input type="file" name="backup_file" accept=".db" required>
            </div>
            <button type="submit" class="btn btn-danger"><span aria-hidden="true">🔄</span> Restaurer la base de données</button>
        </form>
    </div>

    <!-- ═══════════════════════════════════════════════════════════ -->
    <!--  3. PURGE DES ANCIENNES DONNÉES                           -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <div class="card danger-zone">
        <h2><span aria-hidden="true">🗑️</span> Purger les anciennes données</h2>
        <p class="caption-2">
            Supprimez les soumissions clôturées (validées ou refusées) anciennes, ainsi que leurs tokens, alertes et données validateur associées.
            Les soumissions en cours (<span class="badge badge-en-cours">en_cours</span>) ne seront <strong>jamais</strong> supprimées.
        </p>

        <?php if ($purge_preview !== null): ?>
            <!-- Récapitulatif de la purge avant confirmation -->
            <div class="purge-recap">
                <h3><span aria-hidden="true">⚠️</span> Récapitulatif de la purge — données clôturées depuis plus de <?= (int) $purge_preview['months'] ?> mois</h3>
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
                    <div class="purge-count">
                        <strong><?= number_format($purge_preview['validator_data'] ?? 0, 0, '', ' ') ?></strong>
                        <span>Donnée(s) validateur</span>
                    </div>
                </div>
                <?php if ($purge_preview['submissions'] > 0): ?>
                    <p class="u-col-fon-mar">
                        Ces données seront <strong>définitivement supprimées</strong>. Cette action est irréversible.
                    </p>
                    <form method="POST" class="flex-gap5-6">
                        <?= \App\Core\App::security()->csrfField() ?>
                        <input type="hidden" name="action" value="purge_confirm">
                        <input type="hidden" name="purge_months" value="<?= (int) $purge_preview['months'] ?>">
                        <button type="submit" class="btn btn-danger"><span aria-hidden="true">✅</span> Confirmer la purge</button>
                        <a href="index.php?p=backup" class="btn btn-secondary">Annuler</a>
                    </form>
                <?php else: ?>
                    <p class="u-col-fon-15">
                        <span aria-hidden="true">✅</span> Aucune donnée à purger pour cette période. Toutes les soumissions clôturées sont récentes.
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <?= \App\Core\App::security()->csrfField() ?>
            <input type="hidden" name="action" value="purge_count">
            <div class="field">
                <label>Purger les données clôturées depuis plus de</label>
                <select name="purge_months" class="u-max">
                    <option value="6">6 mois</option>
                    <option value="12" selected>12 mois</option>
                    <option value="18">18 mois</option>
                    <option value="24">24 mois</option>
                </select>
            </div>
            <button type="submit" class="btn btn-danger"><span aria-hidden="true">🔍</span> Compter les données à purger</button>
        </form>
    </div>

    <!-- Retour -->
    <div class="form-actions">
        <a href="index.php?p=dashboard" class="btn btn-secondary">← Retour au tableau de bord</a>
    </div>

    <?php
    $content = ob_get_clean();
        return $content === false ? '' : $content;
    }

    /**
     * Compose et affiche la page sauvegarde complète.
     *
     * @param array{file_size?: int|false, file_exists: bool, file_size_readable: string, file_modified: string, row_counts: array<string, '—'|int>, oldest_submission?: string, newest_submission?: string, page_size?: int, page_count?: int, db_size_pages?: string, freelist_count?: int, free_pages?: string, error?: string} $db_stats
     * @param array{months: int, submissions: int, tokens: int, alert_logs: int, validator_data?: int}|null $purge_preview
     */
    public function renderPage(
        string $db_path,
        array $db_stats,
        ?array $purge_preview,
        string $success_msg,
        string $error_msg,
        string $info_msg
    ): void {
        $page_css    = $this->pageCss();
        $nav_extra   = [
            'backup' => ['href' => 'index.php?p=backup', 'label' => 'Sauvegarde', 'icon' => '💾'],
            'health' => ['href' => 'index.php?p=health', 'label' => 'Santé', 'icon' => '🏥'],
        ];

        $content = $this->renderContent(
            $db_path,
            $db_stats,
            $purge_preview,
            $success_msg,
            $error_msg,
            $info_msg
        );

        echo new PageRenderer()->page(
            'Sauvegarde et restauration',
            'backup',
            $page_css,
            $content,
            ['nav_extra' => $nav_extra]
        );
    }
}
