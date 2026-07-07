<?php
declare(strict_types=1);

/**
 * CSV export of submissions — thin wrapper delegating to ExportService.
 *
 * @package lib
 */

function export_csv(PDO $pdo, array $options = []): void {
    \App\Core\App::export()->exportCsv($options);
}
