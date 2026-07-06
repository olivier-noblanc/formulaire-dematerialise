<?php
declare(strict_types=1);

/**
 * Migration v26: Ajoute la colonne rgpd_consent à la table submissions.
 *
 * La colonne rgpd_consent est utilisée par FormController (INSERT) et
 * download.php (SELECT) mais n'avait jamais été ajoutée en DB — bug
 * pré-existant détecté par Bug01_EndifFormControllerTest.
 *
 * @package Migrations
 */

function apply_migration_v26(PDO $pdo, int $current_version): int {
    $needs_v26 = ($current_version < 26) || ($current_version >= 900);
    if (!$needs_v26) {
        return $current_version;
    }

    try {
        $v26_done = (int) $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 26")->fetchColumn();
        if ($v26_done > 0) {
            return max($current_version, 26);
        }

        // Vérifier si la colonne existe déjà (sécurité)
        $cols = $pdo->query("PRAGMA table_info(submissions)")->fetchAll(PDO::FETCH_ASSOC);
        $hasRgpdConsent = false;
        foreach ($cols as $col) {
            if ($col['name'] === 'rgpd_consent') {
                $hasRgpdConsent = true;
                break;
            }
        }

        if (!$hasRgpdConsent) {
            $pdo->exec("ALTER TABLE submissions ADD COLUMN rgpd_consent INTEGER NOT NULL DEFAULT 0");
        }

        // Enregistrer la version
        $stmt = $pdo->prepare("INSERT INTO schema_version (version, applied_at) VALUES (26, datetime('now'))");
        $stmt->execute();

        return 26;
    } catch (PDOException $e) {
        error_log("Migration v26 failed: " . $e->getMessage());
        return $current_version;
    }
}
