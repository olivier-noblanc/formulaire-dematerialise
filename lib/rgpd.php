<?php
declare(strict_types=1);

/**
 * RGPD compliance — export, deletion, auto-purge.
 *
 * rgpd_export_user_data() — exporte les données d'un agent (droit d'accès)
 * rgpd_delete_user_data() — supprime/anonymise les données d'un agent (droit à l'effacement)
 * rgpd_auto_purge()       — purge automatique des soumissions anciennes
 *
 * @package lib
 */

use App\Rgpd\RgpdService;
use App\Core\App;

// ── RGPD COMPLIANCE ──────────────────────────────────────────

/**
 * Exporte toutes les données d'un agent au format JSON (droit d'accès RGPD)
 * @return array<string, mixed>
 */
function rgpd_export_user_data(string $email): array {
    return (new RgpdService(App::db()))->exportUserData($email);
}

/**
 * Supprime les données d'un agent (droit à l'effacement RGPD)
 * Anonymise les soumissions et supprime les pièces jointes
 */
function rgpd_delete_user_data(string $email): bool {
    return (new RgpdService(App::db()))->deleteUserData($email);
}

/**
 * Purge automatique des données anciennes (RGPD - conservation limitée)
 * Supprime les soumissions clôturées de plus de X mois
 */
function rgpd_auto_purge(int $months = 24): int {
    return (new RgpdService(App::db()))->autoPurge($months);
}
