<?php
declare(strict_types=1);

/**
 * Migration v28: Colonnes manquantes + seed test user admin.
 *
 * - tokens.action : type d'action du token (valider/refuser), utilisé par les renderers
 * - admin_requests.reviewed_at/reviewed_by : traçabilité des décisions d'accès
 * - Seed testeur@e2e.test dans admins pour les tests PHPUnit
 *
 * @package Migrations
 */

function apply_migration_v28(PDO $pdo, int $current_version): int {
    $needs_v28 = ($current_version < 28) || ($current_version >= 900);
    if (!$needs_v28) {
        return $current_version;
    }

    try {
        $v28_stmt = $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 28");
        if ($v28_stmt === false) {
            throw new \RuntimeException('v28: COUNT query failed');
        }
        $v28_done = (int) $v28_stmt->fetchColumn();
        if ($v28_done > 0) {
            return max($current_version, 28);
        }

        // tokens.action — type d'action du token (valider/refuser)
        // v30 : contrainte via triggers (BEFORE INSERT + BEFORE UPDATE OF action) — voir v30.php
        $columns = $pdo->query("PRAGMA table_info(tokens)");
        $hasAction = false;
        if ($columns !== false) {
            while ($col = $columns->fetch(\PDO::FETCH_ASSOC)) {
                if ($col['name'] === 'action') {
                    $hasAction = true;
                    break;
                }
            }
        }
        if (!$hasAction) {
            $pdo->exec("ALTER TABLE tokens ADD COLUMN action TEXT");
        }

        // admin_requests.reviewed_at — date de décision
        $columns = $pdo->query("PRAGMA table_info(admin_requests)");
        $hasReviewedAt = false;
        if ($columns !== false) {
            while ($col = $columns->fetch(\PDO::FETCH_ASSOC)) {
                if ($col['name'] === 'reviewed_at') {
                    $hasReviewedAt = true;
                    break;
                }
            }
        }
        if (!$hasReviewedAt) {
            $pdo->exec("ALTER TABLE admin_requests ADD COLUMN reviewed_at DATETIME");
        }

        // admin_requests.reviewed_by — qui a décidé
        $columns = $pdo->query("PRAGMA table_info(admin_requests)");
        $hasReviewedBy = false;
        if ($columns !== false) {
            while ($col = $columns->fetch(\PDO::FETCH_ASSOC)) {
                if ($col['name'] === 'reviewed_by') {
                    $hasReviewedBy = true;
                    break;
                }
            }
        }
        if (!$hasReviewedBy) {
            $pdo->exec("ALTER TABLE admin_requests ADD COLUMN reviewed_by TEXT");
        }

        // Seed testeur@e2e.test dans admins pour les tests PHPUnit
        $testUser = $pdo->query("SELECT COUNT(*) FROM admins WHERE email = 'testeur@e2e.test'");
        if ($testUser !== false && (int) $testUser->fetchColumn() === 0) {
            $testUuid = sprintf('%08x-%04x-%04x-%04x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffffffffffff));
            $pdo->prepare("INSERT OR IGNORE INTO admins (id, email, added_at) VALUES (?, 'testeur@e2e.test', datetime('now'))")->execute([$testUuid]);
        }

        // Enregistrer la version
        $stmt = $pdo->prepare("INSERT INTO schema_version (version, applied_at) VALUES (28, datetime('now'))");
        $stmt->execute();

        return 28;
    } catch (PDOException $e) {
        error_log("Migration v28 failed: " . $e->getMessage());
        return $current_version;
    }
}
