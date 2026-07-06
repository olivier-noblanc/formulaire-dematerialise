<?php
declare(strict_types=1);

/**
 * Migration v21: Condition sur form_fields + harmonisation opérateurs.
 *
 * 1. Ajoute colonne `condition` à form_fields (affichage conditionnel).
 * 2. Convertit les anciens opérateurs de steps (equals/not_equals/contains)
 *    vers les nouveaux noms courts (eq/neq/in) — pas de rétrocompat.
 *
 * Format JSON stocké (identique pour fields et steps) :
 *   {"field": "origine_demande", "op": "eq", "value": "Agent"}
 *   {"field": "type_demande", "op": "in", "value": ["A", "B"]}
 *
 * Opérateurs : eq, neq, in, not_empty, empty
 *
 * @package Migrations
 */

function apply_migration_v21(PDO $pdo, int $current_version): int {
    $needs_v21 = ($current_version < 21) || ($current_version >= 900);
    if ($needs_v21) {
        try {
            $v21_done = (int) $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 21")->fetchColumn();
            if ($v21_done > 0) return max($current_version, 21);

            // 1. Ajouter colonne condition à form_fields (idempotent)
            $cols = $pdo->query("PRAGMA table_info(form_fields)")->fetchAll(PDO::FETCH_ASSOC);
            $has_condition = false;
            foreach ($cols as $c) {
                if ($c['name'] === 'condition') { $has_condition = true; break; }
            }
            if (!$has_condition) {
                $pdo->exec("ALTER TABLE form_fields ADD COLUMN condition TEXT DEFAULT ''");
            }

            // 2. Convertir les anciens opérateurs sur steps
            // equals → eq, not_equals → neq, contains → in
            $steps = $pdo->query("SELECT id, condition FROM steps WHERE condition IS NOT NULL AND condition != ''")->fetchAll(PDO::FETCH_ASSOC);
            $convert = ['equals' => 'eq', 'not_equals' => 'neq', 'contains' => 'in'];
            $stmt_update = $pdo->prepare("UPDATE steps SET condition = ? WHERE id = ?");
            foreach ($steps as $step) {
                $cond = json_decode($step['condition'], true);
                if (is_array($cond) && isset($cond['op']) && isset($convert[$cond['op']])) {
                    $cond['op'] = $convert[$cond['op']];
                    $stmt_update->execute([json_encode($cond, JSON_UNESCAPED_UNICODE), $step['id']]);
                }
            }

            $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([21]);
            return 21;
        } catch (PDOException $e) {
            error_log('[db_migrate] v21 FAILED: ' . $e->getMessage());
        }
    }
    return $current_version;
}
