<?php
declare(strict_types=1);

use App\Validation\RecipientAddress;

/**
 * v47 — durcissement des ordres et des destinataires.
 *
 * Domaines couverts :
 *  - `form_fields.ordre` : NOT NULL, entier, >= 0 ;
 *  - `steps.ordre`       : entier positif (> 0) ;
 *  - `step_recipients.email` : email classique ou référence `{{...}}`, jamais
 *    vide ni tronquée.
 *
 * `form_fields` : la rotation legacy d'export connaît une signature exacte,
 * réparée de façon déterministe (voir le préflight). Toute rotation qui ne
 * correspond pas exactement à cette signature reste bloquante : aucune
 * réparation silencieuse, aucun ID perdu.
 *
 * Cause historique : le dépôt contient des reconstructions positionnelles
 * `INSERT INTO ... SELECT *` (v43/v44 au moment de v47, v30/v31/v33 avant le
 * squash). Elles constituent un mécanisme réel de permutation dès que l'ordre
 * physique de la table déployée diffère de la table reconstruite. L'origine
 * exacte de la rotation observée en production n'est pas prouvée par
 * l'historique disponible (le premier commit du dépôt porte déjà l'ordre
 * canonique) : v47 ne revendique donc pas une cause racine unique et conserve
 * la détection + renumérotation explicites décidées, tout en supprimant le
 * risque positionnel des rebuilds (listes de colonnes explicites).
 *
 * Portée des CHECK : elles expriment des domaines (type/valeur), pas une
 * sémantique de colonne. Une valeur légale placée dans la mauvaise colonne
 * (ex. `ordre = 0` vs `required = 0`) reste légalement valide ; une
 * permutation sémantique est détectée par le préflight, pas par SQLite.
 *
 * `step_recipients` ne possède pas de colonne `ordre` dans le schéma courant :
 * ce domaine est donc durci sur le destinataire, pas sur un ordre inventé.
 *
 * Réparation : seules les valeurs tronquées documentées de
 * `RecipientAddress::KNOWN_TRUNCATED` sont corrigées, et uniquement si le
 * libellé de l'étape correspond exactement à la correspondance documentée.
 * Toute autre valeur invalide bloque la migration avant le moindre BEGIN :
 * aucune normalisation silencieuse, aucun ID perdu.
 *
 * Le rebuild des trois tables est atomique (BEGIN IMMEDIATE, FK hors
 * transaction comme v43/v44/v46), avec contrôle des comptes de lignes et du
 * schéma après reconstruction.
 */
