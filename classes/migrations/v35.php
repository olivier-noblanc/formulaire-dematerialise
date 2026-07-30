<?php
declare(strict_types=1);

/**
 * Migration v35: Nettoyage des hints qui ne contiennent qu'un chiffre (ex: "1").
 *
 * Ces hints sont inutiles (un chiffre brut n'est pas un texte d'aide) et
 * polluent l'affichage du formulaire (span.hint = "1" sous chaque champ).
 * Probablement issus d'un import JSON où l'IA a généré "hint": "1" au lieu
 * d'un vrai texte d'aide.
 *
 * @package Migrations
 */

function apply_migration_v35(PDO $pdo, int $current_version): int {
    $needs_v35 = ($current_version < 35) || ($current_version >= 900);
    if (!$needs_v35) {
        return $current_version;
    }

    try {
        $v35_stmt = $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 35");
        if ($v35_stmt === false) {
            throw new \RuntimeException('v35: COUNT query failed');
        }
        $v35_done = (int) $v35_stmt->fetchColumn();
        $v35_stmt = null;
        if ($v35_done > 0) {
            return max($current_version, 35);
        }

        $cleaned = $pdo->exec("UPDATE form_fields SET hint = '' WHERE TRIM(hint) GLOB '[0-9]*' AND LENGTH(TRIM(hint)) <= 3");
        if ($cleaned > 0) {
            error_log("[db_migrate] v35: $cleaned hint(s) contenant uniquement un chiffre nettoyé(s)");
        }

        $pdo->prepare("INSERT OR IGNORE INTO schema_version (version, applied_at) VALUES (35, datetime('now'))")->execute();

        return 35;
    } catch (PDOException $e) {
        error_log("Migration v35 failed: " . $e->getMessage());
        return $current_version;
    }
}
