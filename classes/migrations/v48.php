<?php
declare(strict_types=1);

/**
 * v48 — unicité de `form_fields.field_name` par formulaire.
 *
 * Un import JSON pouvait créer deux champs de même `field_name` dans un même
 * formulaire (y compris via un nom généré depuis le libellé). Au runtime,
 * `submission_data` indexe les valeurs par `field_name` : deux champs
 * homonymes rendent la lecture ambiguë et les conditions de workflow
 * indéterminées. v48 pose donc un index UNIQUE (form_id, lower(field_name)).
 *
 * Politique fail-closed (identique à v47) : si des doublons existent déjà,
 * la migration BLOQUE avant tout `CREATE INDEX`, avec la liste des
 * `form_id`/`field_name`/`id` en cause et la requête de diagnostic. Aucune
 * suppression ni renommage silencieux : renommer un `field_name` casserait
 * les soumissions existantes indexées par ce nom.
 *
 * `lower(field_name)` : la validation applicative (`FormJsonValidator`)
 * compare les noms sans tenir compte de la casse ; l'index doit exprimer le
 * même invariant. SQLite supporte les index d'expression (`lower`) depuis
 * la 3.9 (2015), largement en dessous du minimum runtime du projet.
 */
function apply_migration_v48(PDO $pdo, int $current_version): int
{
    if ($current_version >= 48 && $current_version < 900) {
        return $current_version;
    }

    $done = $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 48');
    if ($done === false) {
        throw new RuntimeException('v48: version query failed');
    }
    $already = (int) $done->fetchColumn();
    $done = null;
    if ($already > 0) {
        return max($current_version, 48);
    }

    // ── Préflight : aucun doublon de field_name dans un même formulaire ──
    $rows = $pdo->query(
        'SELECT form_id, lower(field_name) AS name, COUNT(*) AS n, GROUP_CONCAT(id) AS ids '
        . 'FROM form_fields GROUP BY form_id, lower(field_name) HAVING COUNT(*) > 1 LIMIT 50'
    );
    if ($rows === false) {
        throw new RuntimeException('v48: duplicate preflight query failed');
    }
    $duplicates = $rows->fetchAll(PDO::FETCH_ASSOC);
    $rows = null;

    if ($duplicates !== []) {
        $diagnostics = [];
        foreach ($duplicates as $duplicate) {
            $form_id = (string) ($duplicate['form_id'] ?? '');
            $name = (string) ($duplicate['name'] ?? '');
            $ids = (string) ($duplicate['ids'] ?? '');
            $diagnostics[] = "form_id={$form_id} field_name={$name} ids=[{$ids}]";
        }
        throw new RuntimeException(
            'v48 blocked: duplicate form_fields.field_name detected — '
            . implode('; ', $diagnostics)
            . '. Corrigez manuellement (diagnostic : SELECT form_id, lower(field_name), GROUP_CONCAT(id) '
            . 'FROM form_fields GROUP BY form_id, lower(field_name) HAVING COUNT(*) > 1).'
        );
    }

    // ── Index UNIQUE (form_id, lower(field_name)) ──
    $pdo->exec(
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_ff_form_field_name '
        . 'ON form_fields(form_id, lower(field_name))'
    );

    $index = $pdo->query(
        "SELECT sql FROM sqlite_master WHERE type='index' AND name='idx_ff_form_field_name'"
    );
    if ($index === false) {
        throw new RuntimeException('v48: index verification query failed');
    }
    $index_sql = (string) $index->fetchColumn();
    $index = null;
    if ($index_sql === '' || !str_contains($index_sql, 'UNIQUE')) {
        throw new RuntimeException('v48: unique index idx_ff_form_field_name missing after creation');
    }

    $pdo->exec("INSERT INTO schema_version (version, applied_at) VALUES (48, datetime('now'))");

    return 48;
}