function apply_migration_v47(PDO $pdo, int $current_version): int
{
    if ($current_version >= 47 && $current_version < 900) {
        return $current_version;
    }

    $done = $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 47');
    if ($done === false) {
        throw new RuntimeException('v47: version query failed');
    }
    $already = (int) $done->fetchColumn();
    $done = null;
    if ($already > 0) {
        return max($current_version, 47);
    }

    // ── Préflight strict : ordre des étapes (entier > 0) ──
    $invalid = [];
    $rows = $pdo->query(
        "SELECT id FROM steps WHERE ordre IS NULL OR typeof(ordre) <> 'integer' OR ordre < 1 LIMIT 10"
    );
    if ($rows === false) {
        throw new RuntimeException('v47: steps.ordre preflight query failed');
    }
    foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $invalid[] = 'steps.ordre id=' . $id;
    }
    $rows = null;

    // ── Préflight form_fields + réparation de la rotation connue ──
    // Signature EXACTE observée en production (rotation d'un import legacy) :
    //   required   = ancien ordre (entier >= 2)
    //   ordre      = ancien card_group (0/vide si défaut, sinon texte du groupe)
    //   card_group = ancien hint (vide, ou l'un des textes de hint documentés)
    //   hint       = ''
    // Réparation déterministe, uniquement pour cette signature :
    //   card_group := ordre      (0/vide -> 'Général', sinon texte observé)
    //   hint       := card_group (texte du hint observé, vide conservé)
    //   required   := 0          (valeur d'origine non récupérable, choix
    //                             conservateur : ne force pas un champ requis)
    //   ordre      := séquentiel  (règle ORDER BY documentée plus bas)
    // Toute autre combinaison (hint non vide, `ordre` numérique positif, ou
    // card_group non vide hors liste documentée) est fail-closed : aucune
    // réparation silencieuse.
    $rotations = [];
    /** @var list<array{rowid:int,id:string,form_id:string,old_ordre:int,hint_text:string,group_text:string}> $repairable */
    $repairable = [];
    $legacyHintGroups = [
        'Préciser les droits ou le rôle valideur le cas échéant',
        'Préciser les applications métiers nécessaires',
        'Préciser les répertoires et droits attendus',
        'Adresse email du service chargé de compléter les informations d\'affectation',
        'Site et numéro de bureau',
        'Adresse email personnelle de l\'agent',
        'Cocher si du mobilier est nécessaire',
        'À compléter si du mobilier est demandé',
        'Matériel de base, à prévoir systématiquement',
        'Matériel attribué par défaut',
        'Préciser les autres équipements nécessaires',
    ];
    $rows = $pdo->query(
        'SELECT rowid AS rid, id, form_id, required, ordre, card_group, hint '
        . 'FROM form_fields ORDER BY form_id, rowid'
    );
    if ($rows === false) {
        throw new RuntimeException('v47: form_fields rotation preflight query failed');
    }
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $required = $row['required'];
        $requiredInt = is_int($required)
            ? $required
            : (is_string($required) && preg_match('/^-?\d+$/', $required) === 1 ? (int) $required : null);

        $ordreRaw = $row['ordre'];
        $ordreZero = $ordreRaw === 0 || $ordreRaw === '0';
        $ordreDefault = $ordreZero || (is_string($ordreRaw) && trim($ordreRaw) === '');
        $ordreText = is_string($ordreRaw)
            && trim($ordreRaw) !== ''
            && preg_match('/^-?\d+$/', trim($ordreRaw)) !== 1;

        $cardGroup = trim((string) ($row['card_group'] ?? ''));
        $hint = trim((string) ($row['hint'] ?? ''));

        $invalidRequired = $requiredInt === null
            || $requiredInt < 0
            || ($requiredInt !== 0 && $requiredInt !== 1);
        $knownHintInCardGroup = $hint === '' && in_array($cardGroup, $legacyHintGroups, true);
        $exactRotation = $requiredInt !== null
            && $requiredInt >= 2
            && ($ordreDefault || $ordreText)
            && $hint === ''
            && ($cardGroup === '' || $knownHintInCardGroup);

        if ($exactRotation) {
            $repairable[] = [
                'rowid' => (int) $row['rid'],
                'id' => (string) $row['id'],
                'form_id' => (string) $row['form_id'],
                'old_ordre' => $requiredInt,
                'hint_text' => $cardGroup,
                'group_text' => $ordreDefault ? '' : trim((string) $ordreRaw),
            ];
            continue;
        }

        $validOrdre = (is_int($ordreRaw) && $ordreRaw >= 0)
            || (is_string($ordreRaw) && preg_match('/^\d+$/', $ordreRaw) === 1);
        if (!$validOrdre) {
            $invalid[] = 'form_fields.ordre id=' . $row['id']
                . ' value=' . json_encode($ordreRaw);
        }

        if ($invalidRequired) {
            $invalid[] = 'form_fields.required id=' . $row['id']
                . ' value=' . json_encode($required)
                . ($ordreZero ? ' (possible ordre déplacé)' : '');
        }

        if ($knownHintInCardGroup) {
            $rotations[] = 'id=' . $row['id']
                . ' required=' . json_encode($required)
                . ' ordre=' . json_encode($ordreRaw)
                . ' card_group=' . json_encode($cardGroup, JSON_UNESCAPED_UNICODE);
        }
    }
    $rows = null;

    // ── Ordre de renumérotation (déterministe et documenté) ──
    // Pour chaque `form_id` concerné, les lignes réparées sont triées par
    // `required` ASC (ancien ordre métier conservé par la rotation), puis
    // `rowid` ASC (départage stable des égalités). Elles reçoivent des ordres
    // contigus strictement supérieurs au plus grand `ordre` déjà présent dans
    // le formulaire : les lignes saines (hors signature) ne sont jamais
    // renumérotées et aucun doublon n'est créé. Si le formulaire est
    // entièrement en rotation, la base vaut 1 et les ordres sont exactement
    // 1..N.
    /** @var array<string, list<array{rowid:int,id:string,form_id:string,old_ordre:int,hint_text:string,group_text:string}>> $byForm */
    $byForm = [];
    foreach ($repairable as $candidate) {
        $byForm[$candidate['form_id']][] = $candidate;
    }
    /** @var list<array{rowid:int,id:string,form_id:string,hint:string,card_group:string,ordre:int}> $assignments */
    $assignments = [];
    foreach ($byForm as $formId => $list) {
        usort($list, static fn(array $a, array $b): int => [$a['old_ordre'], $a['rowid']] <=> [$b['old_ordre'], $b['rowid']]);

        // Base = après le plus grand ordre sain (entier) du formulaire. Les
        // lignes en rotation portent soit 0, soit le groupe texte : on ne
        // retient donc que les ordres entiers pour ne jamais dépasser une
        // ligne saine ni produire de doublon.
        $maxQuery = $pdo->prepare(
            "SELECT COALESCE(MAX(ordre), 0) FROM form_fields "
            . "WHERE form_id = ? AND typeof(ordre) = 'integer' AND ordre >= 0"
        );
        $maxQuery->execute([$formId]);
        $next = (int) $maxQuery->fetchColumn() + 1;
        $maxQuery = null;

        foreach ($list as $candidate) {
            $assignments[] = [
                'rowid' => $candidate['rowid'],
                'id' => $candidate['id'],
                'form_id' => $formId,
                'hint' => $candidate['hint_text'],
                'card_group' => $candidate['group_text'] === '' ? 'Général' : $candidate['group_text'],
                'ordre' => $next,
            ];
            $next++;
        }
    }

    // Contrôle des doublons avant écriture : les nouveaux ordres sont
    // strictement supérieurs au maximum existant, donc uniques. Une collision
    // signalerait un bug de calcul.
    $seen = [];
    foreach ($assignments as $assignment) {
        $key = $assignment['form_id'] . ':' . $assignment['ordre'];
        if (isset($seen[$key])) {
            throw new RuntimeException('v47: duplicate ordre computed for form ' . $assignment['form_id']);
        }
        $seen[$key] = true;
    }

    // ── Préflight strict : destinataires valides ou réparables de façon sûre ──
    $rows = $pdo->query(
        'SELECT sr.id, sr.step_id, sr.email, s.label AS step_label '
        . 'FROM step_recipients sr LEFT JOIN steps s ON s.id = sr.step_id'
    );
    if ($rows === false) {
        throw new RuntimeException('v47: step_recipients preflight query failed');
    }
    $repairs = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $email = (string) $row['email'];
        if (RecipientAddress::isValid($email)) {
            continue;
        }

        $stepLabel = isset($row['step_label']) ? (string) $row['step_label'] : '';
        $repaired = RecipientAddress::knownRepair($email, $stepLabel);
        if ($repaired !== null) {
            $repairs[] = ['id' => (string) $row['id'], 'email' => $repaired];
            continue;
        }

        $invalid[] = 'step_recipients.email id=' . $row['id']
            . ' step=' . $row['step_id'];
    }
    $rows = null;

    if ($invalid !== [] || $rotations !== []) {
        $diagnostics = [];
        if ($invalid !== []) {
            $diagnostics[] = 'invalid ordre/recipient/required values for '
                . implode(', ', array_slice($invalid, 0, 10));
        }
        if ($rotations !== []) {
            $diagnostics[] = 'ambiguous form_fields.card_group/hint rotation (not the exact known signature, not repaired) for '
                . implode(', ', array_slice($rotations, 0, 10));
        }
        throw new RuntimeException('v47 blocked: ' . implode('; ', $diagnostics));
    }

    $counts = [];
    foreach (['form_fields', 'steps', 'step_recipients'] as $table) {
        $countQuery = $pdo->query("SELECT COUNT(*) FROM {$table}");
        if ($countQuery === false) {
            throw new RuntimeException("v47: {$table} count query failed");
        }
        $counts[$table] = (int) $countQuery->fetchColumn();
        $countQuery = null;
    }

    // PRAGMA foreign_keys est sans effet dans une transaction.
    $fkQuery = $pdo->query('PRAGMA foreign_keys');
    if ($fkQuery === false) {
        throw new RuntimeException('v47: foreign key query failed');
    }
    $foreignKeys = (int) $fkQuery->fetchColumn() === 1;
    $fkQuery = null;
    $pdo->exec('PRAGMA foreign_keys = OFF');
    try {
        $pdo->exec('BEGIN IMMEDIATE');

        // ── Réparation form_fields : rotation exacte capturée au préflight ──
        // card_group := groupe observé (défaut si vide), hint := hint observé,
        // required := 0, ordre := séquentiel calculé ci-dessus.
        if ($assignments !== []) {
            $repairField = $pdo->prepare(
                'UPDATE form_fields SET hint = ?, card_group = ?, required = 0, ordre = ? WHERE rowid = ?'
            );
            foreach ($assignments as $assignment) {
                $repairField->execute([
                    $assignment['hint'],
                    $assignment['card_group'],
                    $assignment['ordre'],
                    $assignment['rowid'],
                ]);
            }
            $repairField = null;
        }

        $update = $pdo->prepare('UPDATE step_recipients SET email = ? WHERE id = ?');
        foreach ($repairs as $repair) {
            $update->execute([$repair['email'], $repair['id']]);
        }
        $update = null;

        // ── form_fields : ordre NOT NULL, entier, >= 0 ──
        $pdo->exec('DROP TABLE IF EXISTS form_fields_new');
        $pdo->exec(
            "CREATE TABLE form_fields_new (id TEXT PRIMARY KEY NOT NULL, form_id TEXT NOT NULL, "
            . "label TEXT NOT NULL, field_type TEXT NOT NULL DEFAULT 'text' CHECK (field_type IN ('text','email','date','select','checkbox','textarea','file')), "
            . "field_name TEXT NOT NULL, options TEXT, hint TEXT DEFAULT '', required INTEGER NOT NULL DEFAULT 0 CHECK (typeof(required) = 'integer' AND required IN (0,1)), "
            . "ordre INTEGER NOT NULL DEFAULT 0 CHECK (typeof(ordre) = 'integer' AND ordre >= 0), "
            . "card_group TEXT DEFAULT 'Général', filled_by TEXT NOT NULL DEFAULT 'demandeur' CHECK (filled_by IN ('demandeur','validator')), "
            . "validator_step TEXT DEFAULT '', visibility TEXT NOT NULL DEFAULT 'all' CHECK (visibility IN ('all','owner_only')), "
            . "`condition` TEXT DEFAULT '', FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE)"
        );
        $formFieldColumns = 'id, form_id, label, field_type, field_name, options, hint, '
            . 'required, ordre, card_group, filled_by, validator_step, visibility, `condition`';
        $pdo->exec(
            "INSERT INTO form_fields_new ({$formFieldColumns}) "
            . "SELECT {$formFieldColumns} FROM form_fields"
        );
        $pdo->exec('DROP TABLE form_fields');
        $pdo->exec('ALTER TABLE form_fields_new RENAME TO form_fields');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ff_form ON form_fields(form_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ff_filled_by ON form_fields(form_id,filled_by)');

        // ── steps : ordre entier, >= 1 ──
        $pdo->exec('DROP TABLE IF EXISTS steps_new');
        $pdo->exec(
            "CREATE TABLE steps_new (id TEXT PRIMARY KEY NOT NULL, form_id TEXT NOT NULL, label TEXT NOT NULL, "
            . "ordre INTEGER NOT NULL CHECK (typeof(ordre) = 'integer' AND ordre >= 1), "
            . "actif INTEGER NOT NULL DEFAULT 1 CHECK (typeof(actif) = 'integer' AND actif IN (0,1)), `condition` TEXT DEFAULT '', "
            . 'FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE)'
        );
        $stepColumns = 'id, form_id, label, ordre, actif, `condition`';
        $pdo->exec(
            "INSERT INTO steps_new ({$stepColumns}) SELECT {$stepColumns} FROM steps"
        );
        $pdo->exec('DROP TABLE steps');
        $pdo->exec('ALTER TABLE steps_new RENAME TO steps');

        // ── step_recipients : email classique ou référence {{...}} ──
        // La CHECK SQL est un miroir volontairement grossier du contrat PHP
        // `RecipientAddress` (pas de regex en SQLite) : elle bloque les formes
        // vides/tronquées/espacées, mais c'est `RecipientAddress::isValid()`
        // qui reste la source de vérité du format exact.
        $pdo->exec('DROP TABLE IF EXISTS step_recipients_new');
        $pdo->exec(
            "CREATE TABLE step_recipients_new (id TEXT PRIMARY KEY NOT NULL, step_id TEXT NOT NULL, email TEXT NOT NULL CHECK ("
            . "email <> '' "
            . "AND trim(email) = email "
            . "AND instr(email, ' ') = 0 "
            . "AND instr(email, char(9)) = 0 "
            . "AND instr(email, char(10)) = 0 "
            . "AND instr(email, char(13)) = 0 "
            . "AND ("
            . "(email LIKE '{{%}}' AND length(email) > 4) "
            . "OR ("
            . "length(email) - length(replace(email, '@', '')) = 1 "
            . "AND instr(email, '@') > 1 "
            . "AND substr(email, -1) <> '@'"
            . ")"
            . ")), FOREIGN KEY (step_id) REFERENCES steps(id) ON DELETE CASCADE)"
        );
        $recipientColumns = 'id, step_id, email';
        $pdo->exec(
            "INSERT INTO step_recipients_new ({$recipientColumns}) "
            . "SELECT {$recipientColumns} FROM step_recipients"
        );
        $pdo->exec('DROP TABLE step_recipients');
        $pdo->exec('ALTER TABLE step_recipients_new RENAME TO step_recipients');

        // ── Vérifications post-rebuild ──
        foreach ($counts as $table => $before) {
            $afterQuery = $pdo->query("SELECT COUNT(*) FROM {$table}");
            if ($afterQuery === false) {
                throw new RuntimeException("v47: {$table} post-rebuild count failed");
            }
            $after = (int) $afterQuery->fetchColumn();
            $afterQuery = null;
            if ($after !== $before) {
                throw new RuntimeException("v47: {$table} row count changed during rebuild");
            }
        }

        $schema = [];
        foreach (['form_fields', 'steps', 'step_recipients'] as $table) {
            $schemaQuery = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$table}'");
            if ($schemaQuery === false) {
                throw new RuntimeException("v47: {$table} schema query failed");
            }
            $schema[$table] = (string) $schemaQuery->fetchColumn();
            $schemaQuery = null;
        }
        if (!str_contains($schema['form_fields'], "typeof(ordre) = 'integer'")
            || !str_contains($schema['form_fields'], "typeof(required) = 'integer'")
            || !str_contains($schema['form_fields'], 'required IN (0,1)')
            || !str_contains($schema['steps'], "typeof(ordre) = 'integer'")
            || !str_contains($schema['steps'], "typeof(actif) = 'integer'")
            || !str_contains($schema['steps'], 'ordre >= 1')
            || !str_contains($schema['step_recipients'], "LIKE '{{%}}'")) {
            throw new RuntimeException('v47: ordre/recipient CHECK missing after rebuild');
        }

        // ── Vérification des réparations et de l'unicité des ordres ──
        if ($assignments !== []) {
            $verify = $pdo->prepare(
                'SELECT hint, card_group, required, ordre FROM form_fields WHERE id = ?'
            );
            foreach ($assignments as $assignment) {
                $verify->execute([$assignment['id']]);
                $repaired = $verify->fetch(PDO::FETCH_ASSOC);
                if (!is_array($repaired)
                    || (string) $repaired['hint'] !== $assignment['hint']
                    || (string) $repaired['card_group'] !== $assignment['card_group']
                    || (int) $repaired['required'] !== 0
                    || (int) $repaired['ordre'] !== $assignment['ordre']) {
                    throw new RuntimeException(
                        'v47: repaired form_fields row not persisted for id=' . $assignment['id']
                    );
                }
            }
            $verify = null;

            // Les ordres attribués sont contigus et strictement au-dessus du
            // maximum existant : toute ligne supplémentaire dans l'intervalle
            // serait un doublon avec une ligne saine.
            /** @var array<string, list<int>> $ordresByForm */
            $ordresByForm = [];
            foreach ($assignments as $assignment) {
                $ordresByForm[$assignment['form_id']][] = $assignment['ordre'];
            }
            $duplicateQuery = $pdo->prepare(
                'SELECT COUNT(*) FROM form_fields WHERE form_id = ? AND ordre BETWEEN ? AND ?'
            );
            foreach ($ordresByForm as $formId => $ordres) {
                $duplicateQuery->execute([$formId, min($ordres), max($ordres)]);
                if ((int) $duplicateQuery->fetchColumn() !== count($ordres)) {
                    throw new RuntimeException(
                        'v47: duplicate ordre detected for form ' . $formId . ' after repair'
                    );
                }
            }
            $duplicateQuery = null;
        }

        $pdo->exec("INSERT INTO schema_version (version, applied_at) VALUES (47, datetime('now'))");
        $pdo->exec('COMMIT');
        return 47;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK');
        }
        throw $e;
    } finally {
        if ($foreignKeys) {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }
}