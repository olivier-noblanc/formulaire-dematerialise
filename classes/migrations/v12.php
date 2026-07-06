<?php
declare(strict_types=1);

/**
 * Migration v12: STUB — système de brouillons supprimé (KISS).
 * La table drafts a été retirée du schéma initial. Cette migration est gardée
 * comme no-op pour préserver la compatibilité avec la boucle for v10..v19.
 *
 * @package Migrations
 */

function apply_migration_v12(PDO $pdo, int $current_version): int {
    // No-op — drafts system removed
    $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([12]);
    return max($current_version, 12);
}
