<?php
declare(strict_types=1);

/**
 * Post-migration fixes & deferred seeding.
 *
 * Exécuté après les migrations versionnées v10-v14.
 * - Seed règles d'alerte par défaut
 * - Seed webhook settings
 *
 * @package Migrations
 */

/**
 * Applique les correctifs post-migration et le seeding différé.
 *
 * Regroupe les opérations qui doivent s'exécuter à chaque appel de
 * db_migrate() APRÈS les migrations versionnées v10-v14 :
 *
 *  1. Seed des règles d'alerte par défaut (onboarding / outboarding).
 *  2. Seed des webhook settings si absents.
 *
 * @param PDO  $pdo         Connexion SQLite
 * @param bool $seed_needed Ignoré (conservé pour compat signature)
 */
function apply_post_migration_fixes(PDO $pdo, bool $seed_needed = false): void {
    // ─────────────────────────────────────────────────────────────────
    // 1. Seed des règles d'alerte par défaut
    // ─────────────────────────────────────────────────────────────────
    try {
        $alert_count = _dbm_q($pdo, "SELECT COUNT(*) FROM alert_rules")->fetchColumn();
        if ($alert_count == 0) {
            // Onboarding : alerter 5 jours et 2 jours avant la prise de poste
            $onb = _dbm_q($pdo, "SELECT id FROM forms WHERE slug = 'onboarding' LIMIT 1")->fetchColumn();
            if ($onb) {
                $stmt_ar = $pdo->prepare("INSERT INTO alert_rules (id, form_id, days_before, condition_type, notify_who, label, actif) VALUES (?, ?, ?, ?, ?, ?, 1)");
                $stmt_ar->execute([generate_uuid(), $onb, 5, 'steps_incomplete', 'admin', 'Alerte J-5 : étapes non complétées']);
                $stmt_ar->execute([generate_uuid(), $onb, 2, 'steps_incomplete', 'admin', 'Alerte J-2 : étapes non complétées']);
                $pdo->prepare("UPDATE forms SET deadline_field = ? WHERE id = ?")->execute(['date_prise_poste', $onb]);
            }
            // Outboarding : alerter 5 jours et 2 jours avant le départ
            $ob = _dbm_q($pdo, "SELECT id FROM forms WHERE slug = 'outboarding' LIMIT 1")->fetchColumn();
            if ($ob) {
                $stmt_ar = $pdo->prepare("INSERT INTO alert_rules (id, form_id, days_before, condition_type, notify_who, label, actif) VALUES (?, ?, ?, ?, ?, ?, 1)");
                $stmt_ar->execute([generate_uuid(), $ob, 5, 'steps_incomplete', 'admin', 'Alerte J-5 : étapes non complétées']);
                $stmt_ar->execute([generate_uuid(), $ob, 2, 'steps_incomplete', 'admin', 'Alerte J-2 : étapes non complétées']);
                $pdo->prepare("UPDATE forms SET deadline_field = ? WHERE id = ?")->execute(['date_depart', $ob]);
            }
        }
    } catch (PDOException $e) {
        // Ignorer si déjà fait
    }
}
