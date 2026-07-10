<?php
declare(strict_types=1);

/**
 * Rendu HTML de la page sauvegarde / restauration (backup.php).
 *
 * Wrapper backward-compatible — délègue à App\Render\BackupRenderer.
 *
 * @package lib
 * @see /backup.php
 */

function backup_page_css(): string {
    return (new \App\Render\BackupRenderer())->pageCss();
}

function render_backup_content(
    string $db_path,
    array $db_stats,
    ?array $purge_preview,
    string $success_msg,
    string $error_msg,
    string $info_msg
): string {
    return (new \App\Render\BackupRenderer())->renderContent(
        $db_path, $db_stats, $purge_preview, $success_msg, $error_msg, $info_msg
    );
}

function render_backup_page(
    string $db_path,
    array $db_stats,
    ?array $purge_preview,
    string $success_msg,
    string $error_msg,
    string $info_msg
): void {
    (new \App\Render\BackupRenderer())->renderPage(
        $db_path, $db_stats, $purge_preview, $success_msg, $error_msg, $info_msg
    );
}
