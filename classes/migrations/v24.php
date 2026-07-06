<?php
declare(strict_types=1);

/**
 * Migration v24: Nettoie les hints inutiles "Saisie libre" / "Texte libre"
 * dans form_fields. Ces hints sont évidents (un champ texte est à saisie libre)
 * et n'apportent rien — ils ajoutent du bruit visuel.
 *
 * @package Migrations
 */

function apply_migration_v24(PDO $pdo, int $current_version): int {
    $needs_v24 = ($current_version < 24) || ($current_version >= 900);
    if (!$needs_v24) {
        return $current_version;
    }

    try {
        $v24_done = (int) $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 24")->fetchColumn();
        if ($v24_done > 0) {
            return max($current_version, 24);
        }

        // Nettoyer les hints inutiles
        $useless_hints = ['Saisie libre', 'Texte libre', 'saisie libre', 'texte libre',
                          'Saisie libre.', 'Texte libre.', 'Champ libre', 'champ libre'];
        $cleaned = 0;
        foreach ($useless_hints as $hint) {
            $stmt = $pdo->prepare("UPDATE form_fields SET hint = '' WHERE hint = ?");
            $stmt->execute([$hint]);
            $cleaned += $stmt->rowCount();
        }
        // Aussi nettoyer les hints qui ne contiennent QUE "Saisie libre" ou "Texte libre"
        $pdo->exec("UPDATE form_fields SET hint = '' WHERE TRIM(hint) IN ('Saisie libre', 'Texte libre', 'Saisie libre.', 'Texte libre.', 'saisie libre', 'texte libre')");

        if ($cleaned > 0) {
            error_log("[db_migrate] v24: $cleaned hint(s) inutile(s) nettoyé(s)");
        }

        $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([24]);
        return 24;
    } catch (PDOException $e) {
        error_log('[db_migrate] v24 FAILED: ' . $e->getMessage() . ' — retry au prochain appel');
    }
    return $current_version;
}
